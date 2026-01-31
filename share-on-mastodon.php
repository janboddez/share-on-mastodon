<?php
/**
 * Plugin Name:       Share on Mastodon
 * Description:       Easily share WordPress posts on Mastodon.
 * Plugin URI:        https://jan.boddez.net/wordpress/share-on-mastodon
 * Author:            Jan Boddez
 * Author URI:        https://jan.boddez.net/
 * License:           GNU General Public License v3
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       share-on-mastodon
 * Version:           0.20.1
 * Requires at least: 5.9
 * Requires PHP:      7.2
 *
 * @author  Jan Boddez <jan@janboddez.be>
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/includes/class-block-editor.php';
require __DIR__ . '/includes/class-image-handler.php';
require __DIR__ . '/includes/class-micropub-compat.php';
require __DIR__ . '/includes/class-notices.php';
require __DIR__ . '/includes/class-options-handler.php';
require __DIR__ . '/includes/class-post-handler.php';
require __DIR__ . '/includes/class-share-on-mastodon.php';
require __DIR__ . '/includes/class-syn-links-compat.php';
require __DIR__ . '/includes/functions.php';

Share_On_Mastodon::get_instance()
	->register();
