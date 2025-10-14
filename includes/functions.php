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
		->get_options_handler()
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
	if ( str_starts_with( $url, 'data:' ) ) {
		// Nothing to do.
		return 0;
	}

	if ( strlen( $url ) > ( 2 * 2084 ) ) {
		// 2,084 is sometimes seen as a practical maximum URL length, so anything over *twice* that is likely not a URL.
		return 0;
	}

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

	$extension           = pathinfo( $path, PATHINFO_EXTENSION );
	$path_sans_extension = ! empty( $extension )
		? preg_replace( "~\.{$extension}$~", '', $path )
		: $path;

	$sql = $wpdb->prepare(
		"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value = %s OR meta_value = %s) LIMIT 1",
		$path,
		"{$path_sans_extension}-scaled" . ( ! empty( $extension ) ? ".{$extension}" : '' ),
		"{$path_sans_extension}-rotated" . ( ! empty( $extension ) ? ".{$extension}" : '' )
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	$results = $wpdb->get_results( $sql );
	$post_id = 0;

	if ( $results ) {
		$post_id = reset( $results )->post_id;

		if ( count( $results ) > 1 ) {
			foreach ( $results as $result ) {
				if ( $path === $result->meta_value ) {
					// If a case-sensitive match exists, use that instead.
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
 * @param  int          $seconds Minimum "age," in seconds.
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
