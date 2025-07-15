<?php
/**
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * All things images.
 */
class Image_Handler {
	/**
	 * Returns a post's associated images.
	 *
	 * @param  \WP_Post $post Post object.
	 * @return array          Attachment array.
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

		// Alright, let's get started.
		$media_ids = array();

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

		$referenced_images = array();

		if ( $enable_referenced_images || ! empty( $media_ids ) ) {
			// Parse post content for images and alt text.
			$referenced_images = static::get_referenced_images( $post );
		}

		if ( $enable_referenced_images && ! empty( $referenced_images ) ) {
			// Actually add any in-post images.
			$media_ids = array_merge( $media_ids, array_keys( $referenced_images ) ); // We're interested only in the IDs, for now.
		}

		// Remove duplicates, and (even though it isn't _really_ needed) reindex.
		$media_ids = array_values( array_unique( $media_ids ) );
		// Allow developers to filter the array of media IDs.
		$media_ids = (array) apply_filters( 'share_on_mastodon_media', $media_ids, $post );

		// Convert the array of media IDs into something of the format `array( $id => 'Alt text.' )`.
		$media = static::add_alt_text( $media_ids, $referenced_images );

		debug_log( '[Share on Mastodon] The images selected for crossposting (but not yet limited to 4):' );
		debug_log( $media );

		debug_log( '[Share on Mastodon] The images as found in the post:' );
		debug_log( $referenced_images );

		return $media;
	}

	/**
	 * Attempts to find and return in-post images.
	 *
	 * @param  \WP_Post $post Post object.
	 * @return array          Image array.
	 */
	protected static function get_referenced_images( $post ) {
		$images = array();

		// Wrap post content in a dummy `div`, as there must (!) be a root-level element at all times.
		$html = '<div>' . mb_convert_encoding( $post->post_content, 'HTML-ENTITIES', get_bloginfo( 'charset' ) ) . '</div>';

		$use_errors = libxml_use_internal_errors( true );

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
			$image_id = attachment_url_to_postid( $url );

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

		libxml_use_internal_errors( $use_errors );

		return $images;
	}

	/**
	 * Uploads an attachment and returns a (single) media ID.
	 *
	 * @param  int    $image_id Attachment ID.
	 * @param  string $alt      Alt text.
	 * @param  array  $options  Mastodon (API) settings to use.
	 * @return string|null      Unique media ID, or `null` on failure.
	 */
	public static function upload_image( $image_id, $alt, $options ) {
		if ( wp_attachment_is_image( $image_id ) ) {
			// Grab the image's "large" thumbnail.
			$image = wp_get_attachment_image_src( $image_id, apply_filters( 'share_on_mastodon_image_size', 'large', $image_id ) );
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $image[0] ) && 0 === strpos( $image[0], $uploads['baseurl'] ) ) {
			// Found a "large" thumbnail that lives on our own site (and not, e.g., a CDN).
			$url = $image[0];
		} else {
			// Get the original attachment URL. Note that Mastodon has an upload limit of 8 MB. Either way, this should
			// return a *local* URL.
			$url = wp_get_attachment_url( $image_id );
		}

		$file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );

		if ( ! is_file( $file_path ) ) {
			// File doesn't seem to exist.
			debug_log( "[Share on Mastodon] Could not read the image at `$file_path`." );
			return;
		}

		$boundary = md5( time() );
		$eol      = "\r\n";

		$body = '--' . $boundary . $eol;

		if ( '' !== $alt ) {
			debug_log( "[Share on Mastodon] Found the following alt text for the attachment with ID $image_id: `$alt`." );

			// Send along an image description, because accessibility.
			$body .= 'Content-Disposition: form-data; name="description";' . $eol . $eol;
			$body .= $alt . $eol;
			$body .= '--' . $boundary . $eol;
		} else {
			debug_log( "[Share on Mastodon] Did not find alt text for the attachment with ID $image_id." );
		}

		// The actual (binary) image data.
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . $eol;
		$body .= 'Content-Type: ' . static::get_content_type( $file_path ) . $eol . $eol;
		$body .= file_get_contents( $file_path ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body .= '--' . $boundary . '--'; // Note the extra two hyphens at the end.

		$response = wp_safe_remote_post(
			esc_url_raw( $options['mastodon_host'] . '/api/v1/media' ),
			array(
				'headers'             => array(
					'Authorization' => 'Bearer ' . $options['mastodon_access_token'],
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'data_format'         => 'body',
				'body'                => $body,
				'timeout'             => 15,
				'limit_response_size' => 1048576,
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

		// Provided debugging's enabled, let's store the (somehow faulty) response.
		debug_log( $response );
	}

	/**
	 * Returns alt text for a certain image.
	 *
	 * Looks through `$images` first, and falls back on what's stored in the
	 * `wp_postmeta` table.
	 *
	 * @param  array $image_ids         IDs of images we want to upload.
	 * @param  array $referenced_images In-post images and their alt attributes, to look through first.
	 * @return array                    An array with image IDs as its keys and these images' alt attributes as its values.
	 */
	protected static function add_alt_text( $image_ids, $referenced_images ) {
		$images = array();

		foreach ( $image_ids as $image_id ) {
			if ( isset( $referenced_images[ $image_id ] ) && '' !== $referenced_images[ $image_id ] ) {
				// This image was found inside the post, with alt text.
				$alt = $referenced_images[ $image_id ];
			} else {
				// Fetch alt text from the `wp_postmeta` table.
				$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

				if ( '' === $alt ) {
					$alt = wp_get_attachment_caption( $image_id ); // Fallback to caption. Might return `false`.
				}
			}

			$images[ $image_id ] = is_string( $alt )
				? html_entity_decode( $alt, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) // Avoid double-encoded entities.
				: '';
		}

		return $images;
	}

	/**
	 * Returns a MIME content type for a certain file.
	 *
	 * @param  string $file_path File path.
	 * @return string            MIME type.
	 */
	protected static function get_content_type( $file_path ) {
		if ( function_exists( 'mime_content_type' ) ) {
			$result = mime_content_type( $file_path );

			if ( is_string( $result ) ) {
				return $result;
			}
		}

		if ( function_exists( 'finfo_open' ) && function_exists( 'finfo_file' ) ) {
			$finfo  = finfo_open( FILEINFO_MIME_TYPE );
			$result = finfo_file( $finfo, $file_path );

			if ( is_string( $result ) ) {
				return $result;
			}
		}

		$ext = pathinfo( $file_path, PATHINFO_EXTENSION );
		if ( ! empty( $ext ) ) {
			$mime_types = wp_get_mime_types();
			foreach ( $mime_types as $key => $value ) {
				if ( in_array( $ext, explode( '|', $key ), true ) ) {
					return $value;
				}
			}
		}

		return 'application/octet-stream';
	}
}
