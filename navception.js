jQuery(document).ready( function() {

	var check_for_limbo = function( list_id ) {
		if( ! jQuery( this ).is( ':checked' ) ) {
			return;
		}

		var target = jQuery(this);

		var handle_response = function( response, status ) {
			if( status != "success" || ! response.success ) { return; }

			if( response.causes_limbo ) {
				jQuery('#' + response.checkbox_ul + ' [value=' + response.menu_id + ']' ).prop('checked', false);
				alert( 'Adding that menu would cause an infinite loop, I unchecked it for you :D' );
			}
		};

		var data = {
			'action': 'check_for_limbo',
			'navception_original_menu': navception.current_menu,
			'navception_new_menu' : jQuery( this ).val(),
			'navception_checkbox_ul' : jQuery( this ).closest( 'ul' ).prop( 'id' )
		};

		jQuery.post( ajaxurl, data, handle_response );
	}

	jQuery('#nav_menuchecklist-pop input[type=checkbox], #nav_menuchecklist input[type=checkbox]').on( 'change.navception', check_for_limbo );


	jQuery('#nav_menu-search-checklist').bind("DOMNodeInserted",function(){
		var childs = jQuery(this).find( 'input[type=checkbox]' );
		if( childs.length > 0 ) {
			var child = childs[ childs.length - 1 ];
		    jQuery( child ).on( 'change.navception', check_for_limbo );
		}
	});
} );