# Share on Mastodon
Automatically share WordPress posts on [Mastodon](https://joinmastodon.org/). You choose which Post Types are shared—though sharing can still be disabled on a per-post basis.

Custom formatting is done through filter hooks rather than the options page UI, and requires some familiarity with PHP. (Always here to help, though!) Benefits are things like infinite flexibility, _version control_, and, frankly, less code for me to maintain.

Share on Mastodon is compatible with both the Classic and the Block Editor (Gutenberg). It supports mobile apps, too; this [requires a so-called filter callback](https://jan.boddez.net/wordpress/share-on-mastodon#share_on_mastodon_enabled)—literally, a single line of PHP!—which you'd typically add to either your theme's `functions.php` or a site-specific plugin. 

## Documentation
Complete documentation, and code examples, can be found at https://jan.boddez.net/wordpress/share-on-mastodon.

## Installation
The plugin's available from WordPress.org's plugin [repo](https://wordpress.org/plugins/share-on-mastodon/), so you can just head into WP Admin > Plugins > Add New, search for _share on mastodon_, and install and activate from there.

## Configuration
Tell the Share on Mastodon settings page about your instance URL, and make sure to hit Save Changes. You'll then be able authorize WordPress to post on your behalf.

Then, select the Post Types for which sharing to Mastodon should be possible. (Sharing can still be disabled on a per-post basis.)

## Media
When a Featured Image is set, Share on Mastodon will try to include it. The same goes for any other images _attached to the post_. This behavior can be modified through filter hooks.

## Advanced
_Share on Mastodon_ comes with a fair number of **filters** that allow tweaking its behavior. The code examples on the [official documentation](https://jan.boddez.net/wordpress/share-on-mastodon) page should be enough to get you going. Please file a GitHub issue if you need help, still.
