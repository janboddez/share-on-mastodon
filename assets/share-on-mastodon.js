jQuery( document ).ready( function ( $ ) {
	$( '#share-on-mastodon .unlink' ).click( function( e ) {
		e.preventDefault();

		if ( ! confirm( share_on_mastodon_obj.message ) ) {
			return false;
		}

		var button = $( this );
		var data = {
			'action': 'share_on_mastodon_unlink_url',
			'post_id': share_on_mastodon_obj.post_id, // Current post ID.
			'share_on_mastodon_nonce': $( this ).parent().siblings( '#share_on_mastodon_nonce' ).val() // Nonce.
		};

		$.post( ajaxurl, data, function( response ) {
			// On success, untick the checkbox, and remove the link (and the `button` with it).
			$( 'input[name="share_on_mastodon"]' ).prop( 'checked', false );
			button.closest( '.description' ).remove();
		} );
	} );

	$( '.settings_page_share-on-mastodon .button-reset-settings' ).click( function( e ) {
		if ( ! confirm( share_on_mastodon_obj.message ) ) {
			e.preventDefault();
		}
	} );
} );
