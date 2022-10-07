<?php
/**
 * Handles WP Admin settings pages and the like.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Options handler class.
 */
class Options_Handler {
	/**
	 * Plugin options.
	 *
	 * @since 0.1.0
	 *
	 * @var array $options Plugin options.
	 */
	private $options = array(
		'mastodon_host'          => '',
		'mastodon_client_id'     => '',
		'mastodon_client_secret' => '',
		'mastodon_access_token'  => '',
		'post_types'             => array(),
		'mastodon_username'      => '',
		'delay_sharing'          => 0,
		'micropub_compat'        => false,
	);

	/**
	 * WordPress's default post types.
	 *
	 * @since 0.1.0
	 *
	 * @var array WordPress's default post types, minus "post" itself.
	 */
	const DEFAULT_POST_TYPES = array(
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'user_request',
		'oembed_cache',
		'wp_block',
		'wp_global_styles',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'jp_mem_plan',
		'jp_pay_order',
		'jp_pay_product',
		'coblocks_pattern',
		'genesis_custom_block',
	);

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->options = get_option(
			'share_on_mastodon_settings',
			$this->options
		);
	}

	/**
	 * Interacts with WordPress's Plugin API.
	 *
	 * @since 0.5.0
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_post_share_on_mastodon', array( $this, 'admin_post' ) );
	}

	/**
	 * Registers the plugin settings page.
	 *
	 * @since 0.1.0
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
	 *
	 * @since 0.1.0
	 */
	public function add_settings() {
		register_setting(
			'share-on-mastodon-settings-group',
			'share_on_mastodon_settings',
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Handles submitted options.
	 *
	 * @since 0.1.0
	 *
	 * @param array $settings Settings as submitted through WP Admin.
	 *
	 * @return array Options to be stored.
	 */
	public function sanitize_settings( $settings ) {
		$this->options['post_types'] = array();

		if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
			// Post types considered valid.
			$supported_post_types = array_diff(
				get_post_types(),
				self::DEFAULT_POST_TYPES
			);

			foreach ( $settings['post_types'] as $post_type ) {
				if ( in_array( $post_type, $supported_post_types, true ) ) {
					// Valid post type. Add to array.
					$this->options['post_types'][] = $post_type;
				}
			}
		}

		if ( isset( $settings['mastodon_host'] ) ) {
			$mastodon_host = untrailingslashit( trim( $settings['mastodon_host'] ) );

			if ( '' === $mastodon_host ) {
				// Removing the instance URL. Might be done to temporarily
				// disable crossposting. Let's not revoke access just yet.
				$this->options['mastodon_host'] = '';
			} else {
				if ( 0 !== strpos( $mastodon_host, 'https://' ) && 0 !== strpos( $mastodon_host, 'http://' ) ) {
					// Missing protocol. Try adding `https://`.
					$mastodon_host = 'https://' . $mastodon_host;
				}

				if ( wp_http_validate_url( $mastodon_host ) ) {
					if ( $mastodon_host !== $this->options['mastodon_host'] ) {
						// Updated URL.

						// (Try to) revoke access. Forget token regardless of the
						// outcome.
						$this->revoke_access();

						// Then, save the new URL.
						$this->options['mastodon_host'] = untrailingslashit( $mastodon_host );

						// Forget client ID and secret. A new client ID and secret will
						// be requested next time the page is loaded.
						$this->options['mastodon_client_id']     = '';
						$this->options['mastodon_client_secret'] = '';
					}
				} else {
					// Invalid URL. Display error message.
					add_settings_error(
						'share-on-mastodon-mastodon-host',
						'invalid-url',
						esc_html__( 'Please provide a valid URL.', 'share-on-mastodon' )
					);
				}
			}
		}

		$this->options['delay_sharing'] = 0;

		if ( isset( $settings['delay_sharing'] ) && ctype_digit( $settings['delay_sharing'] ) ) {
			$this->options['delay_sharing'] = (int) $settings['delay_sharing'];
		}

		$this->options['micropub_compat'] = isset( $settings['micropub_compat'] ) ? true : false;

		// Updated settings.
		return $this->options;
	}

	/**
	 * Echoes the plugin options form. Handles the OAuth flow, too, for now.
	 *
	 * @since 0.1.0
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Share on Mastodon', 'share-on-mastodon' ); ?></h1>

			<h2><?php esc_html_e( 'Settings', 'share-on-mastodon' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				// Print nonces and such.
				settings_fields( 'share-on-mastodon-settings-group' );

				// Post types considered valid.
				$supported_post_types = array_diff(
					get_post_types(),
					self::DEFAULT_POST_TYPES
				);
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="share_on_mastodon_settings[mastodon_host]"><?php esc_html_e( 'Instance', 'share-on-mastodon' ); ?></label></th>
						<td><input type="url" id="share_on_mastodon_settings[mastodon_host]" name="share_on_mastodon_settings[mastodon_host]" style="min-width: 33%;" value="<?php echo esc_attr( $this->options['mastodon_host'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Your Mastodon instance&rsquo;s URL.', 'share-on-mastodon' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Supported Post Types', 'share-on-mastodon' ); ?></th>
						<td><ul style="list-style: none; margin-top: 4px;">
							<?php
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
					<tr valign="top">
						<th scope="row"><label for="share_on_mastodon_settings[delay_sharing]"><?php esc_html_e( 'Delayed Sharing', 'share-on-mastodon' ); ?></label></th>
						<td><input type="number" id="share_on_mastodon_settings[delay_sharing]" name="share_on_mastodon_settings[delay_sharing]" value="<?php echo esc_attr( isset( $this->options['delay_sharing'] ) ? $this->options['delay_sharing'] : 0 ); ?>" />
						<p class="description"><?php esc_html_e( 'The time, in seconds, WordPress should delay sharing after a post is first published. (Setting this to, e.g., &ldquo;300&rdquo;&mdash;that&rsquo;s 5 minutes&mdash;might resolve issues with image uploads.)', 'share-on-mastodon' ); ?></p></td>
					</tr>
					<?php if ( class_exists( 'Micropub_Endpoint' ) ) : ?>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Micropub', 'share-on-mastodon' ); ?></label></th>
							<td><label><input type="checkbox" id="share_on_mastodon_settings[micropub_compat]" name="share_on_mastodon_settings[micropub_compat]" value="1" <?php checked( ! empty( $this->options['micropub_compat'] ) ); ?> /> <?php esc_html_e( 'Add syndication target', 'share-on-mastodon' ); ?></label>
							<p class="description"><?php esc_html_e( '(Experimental) Add &ldquo;Mastodon&rdquo; as a Micropub syndication target.', 'share-on-mastodon' ); ?></p></td>
						</tr>
					<?php endif; ?>
				</table>
				<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
			</form>

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
						if ( $this->request_access_token( wp_unslash( $_GET['code'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
						$this->revoke_access();
					}

					if ( empty( $this->options['mastodon_access_token'] ) ) {
						// No access token exists. Echo authorization link.
						$url = $this->options['mastodon_host'] . '/oauth/authorize?' . http_build_query(
							array(
								'response_type' => 'code',
								'client_id'     => $this->options['mastodon_client_id'],
								'client_secret' => $this->options['mastodon_client_secret'],
								'redirect_uri'  => add_query_arg(
									array(
										'page' => 'share-on-mastodon',
									),
									admin_url( 'options-general.php' )
								), // Redirect here after authorization.
								'scope'         => 'write:media write:statuses read:accounts read:statuses',
							)
						);
						?>
						<p><?php esc_html_e( 'Authorize WordPress to read and write to your Mastodon timeline in order to enable crossposting.', 'share-on-mastodon' ); ?></p>
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
											'action'   => 'revoke',
											'_wpnonce' => wp_create_nonce( 'share-on-mastodon-reset' ),
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
			?>

			<h2><?php esc_html_e( 'Debugging', 'share-on-mastodon' ); ?></h2>
			<p><?php esc_html_e( 'Just in case, below button lets you delete Share on Mastodon&rsquo;s settings. Note: This will not invalidate previously issued tokens! (You can, however, still invalidate them on your instance&rsquo;s &ldquo;Account &gt; Authorized apps&rdquo; page.)', 'share-on-mastodon' ); ?></p>
			<p style="margin-bottom: 2rem;">
				<?php
				printf(
					'<a href="%1$s" class="button button-reset-settings" style="color: #a00; border-color: #a00;">%2$s</a>',
					esc_url(
						add_query_arg(
							array(
								'action'   => 'share_on_mastodon',
								'reset'    => 'true',
								'_wpnonce' => wp_create_nonce( 'share-on-mastodon-reset' ),
							),
							admin_url( 'admin-post.php' )
						)
					),
					esc_html__( 'Reset Settings', 'share-on-mastodon' )
				);
				?>
			</p>
			<?php
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
				?>
				<p><?php esc_html_e( 'Below information is not meant to be shared with anyone but may help when troubleshooting issues.', 'share-on-mastodon' ); ?></p>
				<p><textarea class="widefat" rows="5"><?php print_r( $this->options ); ?></textarea></p><?php // phpcs:ignore WordPress.PHP.DevelopmentFunctions ?>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Loads (admin) scripts.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current WP-Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'settings_page_share-on-mastodon' !== $hook_suffix ) {
			// Not the "Share on Mastodon" settings page.
			return;
		}

		// Enqueue JS.
		wp_enqueue_script( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.js', dirname( __FILE__ ) ), array( 'jquery' ), '0.6.1', true );
		wp_localize_script(
			'share-on-mastodon',
			'share_on_mastodon_obj',
			array( 'message' => esc_attr__( 'Are you sure you want to reset all settings?', 'share-on-mastodon' ) ) // Confirmation message.
		);
	}

	/**
	 * Registers a new Mastodon app (client).
	 *
	 * @since 0.1.0
	 */
	private function register_app() {
		// Register a new app. Should probably only run once (per host).
		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] ) . '/api/v1/apps',
			array(
				'body' => array(
					'client_name'   => __( 'Share on Mastodon' ),
					'redirect_uris' => add_query_arg(
						array(
							'page' => 'share-on-mastodon',
						),
						admin_url(
							'options-general.php'
						)
					), // Allowed redirect URLs.
					'scopes'        => 'write:media write:statuses read:accounts read:statuses',
					'website'       => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		$app = json_decode( $response['body'] );

		if ( isset( $app->client_id ) && isset( $app->client_secret ) ) {
			// After successfully registering the App, store its keys.
			$this->options['mastodon_client_id']     = $app->client_id;
			$this->options['mastodon_client_secret'] = $app->client_secret;
			update_option( 'share_on_mastodon_settings', $this->options );
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Requests a new access token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code Authorization code.
	 */
	private function request_access_token( $code ) {
		// Request an access token.
		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] ) . '/oauth/token',
			array(
				'body' => array(
					'client_id'     => $this->options['mastodon_client_id'],
					'client_secret' => $this->options['mastodon_client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => add_query_arg(
						array(
							'page' => 'share-on-mastodon',
						),
						admin_url( 'options-general.php' )
					), // Redirect here after authorization.
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return false;
		}

		$token = json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['mastodon_access_token'] = $token->access_token;
			update_option( 'share_on_mastodon_settings', $this->options );

			$this->cron_verify_token(); // In order to get and store a username.
										// @todo: This function **might** delete
										// our token, we should take that into
										// account somehow.

			return true;
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		return false;
	}

	/**
	 * Revokes WordPress's access to Mastodon.
	 *
	 * @since 0.1.0
	 *
	 * @return boolean Whether access was revoked.
	 */
	private function revoke_access() {
		if ( empty( $this->options['mastodon_host'] ) ) {
			return false;
		}

		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return false;
		}

		if ( empty( $this->options['mastodon_client_id'] ) ) {
			return false;
		}

		if ( empty( $this->options['mastodon_client_secret'] ) ) {
			return false;
		}

		// Revoke access.
		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] ) . '/oauth/revoke',
			array(
				'body' => array(
					'client_id'     => $this->options['mastodon_client_id'],
					'client_secret' => $this->options['mastodon_client_secret'],
					'token'         => $this->options['mastodon_access_token'],
				),
			)
		);

		// Delete access token and username, regardless of the outcome.
		$this->options['mastodon_access_token'] = '';
		$this->options['mastodon_username']     = '';
		update_option( 'share_on_mastodon_settings', $this->options );

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return false;
		}

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			// If we were actually successful.
			return true;
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		// Something went wrong.
		return false;
	}

	/**
	 * Resets all plugin options.
	 *
	 * @since 0.3.1
	 */
	private function reset_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		/* @todo: Store defaults as a class constant. Currently, they're defined twice. */
		$this->options = array(
			'mastodon_host'          => '',
			'mastodon_client_id'     => '',
			'mastodon_client_secret' => '',
			'mastodon_access_token'  => '',
			'post_types'             => array(),
			'mastodon_username'      => '',
			'delay_sharing'          => 0,
		);

		update_option( 'share_on_mastodon_settings', $this->options );
	}

	/**
	 * `admin-post.php` callback.
	 *
	 * @since 0.3.1
	 */
	public function admin_post() {
		if ( isset( $_GET['reset'] ) && 'true' === $_GET['reset'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'share-on-mastodon-reset' ) ) {
			// Reset all of this plugin's settings.
			$this->reset_options();
		}

		wp_redirect( // phpcs:ignore WordPress.Security.SafeRedirect
			esc_url_raw(
				add_query_arg(
					array(
						'page' => 'share-on-mastodon',
					),
					admin_url( 'options-general.php' )
				)
			)
		);
		exit;
	}

	/**
	 * Verifies Share on Mastodon's token status.
	 *
	 * Normally runs once a day.
	 *
	 * @since 0.4.0
	 */
	public function cron_verify_token() {
		if ( empty( $this->options['mastodon_host'] ) ) {
			return;
		}

		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return;
		}

		// Verify the current access token.
		$response = wp_remote_get(
			esc_url_raw( $this->options['mastodon_host'] ) . '/api/v1/accounts/verify_credentials',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->options['mastodon_access_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		if ( in_array( wp_remote_retrieve_response_code( $response ), array( 401, 403 ), true ) ) {
			// The current access token has somehow become invalid. Forget it.
			$this->options['mastodon_access_token'] = '';
			update_option( 'share_on_mastodon_settings', $this->options );
			return;
		}

		// Store username. Isn't actually used, yet, but may very well be in the
		// near future.
		$account = json_decode( $response['body'] );

		if ( isset( $account->username ) ) {
			if ( empty( $this->options['mastodon_username'] ) || $account->username !== $this->options['mastodon_username'] ) {
				$this->options['mastodon_username'] = $account->username;
				update_option( 'share_on_mastodon_settings', $this->options );
			}
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Returns the plugin options.
	 *
	 * @since 0.3.0
	 *
	 * @return array Plugin options.
	 */
	public function get_options() {
		return $this->options;
	}
}
