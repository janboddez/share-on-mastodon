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
 * Determines whether a post is older than a certain number of seconds.
 *
 * @param  int     $seconds Minimum "age," in secondss.
 * @param  WP_Post $post    Post object.
 * @return bool             True if the post exists and is older than `$seconds`, false otherwise.
 */
function is_older_than( $seconds, $post ) {
	$post_time = get_post_time( 'U', true, $post );

	if ( false === $post_time ) {
		return false;
	}

	if ( $post_time >= time() - $seconds ) {
		return false;
	}

	return true;
}

/**
 * Converts an array of media IDs to the newer multidimensional format.
 *
 * @param  array $media Original media array.
 * @return array        Processed array.
 */
function convert_media_array( $media ) {
	$array = array();

	foreach ( $media as $item ) {
		if ( is_array( $item ) ) {
			$array[] = $item; // Keep as is.
		} elseif ( is_int( $item ) ) {
			$array[] = array(
				'id'  => $item,
				'alt' => '',
			);
		}
	}

	$tmp   = array_unique( array_column( $array, 'id' ) ); // `array_column()` preserves keys, and so does `array_unique()`.
	$array = array_intersect_key( $array, $tmp );          // And that is what allows us to do this.

	return array_values( $array );
}

/**
 * Returns this plugin's options.
 *
 * Roughly equal to `get_option( 'share_on_mastodon_settings' )`.
 *
 * @return array Current plugin settings.
 */
function get_options() {
	return Share_On_Mastodon::get_instance()
		->get_options_handler()
		->get_options();
}
