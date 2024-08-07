=== Share on Mastodon ===
Contributors: janboddez
Tags: mastodon, social, fediverse, syndication, posse
Tested up to: 6.6
Stable tag: 0.19.1
License: GNU General Public License v3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automatically share WordPress posts on Mastodon.

== Description ==
Automatically share WordPress posts on [Mastodon](https://joinmastodon.org/).

You choose which post types are shared, and sharing can still be disabled on a per-post basis.

Supports WordPress' new block editor, image uploads and alt text, "template tags," and comes with a number of filter hooks for developers.

More details can be found on [this plugin's web page](https://jan.boddez.net/wordpress/share-on-mastodon).

= Credit =
Share icon by [Heroicons](https://heroicons.dev/), licensed under the terms of the MIT License. Elephant illustration sourced from Mastodon's [Press Kit](https://joinmastodon.org/press-kit.zip).

== Installation ==
Within WordPress' admin interface, visit *Plugins > Add New* and search for "share on mastodon" to locate the plugin. (Alternatively, upload this plugin's ZIP file via the "Upload Plugin" button.)

After activation, head over to *Settings > Share on Mastodon* to authorize WordPress to post to your Mastodon account.

More detailed instructions can be found on [this plugin's web page](https://jan.boddez.net/wordpress/share-on-mastodon).

== Changelog ==
= 0.19.1 =
Auto-disable share toggle ("block editor") for older posts. Fix default "share" value. Provide fallback when `mime_content_type()` is undefined.

= 0.19.0 =
Update `share_on_mastodon_enabled` filter. Improve compatibility with Syndication Links. Rework `Options_Handler`.

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
