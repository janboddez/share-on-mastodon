<?php
/**
 * Syndication Links compatibility.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * All things Syndication Links.
 */
class Syn_Links_Compat {
	/**
	 * Register Syndication Links callbacks.
	 *
	 * @since 0.11.0
	 */
	public static function register() {
		add_filter( 'syn_add_links', array( __CLASS__, 'syndication_links' ), 10, 2 );
	}

	/**
	 * Adds the Mastodon URL to Syndication Links' list of URLs.
	 *
	 * @param  array $urls      Syndication links.
	 * @param  array $object_id The post we're gathering these links for.
	 * @return array            Modified syndication links.
	 *
	 * @since 0.11.0
	 */
	public static function syndication_links( $urls, $object_id ) {
		$mastodon_url = get_post_meta( $object_id, '_share_on_mastodon_url', true );

		if ( ! empty( $mastodon_url ) ) {
			$urls[] = $mastodon_url;
		}

		return $urls;
	}
}
