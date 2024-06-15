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
class User_Options {
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
	);

	/**
	 * User options.
	 *
	 * @since 0.19.0
	 *
	 * @var array $options User options.
	 */
	private $options = array();

	/**
	 * User ID.
	 *
	 * @since 0.19.0
	 *
	 * @var int $user_id Current user ID.
	 */
	private $user_id = 0;

	/**
	 * Interacts with WordPress's Plugin API.
	 *
	 * @since 0.19.0
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_post_share_on_mastodon_update_user_meta', array( $this, 'admin_post' ) );
	}

	/**
	 * Registers the plugin settings page.
	 *
	 * @since 0.19.0
	 */
	public function create_menu() {
		// Can't put this in a constructor because `get_current_user_id()` would return `0` there.
		$options = get_user_meta( get_current_user_id(), 'share_on_mastodon_settings', true );

		$this->options = array_merge(
			static::get_default_options(),
			is_array( $options )
				? $options
				: array()
		);

		$this->user_id = get_current_user_id();

		add_users_page(
			__( 'Share on Mastodon', 'share-on-mastodon' ),
			__( 'Share on Mastodon', 'share-on-mastodon' ),
			'edit_posts',
			'share-on-mastodon',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Echoes the plugin options form. Handles the OAuth flow, too, for now.
	 *
	 * @since 0.19.0
	 */
	public function settings_page() {
		$user_id = get_current_user_id();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Share on Mastodon', 'share-on-mastodon' ); ?></h1>
			<form method="post" action="admin-post.php" novalidate="novalidate">
				<input type="hidden" name="action" value="share_on_mastodon_update_user_meta">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( "share-on-mastodon:users:{$user_id}" ) ); ?>">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>">
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

			<?php
			if ( ! empty( $this->options['mastodon_host'] ) ) {
				// A valid instance URL was set.
				if ( empty( $this->options['mastodon_client_id'] ) || empty( $this->options['mastodon_client_secret'] ) ) {
					// No app is currently registered. Let's try to fix that!
					Share_On_Mastodon::get_instance()
						->get_options_handler()
						->register_app( $this );
				}

				if ( ! empty( $this->options['mastodon_client_id'] ) && ! empty( $this->options['mastodon_client_secret'] ) ) {
					// An app was successfully registered.
					if ( ! empty( $_GET['code'] ) && '' === $this->options['mastodon_access_token'] ) {
						// Access token request.
						if (
							Share_On_Mastodon::get_instance()
								->get_options_handler()
								->request_access_token( wp_unslash( $_GET['code'] ), $this ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						) {
							?>
							<div class="notice notice-success is-dismissible">
								<p><?php esc_html_e( 'Access granted!', 'share-on-mastodon' ); ?></p>
							</div>
							<?php
						}
					}

					if ( isset( $_GET['action'] ) && 'revoke' === $_GET['action'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'share-on-mastodon-reset' ) ) {
						// Revoke access. Forget access token regardless of the
						// outcome.
						Share_On_Mastodon::get_instance()
							->get_options_handler()
							->revoke_access( $this );
					}

					if ( empty( $this->options['mastodon_access_token'] ) ) {
						// No access token exists. Echo authorization link.
						$url = $this->options['mastodon_host'] . '/oauth/authorize?' . http_build_query(
							array(
								'response_type' => 'code',
								'client_id'     => $this->options['mastodon_client_id'],
								'client_secret' => $this->options['mastodon_client_secret'],
								'redirect_uri'  => esc_url_raw(
									add_query_arg(
										array(
											'page' => 'share-on-mastodon',
										),
										current_user_can( 'list_users' )
											? admin_url( 'users.php' )
											: admin_url( 'profile.php' )
									)
								), // Redirect here after authorization.
								'scope'         => 'write:media write:statuses read:accounts read:statuses',
							)
						);
						?>
						<p><?php esc_html_e( 'Authorize WordPress to read and write to your Mastodon timeline in order to enable syndication.', 'share-on-mastodon' ); ?></p>
						<p style="margin-bottom: 2rem;"><?php printf( '<a href="%1$s" class="button">%2$s</a>', esc_url( $url ), esc_html__( 'Authorize Access', 'share-on-mastodon' ) ); ?>
						<?php
					} else {
						// An access token exists.
						?>
						<p><?php esc_html_e( 'You&rsquo;ve authorized WordPress to read and write to your Mastodon timeline.', 'share-on-mastodon' ); ?></p>
						<p style="margin-bottom: 2rem;">
							<?php
							printf(
								'<a href="%1$s" class="button">%2$s</a>',
								esc_url(
									add_query_arg(
										array(
											'page'     => 'share-on-mastodon',
											'action'   => 'revoke', // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.LongIndexSpaceBeforeDoubleArrow
											'_wpnonce' => wp_create_nonce( 'share-on-mastodon-reset' ),
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
		</div>
		<?php
	}

	/**
	 * `admin-post.php` callback.
	 *
	 * @since 0.3.1
	 */
	public function admin_post() {
		$redirect_url = add_query_arg(
			array(
				'page' => 'share-on-mastodon',
			),
			current_user_can( 'list_users' )
				? admin_url( 'users.php' )
				: admin_url( 'profile.php' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['user_id'] ) || ! ctype_digit( (string) $_POST['user_id'] ) ) {
			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$user_id = (int) $_POST['user_id'];

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), "share-on-mastodon:users:{$user_id}" ) ) {
			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		if ( ! user_can( $user_id, 'edit_posts' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			// Unsupported role.
			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$settings = ! empty( $_POST['share_on_mastodon_settings'] )
			? wp_unslash( $_POST['share_on_mastodon_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		if ( isset( $settings['mastodon_host'] ) ) {

			// Clean up and sanitize the user-submitted URL.
			$mastodon_host = Share_On_Mastodon::get_instance()
				->get_options_handler()
				->clean_url( $settings['mastodon_host'] );

			if ( '' === $mastodon_host ) {
				// Removing the instance URL. Might be done to temporarily
				// disable crossposting. Let's not revoke access just yet.
				$this->options['mastodon_host'] = '';
			} elseif ( wp_http_validate_url( $mastodon_host ) ) {
				if ( empty( $this->options['mastodon_host'] ) || $mastodon_host !== $this->options['mastodon_host'] ) {
					// Updated URL. (Try to) revoke access. Forget token
					// regardless of the outcome.
					Share_On_Mastodon::get_instance()
						->get_options_handler()
						->revoke_access( $this );

					// Then, save the new URL.
					$this->options['mastodon_host'] = esc_url_raw( $mastodon_host );

					// Forget client ID and secret. A new client ID and
					// secret will be requested next time the page loads.
					$this->options['mastodon_client_id']     = '';
					$this->options['mastodon_client_secret'] = '';
				}
			} else {
				// Not a valid URL. Display error message.
				// @todo: Don't think this works outside of settings pages!
				add_settings_error(
					'share-on-mastodon-mastodon-host',
					'invalid-url',
					esc_html__( 'Please provide a valid URL.', 'share-on-mastodon' )
				);
			}
		}

		update_user_meta( $user_id, 'share_on_mastodon_settings', $this->options );

		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	/**
	 * Returns the user ID.
	 *
	 * @since 0.19.0
	 *
	 * @return int $user_id User ID.
	 */
	public function get_user_id() {
		return $this->user_id;
	}

	/**
	 * Returns user options.
	 *
	 * @since 0.19.0
	 *
	 * @return array User options.
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Updates user options.
	 *
	 * @since 0.19.0
	 *
	 * @param array $options Updated user options.
	 */
	public function set_options( $options ) {
		$this->options = $options;
	}

	/**
	 * Returns the default user options.
	 *
	 * @since 0.19.0
	 *
	 * @return array Default user options.
	 */
	public static function get_default_options() {
		return array_combine( array_keys( self::SCHEMA ), array_column( self::SCHEMA, 'default' ) );
	}
}