<?php
/**
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

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_var_export
	error_log( is_string( $item ) ? $item : var_export( $item, true ) );
}

/**
 * Returns the current plugin options.
 *
 * Roughly equal to `get_option( 'share_on_mastodon_settings' )`.
 *
 * @param  int $user_id (Optional) user ID.
 * @return array        Current plugin settings.
 */
function get_options( $user_id = 0 ) {
	$options = Share_On_Mastodon::get_instance()
		->get_plugin_options()
		->get_options();

	return apply_filters( 'share_on_mastodon_options', $options, $user_id );
}

/**
 * Tries to convert an attachment URL into a post ID.
 *
 * Mostly lifted from core. The main difference is this function will also match URLs whose filename part probably
 * should include `-scaled`.
 *
 * @param  string $url The URL to resolve.
 * @return int         The found post ID, or 0 on failure.
 */
function attachment_url_to_postid( $url ) {
	global $wpdb;

	$dir  = wp_get_upload_dir();
	$path = $url;

	$site_url   = wp_parse_url( $dir['url'] );
	$image_path = wp_parse_url( $path );

	// Force the protocols to match if needed.
	if ( isset( $image_path['scheme'] ) && ( $image_path['scheme'] !== $site_url['scheme'] ) ) {
		$path = str_replace( $image_path['scheme'], $site_url['scheme'], $path );
	}

	if ( str_starts_with( $path, $dir['baseurl'] . '/' ) ) {
		$path = substr( $path, strlen( $dir['baseurl'] . '/' ) );
	}

	$filename = pathinfo( $path, PATHINFO_FILENAME ); // The bit before the (last) file extension (if any).

	$sql = $wpdb->prepare(
		"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value REGEXP %s",
		str_replace( $filename, "$filename(-scaled)*", $path ) // This is really the only change here.
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	$results = $wpdb->get_results( $sql );
	$post_id = null;

	if ( $results ) {
		// Use the first available result, but prefer a case-sensitive match, if exists.
		$post_id = reset( $results )->post_id;

		if ( count( $results ) > 1 ) {
			foreach ( $results as $result ) {
				if ( $path === $result->meta_value ) {
					$post_id = $result->post_id;
					break;
				}
			}
		}
	}

	return (int) $post_id;
}

/**
 * Determines whether a post is older than a certain number of seconds.
 *
 * @param  int          $seconds Minimum "age," in secondss.
 * @param  int|\WP_Post $post    Post ID or object. Defaults to global `$post`.
 * @return bool                  True if the post exists and is older than `$seconds`, false otherwise.
 */
function is_older_than( $seconds, $post = null ) {
	$post_time = get_post_time( 'U', true, $post );

	if ( false === $post_time ) {
		return false;
	}

	if ( $post_time >= time() - $seconds ) {
		return false;
	}

	return true;
}
