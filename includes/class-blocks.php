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
class Blocks {
	/**
	 * Registers hook callbacks.
	 */
	public static function register() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_api_endpoints' ) );
	}

	/**
	 * Enqueues block editor scripts.
	 */
	public static function enqueue_scripts() {
		$options = get_options();

		global $post;

		if ( empty( $post->post_type ) || ! in_array( $post->post_type, $options['post_types'], true ) ) {
			return;
		}

		wp_enqueue_script(
			'share-on-mastodon-editor',
			plugins_url( '/assets/block-editor.js', dirname( __FILE__ ) ),
			array(
				'wp-element',
				'wp-components',
				'wp-i18n',
				'wp-data',
				'wp-plugins',
				'wp-edit-post',
				'wp-api-fetch',
				'wp-url',
			),
			\Share_On_Mastodon\Share_On_Mastodon::PLUGIN_VERSION,
			false
		);
	}

	/**
	 * Registers (block-related) REST API endpoints.
	 */
	public static function register_api_endpoints() {
		register_rest_route(
			'share-on-mastodon/v1',
			'/url',
			array(
				'methods'             => array( 'GET' ),
				'callback'            => array( __CLASS__, 'get_url' ),
				'permission_callback' => array( __CLASS__, 'permission_callback' ),
			)
		);
	}

	/**
	 * The one, for now, REST API permission callback.
	 *
	 * @param  \WP_REST_Request $request WP REST API request.
	 * @return bool                      If the request's authorized.
	 */
	public static function permission_callback( $request ) {
		$post_id = $request->get_param( 'post_id' );

		if ( empty( $post_id ) || ! ctype_digit( $post_id ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Fetches a post's Mastodon URL.
	 *
	 * Should only ever be called as a REST API endpoint.
	 *
	 * @param  \WP_REST_Request $request   WP REST API request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function get_url( $request ) {
		$post_id = $request->get_param( 'post_id' );

		if ( empty( $post_id ) || ! ctype_digit( $post_id ) ) {
			return new WP_Error( 'invalid_id', 'Invalid post ID.', array( 'status' => 400 ) );
		}

		$post_id = (int) $post_id;

		$url = get_transient( "share_on_mastodon:$post_id:url" );

		if ( false === $url ) { // If no such transient exists.
			$url = get_post_meta( $post_id, '_share_on_mastodon_url', true );
			set_transient( "share_on_mastodon:$post_id:url", $url, 300 ); // If no URL exists, this will cache an empty string instead. Is that what we want?
		}

		return $url;
	}
}
