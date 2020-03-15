( function( $ ) {
	$( '.settings_page_share-on-mastodon .button-reset-settings' ).click( function( e ) {
		if ( ! confirm( share_on_mastodon_obj.message ) ) {
			e.preventDefault();
		}
	} );
} )( jQuery );
