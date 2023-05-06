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
		$options = \Share_On_Mastodon\Share_On_Mastodon::get_instance()
			->get_options_handler()
			->get_options();

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
			),
			\Share_On_Mastodon\Share_On_Mastodon::PLUGIN_VERSION,
			false
		);
	}

	/**
	 * Registers (block-related) REST API endpoints.
	 *
	 * @todo: (Eventually) also add an "author" endpoint. Or have the single endpoint return both title and author information.
	 */
	public static function register_api_endpoints() {
		register_rest_route(
			'share-on-mastodon/v1',
			'/unlink',
			array(
				'methods'             => array( 'POST' ),
				'callback'            => array( __CLASS__, 'unlink_url' ),
				'permission_callback' => array( __CLASS__, 'permission_callback' ),
			)
		);
	}

	/**
	 * The one, for now, REST API permission callback.
	 *
	 * @return bool If the request's authorized.
	 */
	public static function permission_callback() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Deletes a post's Mastodon URL.
	 *
	 * Should only ever be called as a REST API endpoint.
	 *
	 * @since 0.15.0
	 *
	 * @param  \WP_REST_Request $request   WP REST API request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function unlink_url( $request ) {
		$post_id = $request->get_param( 'post_id' );

		if ( empty( $post_id ) || ! ctype_digit( $post_id ) ) {
			// @todo: Make this JSON!
			status_header( 400 );
			esc_html_e( 'Missing or incorrect post ID.', 'share-on-mastodon' );
			wp_die();
		}

		$post_id = (int) $post_id;

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			// @todo: Make this JSON!
			status_header( 400 );
			esc_html_e( 'Insufficient rights.', 'share-on-mastodon' );
			wp_die();
		}

		// Have WordPress forget the Mastodon URL.
		delete_post_meta( $post_id, '_share_on_mastodon_url' );
		// delete_post_meta( $post_id, '_share_on_mastodon' );

		return new \WP_REST_Response( array( 'status' => 200 ) );
	}
}
