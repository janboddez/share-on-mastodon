<?php
/**
 * Main plugin class.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Main plugin class.
 */
class Share_On_Mastodon {
	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		register_activation_hook( dirname( dirname( __FILE__ ) ) . '/share-on-mastodon.php', array( $this, 'activate' ) );
		register_deactivation_hook( dirname( dirname( __FILE__ ) ) . '/share-on-mastodon.php', array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'share_on_mastodon_verify_token', array( Options_Handler::get_instance(), 'cron_verify_token' ) );

		$post_handler = Post_Handler::get_instance();
	}

	/**
	 * Runs on activation.
	 *
	 * @since 0.4.0
	 */
	public function activate() {
		// Schedule a daily cron job.
		if ( false === wp_next_scheduled( 'share_on_mastodon_verify_token' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'share_on_mastodon_verify_token' );
		}
	}

	/**
	 * Runs on deactivation.
	 *
	 * @since 0.4.0
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'share_on_mastodon_verify_token' );
	}

	/**
	 * Enables localization.
	 *
	 * @since 0.1.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'share-on-mastodon', false, basename( dirname( dirname( __FILE__ ) ) ) . '/languages' );
	}
}
