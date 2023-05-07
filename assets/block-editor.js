( function ( element, components, i18n, data, coreData, plugins, editPost ) {
	var el                         = element.createElement;
	var interpolate                = element.createInterpolateElement;
	var useState                   = element.useState;
	var TextareaControl            = components.TextareaControl;
	var ToggleControl              = components.ToggleControl;
	var __                         = i18n.__;
	var sprintf                    = i18n.sprintf;
	var useSelect                  = data.useSelect;
	var useEntityProp              = coreData.useEntityProp;
	var registerPlugin             = plugins.registerPlugin;
	var PluginDocumentSettingPanel = editPost.PluginDocumentSettingPanel;

	function displayUrl( url ) {
		var parser = new URL( url );

		return sprintf(
			'<a><b>%1$s</b><c>%2$s</c><b>%3$s</b></a>',
			parser.protocol + '://' + ( parser.username ? parser.username + ( parser.password ? ':' + parser.password : '' ) + '@' : '' ),
			parser.hostname.concat( parser.pathname ).slice( 0, 20 ),
			parser.hostname.concat( parser.pathname ).slice( 20 ),
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

		try {
			window.wp.apiFetch( {
				path: '/share-on-mastodon/v1/unlink',
				signal: controller.signal, // That time-out thingy.
				method: 'POST',
				data: { post_id: postId },
			} ).then( function( response ) {
				clearTimeout( timeoutId );
			} ).catch( function( error ) {
				// The request timed out or otherwise failed. Leave as is.
				throw new Error( 'The "Unlink" request failed.' )
			} );
		} catch ( error ) {
			return false;
		}

		return true;
	}

	registerPlugin( 'share-on-mastodon-panel', {
		render: function( props ) {
			var postId   = useSelect( ( select ) => select('core/editor').getCurrentPostId(), [] );
			var postType = useSelect( ( select ) => select('core/editor').getCurrentPostType(), [] );

			var [ meta, setMeta ]       = useEntityProp( 'postType', postType, 'meta' );
			var [ visible, setVisible ] = useState( !! meta._share_on_mastodon_url ); // Using `useState()` to trigger a re-render on change.

			return el( PluginDocumentSettingPanel, {
					name: 'share-on-mastodon-panel',
					// icon: 'share',
					title: __( 'Share on Mastodon', 'share-on-mastodon' ),
				},
				el( ToggleControl, {
					label: __( 'Share on Mastodon', 'share-on-mastodon' ),
					checked: '1' === meta._share_on_mastodon,
					onChange: ( newValue ) => {
						setMeta( { ...meta, _share_on_mastodon: ( newValue ? '1' : '0' ) } );
					},
				} ),
				el( TextareaControl, {
					label: __( '(Optional) Custom Message', 'share-on-mastodon' ),
					value: meta._share_on_mastodon_status ? meta._share_on_mastodon_status : '',
					onChange: ( newValue ) => {
						setMeta( { ...meta, _share_on_mastodon_status: ( newValue ? newValue : null ) } );
					},
				} ),
				visible
					? el( 'div', {},
						// @todo: "Shorten" the URL.
						interpolate( sprintf( __( 'Shared at %s', 'share-on-mastodon' ), displayUrl( meta._share_on_mastodon_url ) ), {
							a: el( 'a', { className: 'share-on-mastodon-url', href: encodeURI( meta._share_on_mastodon_url ), target: '_blank', rel: 'noreferrer noopener' } ),
							b: el( 'span', { className: 'screen-reader-text' } ),
							c: el( 'span', { className: 'ellipsis' } ),
						} ),
						el( 'a', {
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
			);
		},
	} );
} )( window.wp.element, window.wp.components, window.wp.i18n, window.wp.data, window.wp.coreData, window.wp.plugins, window.wp.editPost );
