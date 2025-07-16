<?php
/**
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Handles post meta and the like, and posting to Mastodon.
 */
class Post_Handler {
	/**
	 * Interacts with WordPress's Plugin API.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_share_on_mastodon_unlink_url', array( $this, 'unlink_url' ) );

		$options = get_options();
		if ( ! empty( $options['post_types'] ) ) {
			foreach ( (array) $options['post_types'] as $post_type ) {
				add_action( "save_post_{$post_type}", array( $this, 'update_meta' ), 11 );
				add_action( "save_post_{$post_type}", array( $this, 'toot' ), 20 );
			}
		}

		// "Delayed" sharing.
		add_action( 'share_on_mastodon_post', array( $this, 'post_to_mastodon' ) );

		// Classic editor notices.
		Notices::register();
	}

	/**
	 * Handles metadata.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 */
	public function update_meta( $post ) {
		$post = get_post( $post );

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
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

		$options = get_options();

		// Sanitize custom status, if any.
		if ( isset( $_POST['share_on_mastodon_status'] ) ) {
			$status = sanitize_textarea_field( wp_unslash( $_POST['share_on_mastodon_status'] ) );
			$status = preg_replace( '~\R~u', "\r\n", $status );
		}

		if (
			! empty( $status ) && '' !== preg_replace( '~\s~', '', $status ) &&
			( empty( $options['status_template'] ) || $status !== $options['status_template'] )
		) {
			// Save only if `$status` is non-empty and, if a template exists, different from said template.
			update_post_meta( $post->ID, '_share_on_mastodon_status', $status );
		} else {
			// Ignore, or delete a previously stored value.
			delete_post_meta( $post->ID, '_share_on_mastodon_status' );
		}

		// Sanitize CW, if any.
		if ( isset( $_POST['share_on_mastodon_cw'] ) ) {
			$content_warning = sanitize_text_field( wp_unslash( $_POST['share_on_mastodon_cw'] ) );
		}

		if ( ! empty( $content_warning ) && '' !== preg_replace( '~\s~', '', $content_warning ) ) {
			// Save only if `$content_warning` is non-empty and.
			update_post_meta( $post->ID, '_share_on_mastodon_cw', $content_warning );
		} else {
			// Ignore, or delete a previously stored value.
			delete_post_meta( $post->ID, '_share_on_mastodon_cw' );
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
	 * @param int|\WP_Post $post Post ID or object.
	 */
	public function toot( $post ) {
		$post = get_post( $post );

		if ( 0 === strpos( current_action(), 'save_' ) && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// For REST requests, we use a *later* hook, which runs *after* metadata, if any, has been saved.
			debug_log( "[Share on Mastodon] Delaying scheduling to `rest_after_insert_{$post->post_type}`." );
			add_action( "rest_after_insert_{$post->post_type}", array( $this, 'toot' ), 20 );

			// Don't do anything just yet.
			return;
		}

		$options = get_options();
		if ( ! empty( $options['meta_box'] ) && $this->is_gutenberg() && empty( $_REQUEST['meta-box-loader'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// This has to be the first of *two* "Gutenberg requests," and we should ignore it. Note: It could be that
			// `$this->is_gutenberg()` always returns `false` whenever `$_REQUEST['meta-box-loader']` is present.
			// Still, doesn't hurt to check.
			debug_log( '[Share on Mastodon] First of two expected requests. Quitting.' );

			return;
		}

		if ( empty( $options['meta_box'] ) && ! empty( $_REQUEST['meta-box-loader'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// This has to be that second "Gutenberg request," and *because we ourselves don't rely on a "classic" meta
			// box*, it should be safe to ignore it.
			debug_log( '[Share on Mastodon] Second, yet irrelevant (to us) request. Quitting.' );

			return;
		}

		// In all other cases, we move on.
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( ! $this->setup_completed( $post ) ) {
			debug_log( '[Share on Mastodon] Setup incomplete.' );
			return;
		}

		if ( ! $this->is_valid( $post ) ) {
			return;
		}

		if ( ! empty( $options['delay_sharing'] ) ) {
			// Since version 0.7.0, there's an option to "schedule" sharing rather than do everything inline.
			wp_schedule_single_event(
				time() + min( $options['delay_sharing'], 3600 ), // Limit to one hour.
				'share_on_mastodon_post',
				array( $post )
			);
		} else {
			// Share immediately.
			$this->post_to_mastodon( $post );
		}
	}

	/**
	 * Actually shares a post on Mastodon.
	 *
	 * Can be called directly or as a (scheduled) `share_on_mastodon_post`
	 * callback.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 */
	public function post_to_mastodon( $post ) {
		$post = get_post( $post );

		if ( ! $this->setup_completed( $post ) ) {
			return;
		}

		if ( ! $this->is_valid( $post ) ) {
			debug_log( '[Share on Mastodon] Skipping post.' );
			return;
		}

		$options = get_options( $post->post_author );

		// Fetch custom status message, if any.
		$status = get_post_meta( $post->ID, '_share_on_mastodon_status', true );
		// Parse template tags, and sanitize.
		$status = $this->parse_status( $status, $post->ID );

		if ( ( empty( $status ) || '' === preg_replace( '~\s~', '', $status ) ) && ! empty( $options['status_template'] ) ) {
			// Use template stored in settings.
			$status = $this->parse_status( $options['status_template'], $post->ID );
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
			// May render hashtags or URLs, or unfiltered HTML, at the very end of a toot unusable.
			$args['status'] = mb_substr( $args['status'], 0, 499, get_bloginfo( 'charset' ) ) . '…';
		}

		$content_warning = get_post_meta( $post->ID, '_share_on_mastodon_cw', true );
		$content_warning = (string) apply_filters( 'share_on_mastodon_cw', $content_warning );

		if ( '' !== $content_warning ) {
			// May render hashtags or URLs, or unfiltered HTML, at the very end of a toot unusable.
			$args['spoiler_text'] = sanitize_text_field( $content_warning );
		}

		// Encode, build query string.
		$query_string = http_build_query( $args );

		// And now, images.
		$media = Image_Handler::get_images( $post );

		if ( ! empty( $media ) ) {
			$max = isset( $options['max_images'] )
				? $options['max_images']
				: 4;

			$max   = (int) apply_filters( 'share_on_mastodon_num_images', $max, $post );
			$count = min( count( $media ), $max );

			// Limit the no. of images (or other media) to `$count`.
			$media = array_slice( $media, 0, $count, true );

			foreach ( $media as $id => $alt ) {
				$media_id = Image_Handler::upload_image( $id, $alt, $options );

				if ( ! empty( $media_id ) ) {
					// The image got uploaded OK.
					$query_string .= '&media_ids[]=' . rawurlencode( $media_id );
				}
			}
		}

		debug_log( '[Share on Mastodon] Posting to Mastodon ...' );

		$response = wp_safe_remote_post(
			esc_url_raw( $options['mastodon_host'] . '/api/v1/statuses' ),
			array(
				'headers'             => array(
					'Authorization' => 'Bearer ' . $options['mastodon_access_token'],
				),
				// Prevent WordPress from applying `http_build_query()`.
				'data_format'         => 'body',
				'body'                => $query_string,
				'timeout'             => 15,
				'limit_response_size' => 1048576,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			debug_log( $response );
			return;
		}

		$status = json_decode( $response['body'] );

		if ( ! empty( $status->url ) ) {
			debug_log( "[Share on Mastodon] Post shared OK at {$status->url}." );

			delete_post_meta( $post->ID, '_share_on_mastodon_error' );
			update_post_meta( $post->ID, '_share_on_mastodon_url', esc_url_raw( $status->url ) );

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

			// Provided debugging's enabled, let's store the (somehow faulty) response.
			debug_log( $response );
		}

		debug_log( '[Share on Mastodon] All done!' );
	}

	/**
	 * Registers a new meta box.
	 */
	public function add_meta_box() {
		$options = get_options();

		if ( empty( $options['post_types'] ) ) {
			// Sharing disabled for all post types.
			return;
		}

		// This'll hide the meta box for Gutenberg users, who by default get the new sidebar panel.
		$args = array( '__back_compat_meta_box' => true );
		if ( ! empty( $options['meta_box'] ) ) {
			// And this will bring it back.
			$args = null;
		}

		add_meta_box(
			'share-on-mastodon',
			__( 'Share on Mastodon', 'share-on-mastodon' ),
			array( $this, 'render_meta_box' ),
			(array) $options['post_types'],
			'side',
			'default',
			$args
		);
	}

	/**
	 * Renders meta box.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( basename( __FILE__ ), 'share_on_mastodon_nonce' );

		$options = get_options();
		$checked = get_post_meta( $post->ID, '_share_on_mastodon', true );

		if ( '' === $checked ) {
			// If sharing is "opt-in" or the post in question is older than 30 minutes, do _not_ check the checkbox by
			// default.
			$checked = apply_filters( 'share_on_mastodon_optin', ! empty( $options['optin'] ) ) || is_older_than( HOUR_IN_SECONDS / 2, $post ) ? '0' : '1';
		}
		?>
		<label>
			<input type="checkbox" name="share_on_mastodon" value="1" <?php checked( '1' === $checked ); ?>>
			<?php esc_html_e( 'Share on Mastodon', 'share-on-mastodon' ); ?>
		</label>
		<?php
		if ( ! empty( $options['content_warning'] ) ) :
			// Content warning saved earlier, if any.
			$content_warning = get_post_meta( $post->ID, '_share_on_mastodon_cw', true );
			?>
			<div style="margin-top: 1em;">
				<label for="share_on_mastodon_cw"><?php esc_html_e( '(Optional) Content Warning', 'share-on-mastodon' ); ?></label>
				<input type="text" id="share_on_mastodon_cw" name="share_on_mastodon_cw" style="width: 100%; box-sizing: border-box; margin-top: 0.5em;" value="<?php echo esc_attr( trim( $content_warning ) ); ?>" />
			</div>
			<?php
		endif;

		if ( ! empty( $options['custom_status_field'] ) ) :
			// Custom message saved earlier, if any.
			$custom_status = get_post_meta( $post->ID, '_share_on_mastodon_status', true );

			if ( '' === $custom_status && ! empty( $options['status_template'] ) ) {
				// Default to the template as set on the options page.
				$custom_status = $options['status_template'];
			}
			?>
			<div style="margin-top: 1em;">
				<label for="share_on_mastodon_status"><?php esc_html_e( '(Optional) Custom Message', 'share-on-mastodon' ); ?></label>
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

		if ( ! empty( $_POST['is_gutenberg'] ) ) {
			// Delete the checkbox value, too, to prevent Gutenberg's' odd meta box behavior from triggering an
			// immediate re-share.
			delete_post_meta( $post_id, '_share_on_mastodon' );
		}

		wp_die();
	}

	/**
	 * Adds admin scripts and styles.
	 *
	 * @param string $hook_suffix Current WP-Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'post-new.php' !== $hook_suffix && 'post.php' !== $hook_suffix ) {
			// Not an "Edit Post" screen.
			return;
		}

		$options = get_options();
		if ( empty( $options['post_types'] ) ) {
			return;
		}

		$current_screen = get_current_screen();
		if ( ( isset( $current_screen->post_type ) && ! in_array( $current_screen->post_type, $options['post_types'], true ) ) ) {
			// Only load JS for actually supported post types.
			return;
		}

		global $post;

		// Enqueue CSS and JS.
		wp_enqueue_style( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.css', __DIR__ ), array(), Share_On_Mastodon::PLUGIN_VERSION );
		wp_enqueue_script( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.js', __DIR__ ), array(), Share_On_Mastodon::PLUGIN_VERSION, false );
		wp_localize_script(
			'share-on-mastodon',
			'share_on_mastodon_obj',
			array(
				'message'             => esc_attr__( 'Forget this URL?', 'share-on-mastodon' ), // Confirmation message.
				'post_id'             => ! empty( $post->ID ) ? $post->ID : 0, // Pass current post ID to JS.
				'nonce'               => wp_create_nonce( basename( __FILE__ ) ),
				'ajaxurl'             => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'custom_status_field' => ! empty( $options['custom_status_field'] ) ? '1' : '0',
				'content_warning'     => ! empty( $options['content_warning'] ) ? '1' : '0',
			)
		);
	}

	/**
	 * Determines if a post should, in fact, be shared.
	 *
	 * @param  \WP_Post $post Post object.
	 * @return bool           If the post should be shared.
	 */
	protected function is_valid( $post ) {
		if ( 'publish' !== $post->post_status ) {
			// Status is something other than `publish`.
			debug_log( '[Share on Mastodon] Post not public.' );
			return false;
		}

		if ( post_password_required( $post ) ) {
			// Post is password-protected.
			debug_log( '[Share on Mastodon] Post password-protected.' );
			return false;
		}

		$options = get_options( $post->post_author );

		if ( ! in_array( $post->post_type, (array) $options['post_types'], true ) ) {
			// Unsupported post type.
			debug_log( '[Share on Mastodon] Unsupported post type.' );
			return false;
		}

		if ( '' !== get_post_meta( $post->ID, '_share_on_mastodon_url', true ) ) {
			// Was shared before (and not "unlinked").
			debug_log( '[Share on Mastodon] Post shared before.' );
			return false;
		}

		if ( is_older_than( DAY_IN_SECONDS / 2, $post ) && '1' !== get_post_meta( $post->ID, '_share_on_mastodon', true ) ) {
			// Unless the box was ticked explicitly, we won't share "older" posts. Since v0.13.0, sharing "older" posts
			// is "opt-in," always.
			debug_log( '[Share on Mastodon] Preventing older post from being shared automatically.' );
			return false;
		}

		$is_enabled = false;

		if ( '1' === get_post_meta( $post->ID, '_share_on_mastodon', true ) ) {
			// Sharing was enabled for this post.
			$is_enabled = true;
		}

		// That's not it, though; we have a setting that enables posts to be shared nevertheless.
		if ( ! empty( $options['share_always'] ) ) {
			$is_enabled = true;
		}

		// We let developers override `$is_enabled` through a callback function.
		return apply_filters( 'share_on_mastodon_enabled', $is_enabled, $post->ID );
	}

	/**
	 * Parses `%title%`, etc. template tags.
	 *
	 * @param  string $status  Mastodon status, or template.
	 * @param  int    $post_id Post ID.
	 * @return string          Parsed status.
	 */
	protected function parse_status( $status, $post_id ) {
		// Fill out title and tags.
		$status = str_replace( '%title%', get_the_title( $post_id ), $status );
		$status = str_replace( '%tags%', $this->get_tags( $post_id ), $status );
		$status = str_replace( '%category%', $this->get_category( $post_id ), $status );

		// Estimate a max length of sorts.
		$max_length = mb_strlen( str_replace( array( '%excerpt%', '%permalink%' ), '', $status ) );
		$max_length = max( 0, 450 - $max_length ); // For a possible permalink, and then some.

		$status = str_replace( '%excerpt%', $this->get_excerpt( $post_id, $max_length ), $status );

		$status = preg_replace( '~(\r\n){2,}~', "\r\n\r\n", $status ); // We should have normalized line endings by now.
		$status = sanitize_textarea_field( $status ); // Strips HTML and whatnot.

		// Add the (escaped) URL after the everything else has been sanitized, so as not to garble permalinks with
		// multi-byte characters in them.
		$status = str_replace( '%permalink%', esc_url_raw( get_permalink( $post_id ) ), $status );

		return $status;
	}

	/**
	 * Returns a post's excerpt, but limited to approx. 125 characters.
	 *
	 * @param  int $post_id    Post ID.
	 * @param  int $max_length Estimated maximum length.
	 * @return string          (Possibly shortened) excerpt.
	 */
	protected function get_excerpt( $post_id, $max_length = 125 ) {
		if ( 0 === $max_length ) {
			// Nothing to do.
			return '';
		}

		// Grab the default `excerpt_more`.
		$excerpt_more = apply_filters( 'excerpt_more', ' [&hellip;]' );

		// The excerpt as generated by WordPress.
		$orig = apply_filters( 'the_excerpt', get_the_excerpt( $post_id ) );

		// Trim off the `excerpt_more` string.
		$excerpt = preg_replace( "~$excerpt_more$~", '', $orig );

		$excerpt = wp_strip_all_tags( $orig ); // Just in case a site owner's allowing HTML in their excerpts or something.
		$excerpt = html_entity_decode( $orig, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ); // Prevent special characters from messing things up.

		$shortened = mb_substr( $excerpt, 0, apply_filters( 'share_on_mastodon_excerpt_length', $max_length ) );
		$shortened = trim( $shortened );

		if ( $shortened === $excerpt ) {
			// Might as well done nothing.
			return $orig;
		} elseif ( '…' !== mb_substr( $shortened, -1 ) ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedElseif
			// Final char is a horizontal ellipsis. Do nothing, haha.
		} elseif ( ctype_punct( mb_substr( $shortened, -1 ) ) ) {
			// Final char is a "punctuation" character but not an ellipsis.
			$shortened .= ' …';
		} else {
			$shortened .= '…';
		}

		return $shortened;
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

				if ( preg_match( '/(\s|-)+/', $tag_name ) ) {
					// Try to "CamelCase" multi-word tags.
					$tag_name = preg_replace( '~(\s|-)+~', ' ', $tag_name );
					$tag_name = explode( ' ', $tag_name );
					$tag_name = implode( '', array_map( 'ucfirst', $tag_name ) );
				}

				$hashtags .= '#' . $tag_name . ' ';
			}
		}

		return trim( $hashtags );
	}

	/**
	 * Returns the first of a post's categories as a hashtag.
	 *
	 * @param  int $post_id Post ID.
	 * @return string       (Possibly "CamelCased") hashtag, or empty string.
	 */
	protected function get_category( $post_id ) {
		$cats = get_the_category( $post_id );

		if ( empty( $cats[0]->cat_name ) ) {
			return '';
		}

		// Grab the first category.
		$cat_name = $cats[0]->cat_name;

		if ( preg_match( '/(\s|-)+/', $cat_name ) ) {
			// Try to "CamelCase" multi-word categories.
			$cat_name = preg_replace( '~(\s|-)+~', ' ', $cat_name );
			$cat_name = explode( ' ', $cat_name );
			$cat_name = implode( '', array_map( 'ucfirst', $cat_name ) );
		}

		return '#' . trim( $cat_name );
	}

	/**
	 * Checks for a Mastodon instance and auth token.
	 *
	 * @param  \WP_Post $post Post object.
	 * @return bool           Whether auth access was set up okay.
	 */
	protected function setup_completed( $post ) {
		$options = get_options( $post->post_author );

		if ( empty( $options['mastodon_host'] ) ) {
			return false;
		}

		if ( ! wp_http_validate_url( $options['mastodon_host'] ) ) {
			return false;
		}

		if ( empty( $options['mastodon_access_token'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks whether the current request was initiated by the block editor.
	 *
	 * @return bool Whether the current request was initiated by the block editor.
	 */
	protected function is_gutenberg() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			// Not a REST request.
			return false;
		}

		if ( wp_doing_cron() ) {
			return false;
		}

		$nonce = null;

		if ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = $_REQUEST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = $_SERVER['HTTP_X_WP_NONCE']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( null === $nonce ) {
			return false;
		}

		// Check the nonce.
		return wp_verify_nonce( $nonce, 'wp_rest' );
	}
}
