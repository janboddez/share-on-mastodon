<?php
/**
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * An extremely simple "ORM" of sorts.
 */
class Mastodon_Client {
	/**
	 * Custom table name.
	 */
	const TABLE = 'share_on_mastodon_clients';

	/**
	 * Returns one or more (or no) previously registered apps.
	 *
	 * @param  int|array $where  ID, or associative array representing a simple "where" clause.
	 * @return array|object|null (Possibly empty) array of "app" objects, or single "app" object, or `null`.
	 */
	public static function find( $where ) {
		global $wpdb;

		if ( ! empty( $where['host'] ) ) {
			$sql = sprintf( 'SELECT * FROM %s WHERE host = %%s', static::table() );

			// Return all rows so we can iterate over them, because if we returned only the first restult and that was
			// invalid somehow, we'd still be re-registering apps all the time.
			return $wpdb->get_results( $wpdb->prepare( $sql, $where['host'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( is_int( $where ) ) {
			$sql = sprintf( 'SELECT * FROM %s WHERE id = %%d', static::table() );

			// Return just one row.
			return $wpdb->get_row( $wpdb->prepare( $sql, $where ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		}

		return null;
	}

	/**
	 * Inserts a newly registered app.
	 *
	 * @param  array $data Associative array representing an API client.
	 * @return int         (Internal) app ID.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql', 1 );

		if ( $wpdb->insert( static::table(), $data ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return $wpdb->insert_id;
		}

		return 0;
	}

	/**
	 * Updates an existing app.
	 *
	 * @param  array $data  Associative array representing an API client.
	 * @param  array $where Associative array representing a "where" clause.
	 * @return int|false    Number of rows affected, or `false` on failure.
	 */
	public static function update( $data, $where ) {
		global $wpdb;

		$data['modified_at'] = current_time( 'mysql', 1 );

		return $wpdb->update( static::table(), $data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Deletes an app.
	 *
	 * @param  int $id   App ID.
	 * @return int|false Number of rows affected, or `false` on failure.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$sql = sprintf( 'DELETE FROM %s WHERE id = %%s', static::table() );

		return $wpdb->query( $wpdb->prepare( $sql, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns (prefixed) table name.
	 *
	 * @return string Table name.
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . static::TABLE;
	}
}
