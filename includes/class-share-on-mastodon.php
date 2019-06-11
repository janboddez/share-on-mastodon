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
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'create_settings_link' ) );

		// Register and handle plugin options.
		new Options_Handler();

		// Post-related functions.
		new Post_Handler();
	}

	/**
	 * Enables localization.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'share-on-mastodon', false, basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Adds a 'Settings' link on the Plugins page.
	 *
	 * @param  array $links Links array.
	 * @return array        Modified links array.
	 */
	public function create_settings_link( $links ) {
		return array_merge(
			array( '<a href="' . esc_url( admin_url( 'options-general.php?page=share-on-mastodon' ) ) . '">' . __( 'Settings', 'share-on-mastodon' ) . '</a>' ),
			$links
		);
	}
}
