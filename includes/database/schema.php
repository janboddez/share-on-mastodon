<?php
/**
 * @package Share_On_Mastodon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
?>
CREATE TABLE <?php echo \Share_On_Mastodon\Mastodon_Client::table(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> (
	id mediumint(9) UNSIGNED NOT NULL AUTO_INCREMENT,
	host varchar(191) NOT NULL,
	client_name varchar(191),
	website varchar(191),
	scopes varchar(191),
	redirect_uris text,
	client_id varchar(191) NOT NULL,
	client_secret varchar(191) NOT NULL,
	vapid_key varchar(191),
	client_token varchar(191),
	created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
	modified_at datetime,
	PRIMARY KEY (id)
) <?php echo $wpdb->get_charset_collate(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
