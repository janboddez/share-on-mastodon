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
	 * @var array $options Plugin options.
	 */
	private $options = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Fetch settings from database. Fall back onto an empty array if none
		// exist.
		$this->options = get_option( 'share_on_mastodon_settings', array() );

		// Note: below action is incompatible with Gutenberg/WordPress' block
		// editor.
		add_action( 'post_submitbox_misc_actions', array( $this, 'render_meta_box' ) );\

		add_action( 'transition_post_status', array( $this, 'update_meta' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'toot' ), 999, 3 );
	}

	/**
	 * Renders custom fields meta boxes on the custom post type edit page.
	 *
	 * @since 0.1.0
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		$current_screen = get_current_screen();

		if ( empty( $current_screen ) || ! in_array( $current_screen->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}
		?>
		<div class="misc-pub-section">
			<?php wp_nonce_field( basename( __FILE__ ) ); ?>
			<label><input type="checkbox" name="share_on_mastodon" value="1" <?php checked( in_array( get_post_meta( $post->ID, '_share_on_mastodon', true ), array( '', '1' ), true ) ); ?>>
			<?php echo strip_tags( __( 'Share on <b>Mastodon</b>', 'share-on-mastodon' ), '<b>' ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Handles metadata.
	 *
	 * @param string  $old_status Old post status.
	 * @param string  $new_status New post status.
	 * @param WP_Post $post       Post object.
	 */
	public function update_meta( $old_status, $new_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent double posting.
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), basename( __FILE__ ) ) ) {
			// Nonce missing or invalid.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( isset( $_POST['share_on_mastodon'] ) && '' !== $post->post_password ) {
			// If checked and post is not password-protected.
			update_post_meta( $post->ID, '_share_on_mastodon', '1' );
		} else {
			update_post_meta( $post->ID, '_share_on_mastodon', '0' );
		}
	}

	/**
	 * Shares a post on Mastodon.
	 *
	 * @param string  $old_status Old post status.
	 * @param string  $new_status New post status.
	 * @param WP_Post $post       Post object.
	 */
	public function toot( $old_status, $new_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent accidental double posting.
			return;
		}

		if ( '' !== get_post_meta( $post->ID, '_share_on_mastodon_url', true ) ) {
			// Prevent duplicate toots.
			return;
		}

		if ( '1' !== get_post_meta( $post->ID, '_share_on_mastodon', true ) ) {
			// Disabled for this post.
			return;
		}

		if ( 'publish' !== $new_status ) {
			// Status is something other than `publish`.
			return;
		}

		if ( '' !== $post->post_password ) {
			// Post is password-protected.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( empty( $this->options['mastodon_host'] ) || ! wp_http_validate_url( $this->options['mastodon_host'] ) || empty( $this->options['mastodon_access_token'] ) ) {
			// Settings missing or invalid.
			return;
		}

		$status = wp_strip_all_tags( get_the_title( $post->ID ) ) . ' ' . esc_url_raw( get_permalink( $post->ID ) );
		$status = apply_filters( 'share_on_mastodon_status', $status, $post );

		// Encode, build query string.
		$query_string = http_build_query(
			array( 'status' => $status )
		);

		if ( has_post_thumbnail( $post->ID ) ) {
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
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		// Decode JSON, surpressing possible formatting errors.
		$status = @json_decode( $response['body'] );

		if ( ! empty( $status->url ) ) {
			// Store resulting toot URL.
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
	 * Since posting files using the WP HTTP API is somewhat tricky, uses PHP's
	 * native cURL functions instead.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Unique media ID, or nothing on failure.
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

		// Revert to cURL, as apparently posting files through wp_remote_post()
		// is pretty difficult.
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$ch = curl_init();

		curl_setopt( $ch, CURLOPT_URL, esc_url_raw( $this->options['mastodon_host'] . '/api/v1/media' ) );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'Authorization: Bearer ' . $this->options['mastodon_access_token'],
				'Content-Type: multipart/form-data',
			)
		);
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt(
			$ch,
			CURLOPT_POSTFIELDS,
			array(
				// The use of `curl_file_create` (the cleanest way to attach a file)
				// seems to prevent us from using `http_build_query()` (or, rather,
				// Mastodon seems to choke on the outcome).
				'file'        => curl_file_create( $file_path, get_post_mime_type( $thumb_id ) ),
				'description' => get_the_title( $thumb_id ),
			)
		);
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		// Send request.
		$response = curl_exec( $ch );
		curl_close( $ch );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		// Decode JSON, surpressing possible formatting errors.
		$media = @json_decode( $response );

		if ( ! empty( $media->id ) ) {
			return $media->id;
		} else {
			// Provided debugging's enabled, let's store the (somehow faulty)
			// response.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
