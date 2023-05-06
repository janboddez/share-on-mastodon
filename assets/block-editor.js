( function ( element, components, i18n, data, coreData, plugins, editPost ) {
	var el                        = element.createElement;
	var interpolate               = element.createInterpolateElement;
	var useState                  = element.useState;
	var CheckboxControl           = components.CheckboxControl;
	var PanelBody                 = components.PanelBody;
	var __                        = i18n.__;
	var sprintf                   = i18n.sprintf;
	var useSelect                 = data.useSelect;
	var useEntityProp             = coreData.useEntityProp;
	var registerPlugin            = plugins.registerPlugin;
	var PluginSidebar             = editPost.PluginSidebar;
	var PluginSidebarMoreMenuItem = editPost.PluginSidebarMoreMenuItem;

	function displayUrl( url ) {
		var parser = new URL( url );

		return sprintf(
			'<a><b>%1$s</b><c>%2$s</c><d>%3$s</d></a>',
			parser.protocol + '://' + ( parser.username ? parser.username + ( parser.password ? ':' + parser.password : '' ) + '@' : '' ),
			parser.hostname + parser.pathname.slice( 0, 10 ),
			parser.hostname + parser.pathname.slice( 10 ),
		);
	}

	function unlinkUrl( postId ) {
		if ( ! postId ) {
			return;
		}

		// Like a time-out.
		var controller = new AbortController();
		var timeoutId  = setTimeout( function() {
			controller.abort();
		}, 6000 );

		window.wp.apiFetch( {
			path: '/share-on-mastodon/v1/unlink',
			signal: controller.signal, // That time-out thingy.
			method: 'POST',
			data: { post_id: postId },
		} ).then( function( response ) {
			clearTimeout( timeoutId );
		} ).catch( function( error ) {
			// The request timed out or otherwise failed. Leave as is.
		} );
	}

	registerPlugin( 'share-on-mastodon-sidebar', {
		render: function( props ) {
			var postId   = useSelect( ( select ) => select('core/editor').getCurrentPostId(), [] );
			var postType = useSelect( ( select ) => select('core/editor').getCurrentPostType(), [] );

			var [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
			var isChecked         = false;

			if ( '1' === meta._share_on_mastodon ) {
				isChecked = true;
			}

			var [ visible, setVisible ] = useState( !! meta._share_on_mastodon_url );

			return [
				el( PluginSidebarMoreMenuItem, {
						target: 'share-on-mastodon-sidebar',
						icon: 'share'
					},
					__( 'Share on Mastodon', 'share-on-mastodon' )
				),
				el( PluginSidebar, {
						name: 'share-on-mastodon-sidebar',
						icon: 'share',
						title: __( 'Share on Mastodon', 'share-on-mastodon' ),
					},
					el ( PanelBody, {},
						el( CheckboxControl, {
							label: __( 'Share on Mastodon', 'share-on-mastodon' ),
							checked: isChecked,
							onChange: ( newValue ) => {
								setMeta( { ...meta, _share_on_mastodon: ( newValue ? '1' : '0' ) } );
							},
						} ),
						visible
							? el(
								'p',
								{},
								// @todo: "Shorten" the URL.
								interpolate( sprintf( __( 'Shared at %s', 'share-on-mastodon' ), displayUrl( meta._share_on_mastodon_url ) ), {
									a: el( 'a', { className: 'share-on-mastodon-url', href: encodeURI( meta._share_on_mastodon_url ), target: '_blank', rel: 'noreferrer noopener' } ),
									b: el( 'span', { className: 'screen-reader-text' } ),
									c: el( 'span', { className: 'ellipsis' } ),
									d: el( 'span', { className: 'screen-reader-text' } ),
								} ),
								el(
									'a',
									{
										className: 'share-on-mastodon-unlink',
										href: '#',
										onClick: () => {
											if ( confirm( __( 'Forget this URL?', 'share-on-mastodon' ) ) ) {
												unlinkUrl( postId );
												setVisible( false );
											}
										},
									},
								__( 'Unlink', 'share-on-mastodon' )
								)
							)
							: null,
						// @todo: Add that "unlink" button.
					)
				),
			];
		},
	} );
} )( window.wp.element, window.wp.components, window.wp.i18n, window.wp.data, window.wp.coreData, window.wp.plugins, window.wp.editPost );
