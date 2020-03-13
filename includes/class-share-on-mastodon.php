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
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Register and handle plugin options.
		$options = ( new Options_Handler() )->get_options();

		// Post-related functions.
		$post_handler = Post_Handler::get_instance();
		$post_handler->init( $options );
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
