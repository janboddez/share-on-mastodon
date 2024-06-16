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
	 * Plugin option schema.
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
	);

	/**
	 * Plugin or user options.
	 *
	 * @since 0.1.0
	 *
	 * @var array $options Plugin or user options.
	 */
	protected $options = array();

	/**
	 * Registers a new Mastodon app (client).
	 *
	 * @since 0.1.0
	 */
	protected function register_app() {
		// @todo: Ensure this runs only once per host? Like, if we've previously
		// registered with one instance, we should probably reuse those details.
		if ( 'Plugin_Options' === $this->get_class_name() ) {
			// It doesn't make sense to look for existing client details in user
			// meta, as those clients will have a different redirect URI.
			// Update: Looks like Mastodon may now support multiple redirect
			// URIs (https://github.com/mastodon/mastodon/pull/29192).
			$redirect_url = add_query_arg( array( 'page' => 'share-on-mastodon' ), admin_url( 'options-general.php' ) );
		} else {
			// Here we *could* opt to, rather than always register a new client,
			// reuse known client details, but only if the host *and* redirect
			// URI match. Except they *might* be outdated! If so, what are we
			// going to do? Have user reset their settings, and then? How can we
			// tell the plugin to "force" registration and ignore existing known
			// hosts?
			$redirect_url = add_query_arg(
				array(
					'page' => 'share-on-mastodon-profile',
				),
				current_user_can( 'list_users' )
					? admin_url( 'users.php' )
					: admin_url( 'profile.php' )
			);
			// We *could* store this URL so that if a user gains (or loses) the
			// `list_users` capability, it is still possible to request a new
			// token. Although ... if they *lost* access to the `users.php` page
			// and it happened to be their previous redirect URI, that wouldn't
			// work ... Maybe we should just keep things as is and, should we
			// ever end up in this scenario, simply have them reset all settings
			// and start over.
		}

		$response = wp_safe_remote_post(
			esc_url_raw( $this->options['mastodon_host'] ) . '/api/v1/apps',
			array(
				'body'                => array(
					'client_name'   => apply_filters( 'share_on_mastodon_client_name', __( 'Share on Mastodon', 'share-on-mastodon' ) ),
					'redirect_uris' => esc_url_raw( $redirect_url ),
					'scopes'        => 'write:media write:statuses read:accounts read:statuses',
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
			// After successfully registering our app, store its keys.
			$this->options['mastodon_client_id']     = $app->client_id;
			$this->options['mastodon_client_secret'] = $app->client_secret;

			// Update in database.
			$this->save();
		} else {
			debug_log( $response );
		}
	}

	/**
	 * Requests a new access token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code Authorization code.
	 */
	protected function request_access_token( $code ) {
		if ( 'Plugin_Options' === $this->get_class_name() ) {
			$redirect_url = add_query_arg( array( 'page' => 'share-on-mastodon' ), admin_url( 'options-general.php' ) );
		} else {
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
			esc_url_raw( $this->options['mastodon_host'] ) . '/oauth/token',
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
		} else {
			debug_log( $response );
		}

		return false;
	}

	/**
	 * Revokes WordPress's access to Mastodon.
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
			esc_url_raw( $this->options['mastodon_host'] ) . '/oauth/revoke',
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
		} else {
			debug_log( $response );
		}

		// Something went wrong.
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
			esc_url_raw( $this->options['mastodon_host'] ) . '/api/v1/accounts/verify_credentials',
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

		// Store username. Isn't actually used, yet, but may very well be in the
		// near future.
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
	protected static function get_default_options() {
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
