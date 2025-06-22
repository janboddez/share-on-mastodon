<?php
/**
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Handles settings page.
 */
class Plugin_Options extends Options_Handler {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$options = get_option( 'share_on_mastodon_settings' );

		$this->options = array_merge(
			static::get_default_options(),
			is_array( $options )
				? $options
				: array()
		);
	}

	/**
	 * Registers hook callbacks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_post_share_on_mastodon_reset_settings', array( $this, 'reset_settings' ) );
		add_action( 'share_on_mastodon_verify_token', array( $this, 'cron_verify_token' ) );
	}

	/**
	 * Registers the plugin settings page.
	 */
	public function create_menu() {
		add_options_page(
			__( 'Share on Mastodon', 'share-on-mastodon' ),
			__( 'Share on Mastodon', 'share-on-mastodon' ),
			'manage_options',
			'share-on-mastodon',
			array( $this, 'settings_page' )
		);
		add_action( 'admin_init', array( $this, 'add_settings' ) );
	}

	/**
	 * Registers the actual options.
	 */
	public function add_settings() {
		add_option( 'share_on_mastodon_settings', $this->options );

		// @todo: Move to `sanitize_settings()`?
		$active_tab = $this->get_active_tab();

		register_setting(
			'share-on-mastodon-settings-group',
			'share_on_mastodon_settings',
			array( 'sanitize_callback' => array( $this, "sanitize_{$active_tab}_settings" ) )
		);
	}

	/**
	 * Handles submitted "setup" options.
	 *
	 * @param  array $settings Submitted settings.
	 * @return array           (Sanitized) options to be stored.
	 */
	public function sanitize_setup_settings( $settings ) {
		if ( isset( $settings['mastodon_host'] ) ) {
			// Clean up and sanitize the user-submitted URL.
			$mastodon_host = $this->clean_url( $settings['mastodon_host'] );

			if ( '' === $mastodon_host ) {
				// Removing the instance URL. Might be done to temporarily disable crossposting. Let's not revoke access
				// just yet.
				$this->options['mastodon_host'] = '';
			} elseif ( wp_http_validate_url( $mastodon_host ) ) {
				if ( $mastodon_host !== $this->options['mastodon_host'] ) {
					// Updated URL. (Try to) revoke access. Forget token regardless of the outcome.
					$this->revoke_access();

					// Then, save the new URL.
					$this->options['mastodon_host'] = esc_url_raw( $mastodon_host );

					// Forget client ID and secret. A new client ID and secret will be requested next time the page
					// loads.
					$this->options['mastodon_client_id']     = '';
					$this->options['mastodon_client_secret'] = '';
				}
			} else {
				// Not a valid URL. Display error message.
				add_settings_error(
					'share-on-mastodon-mastodon-host',
					'invalid-url',
					esc_html__( 'Please provide a valid URL.', 'share-on-mastodon' )
				);
			}
		}

		// Updated settings.
		return $this->options;
	}

	/**
	 * Handles submitted "post type" options.
	 *
	 * @param  array $settings Submitted settings.
	 * @return array           (Sanitized) options to be stored.
	 */
	public function sanitize_post_types_settings( $settings ) {
		$this->options['post_types'] = array();

		if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
			// Post types considered valid.
			$supported_post_types = (array) apply_filters( 'share_on_mastodon_post_types', get_post_types( array( 'public' => true ) ) );
			$supported_post_types = array_diff( $supported_post_types, array( 'attachment' ) );

			foreach ( $settings['post_types'] as $post_type ) {
				if ( in_array( $post_type, $supported_post_types, true ) ) {
					// Valid post type. Add to array.
					$this->options['post_types'][] = $post_type;
				}
			}
		}

		// Updated settings.
		return $this->options;
	}

	/**
	 * Handles submitted "images" options.
	 *
	 * @param  array $settings Submitted settings.
	 * @return array           (Sanitized) options to be stored.
	 */
	public function sanitize_images_settings( $settings ) {
		$options = array(
			'featured_images'   => isset( $settings['featured_images'] ) ? true : false,
			'attached_images'   => isset( $settings['attached_images'] ) ? true : false,
			'referenced_images' => isset( $settings['referenced_images'] ) ? true : false,
			'max_images'        => isset( $settings['max_images'] ) && ctype_digit( $settings['max_images'] )
				? min( (int) $settings['max_images'], 4 )
				: 4,
		);

		// Updated settings.
		return array_merge( $this->options, $options );
	}

	/**
	 * Handles submitted "advanced" options.
	 *
	 * @param  array $settings Submitted settings.
	 * @return array           (Sanitized) options to be stored.
	 */
	public function sanitize_advanced_settings( $settings ) {
		$delay = isset( $settings['delay_sharing'] ) && ctype_digit( $settings['delay_sharing'] )
			? (int) $settings['delay_sharing']
			: 0;
		$delay = min( $delay, HOUR_IN_SECONDS ); // Limit to one hour.

		$options = array(
			'optin'               => isset( $settings['optin'] ) ? true : false,
			'share_always'        => isset( $settings['share_always'] ) ? true : false,
			'delay_sharing'       => $delay,
			'micropub_compat'     => isset( $settings['micropub_compat'] ) ? true : false,
			'syn_links_compat'    => isset( $settings['syn_links_compat'] ) ? true : false,
			'custom_status_field' => isset( $settings['custom_status_field'] ) ? true : false,
			'status_template'     => isset( $settings['status_template'] ) && is_string( $settings['status_template'] )
				? preg_replace( '~\R~u', "\r\n", sanitize_textarea_field( $settings['status_template'] ) )
				: '',
			'meta_box'            => isset( $settings['meta_box'] ) ? true : false,
			'content_warning'     => isset( $settings['content_warning'] ) ? true : false,
		);

		// Updated settings.
		return array_merge( $this->options, $options );
	}

	/**
	 * Handles submitted "debugging" options.
	 *
	 * @param  array $settings Submitted settings.
	 * @return array           (Sanitized) options to be stored.
	 */
	public function sanitize_debug_settings( $settings ) {
		$options = array(
			'debug_logging' => isset( $settings['debug_logging'] ) ? true : false,
		);

		// Updated settings.
		return array_merge( $this->options, $options );
	}

	/**
	 * Echoes the plugin options form. Handles the OAuth flow, too, for now.
	 */
	public function settings_page() {
		$active_tab = $this->get_active_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Share on Mastodon', 'share-on-mastodon' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $this->get_options_url( 'setup' ) ); ?>" class="nav-tab <?php echo esc_attr( 'setup' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Setup', 'share-on-mastodon' ); ?></a>
				<a href="<?php echo esc_url( $this->get_options_url( 'post_types' ) ); ?>" class="nav-tab <?php echo esc_attr( 'post_types' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Post Types', 'share-on-mastodon' ); ?></a>
				<a href="<?php echo esc_url( $this->get_options_url( 'images' ) ); ?>" class="nav-tab <?php echo esc_attr( 'images' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Images', 'share-on-mastodon' ); ?></a>
				<a href="<?php echo esc_url( $this->get_options_url( 'advanced' ) ); ?>" class="nav-tab <?php echo esc_attr( 'advanced' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Advanced', 'share-on-mastodon' ); ?></a>
				<a href="<?php echo esc_url( $this->get_options_url( 'debug' ) ); ?>" class="nav-tab <?php echo esc_attr( 'debug' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Debugging', 'share-on-mastodon' ); ?></a>

				<?php do_action( 'share_on_mastodon_settings_after_tab_list', $active_tab ); ?>
			</h2>

			<?php
			if ( 'setup' === $active_tab ) :
				ob_start();
				?>
				<form method="post" action="options.php" novalidate="novalidate">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-mastodon-settings-group' );
					?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><label for="share_on_mastodon_settings[mastodon_host]"><?php esc_html_e( 'Instance', 'share-on-mastodon' ); ?></label></th>
							<td><input type="url" id="share_on_mastodon_settings[mastodon_host]" name="share_on_mastodon_settings[mastodon_host]" style="min-width: 33%;" value="<?php echo esc_attr( $this->options['mastodon_host'] ); ?>" />
							<?php /* translators: %s: example URL. */ ?>
							<p class="description"><?php printf( esc_html__( 'Your Mastodon instance&rsquo;s URL. E.g., %s.', 'share-on-mastodon' ), '<code>https://mastodon.online</code>' ); ?></p></td>
						</tr>
					</table>
					<p class="submit"><?php submit_button( __( 'Save Changes', 'share-on-mastodon' ), 'primary', 'submit', false ); ?></p>
				</form>

				<h3><?php esc_html_e( 'Authorize Access', 'share-on-mastodon' ); ?></h3>
				<?php
				if ( ! empty( $this->options['mastodon_host'] ) ) {
					// A valid instance URL was set.
					if ( empty( $this->options['mastodon_client_id'] ) || empty( $this->options['mastodon_client_secret'] ) ) {
						// No app is currently registered. Let's try to fix that!
						$this->register_app();
					}

					if ( ! empty( $this->options['mastodon_client_id'] ) && ! empty( $this->options['mastodon_client_secret'] ) ) {
						// An app was successfully registered.
						if ( ! empty( $_GET['code'] ) && '' === $this->options['mastodon_access_token'] ) {
							// Access token request.
							if ( $this->request_user_token( wp_unslash( $_GET['code'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
								?>
								<div class="notice notice-success is-dismissible">
									<p><?php esc_html_e( 'Access granted!', 'share-on-mastodon' ); ?></p>
								</div>
								<?php
							}
						}

						if ( isset( $_GET['action'] ) && 'revoke' === $_GET['action'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'share-on-mastodon:token:revoke' ) ) {
							// Revoke access. Forget access token regardless of the outcome.
							$this->revoke_access();
						}

						if ( empty( $this->options['mastodon_access_token'] ) ) {
							// No access token exists. Echo authorization link.
							$url = $this->options['mastodon_host'] . '/oauth/authorize?' . http_build_query(
								array(
									'response_type' => 'code',
									'client_id'     => $this->options['mastodon_client_id'],
									'client_secret' => $this->options['mastodon_client_secret'],
									'redirect_uri'  => esc_url_raw( add_query_arg( array( 'page' => 'share-on-mastodon' ), admin_url( 'options-general.php' ) ) ),
									'scope'         => ! empty( $this->options['mastodon_app_id'] )
										? 'write:media write:statuses read' // "New" scopes.
										: 'write:media write:statuses read:accounts read:statuses', // For "legacy" apps.
								)
							);
							?>
							<p><?php esc_html_e( 'Authorize WordPress to read and write to your Mastodon timeline in order to enable syndication.', 'share-on-mastodon' ); ?></p>
							<p class="submit"><?php printf( '<a href="%1$s" class="button">%2$s</a>', esc_url( $url ), esc_html__( 'Authorize Access', 'share-on-mastodon' ) ); ?>
							<?php
						} else {
							// An access token exists.
							?>
							<p><?php esc_html_e( 'You&rsquo;ve authorized WordPress to read and write to your Mastodon timeline.', 'share-on-mastodon' ); ?></p>
							<p class="submit">
								<?php
								printf(
									'<a href="%1$s" class="button">%2$s</a>',
									esc_url(
										add_query_arg(
											array(
												'page'     => 'share-on-mastodon', // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
												'action'   => 'revoke', // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.LongIndexSpaceBeforeDoubleArrow
												'_wpnonce' => wp_create_nonce( 'share-on-mastodon:token:revoke' ),
											),
											admin_url( 'options-general.php' )
										)
									),
									esc_html__( 'Revoke Access', 'share-on-mastodon' )
								);
								?>
							</p>
							<?php
						}
					} else {
						// Still couldn't register our app.
						?>
						<p><?php esc_html_e( 'Something went wrong contacting your Mastodon instance. Please reload this page to try again.', 'share-on-mastodon' ); ?></p>
						<?php
					}
				} else {
					// We can't do much without an instance URL.
					?>
					<p><?php esc_html_e( 'Please fill out and save your Mastodon instance&rsquo;s URL first.', 'share-on-mastodon' ); ?></p>
					<?php
				}
				$content = ob_get_clean();

				echo apply_filters( 'share_on_mastodon_settings_setup_tab', $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			endif;

			if ( 'post_types' === $active_tab ) :
				?>
				<form method="post" action="options.php">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-mastodon-settings-group' );
					?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'Supported Post Types', 'share-on-mastodon' ); ?></span></th>
							<td><ul style="list-style: none; margin-top: 0;">
								<?php
								// Post types considered valid.
								$supported_post_types = (array) apply_filters( 'share_on_mastodon_post_types', get_post_types( array( 'public' => true ) ) );
								$supported_post_types = array_diff( $supported_post_types, array( 'attachment' ) );

								foreach ( $supported_post_types as $post_type ) :
									$post_type = get_post_type_object( $post_type );
									?>
									<li><label><input type="checkbox" name="share_on_mastodon_settings[post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $this->options['post_types'], true ) ); ?> /> <?php echo esc_html( $post_type->labels->singular_name ); ?></label></li>
									<?php
								endforeach;
								?>
							</ul>
							<p class="description"><?php esc_html_e( 'Post types for which sharing to Mastodon is possible. (Sharing can still be disabled on a per-post basis.)', 'share-on-mastodon' ); ?></p></td>
						</tr>
					</table>
					<p class="submit"><?php submit_button( __( 'Save Changes', 'share-on-mastodon' ), 'primary', 'submit', false ); ?></p>
				</form>
				<?php
			endif;

			if ( 'images' === $active_tab ) :
				?>
				<form method="post" action="options.php">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-mastodon-settings-group' );
					?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><label for="share_on_mastodon_settings[max_images]"><?php esc_html_e( 'Max. No. of Images', 'share-on-mastodon' ); ?></label></th>
							<td><input type="number" min="0" max="4" style="width: 6em;" id="share_on_mastodon_settings[max_images]" name="share_on_mastodon_settings[max_images]" value="<?php echo esc_attr( isset( $this->options['max_images'] ) ? $this->options['max_images'] : '4' ); ?>" />
							<p class="description"><?php esc_html_e( 'The maximum number of images that will be uploaded. (Mastodon supports up to 4 images.)', 'share-on-mastodon' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'Featured Images', 'share-on-mastodon' ); ?></span></th>
							<td><label><input type="checkbox" name="share_on_mastodon_settings[featured_images]" value="1" <?php checked( ! isset( $this->options['featured_images'] ) || $this->options['featured_images'] ); ?> /> <?php esc_html_e( 'Include featured images', 'share-on-mastodon' ); ?></label>
							<p class="description"><?php esc_html_e( 'Upload featured images.', 'share-on-mastodon' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'In-Post Images', 'share-on-mastodon' ); ?></span></th>
							<td><label><input type="checkbox" name="share_on_mastodon_settings[referenced_images]" value="1" <?php checked( ! empty( $this->options['referenced_images'] ) ); ?> /> <?php esc_html_e( 'Include &ldquo;in-post&rdquo; images', 'share-on-mastodon' ); ?></label>
							<p class="description"><?php esc_html_e( 'Upload &ldquo;in-content&rdquo; images. (Limited to images in the Media Library.)', 'share-on-mastodon' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'Attached Images', 'share-on-mastodon' ); ?></span></th>
							<td><label><input type="checkbox" name="share_on_mastodon_settings[attached_images]" value="1" <?php checked( ! isset( $this->options['attached_images'] ) || $this->options['attached_images'] ); ?> /> <?php esc_html_e( 'Include attached images', 'share-on-mastodon' ); ?></label>
							<?php /* translators: %s: link to official WordPress documentation.  */ ?>
							<p class="description"><?php printf( esc_html__( 'Upload %s.', 'share-on-mastodon' ), sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', 'https://wordpress.org/documentation/article/use-image-and-file-attachments/#attachment-to-a-post', esc_html__( 'attached images', 'share-on-mastodon' ) ) ); ?></p></td>
						</tr>
					</table>
					<p class="submit"><?php submit_button( __( 'Save Changes', 'share-on-mastodon' ), 'primary', 'submit', false ); ?></p>
				</form>
				<?php
			endif;

			if ( 'advanced' === $active_tab ) :
				?>
				<form method="post" action="options.php">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-mastodon-settings-group' );
					?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><label for="share_on_mastodon_settings[delay_sharing]"><?php esc_html_e( 'Delayed Sharing', 'share-on-mastodon' ); ?></label></th>
							<td><input type="number" min="0" max="3600" style="width: 6em;" id="share_on_mastodon_settings[delay_sharing]" name="share_on_mastodon_settings[delay_sharing]" value="<?php echo esc_attr( isset( $this->options['delay_sharing'] ) ? $this->options['delay_sharing'] : 0 ); ?>" />
							<p class="description"><?php esc_html_e( 'The number of seconds (0&ndash;3600) WordPress should delay sharing after a post is first published. (Setting this to, e.g., &ldquo;300&rdquo;&mdash;that&rsquo;s 5 minutes&mdash;may resolve issues with image uploads.)', 'share-on-mastodon' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'Opt-In', 'share-on-mastodon' ); ?></span></th>
							<td><label><input type="checkbox" name="share_on_mastodon_settings[optin]" value="1" <?php checked( ! empty( $this->options['optin'] ) ); ?> /> <?php esc_html_e( 'Make sharing opt-in rather than opt-out', 'share-on-mastodon' ); ?></label>
							<p class="description"><?php esc_html_e( 'Have the &ldquo;Share on Mastodon&rdquo; checkbox unchecked by default.', 'share-on-mastodon' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'Share Always', 'share-on-mastodon' ); ?></span></th>
							<td><label><input type="checkbox" name="share_on_mastodon_settings[share_always]" value="1" <?php checked( ! empty( $this->options['share_always'] ) ); ?> /> <?php esc_html_e( 'Always share on Mastodon', 'share-on-mastodon' ); ?></label>
							<p class="description"><?php esc_html_e( '&ldquo;Force&rdquo; sharing (regardless of the &ldquo;Share on Mastodon&rdquo; checkbox&rsquo;s state), like when posting from a mobile app.', 'share-on-mastodon' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><label for="share_on_mastodon_status_template"><?php esc_html_e( 'Status Template', 'share-on-mastodon' ); ?></label></th>
							<td><textarea name="share_on_mastodon_settings[status_template]" id="share_on_mastodon_status_template" rows="5" style="min-width: 33%;"><?php echo ! empty( $this->options['status_template'] ) ? esc_html( $this->options['status_template'] ) : ''; ?></textarea>
							<?php /* translators: %s: supported template tags */ ?>
							<p class="description"><?php printf( esc_html__( 'Customize the default status template. Supported &ldquo;template tags&rdquo;: %s.', 'share-on-mastodon' ), '<code>%title%</code>, <code>%excerpt%</code>, <code>%tags%</code>, <code>%permalink%</code>' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'Customize Statuses', 'share-on-mastodon' ); ?></span></th>
							<td><label><input type="checkbox" name="share_on_mastodon_settings[custom_status_field]" value="1" <?php checked( ! empty( $this->options['custom_status_field'] ) ); ?> /> <?php esc_html_e( 'Allow customizing Mastodon statuses', 'share-on-mastodon' ); ?></label>
								<?php /* translators: %s: link to the `share_on_mastodon_status` documentation */ ?>
							<p class="description"><?php printf( esc_html__( 'Add a custom &ldquo;Message&rdquo; field to Share on Mastodon&rsquo;s &ldquo;meta box.&rdquo; (For more fine-grained control, please have a look at the %s filter instead.)', 'share-on-mastodon' ), '<a href="https://jan.boddez.net/wordpress/share-on-mastodon#share_on_mastodon_status" target="_blank" rel="noopener noreferrer"><code>share_on_mastodon_status</code></a>' ); ?></p></td>
						</tr>

						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'Content Warnings', 'share-on-mastodon' ); ?></span></th>
							<td><label><input type="checkbox" name="share_on_mastodon_settings[content_warning]" value="1" <?php checked( ! empty( $this->options['content_warning'] ) ); ?> /> <?php esc_html_e( 'Enable support for content warnings', 'share-on-mastodon' ); ?></label>
							<p class="description"><?php esc_html_e( 'Add a &ldquo;Content Warning&rdquo; input field to Share on Mastodon&rsquo;s &ldquo;meta box.&rdquo;', 'share-on-mastodon' ); ?></p></td>
						</tr>

						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'Meta Box', 'share-on-mastodon' ); ?></span></th>
							<td><label><input type="checkbox" name="share_on_mastodon_settings[meta_box]" value="1" <?php checked( ! empty( $this->options['meta_box'] ) ); ?> /> <?php esc_html_e( 'Use &ldquo;classic&rdquo; meta box', 'share-on-mastodon' ); ?></label>
							<p class="description"><?php esc_html_e( 'Replace Share on Mastodon&rsquo;s &ldquo;block editor sidebar panel&rdquo; with a &ldquo;classic&rdquo; meta box (even for post types that use the block editor).', 'share-on-mastodon' ); ?></p></td>
						</tr>

						<?php if ( class_exists( '\\Micropub_Endpoint' ) ) : ?>
							<tr valign="top">
								<th scope="row"><span class="label"><?php esc_html_e( 'Micropub', 'share-on-mastodon' ); ?></span></th>
								<td><label><input type="checkbox" name="share_on_mastodon_settings[micropub_compat]" value="1" <?php checked( ! empty( $this->options['micropub_compat'] ) ); ?> /> <?php esc_html_e( 'Add syndication target', 'share-on-mastodon' ); ?></label>
								<p class="description"><?php esc_html_e( 'Add &ldquo;Mastodon&rdquo; as a Micropub syndication target.', 'share-on-mastodon' ); ?></p></td>
							</tr>
						<?php endif; ?>

						<?php if ( function_exists( 'get_syndication_links' ) ) : ?>
							<tr valign="top">
								<th scope="row"><span class="label"><?php esc_html_e( 'Syndication Links', 'share-on-mastodon' ); ?></span></th>
								<td><label><input type="checkbox" name="share_on_mastodon_settings[syn_links_compat]" value="1" <?php checked( ! empty( $this->options['syn_links_compat'] ) ); ?> /> <?php esc_html_e( 'Add Mastodon URLs to syndication links', 'share-on-mastodon' ); ?></label>
								<p class="description"><?php esc_html_e( '(Experimental) Add Mastodon URLs to Syndication Links&rsquo; list of syndication links.', 'share-on-mastodon' ); ?></p></td>
							</tr>
						<?php endif; ?>
					</table>
					<p class="submit"><?php submit_button( __( 'Save Changes', 'share-on-mastodon' ), 'primary', 'submit', false ); ?></p>
				</form>
				<?php
			endif;

			if ( 'debug' === $active_tab ) :
				?>
				<form method="post" action="options.php">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-mastodon-settings-group' );
					?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><span class="label"><?php esc_html_e( 'Logging', 'share-on-mastodon' ); ?></span></th>
							<td><label><input type="checkbox" name="share_on_mastodon_settings[debug_logging]" value="1" <?php checked( ! empty( $this->options['debug_logging'] ) ); ?> /> <?php esc_html_e( 'Enable debug logging', 'share-on-mastodon' ); ?></label>
							<?php /* translators: %s: link to the official WordPress documentation */ ?>
							<p class="description"><?php printf( esc_html__( 'You&rsquo;ll also need to set WordPress&rsquo; %s.', 'share-on-mastodon' ), sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', 'https://wordpress.org/documentation/article/debugging-in-wordpress/#example-wp-config-php-for-debugging', esc_html__( 'debug logging constants', 'share-on-mastodon' ) ) ); ?></p></td>
						</tr>
					</table>
					<p class="submit"><?php submit_button( __( 'Save Changes', 'share-on-mastodon' ), 'primary', 'submit', false ); ?></p>
				</form>

				<fieldset>
					<legend><?php esc_html_e( 'Danger Zone', 'share-on-mastodon' ); ?></legend>
					<div class="form-group">
						<p><?php esc_html_e( 'Just in case, this button lets you delete Share on Mastodon&rsquo;s settings. Note: This will not invalidate previously issued tokens! (You can, however, still invalidate them on your instance&rsquo;s &ldquo;Account &gt; Authorized apps&rdquo; page.)', 'share-on-mastodon' ); ?></p>
						<?php
						printf(
							'<a href="%1$s" class="button button-reset-settings" style="color: #a00; border-color: #a00;">%2$s</a>',
							esc_url(
								add_query_arg(
									array(
										'action'   => 'share_on_mastodon_reset_settings',
										'reset'    => 'true',
										'_wpnonce' => wp_create_nonce( 'share-on-mastodon:settings:reset' ),
									),
									admin_url( 'admin-post.php' )
								)
							),
							esc_html__( 'Reset Settings', 'share-on-mastodon' )
						);
						?>
					</div>
				</fieldset>
				<?php
			endif;

			do_action( 'share_on_mastodon_settings_after_tabs', $active_tab );
			?>
		</div>
		<?php
	}

	/**
	 * Loads (admin) scripts and styles.
	 *
	 * @param string $hook_suffix Current WP-Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'settings_page_share-on-mastodon' !== $hook_suffix ) {
			// Not the "Share on Mastodon" settings page.
			return;
		}

		// Enqueue CSS and JS.
		wp_enqueue_style( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.css', __DIR__ ), array(), Share_On_Mastodon::PLUGIN_VERSION );
		wp_enqueue_script( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.js', __DIR__ ), array(), Share_On_Mastodon::PLUGIN_VERSION, true );
		wp_localize_script(
			'share-on-mastodon',
			'share_on_mastodon_obj',
			array( 'message' => esc_attr__( 'Are you sure you want to reset all settings?', 'share-on-mastodon' ) ) // Confirmation message.
		);
	}

	/**
	 * Resets all plugin settings.
	 */
	public function reset_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You have insufficient permissions to access this page.', 'share-on-mastodon' ) );
		}

		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'share-on-mastodon:settings:reset' ) ) {
			// Reset all plugin settings.
			$this->options = static::get_default_options();
			$this->save();
		}

		wp_safe_redirect( esc_url_raw( add_query_arg( array( 'page' => 'share-on-mastodon' ), admin_url( 'options-general.php' ) ) ) );
		exit;
	}

	/**
	 * Returns this plugin's options URL with a `tab` query parameter.
	 *
	 * @param  string $tab Target tab.
	 * @return string      Options page URL.
	 */
	protected function get_options_url( $tab = 'setup' ) {
		return add_query_arg(
			array(
				'page' => 'share-on-mastodon',
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Returns the active tab.
	 *
	 * @return string Active tab.
	 */
	protected function get_active_tab() {
		$active_tab = apply_filters( 'share_on_mastodon_active_tab', null );
		if ( $active_tab ) {
			return $active_tab;
		}

		if ( ! empty( $_POST['submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// @todo: Add a "_wp_http_referer" form field rather than rely on an HTTP header?
			$query_string = wp_parse_url( wp_get_referer(), PHP_URL_QUERY );

			if ( empty( $query_string ) ) {
				return 'setup';
			}

			parse_str( $query_string, $query_vars );

			if ( isset( $query_vars['tab'] ) && in_array( $query_vars['tab'], array( 'setup', 'post_types', 'images', 'advanced', 'debug' ), true ) ) {
				return $query_vars['tab'];
			}

			return 'setup';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'setup', 'post_types', 'images', 'advanced', 'debug' ), true ) ) {
			return $_GET['tab']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		return 'setup';
	}

	/**
	 * Requests a new user token.
	 *
	 * @param string $code Authorization code.
	 */
	protected function request_user_token( $code ) {
		// Redirect here after authorization.
		$redirect_uri = add_query_arg( array( 'page' => 'share-on-mastodon' ), admin_url( 'options-general.php' ) );

		// Request an access token.
		$response = wp_safe_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/oauth/token' ),
			array(
				'body'                => array(
					'client_id'     => $this->options['mastodon_client_id'],
					'client_secret' => $this->options['mastodon_client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => esc_url_raw( $redirect_uri ),
				),
				'timeout'             => 15,
				'limit_response_size' => 1048576,
			)
		);

		if ( is_wp_error( $response ) ) {
			debug_log( $response );
			return false;
		}

		$token = json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['mastodon_access_token'] = $token->access_token;

			// Update in database.
			$this->save();

			// @todo: This function **might** delete our token, we should take that into account somehow.
			$this->cron_verify_token(); // In order to get and store a username.

			return true;
		}

		debug_log( $response );

		return false;
	}

	/**
	 * Writes the current settings to the database.
	 *
	 * @param int $user_id (Optional) user ID.
	 */
	protected function save( $user_id = 0 ) {
		update_option( 'share_on_mastodon_settings', $this->options );
	}
}
