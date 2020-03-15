jQuery( function($) {
	$( '.settings_page_share-on-mastodon a.button-reset' ).click( function( e ) {
		if ( ! confirm( share_on_mastodon_obj.message ) ) {
			e.preventDefault();
		}
	} );
} );
