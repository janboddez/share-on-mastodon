<?php
/**
 * Almost like a wrapper for API client functions. The `Plugin_Options` and
 * `User_Options` classes inherit from this (parent) class.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

class Options_Handler {
	/**
	 * All possible plugin options and their defaults.
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
		'post_types'             => array(
			'type'    => 'array',
			'default' => array( 'post' ),
			'items'   => array( 'type' => 'string' ),
		),
		'featured_images'        => array(
			'type'    => 'boolean',
			'default' => true,
		),
		'attached_images'        => array(
			'type'    => 'boolean',
			'default' => true,
		),
		'referenced_images'      => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'max_images'             => array(
			'type'    => 'integer',
			'default' => 4,
		),
		'optin'                  => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'share_always'           => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'delay_sharing'          => array(
			'type'    => 'integer',
			'default' => 0,
		),
		'micropub_compat'        => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'syn_links_compat'       => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'debug_logging'          => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'custom_status_field'    => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'status_template'        => array(
			'type'    => 'string',
			'default' => '%title% %permalink%',
		),
		'meta_box'               => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'mastodon_app_id'        => array(
			'type'    => 'integer',
			'default' => 0,
		),
	);

	/**
	 * Plugin or user options.
	 *
	 * @var array $options Plugin or user options.
	 */
	protected $options = array();

	/**
	 * Registers a new Mastodon app (client).
	 */
	protected function register_app() {
		// As of v0.19.0, we keep track of known instances, and reuse client IDs
		// and secrets, rather then register as a "new" client for each and
		// every user. Caveat: To ensure "old" registrations' validity, we use
		// an "app token." *Should* an app token ever get revoked, we'll have to
		// re-register after all.
		$apps = Mastodon_Client::find( array( 'host' => $this->options['mastodon_host'] ) );

		if ( ! empty( $apps ) ) {
			foreach ( $apps as $app ) {
				if ( empty( $app->client_id ) || empty( $app->client_secret ) ) {
					// Don't bother.
					continue;
				}

				// @todo: Aren't we being overly cautious here? Does Mastodon "scrap" old registrations?
				if ( $this->verify_client_token( $app ) || $this->request_client_token( $app->id ) ) {
					debug_log( "[Share On Mastodon] Found an existing app (ID: {$app->id}) for host {$this->options['mastodon_host']}." );

					// @todo: Should we store *only* a reference to the clients table?
					$this->options['mastodon_app_id']        = $app->id;
					$this->options['mastodon_client_id']     = $app->client_id;
					$this->options['mastodon_client_secret'] = $app->client_secret;

					$this->save();

					// All done!
					return;
				}
			}
		}

		debug_log( "[Share On Mastodon] Registering a new app for host {$this->options['mastodon_host']}." );

		// It's possible to register multiple redirect URIs. Which one we'll use depends on the plugin settings and,
		// possibly, user role.
		$redirect_urls = array(
			add_query_arg( array( 'page' => 'share-on-mastodon' ), admin_url( 'options-general.php' ) ),
			add_query_arg( array( 'page' => 'share-on-mastodon-profile' ), admin_url( 'users.php' ) ),
			add_query_arg( array( 'page' => 'share-on-mastodon-profile' ), admin_url( 'profile.php' ) ),
		);

		$response = wp_safe_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/apps' ),
			array(
				'body'                => array(
					'client_name'   => apply_filters( 'share_on_mastodon_client_name', __( 'Share on Mastodon', 'share-on-mastodon' ) ),
					'scopes'        => 'read write:media write:statuses',
					'redirect_uris' => implode( ' ', $redirect_urls ),
					'website'       => home_url(),
				),
				'timeout'             => 15,
				'limit_response_size' => 1048576,
			)
		);

		if ( is_wp_error( $response ) ) {
			debug_log( $response );
			return;
		}

		$app = json_decode( $response['body'] );

		if ( isset( $app->client_id ) && isset( $app->client_secret ) ) {
			// After successfully registering our app, store its details.
			$app_id = Mastodon_Client::insert(
				array_filter(
					array(
						'host'          => $this->options['mastodon_host'],
						'client_name'   => apply_filters( 'share_on_mastodon_client_name', __( 'Share on Mastodon', 'share-on-mastodon' ) ),
						'website'       => home_url(),
						'scopes'        => 'read write:media write:statuses',
						'redirect_uris' => implode( ' ', $redirect_urls ),
						'client_id'     => $app->client_id,
						'client_secret' => $app->client_secret,
						'vapid_key'     => isset( $app->vapid_key ) ? $app->vapid_key : null,
					)
				)
			);

			// Store in either plugin or user options, too.
			$this->options['mastodon_app_id']        = $app->id;
			$this->options['mastodon_client_id']     = $app->client_id;
			$this->options['mastodon_client_secret'] = $app->client_secret;

			// Update in database.
			$this->save();

			// Fetch client token. This we'll only use in case someone were to use this same instance in the future.
			$this->request_client_token( $app_id );

			return;
		}

		debug_log( $response );
	}

	protected function request_client_token( $app_id ) {
		debug_log( "[Share On Mastodon] Requesting app (ID: {$app_id}) token (for host {$this->options['mastodon_host']})." );

		$response = wp_safe_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/oauth/token' ),
			array(
				'body'                => array(
					'client_id'     => $this->options['mastodon_client_id'],
					'client_secret' => $this->options['mastodon_client_secret'],
					'grant_type'    => 'client_credentials',
					'redirect_uri'  => 'urn:ietf:wg:oauth:2.0:oob', // This seems to work. I.e., one doesn't *have* to use a redirect URI for requesting app tokens.
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
			// Note: It surely looks like only one app token is given out, ever. Failing to save it here won't lead to
			// an unusable app; it'll only lead to a new registration for the next user that enters this instance, which
			// in itself does not invalidate other registrations, so we should be okay here.
			Mastodon_Client::update(
				array( 'client_token' => $token->access_token ),
				array( 'id' => $app_id )
			);

			return true;
		}

		debug_log( $response );

		return false;
	}

	public function verify_client_token( $app ) {
		debug_log( "[Share On Mastodon] Verifying app (ID: {$app->id}) token (for host {$this->options['mastodon_host']})." );

		if ( empty( $app->host ) ) {
			return false;
		}

		if ( empty( $app->client_token ) ) {
			return false;
		}

		// Verify the current client token.
		$response = wp_safe_remote_get(
			esc_url_raw( $app->host . '/api/v1/apps/verify_credentials' ),
			array(
				'headers'             => array(
					'Authorization' => 'Bearer ' . $app->client_token,
				),
				'timeout'             => 15,
				'limit_response_size' => 1048576,
			)
		);

		if ( is_wp_error( $response ) ) {
			debug_log( $response );
			return false;
		}

		if ( in_array( wp_remote_retrieve_response_code( $response ), array( 401, 403 ), true ) ) {
			// The current client token has somehow become invalid.
			return false;
		}

		$client = json_decode( $response['body'] );

		if ( isset( $client->name ) ) {
			return true;
		}

		// Something went wrong.
		debug_log( $response );

		return false;
	}

	/**
	 * Requests a new user token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code Authorization code.
	 */
	protected function request_user_token( $code ) {
		if ( 'Plugin_Options' === $this->get_class_name() ) {
			// Site-wide crossposting.
			$redirect_url = add_query_arg( array( 'page' => 'share-on-mastodon' ), admin_url( 'options-general.php' ) );
		} else {
			// Per-user crossposting.
			$redirect_url = add_query_arg(
				array(
					'page' => 'share-on-mastodon-profile',
				),
				current_user_can( 'list_users' )
					? admin_url( 'users.php' )
					: admin_url( 'profile.php' )
			);
		}

		// Request an access token.
		$response = wp_safe_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/oauth/token' ),
			array(
				'body'                => array(
					'client_id'     => $this->options['mastodon_client_id'],
					'client_secret' => $this->options['mastodon_client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => esc_url_raw( $redirect_url ),
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

			if ( 'Plugin_Options' === $this->get_class_name() ) {
				$this->cron_verify_token(); // In order to get and store a username.
				// @todo: This function **might** delete our token, we should take that into account somehow.
			} else {
				$this->cron_verify_token( get_current_user_id() );
			}

			return true;
		}

		debug_log( $response );

		return false;
	}

	/**
	 * Revokes WordPress's (or a single user's) access to Mastodon.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Whether access was revoked.
	 */
	protected function revoke_access() {
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
		$response = wp_safe_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/oauth/revoke' ),
			array(
				'body'                => array(
					'client_id'     => $this->options['mastodon_client_id'],
					'client_secret' => $this->options['mastodon_client_secret'],
					'token'         => $this->options['mastodon_access_token'],
				),
				'timeout'             => 15,
				'limit_response_size' => 1048576,
			)
		);

		// Delete access token and username, regardless of the outcome.
		$this->options['mastodon_access_token'] = '';
		$this->options['mastodon_username']     = '';

		// Update in database.
		$this->save();

		if ( is_wp_error( $response ) ) {
			debug_log( $response );
			return false;
		}

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			// If we were actually successful.
			return true;
		}

		// Something went wrong.
		debug_log( $response );

		return false;
	}

	/**
	 * Verifies Share on Mastodon's token status.
	 *
	 * Normally runs once a day.
	 *
	 * @since 0.4.0
	 *
	 * @param $int $user_id (Optional, when not run as a cron job) user ID.
	 */
	public function cron_verify_token( $user_id = 0 ) {
		if ( empty( $this->options['mastodon_host'] ) ) {
			return;
		}

		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return;
		}

		// Verify the current access token.
		$response = wp_safe_remote_get(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/accounts/verify_credentials' ),
			array(
				'headers'             => array(
					'Authorization' => 'Bearer ' . $this->options['mastodon_access_token'],
				),
				'timeout'             => 15,
				'limit_response_size' => 1048576,
			)
		);

		if ( is_wp_error( $response ) ) {
			debug_log( $response );
			return;
		}

		if ( in_array( wp_remote_retrieve_response_code( $response ), array( 401, 403 ), true ) ) {
			// The current access token has somehow become invalid. Forget it.
			$this->options['mastodon_access_token'] = '';

			// Store in database.
			$this->save( $user_id );

			return;
		}

		// Store username. Isn't actually used, yet, but may very well be in the near future.
		$account = json_decode( $response['body'] );

		if ( isset( $account->username ) ) {
			if ( empty( $this->options['mastodon_username'] ) || $account->username !== $this->options['mastodon_username'] ) {
				$this->options['mastodon_username'] = $account->username;

				// Update in database.
				$this->save( $user_id );
			}
		} else {
			debug_log( $response );
		}
	}

	/**
	 * Returns either the plugin or the user options.
	 *
	 * @since 0.3.0
	 *
	 * @return array Plugin options.
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Returns the default plugin options.
	 *
	 * @since 0.17.0
	 *
	 * @return array Default options.
	 */
	public static function get_default_options() {
		return array_combine( array_keys( static::SCHEMA ), array_column( static::SCHEMA, 'default' ) );
	}

	/**
	 * Preps user-submitted instance URLs for validation.
	 *
	 * @since 0.11.0
	 *
	 * @param  string $url Input URL.
	 * @return string      Sanitized URL, or an empty string on failure.
	 */
	protected function clean_url( $url ) {
		$url = untrailingslashit( trim( $url ) );

		// So, it looks like `wp_parse_url()` always expects a protocol.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		if ( 0 !== strpos( $url, 'https://' ) && 0 !== strpos( $url, 'http://' ) ) {
			$url = 'https://' . $url;
		}

		// Take apart, then reassemble the URL, and drop anything (a path, query
		// string, etc.) beyond the host.
		$parsed_url = wp_parse_url( $url );

		if ( empty( $parsed_url['host'] ) ) {
			// Invalid URL.
			return '';
		}

		if ( ! empty( $parsed_url['scheme'] ) ) {
			$url = $parsed_url['scheme'] . ':';
		} else {
			$url = 'https:';
		}

		$url .= '//' . $parsed_url['host'];

		if ( ! empty( $parsed_url['port'] ) ) {
			$url .= ':' . $parsed_url['port'];
		}

		return sanitize_url( $url );
	}

	/**
	 * Writes the current settings to the database.
	 *
	 * Depending on the caller, will save to `wp_options` or (under either the
	 * specified user, or the one currently logged in) `wp_usermeta`.
	 *
	 * @since 0.19.0
	 *
	 * @param int $user_id (Optional) user ID.
	 */
	protected function save( $user_id = 0 ) {
		if ( 'Plugin_Options' === $this->get_class_name() ) {
			update_option( 'share_on_mastodon_settings', $this->options );
		} elseif ( 0 !== $user_id ) {
			update_user_meta( $user_id, 'share_on_mastodon_settings', $this->options );
		} else {
			update_user_meta( get_current_user_id(), 'share_on_mastodon_settings', $this->options );
		}
	}

	/**
	 * Returns the current (child) class' basename.
	 *
	 * @since 0.19.0
	 */
	protected function get_class_name() {
		return ( new \ReflectionClass( $this ) )->getShortName();
	}
}
