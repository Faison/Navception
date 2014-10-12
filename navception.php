<?php
/*
 * Plugin Name: Navception
 * Plugin URI: http://faisonz.com/wordpress-plugins/navception/
 * Description: Embed WordPress Menus inside of other WordPress Menus!
 * Author: Faison Zutavern
 * Author URI: http://faisonz.com
 * Version: 1.0.0
 */

/**
 * The Navception Plugin Class
 *
 * @since 1.0.0
 *
 * @package Navception
 */
class Navception {

	/**
	 * The ID of a newly created Menu, if detected.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $new_menu_id = 0;

	/**
	 * The constructor, which calls register_hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Hook up Navception functions to needed Filters and Actions.
	 *
	 * @since 1.0.0
	 */
	private function register_hooks() {
		add_filter( 'wp_get_nav_menu_items', array( $this, 'navception' ), 10, 3 );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init',              array( $this, 'add_nav_box' ) );
		add_action( 'wp_ajax_check_for_limbo', array( $this, 'check_for_limbo_ajax' ) );
		add_action( 'wp_update_nav_menu_item', array( $this, 'check_for_limbo'), 10, 3);
		add_action( 'admin_enqueue_scripts',   array( $this, 'pw_load_scripts' ) );
		add_action( 'wp_create_nav_menu',      array( $this, 'detect_new_menu' ) );
	}

	/**
	 * Replace Nav Menu Menu Items with Menus.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items An array of menu item post objects.
	 * @param object $menu  The menu object.
	 * @param array  $args  An array of arguments used to retrieve menu item objects.
	 *
	 * @return array Array of menu items with possible Navception applied.
	 */
	public function navception( $items, $menu, $args ) {
		// Don't navcpetion in the admin
		if ( is_admin() ) {
			return $items;
		}

		$filtered_items = array();
		$nav_id_prefix  = ord( 'a' );

		foreach ( $items as $item ) {
			if ( 'nav_menu' != $item->object ) {
				$filtered_items[] = $item;
				continue;
			}

			$navception_items = wp_get_nav_menu_items( $item->object_id, $args );

			// If the nav menu item is an empty menu, just remove it.
			if ( empty( $navception_items ) ) {
				continue;
			}

			foreach ( $navception_items as $navception_item ) {
				$navception_item->ID         .= chr( $nav_id_prefix );
				$navception_item->db_id      .= chr( $nav_id_prefix );
				$navception_item->menu_order .= chr( $nav_id_prefix );

				if ( empty( $navception_item->menu_item_parent ) ) {
					$navception_item->menu_item_parent = $item->menu_item_parent;
				} else if ( $item->menu_item_parent != $navception_item->menu_item_parent ) {
					$navception_item->menu_item_parent .= chr( $nav_id_prefix );
				}

				$filtered_items[] = $navception_item;
			}

			$nav_id_prefix += 1;
		}

		return $filtered_items;
	}

	/**
	 * Adds the Navigation Menus Meta Box to the Edit Menus Screen.
	 *
	 * @since 1.0.0
	 */
	public function add_nav_box() {
		$nav_menu_tax = get_taxonomy( 'nav_menu' );
		/**
		 * Filter whether the Nav Menu menu items meta box will be added.
		 *
		 * If a falsey value is returned instead of an object, the menu items
		 * meta box for Nav Menus will not be added.
		 *
		 * @since 2.0.0
		 *
		 * @param object $nav_menu_tax The Nav Menu object to add a menu items meta box for.
		 */
		$nav_menu_tax = apply_filters( 'navception_nav_menu_meta_box_object', $nav_menu_tax );

		if ( $nav_menu_tax ) {
			$id = $nav_menu_tax->name;
			add_meta_box( "add-{$id}", $nav_menu_tax->labels->name, 'wp_nav_menu_item_taxonomy_meta_box', 'nav-menus', 'side', 'default', $nav_menu_tax );
		}
	}

	/**
	 * Checks if adding a Nav Menu Menu Item to a menu causes an infinite loop, via AJAX.
	 *
	 * @since 1.0.0
	 */
	public function check_for_limbo_ajax() {
		$original_menu  = isset( $_POST['navception_original_menu'] ) ? $_POST['navception_original_menu'] : false;
		$navcepted_menu = isset( $_POST['navception_new_menu'] ) ? $_POST['navception_new_menu'] : false;
		$checkbox_ul    = isset( $_POST['navception_checkbox_ul'] ) ? $_POST['navception_checkbox_ul'] : false;

		if ( ! ( is_numeric( $original_menu ) && is_numeric( $navcepted_menu ) && $checkbox_ul ) ) {
			wp_send_json( array(
				'success' => false
			) );
		}

		$original_menu  = (int) $original_menu;
		$navcepted_menu = (int) $navcepted_menu;

		if ( $this->causes_limbo( $original_menu, $navcepted_menu ) ) {
			wp_send_json( array(
				'success'      => true,
				'causes_limbo' => true,
				'checkbox_ul'  => $checkbox_ul,
				'menu_id'      => $navcepted_menu,
			) );
		}

		wp_send_json( array(
			'success'      => true,
			'causes_limbo' => false,
		) );
	}

	/**
	 * Checks if adding a Nav Menu Menu Item to a menu causes an infinite loop, via not AJAX.
	 *
	 * Since this function runs after the Nav Menu Menu Item is added to a menu, this function
	 * also removes the Nav Menu Menu Item if an infinite loop is caused.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $menu_id         ID of the updated menu.
	 * @param int   $menu_item_db_id ID of the updated menu item.
	 * @param array $args            An array of arguments used to update a menu item.
	 */
	public function check_for_limbo( $menu_id, $menu_item_db_id, $args ) {
		if ( isset( $args['menu-item-object'] ) && 'nav_menu' == $args['menu-item-object'] ) {
			$original_menu   = $menu_id;
			$navception_menu = (int) $args['menu-item-object-id'];

			if ( $this->causes_limbo( $original_menu, $navception_menu ) ) {
				$this->removed_menus[] = $args['menu-item-title'];
				add_action( 'admin_notices', array( $this, 'warn_of_limbo' ) );
				wp_delete_post( $menu_item_db_id, true );

			}
		}
	}

	/**
	 * Warn the user that an infinite loop would have been created.
	 *
	 * This is specifically used when an infinite loop is created when saving
	 * without AJAX.
	 *
	 * @since 1.0.0
	 */
	private function warn_of_limbo() {
		?>
			<div id='message' class='error'>
				<p><strong>Navception Warning:</strong> Adding that Menu would cause an infinite loop! I removed it for you :D</p>
			</div>
		<?php
	}

	/**
	 * Checks if adding a Nav Menu Menu Item to a menu causes an infinite loop, via AJAX.
	 *
	 * @since 1.0.0
	 *
	 * @param int|array $original_menu  A menu ID or array of menu IDs to check the $navcepted_menu against.
	 * @param int       $navcepted_menu The menu ID to check against $original_menu for infinite loops.
	 *
	 * @return bool True if adding $navcepted_menu to $original_menu causes an infinite loop, otherwise false.
	 */
	private function causes_limbo( $original_menu, $navcepted_menu ) {

		if ( ! is_array( $original_menu ) ) {
			$original_menu = array( $original_menu );
		}

		if ( in_array( $navcepted_menu, $original_menu ) ) {
			return true;
		}

		$original_menu[] = $navcepted_menu;
		$navcepted_items = wp_get_nav_menu_items( $navcepted_menu );

		foreach ( $navcepted_items as $navcepted_item ) {
			if ( 'nav_menu' != $navcepted_item->object ) {
				continue;
			}

			if ( in_array( $navcepted_item->object_id, $original_menu ) ) {
				return true;
			}
			
			if ( $this->causes_limbo( $original_menu, $navcepted_item->object_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueues the navception.js if editing a menu.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page.
	 */
	public function pw_load_scripts( $hook ) {
		if( 'nav-menus.php' != $hook ) {
			return;
		}

		$current_menu_id = false;
		if ( $this->new_menu_id ) {
			$current_menu_id = $this->new_menu_id;
		} else if ( isset( $_REQUEST['menu'] ) ) {
			$current_menu_id = (int) $_REQUEST['menu'];
		} else if ( get_user_option( 'nav_menu_recently_edited' ) ) {
			$current_menu_id = (int) get_user_option( 'nav_menu_recently_edited' );
		}

		if ( $current_menu_id ) {

			wp_enqueue_script(
				'navception',
				plugins_url( '/navception.js', __FILE__ ),
				array(
					'jquery',
				)
			);

			wp_localize_script(
				'navception',
				'navception',
				array(
					'current_menu' => $current_menu_id,
				)
			);
		}
	}

	/**
	 * Stores the ID of the newly created menu for use in pw_load_scripts().
	 *
	 * The normal ways of getting a menu's ID doesn't seem to work when a new menu is
	 * created, so this is the work around from version 1.0.0.
	 *
	 * @since 1.0.0
	 *
	 * @param int $menu_id ID of the new menu.
	 */
	public function detect_new_menu( $menu_id ) {
		$this->new_menu_id = $menu_id;
	}
}

new Navception();
