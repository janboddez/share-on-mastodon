<?php
/**
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Support for Micropub's syndication targets.
 */
class Micropub_Compat {
	/**
	 * Enables Micropub syndication.
	 */
	public static function register() {
		// Micropub syndication.
		add_filter( 'micropub_syndicate-to', array( __CLASS__, 'syndicate_to' ), 10, 2 );
		add_action( 'micropub_syndication', array( __CLASS__, 'syndication' ), 10, 2 );
	}

	/**
	 * Registers a Micropub syndication target.
	 *
	 * @param  array $syndicate_to Syndication targets.
	 * @param  array $user_id      User ID.
	 * @return array               Modified syndication targets.
	 */
	public static function syndicate_to( $syndicate_to, $user_id ) {
		$options = get_options( $user_id );

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
	 */
	public static function syndication( $post_id, $synd_requested ) {
		$post    = get_post( $post_id );
		$options = apply_filters( 'share_on_mastodon_options', get_options(), ! empty( $post->post_author ) ? $post->post_author : 0 );

		if ( empty( $options['mastodon_host'] ) ) {
			return;
		}

		if ( empty( $options['mastodon_username'] ) ) {
			return;
		}

		if ( in_array( "{$options['mastodon_host']}/@{$options['mastodon_username']}", $synd_requested, true ) ) {
			update_post_meta( $post_id, '_share_on_mastodon', '1' );
			delete_post_meta( $post_id, '_share_on_mastodon_error' ); // Clear previous errors, if any.

			// Trigger syndication.
			Share_On_Mastodon::get_instance()
				->get_post_handler()
				->toot( $post );
		}
	}
}
