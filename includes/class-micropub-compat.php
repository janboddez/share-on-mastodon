<?php
/**
 * Some Micropub-related enhancements.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

use Error;

/**
 * Micropub goodies.
 */
class Micropub_Compat {
	/**
	 * Enables Micropub syndication.
	 *
	 * @since 0.8.0
	 */
	public static function register() {
		// Micropub syndication.
		add_filter( 'micropub_syndicate-to', array( __CLASS__, 'syndicate_to' ) );
		add_action( 'micropub_syndication', array( __CLASS__, 'syndication' ), 10, 2 );
	}

	/**
	 * Registers a Micropub syndication target.
	 *
	 * @param  array $syndicate_to Syndication targets.
	 * @return array               Modified syndication targets.
	 *
	 * @since 0.8.0
	 */
	public static function syndicate_to( $syndicate_to ) {
		$plugin  = Share_On_Mastodon::get_instance();
		$options = $plugin->get_options_handler()->get_options();

		if ( empty( $options['mastodon_host'] ) ) {
			return $syndicate_to;
		}

		if ( empty( $options['mastodon_username'] ) ) {
			return $syndicate_to;
		}

		$syndicate_to[] = array(
			'uid'  => "{$options['mastodon_host']}/@{$options['mastodon_username']}",
			'name' => "Mastodon ({$options['mastodon_username']})",
		);

		return $syndicate_to;
	}

	/**
	 * Triggers syndication to Mastodon.
	 *
	 * @param int   $post_id        Post ID.
	 * @param array $synd_requested Selected syndication targets.
	 *
	 * @since 0.8.0
	 */
	public static function syndication( $post_id, $synd_requested ) {
		$plugin  = Share_On_Mastodon::get_instance();
		$options = $plugin->get_options_handler()->get_options();

		if ( empty( $options['mastodon_host'] ) ) {
			return;
		}

		if ( empty( $options['mastodon_username'] ) ) {
			return;
		}

		if ( in_array( "{$options['mastodon_host']}/@{$options['mastodon_username']}", $synd_requested, true ) ) {
			update_post_meta( $post_id, '_share_on_mastodon', '1' );

			$post = get_post( $post_id );

			if ( 'publish' === $post->post_status ) {
				// Trigger syndication.
				$post_handler = $plugin->get_post_handler();
				$post_handler->toot( 'publish', 'publish', $post );
			}
		}
	}
}
