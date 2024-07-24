<?php
/**
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * All things Gutenberg.
 */
class Block_Editor {
	/**
	 * Registers hook callbacks.
	 */
	public static function register() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_scripts' ), 11 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_api_endpoints' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_meta' ) );
		add_filter( 'default_post_metadata', array( __CLASS__, 'get_default_meta' ), 10, 4 );
	}

	/**
	 * Enqueues block editor scripts.
	 */
	public static function enqueue_scripts() {
		$options = get_options();

		if ( ! empty( $options['meta_box'] ) ) {
			return;
		}

		if ( empty( $options['post_types'] ) ) {
			return;
		}

		$current_screen = get_current_screen();
		if ( ( isset( $current_screen->post_type ) && ! in_array( $current_screen->post_type, $options['post_types'], true ) ) ) {
			return;
		}

		wp_enqueue_script(
			'share-on-mastodon-editor',
			plugins_url( '/assets/block-editor.js', __DIR__ ),
			array(
				'wp-element',
				'wp-components',
				'wp-i18n',
				'wp-data',
				'wp-core-data',
				'wp-plugins',
				'wp-edit-post',
				'wp-api-fetch',
				'wp-url',
				'share-on-mastodon',
			),
			Share_On_Mastodon::PLUGIN_VERSION,
			false
		);
	}

	/**
	 * Registers block-related REST API endpoints.
	 */
	public static function register_api_endpoints() {
		register_rest_route(
			'share-on-mastodon/v1',
			'/url',
			array(
				'methods'             => array( 'GET' ),
				'callback'            => array( __CLASS__, 'get_meta' ),
				'permission_callback' => function ( $request ) {
					$post_id = $request->get_param( 'post_id' );

					if ( empty( $post_id ) || ! ctype_digit( (string) $post_id ) ) {
						return false;
					}

					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * Exposes Share on Mastodon's metadata to the REST API.
	 *
	 * Can be called from either `register_rest_route()` or `register_rest_field()`.
	 *
	 * @param  \WP_REST_Request|array $request API request (parameters).
	 * @return array|\WP_Error                 Response, or error on failure.
	 */
	public static function get_meta( $request ) {
		if ( is_array( $request ) ) {
			$post_id = $request['id'];
		} else {
			$post_id = $request->get_param( 'post_id' );
		}

		if ( empty( $post_id ) || ! ctype_digit( (string) $post_id ) ) {
			return new \WP_Error( 'invalid_id', 'Invalid post ID.', array( 'status' => 400 ) );
		}

		$post_id = (int) $post_id;

		$url = get_post_meta( $post_id, '_share_on_mastodon_url', true );

		return array(
			'url'   => get_post_meta( $post_id, '_share_on_mastodon_url', true ),
			'error' => empty( $url ) // Don't bother if we've got a URL.
				? get_post_meta( $post_id, '_share_on_mastodon_error', true )
				: '',
		);
	}

	/**
	 * Registers Share on Mastodon's custom fields for use with the REST API.
	 */
	public static function register_meta() {
		$options = get_options();

		if ( empty( $options['post_types'] ) ) {
			return;
		}

		$post_types = (array) $options['post_types'];

		foreach ( $post_types as $post_type ) {
			// Expose Share on Mastodon's custom fields to the REST API. Will appear as a separate `share_on_mastodon`
			// property.
			register_rest_field(
				$post_type,
				'share_on_mastodon',
				array(
					'get_callback'    => array( __CLASS__, 'get_meta' ),
					'update_callback' => null, // These are updated solely in the background.
				)
			);

			if ( use_block_editor_for_post_type( $post_type ) && empty( $options['meta_box'] ) ) {
				// Allow these fields to be *set* by the block editor. These will appear as properties of the post's
				// `meta` property.
				register_post_meta(
					$post_type,
					'_share_on_mastodon',
					array(
						'single'            => true,
						'show_in_rest'      => true,
						'type'              => 'string',
						'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
							if ( empty( $post_id ) || ! ctype_digit( (string) $post_id ) ) {
								return false;
							}

							return current_user_can( 'edit_post', $post_id );
						},
						'sanitize_callback' => function ( $meta_value ) {
							return '1' === $meta_value ? '1' : '0';
						},
					)
				);

				if ( ! empty( $options['custom_status_field'] ) ) {
					// No need to register (and thus save) anything we won't be using.
					register_post_meta(
						$post_type,
						'_share_on_mastodon_status',
						array(
							'single'            => true,
							'show_in_rest'      => true,
							'type'              => 'string',
							'default'           => ! empty( $options['status_template'] ) ? $options['status_template'] : '',
							'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
								if ( empty( $post_id ) || ! ctype_digit( (string) $post_id ) ) {
									return false;
								}

								return current_user_can( 'edit_post', $post_id );
							},
							'sanitize_callback' => function ( $status ) {
								$status = sanitize_textarea_field( $status );
								$status = preg_replace( '~\R~u', "\r\n", $status );
								return $status;
							},
						)
					);
				}
			}
		}
	}

	/**
	 * Returns default meta for `_share_on_mastodon`.
	 *
	 * @param  mixed  $value     Default value.
	 * @param  int    $object_id Object ID.
	 * @param  string $meta_key  Meta key.
	 * @param  bool   $single    Whether to return only the first value.
	 * @return mixed             (Filtered) default value.
	 */
	public static function get_default_meta( $value, $object_id, $meta_key, $single ) {
		if ( '_share_on_mastodon' !== $meta_key ) {
			return $value;
		}

		$default = '1';

		if ( is_older_than( HOUR_IN_SECONDS / 2, $object_id ) ) {
			$default = '0';
		}

		$options = get_options();
		if ( apply_filters( 'share_on_mastodon_optin', ! empty( $options['optin'] ) ) ) {
			// Opt-in.
			$default = '0';
		}

		return ! $single
			? array( $default )
			: $default;
	}
}
