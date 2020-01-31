<?php
/**
 * Handles posting to Mastodon and the like.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Post handler class.
 */
class Post_Handler {
	/**
	 * Array that holds this plugin's settings.
	 *
	 * @since 0.1.0
	 * @var   array $options Plugin options.
	 */
	private $options = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		// Fetch settings from database. Fall back onto an empty array if none
		// exist.
		$this->options = get_option( 'share_on_mastodon_settings', array() );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		add_action( 'transition_post_status', array( $this, 'update_meta' ), 11, 3 );
		add_action( 'transition_post_status', array( $this, 'toot' ), 999, 3 );
	}

	/**
	 * Registers a new meta box.
	 *
	 * @since 0.1.0
	 */
	public function add_meta_box() {
		if ( empty( $this->options['post_types'] ) ) {
			// Sharing disabled for all post types.
			return;
		}

		add_meta_box(
			'share-on-mastodon',
			__( 'Share on Mastodon', 'share-on-mastodon' ),
			array( $this, 'render_meta_box' ),
			(array) $this->options['post_types'],
			'side',
			'default'
		);
	}

	/**
	 * Renders custom fields meta boxes on the custom post type edit page.
	 *
	 * @since 0.1.0
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		?>
			<?php wp_nonce_field( basename( __FILE__ ), 'share_on_mastodon_nonce' ); ?>
			<label>
				<input type="checkbox" name="share_on_mastodon" value="1" <?php checked( in_array( get_post_meta( $post->ID, '_share_on_mastodon', true ), array( '', '1' ), true ) ); ?>>
				<?php esc_html_e( 'Share on Mastodon', 'share-on-mastodon' ); ?>
			</label>
		<?php
	}

	/**
	 * Handles metadata.
	 *
	 * @since 0.1.0
	 * @param string  $new_status Old post status.
	 * @param string  $old_status New post status.
	 * @param WP_Post $post       Post object.
	 */
	public function update_meta( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent double posting.
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		if ( ! isset( $_POST['share_on_mastodon_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['share_on_mastodon_nonce'] ), basename( __FILE__ ) ) ) {
			// Nonce missing or invalid.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( isset( $_POST['share_on_mastodon'] ) && ! post_password_required( $post ) ) {
			// If sharing enabled and post not password-protected.
			update_post_meta( $post->ID, '_share_on_mastodon', '1' );
		} else {
			update_post_meta( $post->ID, '_share_on_mastodon', '0' );
		}
	}

	/**
	 * Shares a post on Mastodon.
	 *
	 * @since 0.1.0
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function toot( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent accidental double posting.
			return;
		}

		$is_enabled = ( '1' === get_post_meta( $post->ID, '_share_on_mastodon', true ) ? true : false );

		if ( ! apply_filters( 'share_on_mastodon_enabled', $is_enabled, $post->ID ) ) {
			// Disabled for this post.
			return;
		}

		if ( '' !== get_post_meta( $post->ID, '_share_on_mastodon_url', true ) ) {
			// Prevent duplicate toots.
			return;
		}

		if ( 'publish' !== $new_status ) {
			// Status is something other than `publish`.
			return;
		}

		if ( post_password_required( $post ) ) {
			// Post is password-protected.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( empty( $this->options['mastodon_host'] ) ) {
			return;
		}

		if ( ! wp_http_validate_url( $this->options['mastodon_host'] ) ) {
			return;
		}

		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return;
		}

		$status = wp_strip_all_tags( get_the_title( $post->ID ) ) . ' ' . esc_url_raw( get_permalink( $post->ID ) );
		$status = apply_filters( 'share_on_mastodon_status', $status, $post );

		// Encode, build query string.
		$query_string = http_build_query(
			array( 'status' => $status )
		);

		if ( has_post_thumbnail( $post->ID ) && apply_filters( 'share_on_mastodon_featured_image', true ) ) {
			// Upload the featured image.
			$media_id = $this->upload_thumbnail( $post->ID );

			if ( ! empty( $media_id ) ) {
				// Handle after `http_build_query()`, as apparently Mastodon
				// doesn't like numbers for query string array keys.
				$query_string .= '&media_ids[]=' . rawurlencode( $media_id );
			}
		}

		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/statuses' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->options['mastodon_access_token'],
				),
				// Prevent WordPress from applying `http_build_query()`, for the
				// same reason.
				'data_format' => 'body',
				'body'        => $query_string,
				'timeout'     => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		// Decode JSON, surpressing possible formatting errors.
		$status = @json_decode( $response['body'] );

		if ( ! empty( $status->url ) && post_type_supports( $post->post_type, 'custom-fields' ) ) {
			update_post_meta( $post->ID, '_share_on_mastodon_url', $status->url );
		} else {
			// Provided debugging's enabled, let's store the (somehow faulty)
			// response.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Uploads a post thumbnail and returns a (single) media ID.
	 *
	 * @since  0.1.0
	 * @param  int $post_id Post ID.
	 * @return string|null  Unique media ID, or nothing on failure.
	 */
	private function upload_thumbnail( $post_id ) {
		$thumb_id  = get_post_thumbnail_id( $post_id );
		$url       = wp_get_attachment_url( $thumb_id );
		$uploads   = wp_upload_dir();
		$file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );

		if ( ! is_file( $file_path ) ) {
			// File doesn't seem to exist.
			return;
		}

		$boundary = md5( time() );
		$eol      = "\r\n";

		$body  = '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . $eol;
		$body .= 'Content-Type: ' . mime_content_type( $file_path ) . $eol . $eol;
		$body .= file_get_contents( $file_path ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body .= '--' . $boundary . '--';

		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/media' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->options['mastodon_access_token'],
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'data_format' => 'body',
				'body'        => $body,
				'timeout'     => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		// Decode JSON, surpressing possible formatting errors.
		$media = @json_decode( $response['body'] );

		if ( ! empty( $media->id ) ) {
			return $media->id;
		}

		// Provided debugging's enabled, let's store the (somehow faulty)
		// response.
		error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
