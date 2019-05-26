<?php

namespace Share_On_Mastodon;

class Options_Handler {
	/**
	 * Plugin options.
	 */
	private $options = array();

	/**
	 * WordPress' default post types, minus 'post' itself.
	 */
	private $default_post_types = array(
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'user_request',
		'oembed_cache',
		'wp_block',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$default_options = array(
			'mastodon_host' => '',
			'mastodon_client_id' => '',
			'mastodon_client_secret' => '',
			'mastodon_access_token' => '',
			'post_types' => array(),
		);
		$this->options = get_option( 'share_on_mastodon_settings', $default_options );

		add_action( 'admin_menu', array( $this, 'create_menu' ) );
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
		register_setting(
			'share-on-mastodon-settings-group',
			'share_on_mastodon_settings',
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Handles submitted options.
	 *
	 * @param array $settings Settings as submitted through WP Admin.
	 * @return array Options to be stored.
	 */
	public function sanitize_settings( $settings ) {
		$this->options['post_types'] = array();

		if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {

			// Post types considered valid.
			$supported_post_types = array_diff(
				get_post_types(),
				$this->default_post_types
			);

			foreach ( $settings['post_types'] as $post_type ) {
				if ( in_array( $post_type, $supported_post_types ) ) {
					// Valid post type. Add to array.
					$this->options['post_types'][] = $post_type;
				}
			}
		}

		if ( isset( $settings['mastodon_host'] ) ) {
			if ( $this->options['mastodon_host'] !== untrailingslashit( $settings['mastodon_host'] ) && wp_http_validate_url( $settings['mastodon_host'] ) ) {
				// The new URL differs from the old one. Someone's switched
				// instances.
				if ( $this->_revoke_access() ) {
					// Update instance URL, forget client ID and secret. A new
					// client ID and secret will be requested the next time this
					// page is visited.
					$this->options['mastodon_host'] = untrailingslashit( $settings['mastodon_host'] );
					$this->options['mastodon_client_id'] = '';
					$this->options['mastodon_client_secret'] = '';
				} elseif ( '' === $this->options['mastodon_host'] ) {
					// First time instance's set?
					$this->options['mastodon_host'] = untrailingslashit( $settings['mastodon_host'] );
				}
			} elseif ( '' === $settings['mastodon_host'] ) {
				// Assuming sharing should be disabled.
				$this->options['mastodon_host'] = '';
				$this->_revoke_access();
			}
		}

		// Sanitized settings.
		return $this->options;
	}

	/**
	 * Echoes the plugin options form. Handles the OAuth flow, too, for now.
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php _e( 'Share on Mastodon', 'share-on-mastodon' ); ?></h1>

			<h2><?php _e( 'Settings', 'share-on-mastodon' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				// Print nonces and such.
				settings_fields( 'share-on-mastodon-settings-group' );

				// Post types considered valid.
				$supported_post_types = array_diff(
					get_post_types(),
					$this->default_post_types
				);
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="share_on_mastodon_settings[mastodon_host]"><?php _e( 'Instance', 'share-on-mastodon' ); ?></label></th>
						<td><input type="text" id="share_on_mastodon_settings[mastodon_host]" name="share_on_mastodon_settings[mastodon_host]" style="min-width: 33%;" value="<?php echo esc_attr( $this->options['mastodon_host'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Your Mastodon instance&rsquo;s URL.', 'share-on-mastodon' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e( 'Supported Post Types', 'share-on-mastodon' ); ?></th>
						<td><ul style="list-style: none; margin-top: 4px;">
							<?php foreach( $supported_post_types as $post_type ) : ?>
								<li><label><input type="checkbox" name="share_on_mastodon_settings[post_types][]" value="<?php $post_type = get_post_type_object( $post_type ); esc_attr_e( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $this->options['post_types'] ) ); ?>><?php echo esc_html( $post_type->labels->singular_name ); ?></label></li>
							<?php endforeach; ?>
						</ul>
						<p class="description"><?php esc_html_e( 'Post types for which sharing to Mastodon is possible. (Sharing can still be disabled on a per-post basis.)', 'share-on-mastodon' ); ?></p></td>
					</tr>
				</table>
				<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
			</form>

			<h2><?php _e( 'Authorize Access', 'share-on-mastodon' ); ?></h2>
			<?php
			if ( ! empty( $this->options['mastodon_host'] ) ) {
				// A valid instance URL was set.
				if ( empty( $this->options['mastodon_client_id'] ) || empty( $this->options['mastodon_client_secret'] ) ) {
					// No app is currently registered. Let's try to fix that!
					$this->_register_app();
				}

				if ( ! empty( $this->options['mastodon_client_id'] ) && ! empty( $this->options['mastodon_client_secret'] ) ) {
					// An app was successfully registered.
					if ( ! empty( $_GET['code'] ) ) {
						// Access token request.
						if ( $this->_request_access_token( sanitize_text_field( $_GET['code'] ) ) ) {
							?>
							<div class="notice notice-success is-dismissible">
								<p><?php esc_html_e( 'Access granted!', 'share-on-mastodon' ); ?></p>
							</div>
							<?php
						}
					}

					if ( ! empty( $_GET['action'] ) && 'revoke' === $_GET['action'] && wp_verify_nonce( $_GET['_wpnonce'], basename( __FILE__ ) ) ) {
						// Request to revoke access.
						$this->_revoke_access();
					}

					if ( empty( $this->options['mastodon_access_token'] ) ) {
						// No access token exists. Echo authorization link.
						$url = $this->options['mastodon_host'] . '/oauth/authorize?' . http_build_query( array(
							'response_type' => 'code',
							'client_id' => $this->options['mastodon_client_id'],
							'client_secret' => $this->options['mastodon_client_secret'],
							'redirect_uri' => admin_url( 'options-general.php?page=share-on-mastodon' ), // Redirect here after authorization.
							'scope' => 'write:media write:statuses read:accounts read:statuses',
						) );
						?>
						<p><?php esc_html_e( 'Authorize WordPress to read and write to your Mastodon timeline in order to enable crossposting.', 'share-on-mastodon' ); ?></p>
						<p><?php printf( '<a href="%1$s" class="button">%2$s</a>', esc_url( $url ), __( 'Authorize Access', 'share-on-mastodon' ) ); ?>
						<?php
					} else {
						// An access token exists.
						$nonce = wp_create_nonce( basename( __FILE__ ) );
						?>
						<p><?php esc_html_e( 'You&rsquo;ve authorized WordPress to read and write to your Mastodon timeline.', 'share-on-mastodon' ); ?></p>
						<p class="submit"><?php printf( '<a href="%1$s" class="button">%2$s</a>', esc_url( admin_url( 'options-general.php?page=share-on-mastodon&action=revoke&_wpnonce=' . $nonce ) ), __( 'Revoke Access', 'share-on-mastodon' ) ); ?>
						<?php
					}
				} else {
					// Still couldn't register our app.
					?>
					<p><?php _e( 'Something went wrong contacting your Mastodon instance. Please reload this page to try again.', 'share-on-mastodon' ); ?></p>
					<?php
				}
			} else {
				// We can't do much without an instance URL.
				?>
				<p><?php esc_html_e( 'Please fill out and save your Mastodon instance&rsquo;s URL first.', 'share-on-mastodon' ); ?></p>
				<?php
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
				?>
				<h2><?php esc_html_e( 'Debugging', 'share-on-mastodon' ); ?></h2>
				<p><?php esc_html_e( 'Below information is not meant to be shared with anyone but may help when troubleshooting issues.', 'share-on-mastodon' ); ?></p>
				<p><textarea class="widefat" rows="5"><?php print_r( $this->options ); ?></textarea></p>
				<?php
			}
			?>
		</div>
		<?php
	}

	private function _register_app() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Register a new app. Should only run once (per host)!
		$response = wp_remote_post( esc_url_raw( $this->options['mastodon_host'] ) . '/api/v1/apps', array(
			'body' => array(
				'client_name' => 'Share to Mastodon',
				'redirect_uris' => admin_url( 'options-general.php?page=share-on-mastodon' ), // Allowed redirect URLs.
				'scopes' => 'write:media write:statuses read:accounts read:statuses',
				'website' => home_url(),
			),
		) );

		// Todo: error handling, etc.
		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) );
			return;
		}

		$app = @json_decode( $response['body'] );

		if ( isset( $app->client_id ) && isset( $app->client_secret ) ) {
			// After successfully registering the App, store its keys.
			$this->options['mastodon_client_id'] = sanitize_text_field( $app->client_id );
			$this->options['mastodon_client_secret'] = sanitize_text_field( $app->client_secret );
			update_option( 'share_on_mastodon_settings', $this->options );
		} else {
			error_log( print_r( $response, true ) );
		}
	}

	private function _request_access_token( $code ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Request an access token.
		$response = wp_remote_post( esc_url_raw( $this->options['mastodon_host'] ) . '/oauth/token', array(
			'body' => array(
				'client_id' => $this->options['mastodon_client_id'],
				'client_secret' => $this->options['mastodon_client_secret'],
				'grant_type' => 'authorization_code',
				'code' => $code,
				'redirect_uri' => admin_url( 'options-general.php?page=share-on-mastodon' ), // Redirect here after authorization.
			),
		) );

		// Todo: error handling, etc.
		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) );

			return false;
		}

		$token = @json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['mastodon_access_token'] = $token->access_token;
			update_option( 'share_on_mastodon_settings', $this->options );

			return true;
		} else {
			error_log( print_r( $response, true ) );
		}

		return false;
	}

	/**
	 * Revokes WordPress' access to Mastodon.
	 *
	 * @return boolean If access was revoked.
	 */
	private function _revoke_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( empty( $this->options['mastodon_host'] ) ) {
			return false;
		}

		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return false;
		}

		if (  empty( $this->options['mastodon_client_id'] ) ) {
			return false;
		}

		if (  empty( $this->options['mastodon_client_secret'] ) ) {
			return false;
		}

		// Revoke access.
		$response = wp_remote_post( esc_url_raw( $this->options['mastodon_host'] ) . '/oauth/revoke', array(
			'body' => array(
				'client_id' => $this->options['mastodon_client_id'],
				'client_secret' => $this->options['mastodon_client_secret'],
				'token' => $this->options['mastodon_access_token'],
			),
		) );

		// Todo: error handling, etc.
		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) );

			return false;
		}

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			// Success. Delete access token.
			$this->options['mastodon_access_token'] = '';
			update_option( 'share_on_mastodon_settings', $this->options );

			return true;
		} else {
			error_log( print_r( $response, true ) );
		}

		// Something went wrong.
		return false;
	}
}
