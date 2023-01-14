<?php
/**
 * All things images.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Image handler class.
 */
class Image_Handler {
	/**
	 * Returns a post's associated images.
	 *
	 * @param  WP_Post $post Post object.
	 * @return array         Array of attachemnt IDs.
	 */
	public static function get_images( $post ) {
		$media = array();

		if ( has_post_thumbnail( $post->ID ) && apply_filters( 'share_on_mastodon_featured_image', true, $post ) ) {
			// Include featured image.
			$media[] = get_post_thumbnail_id( $post->ID );
		}

		if ( apply_filters( 'share_on_mastodon_attached_images', true, $post ) ) {
			// Include all attached images.
			$attachments = get_attached_media( 'image', $post->ID );

			if ( ! empty( $attachments ) && is_array( $attachments ) ) {
				foreach ( $attachments as $attachment ) {
					$media[] = $attachment->ID;
				}
			}
		}

		if ( apply_filters( 'share_on_mastodon_referenced_images', false, $post ) ) {
			// Include in-post, i.e., referenced, images.
			$image_ids = static::get_referenced_images( $post );

			if ( ! empty( $image_ids ) && is_array( $image_ids ) ) {
				foreach ( $image_ids as $image_id ) {
					$media[] = $image_id;
				}
			}
		}

		return array_unique( $media );
	}

	/**
	 * Attempts to find and return in-post images.
	 *
	 * @param  WP_Post $post Post object.
	 * @return array         Array of image IDs.
	 */
	protected static function get_referenced_images( $post ) {
		// Assumes `src` value is wrapped in quotes. This will almost always be
		// the case.
		preg_match_all( '~<img(?:.+?)src=[\'"]([^\'"]+)[\'"](?:.*?)>~i', $post->post_content, $matches );

		if ( empty( $matches[1] ) ) {
			return array();
		}

		$images = array();

		foreach ( $matches[1] as $match ) {
			$filename = pathinfo( $match, PATHINFO_FILENAME );
			$original = preg_replace( '~-(?:\d+x\d+|scaled|rotated)$~', '', $filename ); // Strip dimensions, etc., off resized images.

			$url = str_replace( $filename, $original, $match );

			// Convert URL back to attachment ID.
			$image_id = (int) attachment_url_to_postid( $url );

			if ( 0 === $image_id ) {
				// Unknown to WordPress.
				continue;
			}

			$images[] = $image_id;
		}

		return $images;
	}

	/**
	 * Uploads an image and returns a (single) media ID.
	 *
	 * @param  int $image_id Image ID.
	 * @return string|null   Unique media ID, or nothing on failure.
	 */
	public static function upload_image( $image_id ) {
		$image   = wp_get_attachment_image_src( $image_id, 'large' );
		$uploads = wp_upload_dir();

		if ( ! empty( $image[0] ) && 0 === strpos( $image[0], $uploads['baseurl'] ) ) {
			// Found a "large" thumbnail that lives on our own site (and not,
			// e.g., a CDN).
			$url = $image[0];
		} else {
			// Get the original image URL. Note that Mastodon has an upload
			// limit of, I believe, 8 MB.
			$url = wp_get_attachment_url( $image_id );
		}

		$file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );

		if ( ! is_file( $file_path ) ) {
			// File doesn't seem to exist.
			return;
		}

		// Fetch alt text.
		$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

		if ( '' === $alt ) {
			$alt = wp_get_attachment_caption( $image_id ); // Fallback to caption.
		}

		$boundary = md5( time() );
		$eol      = "\r\n";

		$body = '--' . $boundary . $eol;

		if ( false !== $alt && '' !== $alt ) {
			error_log( "[Share on Mastodon] Found the following alt text for the attachment with ID $image_id: $alt" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			$alt = sanitize_text_field( $alt );

			// Send along an image description, because accessibility.
			$body .= 'Content-Disposition: form-data; name="description";' . $eol . $eol;
			$body .= $alt . $eol;
			$body .= '--' . $boundary . $eol;
		} else {
			error_log( "[Share on Mastodon] Did not find alt text for the attachment with ID $image_id" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// The actual (binary) image data.
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . $eol;
		$body .= 'Content-Type: ' . mime_content_type( $file_path ) . $eol . $eol;
		$body .= file_get_contents( $file_path ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body .= '--' . $boundary . '--'; // Note the extra two hyphens at the end.

		$options = get_option( 'share_on_mastodon_settings' );

		$response = wp_remote_post(
			esc_url_raw( $options['mastodon_host'] . '/api/v1/media' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $options['mastodon_access_token'],
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'data_format' => 'body',
				'body'        => $body,
				'timeout'     => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return;
		}

		$media = json_decode( $response['body'] );

		if ( ! empty( $media->id ) ) {
			return $media->id;
		}

		// Provided debugging's enabled, let's store the (somehow faulty)
		// response.
		error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
