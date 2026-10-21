<?php
/**
 * Storage for large term lists.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Term lists live in the plugin's own table, {prefix}unified_blocklist_lists, never in
 * wp_options. A 66,000-term list is about 350 KB compressed; it is stored base64-encoded in
 * chunks of under 400 KB, so no single write comes near MySQL's default max_allowed_packet.
 *
 * Writes go to a staging key first and only replace the live copy once they read back
 * correctly, so a failed write leaves the previous list in place and reports the error.
 */
final class Storage {

	const CHUNK_BYTES = 400000;

	/**
	 * Request-level cache of lists already loaded.
	 *
	 * @var array<string, string[]>
	 */
	private static $loaded = array();

	/**
	 * Stores a list of terms.
	 *
	 * @param string   $key   List key.
	 * @param string[] $terms Terms.
	 * @return true|WP_Error
	 */
	public static function put( $key, array $terms ) {
		global $wpdb;

		// Compressed and base64-encoded so the chunks are plain text the database handles safely.
		$blob   = base64_encode( gzcompress( implode( "\n", $terms ), 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$chunks = str_split( $blob, self::CHUNK_BYTES );
		$total  = count( $chunks );
		$hash   = md5( $blob );
		$now    = current_time( 'mysql', true );
		$stage  = $key . '~new';

		self::remove( $stage );

		// wpdb clears last_error on every query, so it has to be caught right after each write.
		$db_error = '';
		foreach ( $chunks as $i => $chunk ) {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
			$wpdb->insert(
				$wpdb->prefix . 'unified_blocklist_lists',
				array(
					'list_key'     => $stage,
					'chunk_no'     => $i,
					'total_chunks' => $total,
					'term_count'   => count( $terms ),
					'hash'         => $hash,
					'data'         => $chunk,
					'updated_at'   => $now,
				),
				array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
			if ( '' !== $wpdb->last_error ) {
				$db_error = (string) $wpdb->last_error;
				break;
			}
		}

		// Trust nothing until it reads back correctly.
		$check = '' === $db_error ? self::read( $stage ) : null;
		if ( null === $check || count( $check ) !== count( $terms ) ) {
			self::remove( $stage );
			$message = '' !== $db_error ? $db_error : __( 'The database did not keep the saved list.', 'unified-blocklist' );
			/* translators: %s: database error message. */
			return new WP_Error( 'unified_blocklist_storage', sprintf( __( 'Could not store the list in the database: %s', 'unified-blocklist' ), $message ) );
		}

		// Swap the verified copy in.
		self::remove( $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; the cache is refreshed just below.
		$wpdb->update( $wpdb->prefix . 'unified_blocklist_lists', array( 'list_key' => $key ), array( 'list_key' => $stage ), array( '%s' ), array( '%s' ) );

		self::$loaded[ $key ] = $check;
		wp_cache_set( $key, $check, 'unified_blocklist', HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Loads a list.
	 *
	 * @param string $key   List key.
	 * @param bool   $fresh Skip caches.
	 * @return string[]|null Null when nothing valid is stored.
	 */
	public static function get( $key, $fresh = false ) {
		if ( ! $fresh && isset( self::$loaded[ $key ] ) ) {
			return self::$loaded[ $key ];
		}

		if ( ! $fresh ) {
			$cached = wp_cache_get( $key, 'unified_blocklist' );
			if ( is_array( $cached ) ) {
				self::$loaded[ $key ] = $cached;
				return $cached;
			}
		}

		$terms = self::read( $key );
		if ( null === $terms ) {
			return null;
		}

		self::$loaded[ $key ] = $terms;
		wp_cache_set( $key, $terms, 'unified_blocklist', HOUR_IN_SECONDS );
		return $terms;
	}

	/**
	 * Stored metadata, without decoding the list.
	 *
	 * @param string $key List key.
	 * @return array{count: int, chunks: int, hash: string, updated_at: string}|null
	 */
	public static function meta( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT total_chunks, term_count, hash, updated_at FROM {$wpdb->prefix}unified_blocklist_lists WHERE list_key = %s AND chunk_no = 0", $key ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'count'      => (int) $row['term_count'],
			'chunks'     => (int) $row['total_chunks'],
			'hash'       => (string) $row['hash'],
			'updated_at' => (string) $row['updated_at'],
		);
	}

	/**
	 * Removes a list.
	 *
	 * @param string $key List key.
	 */
	public static function delete( $key ) {
		self::remove( $key );
		self::remove( $key . '~new' );
		unset( self::$loaded[ $key ] );
		wp_cache_delete( $key, 'unified_blocklist' );
	}

	/**
	 * Reads and verifies a list straight from the table.
	 *
	 * @param string $key List key.
	 * @return string[]|null
	 */
	private static function read( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached by the caller.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT chunk_no, total_chunks, term_count, hash, data FROM {$wpdb->prefix}unified_blocklist_lists WHERE list_key = %s ORDER BY chunk_no ASC", $key ), ARRAY_A );
		if ( ! is_array( $rows ) || ! $rows || count( $rows ) !== (int) $rows[0]['total_chunks'] ) {
			return null;
		}

		$blob = '';
		foreach ( $rows as $i => $row ) {
			if ( (int) $row['chunk_no'] !== $i ) {
				return null;
			}
			$blob .= $row['data'];
		}

		if ( md5( $blob ) !== $rows[0]['hash'] ) {
			return null;
		}

		$raw = gzuncompress( base64_decode( $blob ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Our own encoding.
		if ( false === $raw ) {
			return null;
		}

		$terms = '' === $raw ? array() : explode( "\n", $raw );
		return count( $terms ) === (int) $rows[0]['term_count'] ? $terms : null;
	}

	/**
	 * Deletes every row of one key.
	 *
	 * @param string $key List key.
	 */
	private static function remove( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->delete( $wpdb->prefix . 'unified_blocklist_lists', array( 'list_key' => $key ), array( '%s' ) );
	}
}
