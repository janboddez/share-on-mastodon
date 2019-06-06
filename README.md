# Share on Mastodon
Easily share WordPress posts on Mastodon.

By default, shared statuses look something like:
```
My Awesome Post Title https://url.to/original-post/
```

Mastodon is smart enough to then try and find things like an Open Graph image and description for that URL. There's [no need for a link shortener](https://docs.joinmastodon.org/api/guidelines/#other-links), either.

## Gutenberg
This plugin relies on WordPress' `post_submitbox_misc_actions`, which is **incompatible** with the new block editor, to enable and disable per-post sharing.

I currently recommend using it only in combination with the [Classic Editor](https://wordpress.org/plugins/classic-editor/) plugin.

## Media
When a Featured Image is set, Share on Mastodon will try include it. Other media are not supported at the moment.

## Privacy
Currently, all toots sent via this plugin are **public**. [Unlisted or followers-only](https://docs.joinmastodon.org/usage/privacy/#publishing-levels) toots may become an option later on.

## Custom Formatting
If you'd rather format toots differently, there's a `share_on_mastodon_status` filter.

**Example:** if all posts you share are short, plain-text messages and you want them to appear exactly as written and without a backlink, then the following would handle that.
```
add_filter( 'share_on_mastodon_status', function( $status, $post ) {
	$status = wp_strip_all_tags( $post->post_content );
	return $status;
}, 10, 2 );
```
