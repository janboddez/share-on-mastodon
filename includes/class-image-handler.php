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
		$options = get_options();

		$enable_referenced_images = ! empty( $options['referenced_images'] ); // This was always opt-in.
		$enable_referenced_images = apply_filters( 'share_on_mastodon_referenced_images', $enable_referenced_images, $post );

		$enable_featured_image = ! isset( $options['featured_images'] ) || $options['featured_images'];
		$enable_featured_image = has_post_thumbnail( $post->ID ) && apply_filters( 'share_on_mastodon_featured_image', $enable_featured_image, $post );

		$enable_attached_images = ! isset( $options['attached_images'] ) || $options['attached_images'];
		$enable_attached_images = apply_filters( 'share_on_mastodon_attached_images', $enable_attached_images, $post );

		if ( ! ( $enable_referenced_images || $enable_featured_image || $enable_attached_images ) ) {
			// Nothing to do.
			return array();
		}

		// Always parse post content for images and alt text. Doing this (first)
		// allows us to override (possibly empty) `wp_postmeta` alt values.
		$referenced_images = static::get_referenced_images( $post ); // Returns image IDs _and_ alt text (if any).

		// Alright, let's get started.
		$media = array();

		if ( $enable_referenced_images && ! empty( $referenced_images ) ) {
			// Add in-post images. No need to loop over them, as they're already
			// in the right format (and `$media` is still empty at this point).
			$media = $referenced_images;
		}

		if ( $enable_featured_image ) {
			// Include featured image.
			$image_id = get_post_thumbnail_id( $post->ID );
			$media[]  = array(
				'id'  => $image_id,
				'alt' => static::get_alt_text( $image_id, $referenced_images ),
			);
		}

		if ( $enable_attached_images ) {
			// Include all attached images.
			$attachments = get_attached_media( 'image', $post->ID );

			if ( ! empty( $attachments ) ) {
				foreach ( $attachments as $attachment ) {
					$media[] = array(
						'id'  => $attachment->ID,
						'alt' => static::get_alt_text( $image_id, $referenced_images ),
					);
				}
			}
		}

		// Remove duplicates.
		$tmp   = array_unique( array_column( $media, 'id' ) ); // `array_column()` preserves keys, and so does `array_unique()`.
		$media = array_intersect_key( $media, $tmp );          // And that is what allows us to do this.
		$media = array_values( $media ); // Always reindex.

		// Allow developers to filter the resulting array.
		$media = apply_filters( 'share_on_mastodon_media', $media, $post );

		return static::convert_media_array( $media ); // To cover the highly unlikely case that someone's been filtering the (old-format) media array.
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

		$boundary = md5( time() );
		$eol      = "\r\n";

		$body = '--' . $boundary . $eol;

		if ( '' !== $alt ) {
			debug_log( "[Share on Mastodon] Found the following alt text for the attachment with ID $image_id: $alt" );

			// Send along an image description, because accessibility.
			$body .= 'Content-Disposition: form-data; name="description";' . $eol . $eol;
			$body .= wp_strip_all_tags( $alt ) . $eol;
			$body .= '--' . $boundary . $eol;
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

	/**
	 * Returns alt text for a certain image.
	 *
	 * Looks through `$images` first, and falls back on what's stored in the
	 * `wp_postmeta` table.
	 *
	 * @param  int   $image_id Attachment ID.
	 * @param  array $images   An array of (in-post) images to look through first.
	 * @return string          Alt text, or an empty string.
	 */
	protected static function get_alt_text( $image_id, $images ) {
		$alt = '';

		foreach ( $images as $image ) {
			if ( isset( $image['id'] ) && $image_id === $image['id'] ) {
				$alt = isset( $image['alt'] ) ? $image['alt'] : '';

				break;
			}
		}

		if ( '' !== $alt ) {
			return $alt;
		}

		// Fetch alt text from the `wp_postmeta` table.
		$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

		if ( '' === $alt ) {
			$alt = wp_get_attachment_caption( $image_id ); // Fallback to caption.
		}

		return is_string( $alt ) ? $alt : '';
	}

	/**
	 * Converts an array of media IDs to the newer multidimensional format.
	 *
	 * @param  array $media Original media array.
	 * @return array        Processed array.
	 */
	protected static function convert_media_array( $media ) {
		$array = array();

		foreach ( $media as $item ) {
			if ( is_array( $item ) ) {
				$array[] = $item; // Keep as is.
			} elseif ( is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ) {
				// Convert to "new" format.
				$array[] = array(
					'id'  => (int) $item,
					'alt' => '',
				);
			}
		}

		// We've normally done this before, but because we've allowed filtering
		// the resulting array, we have no choice but to do it again.
		$tmp   = array_unique( array_column( $array, 'id' ) ); // `array_column()` preserves keys, and so does `array_unique()`.
		$array = array_intersect_key( $array, $tmp );          // And that is what allows us to do this.

		return array_values( $array );
	}
}
