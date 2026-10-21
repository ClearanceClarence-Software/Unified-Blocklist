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
 * A 66,000-term list serialized as a PHP array is about 1.8 MB. MySQL and MariaDB refuse any
 * single write larger than max_allowed_packet, which XAMPP and many shared hosts leave at the
 * 1 MB default, and WordPress reports that as a quiet "false" from update_option().
 *
 * So lists are stored as compressed, base64-encoded text split into chunks of well under 1 MB,
 * and every write is read back and verified before it counts as saved.
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
	 * @param string   $key   Option key prefix.
	 * @param string[] $terms Terms.
	 * @return true|WP_Error
	 */
	public static function put( $key, array $terms ) {
		global $wpdb;

		// Compressed and base64-encoded so the chunks are plain text the options table handles safely.
		$blob   = base64_encode( gzcompress( implode( "\n", $terms ), 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$chunks = str_split( $blob, self::CHUNK_BYTES );
		$meta   = array(
			'format' => 2,
			'chunks' => count( $chunks ),
			'count'  => count( $terms ),
			'hash'   => md5( $blob ),
			'bytes'  => strlen( $blob ),
		);

		// wpdb clears last_error on every query, so it has to be caught right after each write.
		$db_error = '';
		foreach ( $chunks as $i => $chunk ) {
			$wpdb->last_error = '';
			update_option( self::chunk_key( $key, $i ), $chunk, false );
			$db_error = '' !== $wpdb->last_error ? (string) $wpdb->last_error : $db_error;
		}
		$wpdb->last_error = '';
		update_option( $key, $meta, false );
		$db_error = '' !== $wpdb->last_error ? (string) $wpdb->last_error : $db_error;

		// Forget chunks left over from a longer previous version.
		$n_chunks = count( $chunks );
		$stale_to = max( (int) get_option( $key . '_prev_chunks', 0 ), $n_chunks + 8 );
		for ( $i = $n_chunks; $i < $stale_to; $i++ ) {
			delete_option( self::chunk_key( $key, $i ) );
		}
		update_option( $key . '_prev_chunks', $n_chunks, false );

		unset( self::$loaded[ $key ] );
		wp_cache_delete( $key, 'unified_blocklist' );

		// Trust nothing until it reads back correctly.
		$check = self::get( $key, true );
		if ( null === $check || count( $check ) !== count( $terms ) ) {
			$message = '' !== $db_error
				? $db_error
				: __( 'The database did not keep the saved list.', 'unified-blocklist' );
			/* translators: %s: database error message. */
			return new WP_Error( 'unified_blocklist_storage', sprintf( __( 'Could not store the list in the database: %s', 'unified-blocklist' ), $message ) );
		}

		return true;
	}

	/**
	 * Loads a list.
	 *
	 * @param string $key   Option key prefix.
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

		$meta = get_option( $key, null );
		if ( ! is_array( $meta ) || empty( $meta['format'] ) ) {
			return null;
		}

		$blob = '';
		for ( $i = 0; $i < (int) $meta['chunks']; $i++ ) {
			$chunk = get_option( self::chunk_key( $key, $i ), null );
			if ( ! is_string( $chunk ) ) {
				return null;
			}
			$blob .= $chunk;
		}

		if ( md5( $blob ) !== $meta['hash'] ) {
			return null;
		}

		$raw = gzuncompress( base64_decode( $blob ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Our own encoding.
		if ( false === $raw ) {
			return null;
		}

		$terms = '' === $raw ? array() : explode( "\n", $raw );
		if ( count( $terms ) !== (int) $meta['count'] ) {
			return null;
		}

		self::$loaded[ $key ] = $terms;
		wp_cache_set( $key, $terms, 'unified_blocklist', HOUR_IN_SECONDS );
		return $terms;
	}

	/**
	 * Stored metadata, without loading the list.
	 *
	 * @param string $key Option key prefix.
	 * @return array|null
	 */
	public static function meta( $key ) {
		$meta = get_option( $key, null );
		return is_array( $meta ) && ! empty( $meta['format'] ) ? $meta : null;
	}

	/**
	 * Removes a list and its chunks.
	 *
	 * @param string $key Option key prefix.
	 */
	public static function delete( $key ) {
		$meta = get_option( $key, null );
		$n    = is_array( $meta ) && isset( $meta['chunks'] ) ? (int) $meta['chunks'] : 0;
		$upto = max( $n, (int) get_option( $key . '_prev_chunks', 0 ) ) + 8;
		for ( $i = 0; $i < $upto; $i++ ) {
			delete_option( self::chunk_key( $key, $i ) );
		}
		delete_option( $key . '_prev_chunks' );
		delete_option( $key );
		unset( self::$loaded[ $key ] );
		wp_cache_delete( $key, 'unified_blocklist' );
	}

	/**
	 * Option name of one chunk.
	 *
	 * @param string $key Prefix.
	 * @param int    $i   Index.
	 * @return string
	 */
	private static function chunk_key( $key, $i ) {
		return $key . '_c' . (int) $i;
	}
}
