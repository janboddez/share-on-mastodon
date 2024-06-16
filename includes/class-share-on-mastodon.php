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
	const PLUGIN_VERSION = '0.18.0';

	/**
	 * This plugin's single instance.
	 *
	 * @since 0.5.0
	 *
	 * @var Share_On_Mastodon $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * `Plugin_Options` instance.
	 *
	 * @since 0.19.0
	 *
	 * @var Plugin_Options $instance `Plugin_Options` instance.
	 */
	private $plugin_options;

	/**
	 * `User_Options` instance.
	 *
	 * @since 0.19.0
	 *
	 * @var User_Options $instance `User_Options` instance.
	 */
	private $user_options;

	/**
	 * `Post_Handler` instance.
	 *
	 * @since 0.5.0
	 *
	 * @var Post_Handler $instance `Post_Handler` instance.
	 */
	private $post_handler;

	/**
	 * Returns the single instance of this class.
	 *
	 * @since 0.5.0
	 *
	 * @return Share_On_Mastodon Single class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Interacts with WordPress's Plugin API.
	 *
	 * @since 0.5.0
	 */
	public function register() {
		$this->plugin_options = new Plugin_Options();
		$this->plugin_options->register();

		if ( defined( 'SHARE_ON_MASTODON_MULTI_ACCOUNT' ) && SHARE_ON_MASTODON_MULTI_ACCOUNT ) {
			// Enable per-user client registration.
			$this->user_options = new User_Options();
			$this->user_options->register();
		}

		$this->post_handler = new Post_Handler();
		$this->post_handler->register();

		// Main plugin hooks.
		register_deactivation_hook( dirname( __DIR__ ) . '/share-on-mastodon.php', array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_cron' ) );

		$options = get_options();

		if ( ! empty( $options['micropub_compat'] ) ) {
			Micropub_Compat::register();
		}

		if ( ! empty( $options['syn_links_compat'] ) ) {
			Syn_Links_Compat::register();
		}

		Block_Editor::register();
	}

	/**
	 * Ensures cron job is scheduled.
	 *
	 * @since 0.13.0
	 */
	public function register_cron() {
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
		load_plugin_textdomain( 'share-on-mastodon', false, basename( dirname( __DIR__ ) ) . '/languages' );
	}

	/**
	 * Returns `Post_Handler` instance.
	 *
	 * @since 0.5.0
	 *
	 * @return Post_Handler This plugin's `Post_Handler` instance.
	 */
	public function get_post_handler() {
		return $this->post_handler;
	}

	/**
	 * Returns `Plugin_Options` instance.
	 *
	 * @since 0.19.0
	 *
	 * @return Plugin_Options This plugin's `Plugin_Options` instance.
	 */
	public function get_plugin_options() {
		return $this->plugin_options;
	}
}
