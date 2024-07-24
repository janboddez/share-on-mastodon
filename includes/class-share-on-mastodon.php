<?php
/**
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Main plugin class.
 */
class Share_On_Mastodon {
	const PLUGIN_VERSION = '0.19.1';
	const DB_VERSION     = '1';

	/**
	 * This plugin's single instance.
	 *
	 * @var Share_On_Mastodon $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * `Plugin_Options` instance.
	 *
	 * @var Plugin_Options $instance `Plugin_Options` instance.
	 */
	private $plugin_options;

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
		$this->plugin_options = new Plugin_Options();
		$this->plugin_options->register();

		$this->post_handler = new Post_Handler();
		$this->post_handler->register();

		// Main plugin hooks.
		register_deactivation_hook( dirname( __DIR__ ) . '/share-on-mastodon.php', array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ) );

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

		if ( get_option( 'share_on_mastodon_db_version' ) !== self::DB_VERSION ) {
			$this->migrate();
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
	 * Returns `Plugin_Options` instance.
	 *
	 * @return Plugin_Options This plugin's `Plugin_Options` instance.
	 */
	public function get_plugin_options() {
		return $this->plugin_options;
	}

	/**
	 * Returns `Plugin_Options` instance.
	 *
	 * @return Plugin_Options This plugin's `Plugin_Options` instance.
	 */
	public function get_options_handler() {
		_deprecated_function( __METHOD__, '0.19.0', '\Share_On_Mastodon\Share_On_Mastodon::get_plugin_options' );

		return $this->plugin_options;
	}

	/**
	 * Performs the necessary database migrations, if applicable.
	 */
	protected function migrate() {
		if ( ! function_exists( '\\dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		ob_start();
		include __DIR__ . '/database/schema.php';
		$sql = ob_get_clean();

		dbDelta( $sql );

		update_option( 'share_on_mastodon_db_version', self::DB_VERSION, 'no' );
	}
}
