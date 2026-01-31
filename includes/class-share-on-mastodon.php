<?php
/**
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Main plugin class.
 */
class Share_On_Mastodon {
	const PLUGIN_VERSION = '0.20.1';
	const DB_VERSION     = '2';

	/**
	 * This plugin's single instance.
	 *
	 * @var Share_On_Mastodon $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * `Options_Handler` instance.
	 *
	 * @var Options_Handler $instance `Options_Handler` instance.
	 */
	private $options_handler;

	/**
	 * `Post_Handler` instance.
	 *
	 * @var Post_Handler $instance `Post_Handler` instance.
	 */
	private $post_handler;

	/**
	 * Returns the single instance of this class.
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
	 */
	public function register() {
		$this->options_handler = new Options_Handler();
		$this->options_handler->register();

		$this->post_handler = new Post_Handler();
		$this->post_handler->register();

		// Main plugin hooks.
		register_deactivation_hook( dirname( __DIR__ ) . '/share-on-mastodon.php', array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'wp_loaded', array( $this, 'init' ) );

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
	 * Ensures cron job is scheduled, and, if needed, kicks off database migrations.
	 */
	public function init() {
		// Schedule a daily cron job.
		if ( false === wp_next_scheduled( 'share_on_mastodon_verify_token' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'share_on_mastodon_verify_token' );
		}

		if ( self::DB_VERSION !== get_option( 'share_on_mastodon_db_version' ) ) {
			$this->migrate();
			update_option( 'share_on_mastodon_db_version', self::DB_VERSION, true );
		}
	}

	/**
	 * Runs on deactivation.
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'share_on_mastodon_verify_token' );
	}

	/**
	 * Enables localization.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'share-on-mastodon', false, basename( dirname( __DIR__ ) ) . '/languages' );
	}

	/**
	 * Returns `Post_Handler` instance.
	 *
	 * @return Post_Handler This plugin's `Post_Handler` instance.
	 */
	public function get_post_handler() {
		return $this->post_handler;
	}

	/**
	 * Returns `Options_Handler` instance.
	 *
	 * @return Options_Handler This plugin's `Options_Handler` instance.
	 */
	public function get_plugin_options() {
		return $this->options_handler;
	}

	/**
	 * Returns `Options_Handler` instance.
	 *
	 * @return Options_Handler This plugin's `Options_Handler` instance.
	 */
	public function get_options_handler() {
		return $this->options_handler;
	}

	/**
	 * Performs the necessary database migrations, if applicable.
	 *
	 * We no longer aim to eventually support multiple instances/accounts, so as of v0.20.0, back to basics it is.
	 */
	protected function migrate() {
		global $wpdb;

		debug_log( '[Share on Mastodon] Running migrations.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'share_on_mastodon_clients' );
	}
}
