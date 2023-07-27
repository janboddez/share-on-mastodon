( function ( element, components, i18n, data, coreData, plugins, editPost, apiFetch, url ) {
	const el                         = element.createElement;
	const interpolate                = element.createInterpolateElement;
	const useState                   = element.useState;
	const TextareaControl            = components.TextareaControl;
	const ToggleControl              = components.ToggleControl;
	const __                         = i18n.__;
	const sprintf                    = i18n.sprintf;
	const useSelect                  = data.useSelect;
	const useEntityProp              = coreData.useEntityProp;
	const registerPlugin             = plugins.registerPlugin;
	const PluginDocumentSettingPanel = editPost.PluginDocumentSettingPanel;

	function displayUrl( mastodonUrl ) {
		try {
			let parser = new URL( mastodonUrl );

			return sprintf(
				'<a><b>%1$s</b><c>%2$s</c><b>%3$s</b></a>',
				parser.protocol + '://' + ( parser.username ? parser.username + ( parser.password ? ':' + parser.password : '' ) + '@' : '' ),
				parser.hostname.concat( parser.pathname ).slice( 0, 20 ),
				parser.hostname.concat( parser.pathname ).slice( 20 ),
			);
		} catch ( error ) {
			// Invalid URL.
		}

		return '';
	}

	function updateUrl( postId, setMastodonUrl ) {
		if ( ! postId ) {
			return false;
		}

		// Like a time-out.
		const controller = new AbortController();
		const timeoutId  = setTimeout( function() {
			controller.abort();
		}, 6000 );

		try {
			apiFetch( {
				path: url.addQueryArgs( '/share-on-mastodon/v1/url', { post_id: postId } ),
				signal: controller.signal, // That time-out thingy.
			} ).then( function( response ) {
				clearTimeout( timeoutId );
				setMastodonUrl( response );
			} ).catch( function( error ) {
				// The request timed out or otherwise failed. Leave as is.
				throw new Error( 'The "Get URL" request failed.' )
			} );
		} catch ( error ) {
			return false;
		}

		// All good.
		return true;
	}

	function unlinkUrl( postId, setMastodonUrl ) {
		if ( ! postId ) {
			return false;
		}

		// Like a time-out.
		var controller = new AbortController();
		var timeoutId  = setTimeout( function() {
			controller.abort();
		}, 6000 );

		try {
			fetch( share_on_mastodon_obj.ajaxurl, {
				signal: controller.signal, // That time-out thingy.
				method: 'POST',
				body: new URLSearchParams( {
					action: 'share_on_mastodon_unlink_url',
					post_id: postId,
					share_on_mastodon_nonce: share_on_mastodon_obj.nonce,
				} ),
			} ).then( function( response ) {
				clearTimeout( timeoutId );
				setMastodonUrl( '' ); // To force a re-render.
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
			const postId   = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId() );
			const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType() );

			const [ meta, setMeta ]               = useEntityProp( 'postType', postType, 'meta' );
			const [ mastodonUrl, setMastodonUrl ] = useState( meta?._share_on_mastodon_url ?? '' );
			const [ updated, setUpdated ]         = useState( false );

			// *Should* the code below use `useSelect()`? I have no clue. Looks
			// like `useSelect()` and so on *inside* `data.subscribe()` leads to
			// errors.
			let wasSavingPost     = useSelect( ( select ) => select( 'core/editor' ).isSavingPost() );
			let wasAutosavingPost = useSelect( ( select ) => select( 'core/editor' ).isAutosavingPost() );

			data.subscribe( () => { // Kinda like `publish_post`, I guess.
				const isSavingPost     = data.select( 'core/editor' ).isSavingPost();
				const isAutosavingPost = data.select( 'core/editor' ).isAutosavingPost();
				const status           = data.select( 'core/editor' ).getEditedPostAttribute( 'status' );
				const publishPost      = wasSavingPost && ! wasAutosavingPost && ! isSavingPost && 'publish' === status;
				wasSavingPost          = isSavingPost;
				wasAutosavingPost      = isAutosavingPost;

				if ( publishPost ) {
					setUpdated( true ); // You'd think one could just `setUpdated( publishPost )` or similar, but, no?
				}
			} );

			if ( updated ) { // Using `useState()` so that this runs only once (per "save").
				setTimeout( () => {
					updateUrl( postId, setMastodonUrl );
					setUpdated( false );
				}, 2000 ); // Need a "shortish" delay or it won't work. There's probably instances where these 2 seconds aren't enough, but whatevs.
			}

			const customStatusField = meta?._share_on_mastodon_custom_status_field ?? '0';

			return el( PluginDocumentSettingPanel, {
					name: 'share-on-mastodon-panel',
					title: __( 'Share on Mastodon', 'share-on-mastodon' ),
				},
				el( ToggleControl, {
					label: __( 'Share on Mastodon', 'share-on-mastodon' ),
					checked: '1' === meta._share_on_mastodon,
					onChange: ( newValue ) => {
						setMeta( { ...meta, _share_on_mastodon: ( newValue ? '1' : '0' ) } );
					},
				} ),
				'1' === customStatusField
					? [
						el( TextareaControl, {
							label: __( '(Optional) Custom Message', 'share-on-mastodon' ),
							value: meta._share_on_mastodon_status ?? '',
							onChange: ( newValue ) => {
								setMeta( { ...meta, _share_on_mastodon_status: ( newValue ? newValue : null ) } );
							},
						} ),
						el ( 'p', { className: 'description' },
							__( 'Customize this post’s Mastodon status.', 'share-on-mastodon' ),
						),
					]
					: null,
				'' !== mastodonUrl
					? el( 'div', {},
						// @todo: "Shorten" the URL.
						interpolate( sprintf( __( 'Shared at %s', 'share-on-mastodon' ), displayUrl( mastodonUrl ) ), {
							a: el( 'a', { className: 'share-on-mastodon-url', href: encodeURI( mastodonUrl ), target: '_blank', rel: 'noreferrer noopener' } ),
							b: el( 'span', { className: 'screen-reader-text' } ),
							c: el( 'span', { className: 'ellipsis' } ),
						} ),
						el( 'a', {
								className: 'share-on-mastodon-unlink',
								href: '#',
								onClick: () => {
									if ( confirm( __( 'Forget this URL?', 'share-on-mastodon' ) ) ) {
										unlinkUrl( postId, setMastodonUrl );
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
} )( window.wp.element, window.wp.components, window.wp.i18n, window.wp.data, window.wp.coreData, window.wp.plugins, window.wp.editPost, window.wp.apiFetch, window.wp.url );
