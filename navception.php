<?php
	/*
	 * Plugin Name: Navception
	 * Plugin URI: http://faisonz.com/wordpress-plugins/navception/
	 * Description: Embed WordPress Menus inside of other WordPress Menus!
	 * Author: Faison Zutavern
	 * Author URI: http://faisonz.com
	 * Version: 1.0.0
	 */

	require_once( ABSPATH . 'wp-admin/includes/nav-menu.php' );

	class Navception {

		private $hook;
		private $new_menu_id = false;

		function __construct() {
			$this->register_hooks();
		}

		function register_hooks() {
			add_action( 'admin_init', array( $this, 'add_nav_box' ) );

			add_filter( 'nav_menu_css_class', array( $this, 'navception_class' ), 10, 3 );
			add_filter( 'nav_menu_item_id', array( $this, 'navception_id' ), 10, 3 );

			add_filter( 'walker_nav_menu_start_el', array( $this, 'navception' ), 10, 4 );

			add_action( 'wp_ajax_check_for_limbo', array( $this, 'check_for_limbo_ajax' ) );
			add_action( 'wp_update_nav_menu_item', array( $this, 'check_for_limbo'), 10, 3);

			add_action('admin_enqueue_scripts', array( $this, 'pw_load_scripts' ) );

			add_action( 'wp_create_nav_menu', array( $this, 'detect_new_menu' ) );
		}

		function add_nav_box() {
			$tax = get_taxonomy( 'nav_menu' );
			$tax = apply_filters( 'nav_menu_meta_box_object', $tax );
			if ( $tax ) {
				$id = $tax->name;
				add_meta_box( "add-{$id}", $tax->labels->name, 'wp_nav_menu_item_taxonomy_meta_box', 'nav-menus', 'side', 'default', $tax );
			}
		}

		function navception_class( $classes, $item, $args = null ) {
			if ( 'nav_menu' != $item->object ) {
				return $classes;
			}

			$items = wp_get_nav_menu_items( $item->object_id );

			if ( 0 == count( $items ) ) {
				return $classes;
			}

			_wp_menu_item_classes_by_context( $items );
			$first_item = $items[ 0 ];

			$navcepted_classes   = empty( $first_item->classes ) ? array() : (array) $first_item->classes;
			$navcepted_classes[] = 'menu-item-' . $first_item->ID;

			$classes = array_merge( $classes, $navcepted_classes );
			$classes = apply_filters( 'nav_menu_css_class', array_filter( $classes ), $first_item, $args );

			return $classes;
		}

		function navception_id( $menu_item_id, $item, $args ) {
			if ( 'nav_menu' != $item->object ) {
				return $menu_item_id;
			}

			$items = wp_get_nav_menu_items( $item->object_id );

			if ( 0 == count( $items ) ) {
				return $menu_item_id;
			}

			$first_item = $items[ 0 ];

			$id = apply_filters( 'nav_menu_item_id', 'menu-item-'. $first_item->ID, $first_item, $args );

			return $id;
		}

		/*
		 *
		 *
		 *
		 *	Tap into walker_nav_menu_start_el
		 *	params: $item_output, $item, $depth, $args
		 */
		function navception( $item_output, $item, $depth, $args ) {
			if ( 'nav_menu' != $item->object ) {
				return $item_output;
			}

			//	Gotta find this out based on the item included
			$navception_menu_id = $item->object_id;
			//	if there's a max depth (or -1), do the math to find out how many depths are left.
			$num = $args->depth;
			if ( $num > 0 ) {
				$num -= $depth;
			}

			$navcepted_menu = wp_nav_menu( array(
				'menu'       => $navception_menu_id,
				'container'  => false,
				'fallbak_cb' => false,
				'items_wrap' => '%3$s',
				'depth'      => $num,
				'echo'       => false,
			) );

			$nav_ = explode( '>', $navcepted_menu );
			array_shift( $nav_ );
			$navcepted_menu = implode( '>', $nav_ );

			$nav_ = explode( '</li', $navcepted_menu );
			array_pop( $nav_ );
			$navcepted_menu = implode( '</li', $nav_ );

			return $navcepted_menu;
		}

		function check_for_limbo( $menu_id, $menu_item_db_id, $args ) {
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

		function check_for_limbo_ajax() {
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

		function causes_limbo( $original_menu, $navcepted_menu ) {

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



		function pw_load_scripts( $hook ) {
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

		function detect_new_menu( $menu_id ) {
			$this->new_menu_id = $menu_id;
		}

		function warn_of_limbo() {
			?>
				<div id='message' class='error'>
					<p><strong>Navception Warning:</strong> Adding that Menu would cause an infinite loop! I removed it for you :D</p>
				</div>
			<?php
		}

	}

	new Navception();