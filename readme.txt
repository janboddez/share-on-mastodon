=== Share on Mastodon ===
Contributors: janboddez
Tags: mastodon, share, publicize, crosspost, fediverse, syndication, posse
Tested up to: 6.5
Stable tag: 0.18.0
License: GNU General Public License v3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automatically share WordPress posts on Mastodon.

== Description ==
Automatically share WordPress posts on [Mastodon](https://joinmastodon.org/).

You choose which post types are shared, though sharing can still be disabled on a per-post basis.

Supports image uploads, WordPress's new block editor, and comes with a number of filter hooks for developers.

More details can be found on [this plugin's web page](https://jan.boddez.net/wordpress/share-on-mastodon).

= Credit =
Share icon by [Heroicons](https://heroicons.dev/), licensed under the terms of the MIT License. Elephant illustration sourced from Mastodon's [Press Kit](https://joinmastodon.org/press-kit.zip).

== Installation ==
Within WP Admin, visit *Plugins > Add New* and search for "share on mastodon" to locate the plugin. (Alternatively, upload this plugin's ZIP file via the "Upload Plugin" button.)

After activation, head over to *Settings > Share on Mastodon* to authorize WordPress to post to your Mastodon account.

More detailed instructions can be found on [this plugin's web page](https://jan.boddez.net/wordpress/share-on-mastodon).

== Changelog ==
= 0.18.0 =
Improve compatibility with Syndication Links.

= 0.17.4 =
Also allow pages.

= 0.17.3 =
Fix max images option.

= 0.17.2 =
Fix permalinks with emoji in them. Somewhat smarter `%excerpt%` lengths.

= 0.17.1 =
Various bug fixes.

= 0.17.0 =
Introduce Gutenberg sidebar panel.

= 0.16.1 =
Deprecate `share_on_mastodon_cutoff` filter. Minor improvements.

= 0.16.0 =
Improved alt text discovery.

= 0.15.0 =
Better custom status messages: template tags, default template. Address odd Gutenberg behavior.

= 0.14.0 =
A very first implementation of optional custom status messages.

= 0.13.1 =
Improve Syndication Links compatibility.

= 0.13.0 =
Prevent accidental sharing of (very) old posts.

= 0.12.2 =
Custom field fix.

= 0.12.1 =
Filterable media array.

= 0.12.0 =
Configurable debug logging.

= 0.11.0 =
More flexible/robust instance URL handling. Overhauled plugin options. Syndication Links compatibility.

= 0.10.1 =
Avoid duplicate posts for CPTs without explicit custom field support.

= 0.10.0 =
Add (experimental) support for in-post images, and alt text fallback.

= 0.9.0 =
Additional `share_on_mastodon_toot_args` filter argument. Add stable tag.

= 0.8.0 =
Add as Micropub syndication target.

= 0.7.0 =
Include option to delay crossposting. Might be used to try to fix issues with slow image uploads and the like.

= 0.6.6 =
Exclude more default post types. Store Mastodon username in settings.

= 0.6.5 =
Prevent older WP versions from adding featured images twice.

= 0.6.4 =
Provide filter for "opt-in" sharing.

= 0.6.2 =
Slightly more robust Mastodon host URL handling.

= 0.6.1 =
Fix array notices.

= 0.6.0 =
Add URL information in meta box.

= 0.5.1 =
Avoid double-encoded HTML entities.

= 0.5.0 =
Support uploading of up to 4 attached images. Add additional arguments filter, e.g, for toot threading.

= 0.4.1 =
Fix `Post_Handler` actions.

= 0.4 =
Added token verification cron job.

= 0.3.1 =
Added ability to add image descriptions, improved debugging.

= 0.3 =
Allow `Post_Handler` hooks to be removed, too.

= 0.2 =
Fix `transition_post_status` parameter order.

= 0.1 =
Initial release.
