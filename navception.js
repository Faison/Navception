jQuery( document ).ready(function() {

	var check_for_limbo = function( list_id ) {
		var target = jQuery( this );

		if ( ! target.is( ':checked' ) ) {
			return;
		}

		var data = {
			'action': 'check_for_limbo',
			'navception_original_menu': navception.current_menu,
			'navception_new_menu': target.val(),
			'navception_checkbox_ul': target.closest( 'ul' ).prop( 'id' )
		};

		var handle_response = function( response, status ) {
			if ( 'success' != status || ! response.success ) {
				return;
			}

			if ( response.causes_limbo ) {
				jQuery( '#' + response.checkbox_ul + ' [value=' + response.menu_id + ']' ).prop( 'checked', false );
				alert( 'Adding that menu would cause an infinite loop, I unchecked it for you :D' );
			}
		};

		jQuery.post( ajaxurl, data, handle_response );
	}

	jQuery( '#taxonomy-nav_menu' ).on( 'change.navception', 'input[type=checkbox]', check_for_limbo );
});
