( function ( element, components, i18n, data, coreData, plugins, editPost, apiFetch, url, share_on_mastodon_obj ) {
	const el                         = element.createElement;
	const interpolate                = element.createInterpolateElement;
	const useState                   = element.useState;
	const TextareaControl            = components.TextareaControl;
	const ToggleControl              = components.ToggleControl;
	const __                         = i18n.__;
	const sprintf                    = i18n.sprintf;
	const useSelect                  = data.useSelect;
	const registerPlugin             = plugins.registerPlugin;
	const PluginDocumentSettingPanel = editPost.PluginDocumentSettingPanel;

	// @see https://wordpress.stackexchange.com/questions/362975/admin-notification-after-save-post-when-ajax-saving-in-gutenberg
	const doneSaving = () => {
		const { isSaving, isAutosaving, status } = useSelect( ( select ) => {
			return {
				isSaving:     select( 'core/editor' ).isSavingPost(),
				isAutosaving: select( 'core/editor' ).isAutosavingPost(),
				status:       select( 'core/editor' ).getEditedPostAttribute( 'status' ),
			};
		} );

		const [ wasSaving, setWasSaving ] = useState( isSaving && ! isAutosaving && 'publish' === status ); // Ignore autosaves, and unpublished posts.

		if ( wasSaving ) {
			if ( ! isSaving ) {
				setWasSaving( false );
				return true;
			}
		} else if ( isSaving && ! isAutosaving && 'publish' === status ) {
			setWasSaving( true );
		}

		return false;
	};

	const isValidUrl = ( mastoUrl ) => {
		try {
			const parser = new URL( mastoUrl );
			return true;
		} catch ( error ) {
			// Invalid URL.
		}

		return false;
	};

	const displayUrl = ( mastoUrl ) => {
		if ( ! isValidUrl( mastoUrl ) ) {
			return ''
		};

		const parser = new URL( mastoUrl );

		return sprintf(
			'<a><b>%1$s</b><c>%2$s</c><b>%3$s</b></a>',
			parser.protocol + '://' + ( parser.username ? parser.username + ( parser.password ? ':' + parser.password : '' ) + '@' : '' ),
			parser.hostname.concat( parser.pathname ).slice( 0, 20 ),
			parser.hostname.concat( parser.pathname ).slice( 20 ),
		);
	};

	const updateUrl = ( postId, setMastoUrl, setError ) => {
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

				if ( isValidUrl( response ) ) {
					setMastoUrl( response ); // So as to trigger a re-render?
					setError( '' ); // So as to trigger a re-render?
				}
			} ).catch( function( error ) {
				// The request timed out or otherwise failed. Leave as is.
				throw new Error( 'The "Get URL" request failed.' )
			} );
		} catch ( error ) {
			return false;
		}

		// All good.
		return true;
	};

	const unlinkUrl = ( postId, setMastoUrl ) => {
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
				body:   new URLSearchParams( {
					action:                  'share_on_mastodon_unlink_url',
					post_id:                 postId,
					share_on_mastodon_nonce: share_on_mastodon_obj.nonce,
				} ),
			} ).then( function( response ) {
				clearTimeout( timeoutId );
				setMastoUrl( '' ); // So as to trigger a re-render.
			} ).catch( function( error ) {
				// The request timed out or otherwise failed. Leave as is.
				throw new Error( 'The "Unlink" request failed.' )
			} );
		} catch ( error ) {
			return false;
		}

		return true;
	};

	registerPlugin( 'share-on-mastodon-panel', {
		render: function( props ) {
			const postId   = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
			const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );

			// To be able to actually save post meta (namely, `_share_on_mastodon` and `_share_on_mastodon_status`).
			const [ meta, setMeta ] = coreData.useEntityProp( 'postType', postType, 'meta' );

			// These are the custom fields we *don't* want to be set by `setMeta()`.
			const { record, isResolving }   = coreData.useEntityRecord( 'postType', postType, postId );
			const [ mastoUrl, setMastoUrl ] = useState( record?.share_on_mastodon?.url ?? '' );
			const [ error, setError ]       = useState( record?.share_on_mastodon?.error ?? '' );

			if ( doneSaving() && '' === mastoUrl ) {
				// Post was updated, Mastodon URL is (still) empty.
				setTimeout( () => {
					updateUrl( postId, setMastoUrl, setError ); // Fetch, and store, the new URL (if any).
				}, 1500 ); // Need a shortish delay, and possibly more in certain cases, but something's better than nothing.
			}

			// Wether to also show the `TextareaControl` component.
			const customStatusField = share_on_mastodon_obj?.custom_status_field ?? '0';

			return el( PluginDocumentSettingPanel, {
					name: 'share-on-mastodon-panel',
					title: __( 'Share on Mastodon', 'share-on-mastodon' ),
				},
				el( ToggleControl, {
					label: __( 'Share on Mastodon', 'share-on-mastodon' ),
					checked: '1' === meta._share_on_mastodon,
					onChange: ( value ) => {
						setMeta( { ...meta, _share_on_mastodon: ( value ? '1' : '0' ) } );
					},
				} ),
				'1' === customStatusField
					? [
						el( TextareaControl, {
							label: __( '(Optional) Custom Message', 'share-on-mastodon' ),
							value: meta._share_on_mastodon_status ?? '',
							onChange: ( value ) => {
								setMeta( { ...meta, _share_on_mastodon_status: ( value ? value : null ) } );
							},
						} ),
						el ( 'p', { className: 'description' },
							__( 'Customize this post’s Mastodon status.', 'share-on-mastodon' ),
						),
					]
					: null,
				'' !== mastoUrl
					? el( 'p', { className: 'description', style: { marginTop: '1em', marginBottom: '0' } },
						interpolate( sprintf( __( 'Shared at %s', 'share-on-mastodon' ), displayUrl( mastoUrl ) ), {
							a: el( 'a', { className: 'share-on-mastodon-url', href: encodeURI( mastoUrl ), target: '_blank', rel: 'noreferrer noopener' } ),
							b: el( 'span', { className: 'screen-reader-text' } ),
							c: el( 'span', { className: 'ellipsis' } ),
						} ),
						el( 'a', {
								className: 'share-on-mastodon-unlink',
								href: '#',
								onClick: () => {
									if ( confirm( __( 'Forget this URL?', 'share-on-mastodon' ) ) ) {
										unlinkUrl( postId, setMastoUrl );
									}
								},
							},
							__( 'Unlink', 'share-on-mastodon' )
						)
					)
					: null,
				'' !== error && '' === mastoUrl
					? el( 'p', { className: 'description', style: { marginTop: '1em', marginBottom: '0' } }, error )
					: null,
			);
		},
	} );
} )( window.wp.element, window.wp.components, window.wp.i18n, window.wp.data, window.wp.coreData, window.wp.plugins, window.wp.editPost, window.wp.apiFetch, window.wp.url, window.share_on_mastodon_obj );
