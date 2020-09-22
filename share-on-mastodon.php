<?php
/**
 * Plugin Name: Share on Mastodon
 * Description: Easily share WordPress posts on Mastodon.
 * Plugin URI:  https://jan.boddez.net/wordpress/share-on-mastodon
 * Author:      Jan Boddez
 * Author URI:  https://jan.boddez.net/
 * License: GNU General Public License v3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: share-on-mastodon
 * Version:     0.5.2
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

require dirname( __FILE__ ) . '/includes/class-options-handler.php';
require dirname( __FILE__ ) . '/includes/class-post-handler.php';
require dirname( __FILE__ ) . '/includes/class-share-on-mastodon.php';

Share_On_Mastodon::get_instance()->register();
