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

		// Always parse post content for images and alt text.
		$referenced_images = static::get_referenced_images( $post );

		// Alright, let's get started.
		$media_ids = array();

		if ( $enable_referenced_images && ! empty( $referenced_images ) ) {
			// Add in-post images.
			$media_ids = array_keys( $referenced_images );
		}

		if ( $enable_featured_image ) {
			// Include featured image.
			$media_ids[] = get_post_thumbnail_id( $post->ID );
		}

		if ( $enable_attached_images ) {
			// Include all attached images.
			$attachments = get_attached_media( 'image', $post->ID );

			if ( ! empty( $attachments ) ) {
				foreach ( $attachments as $attachment ) {
					$media_ids[] = $attachment->ID;
				}
			}
		}

		// Remove duplicates, and reindex.
		$media_ids = array_values( array_unique( $media_ids ) );
		$media_ids = (array) apply_filters( 'share_on_mastodon_media', $media_ids, $post );

		return static::add_alt_text( $media_ids, $referenced_images );
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

			if ( ! isset( $images[ $image_id ] ) || '' === $images[ $image_id ] ) {
				// When an image is already present, overwrite it only if its
				// "known" alt text is empty.
				$images[ $image_id ] = $node->hasAttribute( 'alt' ) ? $node->getAttribute( 'alt' ) : '';
			}
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
			$body .= html_entity_decode( wp_strip_all_tags( $alt ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) . $eol;
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
	 * @param  array $image_ids         Attachment IDs.
	 * @param  array $referenced_images An array of (in-post) images and their alt text to look through first.
	 * @return array                    An array with image IDs as its keys and their corresponding alt text as its values.
	 */
	protected static function add_alt_text( $image_ids, $referenced_images ) {
		$image_ids = array_values( $image_ids );
		$images    = array();

		foreach ( $image_ids as $image_id ) {
			if ( isset( $referenced_images[ $image_id ] ) && '' !== $referenced_images[ $image_id ] ) {
				// This image was found inside the post, with alt text.
				$images[ $image_id ] = $referenced_images[ $image_id ];
			} else {
				// Fetch alt text from the `wp_postmeta` table.
				$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

				if ( '' === $alt ) {
					$alt = wp_get_attachment_caption( $image_id ); // Fallback to caption.
				}

				$images[ $image_id ] = is_string( $alt ) ? $alt : '';
			}
		}

		return $images;
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
