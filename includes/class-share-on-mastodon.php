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
	const PLUGIN_VERSION = '0.17.0';

	/**
	 * This plugin's single instance.
	 *
	 * @since 0.5.0
	 *
	 * @var Share_On_Mastodon $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * `Options_Handler` instance.
	 *
	 * @since 0.5.0
	 *
	 * @var Options_Handler $instance `Options_Handler` instance.
	 */
	private $options_handler;

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
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->options_handler = new Options_Handler();
		$this->options_handler->register();

		$this->post_handler = new Post_Handler( $this->options_handler->get_options() );
		$this->post_handler->register();

		Block_Editor::register();
	}

	/**
	 * Interacts with WordPress's Plugin API.
	 *
	 * @since 0.5.0
	 */
	public function register() {
		register_deactivation_hook( dirname( dirname( __FILE__ ) ) . '/share-on-mastodon.php', array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_cron' ) );
		add_action( 'share_on_mastodon_verify_token', array( $this->options_handler, 'cron_verify_token' ) );

		$options = get_options();

		if ( ! empty( $options['micropub_compat'] ) ) {
			Micropub_Compat::register();
		}

		if ( ! empty( $options['syn_links_compat'] ) ) {
			Syn_Links_Compat::register();
		}
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
		load_plugin_textdomain( 'share-on-mastodon', false, basename( dirname( dirname( __FILE__ ) ) ) . '/languages' );
	}

	/**
	 * Returns `Options_Handler` instance.
	 *
	 * @since 0.5.0
	 *
	 * @return Options_Handler This plugin's `Options_Handler` instance.
	 */
	public function get_options_handler() {
		return $this->options_handler;
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
}
