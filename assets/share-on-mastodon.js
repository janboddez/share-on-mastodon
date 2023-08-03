document.addEventListener( 'DOMContentLoaded', function() {
	document.querySelector( '#share-on-mastodon .unlink' )?.addEventListener( 'click', ( event ) => {
		event.preventDefault();

		if ( ! confirm( share_on_mastodon_obj.message ) ) {
			return;
		}

		const button = event.target;

		// Like a time-out.
		const controller = new AbortController();
		const timeoutId  = setTimeout( () => {
			controller.abort();
		}, 6000 );

		fetch( share_on_mastodon_obj.ajaxurl, {
			signal: controller.signal, // That time-out thingy.
			method: 'POST',
			body:   new URLSearchParams( {
				'action': 'share_on_mastodon_unlink_url',
				'post_id': share_on_mastodon_obj.post_id,
				'share_on_mastodon_nonce': share_on_mastodon_obj.nonce,
			} ),
		} ).then( ( response ) => {
			clearTimeout( timeoutId );

			// @todo: Should we uncheck the box also if we don't delete the value server-side?
			const checkbox = document.querySelector( 'input[name="share_on_mastodon"]' );
			if ( checkbox ) {
				checkbox.checked = false;
			}

			button.parentNode.remove();
		} ).catch( ( error ) => {
			// The request timed out or otherwise failed.
		} );
	} );
} );
