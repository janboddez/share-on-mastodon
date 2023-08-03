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
	 *
	 * @var array $options Plugin options.
	 */
	private $options = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array $options Plugin options.
	 */
	public function __construct( $options = array() ) {
		$this->options = $options;
	}

	/**
	 * Interacts with WordPress's Plugin API.
	 *
	 * @since 0.5.0
	 */
	public function register() {
		add_action( 'transition_post_status', array( $this, 'update_meta' ), 11, 3 );
		add_action( 'transition_post_status', array( $this, 'toot' ), 999, 3 );
		add_action( 'share_on_mastodon_post', array( $this, 'post_to_mastodon' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_scripts' ) ); // Just in case (although I'm relatively sure `admin_enqueue_scripts` has already run at this point).
		add_action( 'wp_ajax_share_on_mastodon_unlink_url', array( $this, 'unlink_url' ) );

		Notices::register();
	}

	/**
	 * Handles metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status Old post status.
	 * @param string  $old_status New post status.
	 * @param WP_Post $post       Post object.
	 */
	public function update_meta( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent double posting.
			return;
		}

		if ( use_block_editor_for_post( $post ) ) {
			// We have the block editor deal with updating post meta.
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		if ( ! isset( $_POST['share_on_mastodon_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['share_on_mastodon_nonce'] ), basename( __FILE__ ) ) ) {
			// Nonce missing or invalid. On sites that use the block editor,
			// this will also cause the rest of this function to _not_ run the
			// first time this hook is called.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( isset( $_POST['share_on_mastodon_status'] ) ) {
			$status = sanitize_textarea_field( wp_unslash( $_POST['share_on_mastodon_status'] ) );
			$status = preg_replace( '~\R~u', "\r\n", $status );
		}

		if (
			! empty( $status ) && '' !== preg_replace( '~\s~', '', $status ) &&
			( empty( $this->options['status_template'] ) || $status !== $this->options['status_template'] )
		) {
			// Save only if `$status` is non-empty and, if a template exists, different from said template.
			update_post_meta( $post->ID, '_share_on_mastodon_status', $status );
		} else {
			// Ignore, or delete a previously stored value.
			delete_post_meta( $post->ID, '_share_on_mastodon_status' );
		}

		if ( isset( $_POST['share_on_mastodon'] ) && ! post_password_required( $post ) ) {
			// If sharing enabled and post not password-protected.
			update_post_meta( $post->ID, '_share_on_mastodon', '1' );
		} else {
			delete_post_meta( $post->ID, '_share_on_mastodon_error' ); // Clear previous errors, if any.
			update_post_meta( $post->ID, '_share_on_mastodon', '0' );
		}
	}

	/**
	 * Schedules sharing to Mastodon.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function toot( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent accidental double posting.
			return;
		}

		if (
			defined( 'REST_REQUEST' ) && REST_REQUEST &&
			empty( $_REQUEST['meta-box-loader'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			0 === strpos( wp_get_referer(), admin_url() )
		) {
			// Looks like this call to `transition_post_status` was initiated by
			// the block editor. In that case, this function will be called a
			// second time after custom meta, including `custom_status_field`,
			// is processed. Unless, of course, all meta boxes, including Share
			// on Mastodon's, were hidden (e.g., when a site owner relies on the
			// "Share Always" setting).

			// This behavior will change once we switch to a Gutenberg sidebar
			// panel and hide "Share on Mastodon's" meta box (for the block
			// editor only, obiously); then, these variables will have been
			// saved the first time around.
			return;
		}

		if ( ! $this->is_valid( $post ) ) {
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

		if ( ! empty( $this->options['delay_sharing'] ) ) {
			// Since version 0.7.0, there's an option to "schedule" sharing
			// rather than do everything inline.
			wp_schedule_single_event(
				time() + min( $this->options['delay_sharing'], 3600 ), // Limit to one hour.
				'share_on_mastodon_post',
				array( $post->ID )
			);
		} else {
			// Share immediately.
			$this->post_to_mastodon( $post->ID );
		}
	}

	/**
	 * Shares a post on Mastodon.
	 *
	 * @since 0.7.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function post_to_mastodon( $post_id ) {
		$post = get_post( $post_id );

		// Things may have changed ...
		if ( ! $this->is_valid( $post ) ) {
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

		// Fetch custom status message, if any.
		$status = get_post_meta( $post->ID, '_share_on_mastodon_status', true );
		// Parse template tags, and sanitize.
		$status = $this->parse_status( $status, $post->ID );

		if ( ( empty( $status ) || '' === preg_replace( '~\s~', '', $status ) ) && ! empty( $this->options['status_template'] ) ) {
			// Use template stored in settings.
			$status = $this->parse_status( $this->options['status_template'], $post->ID );
		}

		if ( empty( $status ) || '' === preg_replace( '~\s~', '', $status ) ) {
			// Fall back to post title.
			$status = get_the_title( $post->ID );
		}

		$status = wp_strip_all_tags(
			html_entity_decode( $status, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) // Avoid double-encoded HTML entities.
		);

		// Append permalink, but only if it's not already there.
		$permalink = esc_url_raw( get_permalink( $post->ID ) );

		if ( false === strpos( $status, $permalink ) ) {
			// Post doesn't mention permalink, yet. Append it.
			if ( false === strpos( $status, "\n" ) ) {
				$status .= ' ' . $permalink; // Keep it single-line.
			} else {
				$status .= "\r\n\r\n" . $permalink;
			}
		}

		// Allow developers to (completely) override `$status`.
		$status = apply_filters( 'share_on_mastodon_status', $status, $post );
		$args   = apply_filters( 'share_on_mastodon_toot_args', array( 'status' => $status ), $post );

		if ( apply_filters_deprecated( 'share_on_mastodon_cutoff', array( false ), '0.16.1' ) ) {
			// May render hashtags or URLs, or unfiltered HTML, at the very end
			// of a toot unusable.
			$args['status'] = mb_substr( $args['status'], 0, 499, get_bloginfo( 'charset' ) ) . '…';
		}

		// Encode, build query string.
		$query_string = http_build_query( $args );

		// And now, images.
		$media = Image_Handler::get_images( $post );

		if ( ! empty( $media ) ) {
			$max   = isset( $this->options['max_images'] ) ? $this->options['max_images'] : 4;
			$max   = (int) apply_filters( 'share_on_mastodon_num_images', $max, $post );
			$count = min( count( $media ), $max );

			// Limit the no. of images (or other media) to `$count`.
			$media = array_slice( $media, 0, $count, true );

			foreach ( $media as $id => $alt ) {
				$media_id = Image_Handler::upload_image( $id, $alt );

				if ( ! empty( $media_id ) ) {
					// The image got uploaded OK.
					$query_string .= '&media_ids[]=' . rawurlencode( $media_id );
				}
			}
		}

		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/statuses' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->options['mastodon_access_token'],
				),
				// Prevent WordPress from applying `http_build_query()`.
				'data_format' => 'body',
				'body'        => $query_string,
				'timeout'     => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			debug_log( $response );
			return;
		}

		$status = json_decode( $response['body'] );

		if ( ! empty( $status->url ) ) {
			delete_post_meta( $post->ID, '_share_on_mastodon_error' );
			update_post_meta( $post->ID, '_share_on_mastodon_url', esc_url_raw( $status->url ) );
			delete_transient( "share_on_mastodon:{$post->ID}:url" );

			if ( 'share_on_mastodon_post' !== current_filter() ) {
				// Show a notice only when this function was called directly.
				add_filter( 'redirect_post_location', array( Notices::class, 'add_success_query_var' ) );
			}
		} elseif ( ! empty( $status->error ) ) {
			update_post_meta( $post->ID, '_share_on_mastodon_error', sanitize_text_field( $status->error ) );

			if ( 'share_on_mastodon_post' !== current_filter() ) {
				// Show a notice only when this function was called directly.
				add_filter( 'redirect_post_location', array( Notices::class, 'add_error_query_var' ) );
			}

			// Provided debugging's enabled, let's store the (somehow faulty)
			// response.
			debug_log( $response );
		}
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

		$current_screen = get_current_screen();
		if ( ( isset( $current_screen->post_type ) && use_block_editor_for_post_type( $current_screen->post_type ) ) && empty( $this->options['meta_box'] ) ) {
			// The current post type uses the block editor (and the "classic"
			// meta box is disabled).
			return;
		}

		// Add meta box, for those post types that are supported.
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
	 * Renders meta box.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( basename( __FILE__ ), 'share_on_mastodon_nonce' );
		$checked = get_post_meta( $post->ID, '_share_on_mastodon', true );

		if ( '' === $checked ) {
			// If sharing is "opt-in" or the post in question is older than 15
			// minutes, do _not_ check the checkbox by default.
			$checked = apply_filters( 'share_on_mastodon_optin', ! empty( $this->options['optin'] ) ) || $this->is_older_than( 900, $post ) ? '0' : '1';
		}
		?>
		<label>
			<input type="checkbox" name="share_on_mastodon" value="1" <?php checked( '1' === $checked ); ?>>
			<?php esc_html_e( 'Share on Mastodon', 'share-on-mastodon' ); ?>
		</label>
		<?php
		if ( ! empty( $this->options['custom_status_field'] ) ) :
			// Custom message saved earlier, if any.
			$custom_status = get_post_meta( $post->ID, '_share_on_mastodon_status', true );

			if ( '' === $custom_status && ! empty( $this->options['status_template'] ) ) {
				// Default to the template as set on the options page.
				$custom_status = $this->options['status_template'];
			}
			?>
			<div style="margin-top: 1em;">
				<label for="share_on_mastodon_status"><?php esc_html_e( '(Optional) Message', 'share-on-mastodon' ); ?></label>
				<textarea id="share_on_mastodon_status" name="share_on_mastodon_status" rows="3" style="width: 100%; box-sizing: border-box; margin-top: 0.5em;"><?php echo esc_html( trim( $custom_status ) ); ?></textarea>
				<p class="description" style="margin-top: 0.25em;"><?php esc_html_e( 'Customize this post&rsquo;s Mastodon status.', 'share-on-mastodon' ); ?></p>
			</div>
			<?php
		endif;

		$url = get_post_meta( $post->ID, '_share_on_mastodon_url', true );

		if ( '' !== $url && filter_var( $url, FILTER_VALIDATE_URL ) ) :
			$url_parts = wp_parse_url( $url );

			$display_url  = '<span class="screen-reader-text">' . $url_parts['scheme'] . '://';
			$display_url .= ( ! empty( $url_parts['user'] ) ? $url_parts['user'] . ( ! empty( $url_parts['pass'] ) ? ':' . $url_parts['pass'] : '' ) . '@' : '' ) . '</span>';
			$display_url .= '<span class="ellipsis">' . mb_substr( $url_parts['host'] . $url_parts['path'], 0, 20 ) . '</span><span class="screen-reader-text">' . mb_substr( $url_parts['host'] . $url_parts['path'], 20 ) . '</span>';
			?>
			<p class="description">
				<?php /* translators: toot URL */ ?>
				<?php printf( esc_html__( 'Shared at %s', 'share-on-mastodon' ), '<a class="url" href="' . esc_url( $url ) . '">' . $display_url . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php /* translators: "unlink" link text */ ?>
				<a href="#" class="unlink"><?php esc_html_e( 'Unlink', 'share-on-mastodon' ); ?></a>
			</p>
			<?php
		else :
			$error_message = get_post_meta( $post->ID, '_share_on_mastodon_error', true );

			if ( '' !== $error_message ) :
				?>
				<p class="description"><i><?php echo esc_html( $error_message ); ?></i></p>
				<?php
			endif;
		endif;
	}

	/**
	 * Deletes a post's Mastodon URL.
	 *
	 * Should only ever be called through AJAX.
	 *
	 * @since 0.6.0
	 */
	public function unlink_url() {
		if ( ! isset( $_POST['share_on_mastodon_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['share_on_mastodon_nonce'] ), basename( __FILE__ ) ) ) {
			status_header( 400 );
			esc_html_e( 'Missing or invalid nonce.', 'share-on-mastodon' );
			wp_die();
		}

		if ( ! isset( $_POST['post_id'] ) || ! ctype_digit( $_POST['post_id'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			status_header( 400 );
			esc_html_e( 'Missing or incorrect post ID.', 'share-on-mastodon' );
			wp_die();
		}

		if ( ! current_user_can( 'edit_post', intval( $_POST['post_id'] ) ) ) {
			status_header( 400 );
			esc_html_e( 'Insufficient rights.', 'share-on-mastodon' );
			wp_die();
		}

		$post_id = (int) $_POST['post_id'];

		// Have WordPress forget the Mastodon URL.
		delete_post_meta( $post_id, '_share_on_mastodon_url' );
		delete_transient( "share_on_mastodon:$post_id:url" );

		$options = get_options();

		if ( ! empty( $options['use_meta_box'] ) && use_block_editor_for_post( $post_id ) ) {
			// Delete the checkbox value, too, to prevent Gutenberg's' odd meta
			// box behavior from triggering an immediate re-share.
			delete_post_meta( $post_id, '_share_on_mastodon' );
		}

		wp_die();
	}

	/**
	 * Adds admin scripts and styles.
	 *
	 * @since 0.6.0
	 *
	 * @param string $hook_suffix Current WP-Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'post-new.php' !== $hook_suffix && 'post.php' !== $hook_suffix ) {
			// Not an "Edit Post" screen.
			return;
		}

		if ( empty( $this->options['post_types'] ) ) {
			return;
		}

		$current_screen = get_current_screen();
		if ( ( isset( $current_screen->post_type ) && ! in_array( $current_screen->post_type, (array) $this->options['post_types'], true ) ) ) {
			// The current post type uses the block editor (and the "classic"
			// meta box is disabled).
			return;
		}

		global $post;

		// Enqueue CSS and JS.
		wp_enqueue_style( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.css', dirname( __FILE__ ) ), array(), Share_On_Mastodon::PLUGIN_VERSION );
		wp_enqueue_script( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.js', dirname( __FILE__ ) ), array(), Share_On_Mastodon::PLUGIN_VERSION, false );
		wp_localize_script(
			'share-on-mastodon',
			'share_on_mastodon_obj',
			array(
				'message'             => esc_attr__( 'Forget this URL?', 'share-on-mastodon' ), // Confirmation message.
				'post_id'             => $post->ID, // Pass current post ID to JS.
				'nonce'               => wp_create_nonce( basename( __FILE__ ) ),
				'ajaxurl'             => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'custom_status_field' => ! empty( $this->options['custom_status_field'] ) ? '1' : '0',
			)
		);
	}

	/**
	 * Determines if a post should, in fact, be shared.
	 *
	 * @param  WP_Post $post Post object.
	 * @return bool          If the post should be shared.
	 */
	protected function is_valid( $post ) {
		if ( 'publish' !== $post->post_status ) {
			// Status is something other than `publish`.
			return false;
		}

		if ( post_password_required( $post ) ) {
			// Post is password-protected.
			return false;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return false;
		}

		if ( '' !== get_post_meta( $post->ID, '_share_on_mastodon_url', true ) ) {
			// Was shared before (and not "unlinked").
			return false;
		}

		// A post should only be shared when either the "Share on Mastodon"
		// checkbox was checked (and its value saved), or when "Share Always" is
		// active (and the post isn't "too old," to avoid mishaps).
		$share_always = false;
		$is_enabled   = false;

		if ( '1' === get_post_meta( $post->ID, '_share_on_mastodon', true ) ) {
			// Sharing was "explicitly" enabled for this post.
			$is_enabled = true;
		}

		if ( ! empty( $this->options['share_always'] ) ) {
			$share_always = true;
		}

		// We have let developers override `$is_enabled` through a callback
		// function. In practice, this is almost always used to force sharing.
		if ( apply_filters( 'share_on_mastodon_enabled', $is_enabled, $post->ID ) ) {
			$share_always = true;
		}

		if ( $this->is_older_than( DAY_IN_SECONDS / 2, $post ) ) {
			// Since v0.13.0, we disallow automatic sharing of "older" posts.
			// This sort of changes the behavior of the hook above, which would
			// always come last.
			$share_always = false;
		}

		if ( $is_enabled || $share_always ) {
			return true;
		}

		return false;
	}

	/**
	 * Determines whether a post is older than a certain number of seconds.
	 *
	 * @param  int     $seconds Minimum "age," in secondss.
	 * @param  WP_Post $post    Post object.
	 * @return bool             True if the post exists and is older than `$seconds`, false otherwise.
	 */
	protected function is_older_than( $seconds, $post ) {
		$post_time = get_post_time( 'U', true, $post );

		if ( false === $post_time ) {
			return false;
		}

		if ( $post_time >= time() - $seconds ) {
			return false;
		}

		return true;
	}

	/**
	 * Parses `%title%`, etc. template tags.
	 *
	 * @param  string $status  Mastodon status, or template.
	 * @param  int    $post_id Post ID.
	 * @return string          Parsed status.
	 */
	protected function parse_status( $status, $post_id ) {
		$status = str_replace( '%title%', get_the_title( $post_id ), $status );
		$status = str_replace( '%excerpt%', $this->get_excerpt( $post_id ), $status );
		$status = str_replace( '%tags%', $this->get_tags( $post_id ), $status );
		$status = str_replace( '%permalink%', esc_url_raw( get_permalink( $post_id ) ), $status );
		$status = preg_replace( '~(\r\n){2,}~', "\r\n\r\n", $status ); // We should have normalized line endings by now.

		return sanitize_textarea_field( $status ); // Strips HTML and whatnot.
	}

	/**
	 * Returns a post's excerpt, but limited to approx. 125 characters.
	 *
	 * @param  int $post_id Post ID.
	 * @return string       (Possibly shortened) excerpt.
	 */
	protected function get_excerpt( $post_id ) {
		$orig    = get_the_excerpt( $post_id );
		$excerpt = mb_substr( $orig, 0, apply_filters( 'share_on_mastodon_excerpt_length', 125 ) );

		if ( $excerpt !== $orig && ! ctype_punct( mb_substr( $excerpt, -1 ) ) ) {
			$excerpt .= '…';
		}

		return trim( $excerpt );
	}

	/**
	 * Returns a post's tags as a string of space-separated hashtags.
	 *
	 * @param  int $post_id Post ID.
	 * @return string       Hashtag string.
	 */
	protected function get_tags( $post_id ) {
		$hashtags = '';
		$tags     = get_the_tags( $post_id );

		if ( $tags && ! is_wp_error( $tags ) ) {
			foreach ( $tags as $tag ) {
				$tag_name = $tag->name;

				if ( preg_match( '/\s+/', $tag_name ) ) {
					// Try to "CamelCase" multi-word tags.
					$tag_name = preg_replace( '/\s+/', ' ', $tag_name );
					$tag_name = explode( ' ', $tag_name );
					$tag_name = implode( '', array_map( 'ucfirst', $tag_name ) );
				}

				$hashtags .= '#' . $tag_name . ' ';
			}
		}

		return trim( $hashtags );
	}
}
