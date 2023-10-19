document.addEventListener( 'DOMContentLoaded', function() {
	document.querySelector( '#share-on-mastodon .unlink' )?.addEventListener( 'click', ( event ) => {
		event.preventDefault();

		if ( ! confirm( share_on_mastodon_obj.message ) ) {
			return;
		}

		const button      = event.target;
		const isGutenberg = ( 'undefined' !== typeof wp && 'undefined' !== typeof wp.blocks );

		// Like a time-out.
		const controller = new AbortController();
		const timeoutId  = setTimeout( () => {
			controller.abort();
		}, 6000 );

		fetch( share_on_mastodon_obj.ajaxurl, {
			signal: controller.signal, // That time-out thingy.
			method: 'POST',
			body: new URLSearchParams( {
				'action': 'share_on_mastodon_unlink_url',
				'post_id': share_on_mastodon_obj.post_id,
				'share_on_mastodon_nonce': share_on_mastodon_obj.nonce,
				'is_gutenberg': isGutenberg,
			} ),
		} ).then( ( response ) => {
			clearTimeout( timeoutId );

			const checkbox = document.querySelector( 'input[name="share_on_mastodon"]' );
			if ( checkbox && isGutenberg ) {
				// Uncheck only within a block editor context.
				checkbox.checked = false;
			}

			button.parentNode.remove();
		} ).catch( ( error ) => {
			// The request timed out or otherwise failed.
		} );
	} );

	document.querySelector( '.settings_page_share-on-mastodon .button-reset-settings' )?.addEventListener( 'click', ( event ) => {
		if ( ! confirm( share_on_mastodon_obj.message ) ) {
			event.preventDefault();
		}
	} );
} );
