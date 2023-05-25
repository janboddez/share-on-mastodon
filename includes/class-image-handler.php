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
	 * @return array         Attachment array.
	 */
	public static function get_images( $post ) {
		$media   = array();
		$options = get_options();

		// Process "in-post" images first, so as to give _their_ `alt`
		// attributes priority over what's saved, if anything, in the database.
		$enable_referenced_images = ! empty( $options['referenced_images'] ); // This was always opt-in.

		if ( apply_filters( 'share_on_mastodon_referenced_images', $enable_referenced_images, $post ) ) {
			// Include in-post, i.e., referenced, images.
			$images = static::get_referenced_images( $post );

			if ( ! empty( $images ) && is_array( $images ) ) {
				foreach ( $images as $image ) {
					$media[] = $image;
				}
			}
		}

		$enable_featured_images = ! isset( $options['featured_images'] ) || $options['featured_images'];

		if ( has_post_thumbnail( $post->ID ) && apply_filters( 'share_on_mastodon_featured_image', $enable_featured_images, $post ) ) {
			// Include featured image.
			$media[] = array(
				'id'  => get_post_thumbnail_id( $post->ID ),
				'alt' => '',
			);
		}

		$enable_attached_images = ! isset( $options['attached_images'] ) || $options['attached_images'];

		if ( apply_filters( 'share_on_mastodon_attached_images', $enable_attached_images, $post ) ) {
			// Include all attached images.
			$attachments = get_attached_media( 'image', $post->ID );

			if ( ! empty( $attachments ) && is_array( $attachments ) ) {
				foreach ( $attachments as $attachment ) {
					$media[] = array(
						'id'  => $attachment->ID,
						'alt' => '',
					);
				}
			}
		}

		$tmp   = array_unique( array_column( $media, 'id' ) ); // `array_column()` preserves keys, and so does `array_unique()`.
		$media = array_intersect_key( $media, $tmp );          // And that is what allows us to do this.
		$media = apply_filters( 'share_on_mastodon_media', $media, $post );

		return array_values( $media ); // Always reindex.
	}

	/**
	 * Attempts to find and return in-post images.
	 *
	 * @param  WP_Post $post Post object.
	 * @return array         Image array.
	 */
	protected static function get_referenced_images( $post ) {
		$images = array();

		// Wrap post content in a dummy `div`, as there must (!) be a root-level
		// element at all times.
		$html = '<div>' . mb_convert_encoding( $post->post_content, 'HTML-ENTITIES', get_bloginfo( 'charset' ) ) . '</div>';

		libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$doc->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$xpath = new \DOMXPath( $doc );

		foreach ( $xpath->query( '//img' ) as $node ) {
			if ( ! $node->hasAttribute( 'src' ) || empty( $node->getAttribute( 'src' ) ) ) {
				continue;
			}

			$src      = $node->getAttribute( 'src' );
			$filename = pathinfo( $src, PATHINFO_FILENAME );
			$original = preg_replace( '~-(?:\d+x\d+|scaled|rotated)$~', '', $filename ); // Strip dimensions, etc., off resized images.

			$url = str_replace( $filename, $original, $src );

			// Convert URL back to attachment ID.
			$image_id = (int) attachment_url_to_postid( $url );

			if ( 0 === $image_id ) {
				// Unknown to WordPress.
				continue;
			}

			$images[] = array(
				'id'  => $image_id,
				'alt' => $node->hasAttribute( 'alt' ) ? $node->getAttribute( 'alt' ) : '',
			);
		}

		return $images;
	}

	/**
	 * Uploads an attachment and returns a (single) media ID.
	 *
	 * @param  int    $image_id Attachment ID.
	 * @param  string $alt      (Optional) alt text.
	 * @return string|null      Unique media ID, or nothing on failure.
	 */
	public static function upload_image( $image_id, $alt = '' ) {
		if ( wp_attachment_is_image( $image_id ) ) {
			// Grab the image's "large" thumbnail.
			$image = wp_get_attachment_image_src( $image_id, 'large' );
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $image[0] ) && 0 === strpos( $image[0], $uploads['baseurl'] ) ) {
			// Found a "large" thumbnail that lives on our own site (and not,
			// e.g., a CDN).
			$url = $image[0];
		} else {
			// Get the original attachment URL. Note that Mastodon has an upload
			// limit of 8 MB. Either way, this should return a _local_ URL.
			$url = wp_get_attachment_url( $image_id );
		}

		$file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );

		if ( ! is_file( $file_path ) ) {
			// File doesn't seem to exist.
			return;
		}

		if ( empty( $alt ) ) {
			// Fetch alt text.
			$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

			if ( '' === $alt ) {
				$alt = wp_get_attachment_caption( $image_id ); // Fallback to caption.
			}
		}

		$boundary = md5( time() );
		$eol      = "\r\n";

		$body = '--' . $boundary . $eol;

		if ( false !== $alt && '' !== $alt ) {
			debug_log( "[Share on Mastodon] Found the following alt text for the attachment with ID $image_id: $alt" );

			// @codingStandardsIgnoreStart
			// $alt = sanitize_text_field( $alt ); // Some instances don't like our alt texts, thought maybe avoiding newline chars wo
			// $alt = esc_attr( $alt ); // Leads to double-escaped entities.
			// $alt = wp_strip_all_tags(); // We could probably leave this in, but entities seem to be escaped okay.
			// $alt = str_replace( array( "\r", "\n", '"' ), array( '%0D', '%0A', '%22' ), $alt ); // Also doesn't work, as these aren't unencoded by Mastodon.
			// @codingStandardsIgnoreEnd

			// Send along an image description, because accessibility.
			$body .= 'Content-Disposition: form-data; name="description";' . $eol . $eol;
			$body .= $alt . $eol;
			$body .= '--' . $boundary . $eol;

			debug_log( "[Share on Mastodon] Here's the `alt` bit of what we're about to send the Mastodon API: `$body`" );
		} else {
			debug_log( "[Share on Mastodon] Did not find alt text for the attachment with ID $image_id" );
		}

		// The actual (binary) image data.
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . $eol;
		$body .= 'Content-Type: ' . mime_content_type( $file_path ) . $eol . $eol;
		$body .= file_get_contents( $file_path ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body .= '--' . $boundary . '--'; // Note the extra two hyphens at the end.

		$options = get_options();

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
			debug_log( $response );
			return;
		}

		$media = json_decode( $response['body'] );

		if ( ! empty( $media->id ) ) {
			return $media->id;
		}

		// Provided debugging's enabled, let's store the (somehow faulty)
		// response.
		debug_log( $response );
	}
}
