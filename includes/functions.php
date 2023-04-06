<?php
/**
 * Helper functions.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Writes to WordPress' debug log.
 *
 * @param mixed $item Thing to log.
 */
function debug_log( $item ) {
	$options = get_options();

	if ( empty( $options['debug_logging'] ) ) {
		return;
	}

	error_log( print_r( $item, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
}

/**
 * Determines whether a post was created before the plugin was first activated.
 *
 * @param  WP_Post $post Post object.
 * @return bool          Whether the post was created before the plugin was first activated.
 */
function is_older( $post ) {
	$options = get_options();

	if ( empty( $options['first_activated'] ) ) {
		// Not much we can do.
		return false;
	}

	if ( get_post_time( 'U', true, $post->ID ) < $options['first_activated'] ) {
		return true;
	}

	return false;
}

/**
 * Returns this plugin's options.
 *
 * Roughly equal to `get_option( 'share_on_mastodon' )`.
 *
 * @return array Current plugin settings.
 */
function get_options() {
	return Share_On_Mastodon::get_instance()
		->get_options_handler()
		->get_options();
}
