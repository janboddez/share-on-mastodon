<?php
/**
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Handles (only) "classic editor" notices.
 */
class Notices {
	/**
	 * Registers hook callbacks.
	 */
	public static function register() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );
	}

	/**
	 * Renders an admin notice upon success.
	 */
	public static function admin_notice() {
		if ( ! apply_filters( 'share_on_mastodon_admin_notices', false ) ) {
			// Disabled.
			return;
		}

		if ( ! isset( $_GET['share_on_mastodon_success'] ) || ! in_array( $_GET['share_on_mastodon_success'], array( '0', '1' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Nothing to do.
			return;
		}

		global $post;

		if ( empty( $post ) ) {
			return;
		}

		if ( '0' === $_GET['share_on_mastodon_success'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Oops.
			$error_message = get_post_meta( $post->ID, '_share_on_mastodon_error', true );

			if ( '' === $error_message ) {
				return;
			}
			?>
			<div class="notice notice-error is-dismissible">
				<?php /* translators: %s: error message */ ?>
				<p><?php printf( esc_html__( 'Share on Mastodon ran into the following error: %s', 'share-on-mastodon' ), '<i>' . esc_html( $error_message ) . '</i>' ); ?></p>
			</div>
			<?php
			return;
		}

		if ( '1' === $_GET['share_on_mastodon_success'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$url = get_post_meta( $post->ID, '_share_on_mastodon_url', true );

			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				return;
			}

			$url_parts = wp_parse_url( $url );

			$display_url  = '<span class="screen-reader-text">' . $url_parts['scheme'] . '://';
			$display_url .= ( ! empty( $url_parts['user'] ) ? $url_parts['user'] . ( ! empty( $url_parts['pass'] ) ? ':' . $url_parts['pass'] : '' ) . '@' : '' ) . '</span>';
			$display_url .= '<span class="ellipsis">' . mb_substr( $url_parts['host'] . $url_parts['path'], 0, 20 ) . '</span><span class="screen-reader-text">' . mb_substr( $url_parts['host'] . $url_parts['path'], 20 ) . '</span>';
			?>
			<div class="notice notice-success is-dismissible">
				<?php /* translators: %s: link to Mastodon status */ ?>
				<p><?php printf( esc_html__( 'Shared on Mastodon at %s.', 'share-on-mastodon' ), '<a class="share-on-mastodon-url" href="' . esc_url( $url ) . '">' . $display_url . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Tells WordPress to display the "success" notice.
	 *
	 * @param  string $location The destination URL.
	 * @return string           Updated destination URL.
	 */
	public static function add_success_query_var( $location ) {
		remove_filter( 'redirect_post_location', array( __CLASS__, 'add_success_query_var' ) );

		return add_query_arg(
			array( 'share_on_mastodon_success' => '1' ),
			esc_url_raw( $location )
		);
	}

	/**
	 * Tells WordPress to display the "error" notice.
	 *
	 * @param  string $location The destination URL.
	 * @return string           Updated destination URL.
	 */
	public static function add_error_query_var( $location ) {
		remove_filter( 'redirect_post_location', array( __CLASS__, 'add_error_query_var' ) );

		return add_query_arg(
			array( 'share_on_mastodon_success' => '0' ),
			esc_url_raw( $location )
		);
	}

	/**
	 * Adds our query arguments to WordPress' so-called removable query arguments.
	 *
	 * @param  array $args Array of query variables to remove from a URL.
	 * @return array       Filtered array.
	 */
	public static function removable_query_args( $args ) {
		$args[] = 'share_on_mastodon_success';

		return $args;
	}
}
