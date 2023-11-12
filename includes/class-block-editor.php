<?php
/**
 * All things Gutenberg.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Block editor goodness.
 */
class Block_Editor {
	/**
	 * Registers hook callbacks.
	 *
	 * @since 0.17.0
	 */
	public static function register() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_scripts' ), 11 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_api_endpoints' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_meta' ) );
	}

	/**
	 * Enqueues block editor scripts.
	 *
	 * @since 0.17.0
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
			// Only load JS for actually supported post types.
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
			\Share_On_Mastodon\Share_On_Mastodon::PLUGIN_VERSION,
			false
		);
	}

	/**
	 * Registers (block-related) REST API endpoints.
	 *
	 * @since 0.17.0
	 */
	public static function register_api_endpoints() {
		register_rest_route(
			'share-on-mastodon/v1',
			'/url',
			array(
				'methods'             => array( 'GET' ),
				'callback'            => array( __CLASS__, 'get_meta' ),
				'permission_callback' => array( __CLASS__, 'permission_callback' ),
			)
		);
	}

	/**
	 * The one, for now, REST API permission callback.
	 *
	 * @since 0.17.0
	 *
	 * @param  \WP_REST_Request $request WP REST API request.
	 * @return bool                      If the request's authorized.
	 */
	public static function permission_callback( $request ) {
		$post_id = $request->get_param( 'post_id' );

		if ( empty( $post_id ) || ! ctype_digit( (string) $post_id ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Exposes Share on Mastodon's metadata to the REST API.
	 *
	 * @since 0.17.0
	 *
	 * @param  \WP_REST_Request|array $request API request (parameters).
	 * @return array|\WP_Error                 Response (or error).
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
	 *
	 * @since 0.11.0
	 */
	public static function register_meta() {
		$options = get_options();

		if ( empty( $options['post_types'] ) ) {
			return;
		}

		$post_types = (array) $options['post_types'];

		foreach ( $post_types as $post_type ) {
			// Expose Share on Mastodon's custom fields to the REST API.
			register_rest_field(
				$post_type,
				'share_on_mastodon',
				array(
					'get_callback'    => array( __CLASS__, 'get_meta' ),
					'update_callback' => null,
				)
			);

			if ( use_block_editor_for_post_type( $post_type ) && empty( $options['meta_box'] ) ) {
				// Allow these fields to be *set* by the block editor.
				register_post_meta(
					$post_type,
					'_share_on_mastodon',
					array(
						'single'            => true,
						'show_in_rest'      => true,
						'type'              => 'string',
						'default'           => apply_filters( 'share_on_mastodon_optin', ! empty( $options['optin'] ) ) ? '0' : '1',
						'auth_callback'     => function () {
							return current_user_can( 'edit_posts' );
						},
						'sanitize_callback' => function ( $meta_value ) {
							return '1' === $meta_value ? '1' : '0';
						},
					)
				);

				if ( ! empty( $options['custom_status_field'] ) ) {
					// No need to register (and thus save) anything we won't be
					// using.
					register_post_meta(
						$post_type,
						'_share_on_mastodon_status',
						array(
							'single'            => true,
							'show_in_rest'      => true,
							'type'              => 'string',
							'default'           => ! empty( $options['status_template'] ) ? $options['status_template'] : '',
							'auth_callback'     => function () {
								return current_user_can( 'edit_posts' );
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
}
