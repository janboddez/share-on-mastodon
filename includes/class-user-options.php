<?php
/**
 * Handles per-user settings.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Options handler class.
 */
class User_Options extends Options_Handler {
	/**
	 * User option schema, for what it's worth.
	 */
	const SCHEMA = array(
		'mastodon_host'          => array(
			'type'    => 'string',
			'default' => '',
		),
		'mastodon_client_id'     => array(
			'type'    => 'string',
			'default' => '',
		),
		'mastodon_client_secret' => array(
			'type'    => 'string',
			'default' => '',
		),
		'mastodon_access_token'  => array(
			'type'    => 'string',
			'default' => '',
		),
		'mastodon_username'      => array(
			'type'    => 'string',
			'default' => '',
		),
		'mastodon_app_id'        => array(
			'type'    => 'integer',
			'default' => 0,
		),
	);

	/**
	 * Interacts with WordPress's Plugin API.
	 *
	 * @since 0.19.0
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_post_share_on_mastodon_update_user_meta', array( $this, 'update_user_meta' ) );
		add_action( 'admin_post_share_on_mastodon_reset_user_meta', array( $this, 'reset_user_meta' ) );
		// @todo: Somehow regularly verify all users' tokens.
		// add_action( 'share_on_mastodon_verify_token', array( $this, 'cron_verify_token' ) );

		add_filter( 'share_on_mastodon_options', array( $this, 'user_options' ), 10, 2 );
	}

	/**
	 * Registers the user settings page.
	 *
	 * @since 0.19.0
	 */
	public function create_menu() {
		// Can't put this in a constructor because `get_current_user_id()` would
		// return `0` there. Well, because of how we instantiate things.
		$options = get_user_meta( get_current_user_id(), 'share_on_mastodon_settings', true );

		$this->options = array_merge(
			static::get_default_options(),
			is_array( $options )
				? $options
				: array()
		);

		add_users_page(
			__( 'Share on Mastodon', 'share-on-mastodon' ),
			__( 'Share on Mastodon', 'share-on-mastodon' ),
			'edit_posts',
			'share-on-mastodon-profile', // Adding a `profile` suffix; if we didn't, WordPress' menu would act weird.
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Echoes the user options form. Handles the OAuth flow, too, for now.
	 *
	 * @since 0.19.0
	 */
	public function settings_page() {
		if ( 'invalid-url' === get_transient( 'share-on-mastodon:mastodon-host' ) ) :
			delete_transient( 'share-on-mastodon:mastodon-host' );
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Please provide a valid URL.', 'share-on-mastodon' ); ?></p>
			</div>
			<?php
		endif;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Share on Mastodon', 'share-on-mastodon' ); ?></h1>
			<form method="post" action="admin-post.php" novalidate="novalidate">
				<input type="hidden" name="action" value="share_on_mastodon_update_user_meta">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'share-on-mastodon:users:' . get_current_user_id() ) ); ?>">
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="share_on_mastodon_settings[mastodon_host]"><?php esc_html_e( 'Instance', 'share-on-mastodon' ); ?></label></th>
						<td><input type="url" id="share_on_mastodon_settings[mastodon_host]" name="share_on_mastodon_settings[mastodon_host]" style="min-width: 33%;" value="<?php echo esc_attr( ! empty( $this->options['mastodon_host'] ) ? $this->options['mastodon_host'] : '' ); ?>" />
						<?php /* translators: %s: example URL. */ ?>
						<p class="description"><?php printf( esc_html__( 'Your Mastodon instance&rsquo;s URL. E.g., %s.', 'share-on-mastodon' ), '<code>https://mastodon.online</code>' ); ?></p></td>
					</tr>
				</table>
				<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
			</form>

			<table class="form-table">
				<tr valign="top">
					<td style="padding-inline-start: 0;">
						<h2><?php esc_html_e( 'Authorize Access', 'share-on-mastodon' ); ?></h2>
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
									// Revoke access. Forget access token regardless of the
									// outcome.
									$this->revoke_access();
								}

								if ( empty( $this->options['mastodon_access_token'] ) ) {
									$redirect_url = add_query_arg(
										array(
											'page' => 'share-on-mastodon-profile',
										),
										current_user_can( 'list_users' )
											? admin_url( 'users.php' )
											: admin_url( 'profile.php' )
									);

									// No access token exists. Echo authorization link.
									$url = $this->options['mastodon_host'] . '/oauth/authorize?' . http_build_query(
										array(
											'response_type' => 'code',
											'client_id'     => $this->options['mastodon_client_id'], // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
											'client_secret' => $this->options['mastodon_client_secret'],
											'redirect_uri'  => esc_url_raw( $redirect_url ), // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
											'scope'         => ! empty( $this->options['mastodon_app_id'] ) // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
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
														'page'     => 'share-on-mastodon-profile',
														'action'   => 'revoke', // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.LongIndexSpaceBeforeDoubleArrow
														'_wpnonce' => wp_create_nonce( 'share-on-mastodon:token:revoke' ),
													),
													esc_url_raw(
														current_user_can( 'list_users' )
															? admin_url( 'users.php' )
															: admin_url( 'profile.php' )
													)
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
						?>
					</td>
				</tr>
			</table>

			<?php
			if ( ! empty( $this->options['mastodon_host'] ) ) :
				?>
				<fieldset>
					<legend><?php esc_html_e( 'Danger Zone', 'feed-reader' ); ?></legend>
					<div class="form-group">
						<p><?php esc_html_e( 'Just in case, this button lets you delete your Share on Mastodon settings. Note: This will not invalidate previously issued tokens! (You can, however, still invalidate them on your instance&rsquo;s &ldquo;Account &gt; Authorized apps&rdquo; page.)', 'share-on-mastodon' ); ?></p>
						<?php
						printf(
							'<a href="%1$s" class="button button-reset-settings" style="color: #a00; border-color: #a00;">%2$s</a>',
							esc_url(
								add_query_arg(
									array(
										'action'   => 'share_on_mastodon_reset_user_meta',
										'_wpnonce' => wp_create_nonce( 'share-on-mastodon:users:' . get_current_user_id() . ':reset' ),
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
			?>
			</div>
		<?php
	}

	/**
	 * Adds admin scripts and styles.
	 *
	 * @since 0.6.0
	 *
	 * @param string $hook_suffix Current WP-Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'users_page_share-on-mastodon-profile', 'profile_page_share-on-mastodon-profile' ), true ) ) {
			// Not our "Profile" screen.
			return;
		}

		// Enqueue CSS.
		wp_enqueue_style( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.css', __DIR__ ), array(), Share_On_Mastodon::PLUGIN_VERSION );
	}

	/**
	 * Updates user meta.
	 *
	 * @since 0.19.0
	 */
	public function update_user_meta() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You have insufficient permissions to access this page.', 'share-on-mastodon' ) );
			exit;
		}

		$redirect_url = add_query_arg(
			array(
				'page' => 'share-on-mastodon-profile',
			),
			current_user_can( 'list_users' )
				? admin_url( 'users.php' )
				: admin_url( 'profile.php' )
		);

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'share-on-mastodon:users:' . get_current_user_id() ) ) {
			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$settings = ! empty( $_POST['share_on_mastodon_settings'] )
			? wp_unslash( $_POST['share_on_mastodon_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		if ( isset( $settings['mastodon_host'] ) ) {
			// Clean up and sanitize the user-submitted URL.
			$mastodon_host = $this->clean_url( $settings['mastodon_host'] );

			if ( '' === $mastodon_host ) {
				// Removing the instance URL. Might be done to temporarily
				// disable crossposting. Let's not revoke access just yet.
				$this->options['mastodon_host'] = '';
			} elseif ( wp_http_validate_url( $mastodon_host ) ) {
				if ( empty( $this->options['mastodon_host'] ) || $mastodon_host !== $this->options['mastodon_host'] ) {
					// Updated URL. (Try to) revoke access. Forget token
					// regardless of the outcome.
					$this->revoke_access();

					// Then, save the new URL.
					$this->options['mastodon_host'] = esc_url_raw( $mastodon_host );

					// Forget client ID and secret. A new client ID and
					// secret will be requested next time the page loads.
					$this->options['mastodon_client_id']     = '';
					$this->options['mastodon_client_secret'] = '';
				}
			} else {
				// Since `add_settings_error()` doesn't seem to work here.
				set_transient( 'share-on-mastodon:mastodon-host', 'invalid-url', 5 );
			}
		}

		$this->save();

		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	/**
	 * Deletes user meta.
	 *
	 * @since 0.19.0
	 */
	public function reset_user_meta() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You have insufficient permissions to access this page.', 'share-on-mastodon' ) );
			exit;
		}

		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'share-on-mastodon:users:' . get_current_user_id() . ':reset' ) ) {
			// Reset all plugin settings.
			$this->options = static::get_default_options();
			$this->save();
		}

		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page' => 'share-on-mastodon-profile',
					),
					current_user_can( 'list_users' )
						? admin_url( 'users.php' )
						: admin_url( 'profile.php' )
				)
			)
		);
		exit;
	}

	/**
	 * Filters post options.
	 *
	 * @since 0.19.0
	 *
	 * @param  array $options Default options.
	 * @param  int   $user_id User ID.
	 * @return array          Filtered options.
	 */
	public function user_options( $options, $user_id ) {
		if ( ! empty( $user_id ) ) {
			$user_options = get_user_meta( $user_id, 'share_on_mastodon_settings', true );

			return array_merge(
				$options,
				is_array( $user_options )
					? $user_options
					: array()
			);
		}

		return $options;
	}
}
