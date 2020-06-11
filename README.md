# Share on Mastodon
Automatically share WordPress posts on [Mastodon](https://joinmastodon.org/). You choose which Post Types are shared—though sharing can still be disabled on a per-post basis.

## Installation
The plugin's available from WordPress.org's plugin [repo](https://wordpress.org/plugins/share-on-mastodon/), so you can just head into WP Admin > Plugins > Add New, search for _share on mastodon_, and install and activate from there.

## Configuration
Tell the Share on Mastodon settings page about your instance URL, and make sure to hit Save Changes. You'll then be able authorize WordPress to post on your behalf.

Select the Post Types for which sharing to Mastodon should be possible, too. (Sharing can still be disabled on a per-post basis.)

## Media
When a Featured Image is set, Share on Mastodon will try to include it.

This behavior can be disabled using the `share_on_mastodon_featured_image` filter.
```
// Never upload featured images.
add_filter( 'share_on_mastodon_featured_image', '__return_false' );
```

If you wanted to disable including featured images for a certain post type only, you could do that:
```
add_filter( 'share_on_mastodon_featured_image', function( $enabled, $post ) {
  if ( 'post' === $post->post_type ) {
    return false;
  }

  return $enabled;
}, 10, 2 );
```

The same goes for any other images _attached to the post_. (Note: _Attached_ images aren't necessarily part of the post content. They're typically uploaded through the Edit Post screen when editing the post in question.)
```
add_filter( 'share_on_mastodon_attached_images', function( $enabled, $post ) {
  if ( 'post' === $post->post_type ) {
    return false;
  }

  return $enabled;
}, 10, 2 );
```

Regardless of what images are enabled (the default is _both_ featured and regular attached images), no more than four images will ever be included, even if the number of attached images is larger.

## Privacy
Currently, all toots sent via this plugin are **public**. [Unlisted or followers-only](https://docs.joinmastodon.org/usage/privacy/#publishing-levels) toots may become an option later on.

## Gutenberg
This plugin now uses WordPress' Meta Box API—supported by Gutenberg—to store per-post sharing settings, which makes it 100% compatible with the new block editor.

## Advanced
_Share on Mastodon_ comes with a fair number of filters that allow tweaking its behavior.

### Custom Formatting
By default, shared statuses look something like:
```
My Awesome Post Title https://url.to/original-post/
```

Mastodon is smart enough to then try and find things like an Open Graph image and description for that URL. There's [no need for a link shortener](https://docs.joinmastodon.org/api/guidelines/#other-links), either.

If you'd rather format toots differently, however, there's a `share_on_mastodon_status` filter.

**Example:** if all posts you share are short, plain-text messages and you want them to appear exactly as written and without a backlink—and essentially create a WordPress front end to Mastodon—then the following couple lines of PHP would handle that.
```
add_filter( 'share_on_mastodon_status', function( $status, $post ) {
  $status = wp_strip_all_tags( $post->post_content );
  return $status;
}, 10, 2 );
```
Same example, but with a permalink at the end:
```
add_filter( 'share_on_mastodon_status', function( $status, $post ) {
  $status  = wp_strip_all_tags( $post->post_content );
  $status .= "\n\n" . get_permalink( $post );
  return $status;
}, 10, 2 );
```

We can even append WordPress tags as hashtags:
```
add_filter( 'share_on_mastodon_status', function( $status, $post ) {
  $tags = get_the_tags( $post->ID );

  if ( $tags ) {
    $status .= "\n\n";
    
    foreach ( $tags as $tag ) {
      $status .= '#' . preg_replace( '/\s/', '', $tag->name ) . ' ';
    }

    $status = trim( $status );
  }

  return $status;
}, 11, 2 );
```
See the [Customize “Share on Mastodon” Statuses](https://janboddez.tech/articles/customize-share-on-mastodon-statuses) blog post, too.

### Share Programmatically Created Posts, and More
Even supported (per their Post Type) posts won't be shared if not posted through WP Admin, as the 'Share on Mastodon' checkbox value will not have been saved.

To work around this, there's a `share_on_mastodon_enabled` filter:
```
// Always share supported Post Types.
add_filter( 'share_on_mastodon_enabled', '__return_true' );
```

You could use the same filter to prevent, e.g., certain taxonomies from being shared.
```
add_filter( 'share_on_mastodon_enabled', function( $is_enabled, $post_id ) {
	if ( has_category( 'my-category-slug', $post_id ) ) {
		return false;
	}

	return $is_enabled;
}, 10, 2 );
```
## Debugging
If you've added `define('WP_DEBUG', true);` to `wp-config.php`, the plugin settings page will show some debugging info. If debug logging is enabled as well, connection errors will be logged to WordPress's default debug log (at whatever path and filename you've chosen for it).
