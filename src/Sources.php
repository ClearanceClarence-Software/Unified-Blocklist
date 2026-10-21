<?php
/**
 * Remote list fetching with last-good copies.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * Each source keeps its own last good copy, so one failing source never empties the list.
 */
final class Sources {

	const STATE_OPTION  = 'unified_blocklist_source_state';
	const TERMS_PREFIX  = 'unified_blocklist_src_';
	const MAX_BYTES     = 8388608; // 8 MB.
	const MAX_TERM_LEN  = 500;
	const SHRINK_FACTOR = 0.5;

	/**
	 * Fetches every configured source, then rebuilds the compiled list.
	 *
	 * @param bool $accept_shrink Accept lists that shrank past the guard (manual override).
	 * @return array Per-source results keyed by URL.
	 */
	public static function sync_all( $accept_shrink = false ) {
		$accept_shrink = true === $accept_shrink;
		$results       = array();
		$state         = self::state();
		$active_keys   = array();

		foreach ( (array) Settings::get( 'sources' ) as $url ) {
			$key             = self::key( $url );
			$active_keys[]   = $key;
			$previous        = isset( $state[ $key ] ) ? $state[ $key ] : array();
			$state[ $key ]   = self::fetch_one( $url, $previous, $accept_shrink );
			$results[ $url ] = $state[ $key ];
		}

		// Forget sources that were removed from settings.
		foreach ( array_keys( $state ) as $key ) {
			if ( ! in_array( $key, $active_keys, true ) ) {
				unset( $state[ $key ] );
				Storage::delete( self::TERMS_PREFIX . $key );
			}
		}

		update_option( self::STATE_OPTION, $state, false );
		update_option( 'unified_blocklist_last_sync', time(), false );
		TermList::rebuild();

		return $results;
	}

	/**
	 * Fetches one source. On any failure the previous copy is kept and the error is recorded.
	 *
	 * @param string $url           URL.
	 * @param array  $previous      Previous state for this source.
	 * @param bool   $accept_shrink Skip the shrink guard.
	 * @return array New state.
	 */
	private static function fetch_one( $url, array $previous, $accept_shrink ) {
		$key   = self::key( $url );
		$state = array_merge(
			array(
				'url'           => $url,
				'count'         => 0,
				'etag'          => '',
				'last_modified' => '',
				'updated_at'    => 0,
			),
			$previous
		);

		$state['checked_at'] = time();
		$has_copy            = null !== Storage::meta( self::TERMS_PREFIX . $key );
		$headers             = array();

		if ( $has_copy && '' !== $state['etag'] ) {
			$headers['If-None-Match'] = $state['etag'];
		}
		if ( $has_copy && '' !== $state['last_modified'] ) {
			$headers['If-Modified-Since'] = $state['last_modified'];
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return self::fail( $state, __( 'This address is local, private or could not be resolved, so it was not requested.', 'unified-blocklist' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'UnifiedBlocklist/' . UNIFIED_BLOCKLIST_VERSION,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fail( $state, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 304 === $code && $has_copy ) {
			$state['status'] = 'ok';
			$state['note']   = 'unchanged';
			$state['error']  = '';
			return $state;
		}

		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return self::fail( $state, sprintf( __( 'The server answered with HTTP %d.', 'unified-blocklist' ), $code ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( false !== strpos( $type, 'html' ) || self::looks_like_html( $body ) ) {
			return self::fail( $state, __( 'The response was a web page, not a plain-text list.', 'unified-blocklist' ) );
		}

		$terms = self::parse( $body );
		$count = count( $terms );

		if ( 0 === $count ) {
			return self::fail( $state, __( 'The list was empty.', 'unified-blocklist' ) );
		}

		$previous_count = (int) $state['count'];
		if ( ! $accept_shrink && Settings::get( 'shrink_guard' ) && $previous_count >= 100 && $count < $previous_count * self::SHRINK_FACTOR ) {
			return self::fail(
				$state,
				sprintf(
					/* translators: 1: new term count, 2: previous term count. */
					__( 'The list shrank from %2$s to %1$s terms, so the previous copy was kept. Use "Sync and accept smaller lists" if this is expected.', 'unified-blocklist' ),
					number_format_i18n( $count ),
					number_format_i18n( $previous_count )
				),
				'shrink'
			);
		}

		$stored = Storage::put( self::TERMS_PREFIX . $key, $terms );
		if ( is_wp_error( $stored ) ) {
			// The download worked, but the database refused the list. Say so instead of
			// reporting a successful sync with nothing behind it.
			return self::fail( $state, $stored->get_error_message() );
		}

		$state['status']        = 'ok';
		$state['note']          = 'updated';
		$state['error']         = '';
		$state['count']         = $count;
		$state['updated_at']    = time();
		$state['etag']          = (string) wp_remote_retrieve_header( $response, 'etag' );
		$state['last_modified'] = (string) wp_remote_retrieve_header( $response, 'last-modified' );

		return $state;
	}

	/**
	 * Marks a failed fetch.
	 *
	 * @param array  $state   State.
	 * @param string $message Error.
	 * @param string $status  Status code.
	 * @return array
	 */
	private static function fail( array $state, $message, $status = 'error' ) {
		$state['status'] = $status;
		$state['error']  = $message;
		$state['note']   = '';
		return $state;
	}

	/**
	 * Detects an HTML error page served with a text content type.
	 *
	 * @param string $body Body.
	 * @return bool
	 */
	private static function looks_like_html( $body ) {
		$head = strtolower( ltrim( substr( $body, 0, 512 ), "\xEF\xBB\xBF \t\r\n" ) );
		return 0 === strpos( $head, '<!doctype' ) || 0 === strpos( $head, '<html' ) || false !== strpos( $head, '<head>' ) || false !== strpos( $head, '<body' );
	}

	/**
	 * Parses a one-term-per-line list. Every non-empty line is a term; there is no comment syntax,
	 * because real lists contain terms that start with "#".
	 *
	 * @param string $body Raw body.
	 * @return string[]
	 */
	public static function parse( $body ) {
		$body  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $body );
		$terms = array();
		foreach ( Settings::lines( $body ) as $line ) {
			$line = Settings::clean_term( $line );
			if ( '' !== $line && strlen( $line ) <= self::MAX_TERM_LEN ) {
				$terms[ $line ] = true;
			}
		}
		return array_keys( $terms );
	}

	/**
	 * Stored terms for one source.
	 *
	 * @param string $url URL.
	 * @return string[]
	 */
	public static function terms_for( $url ) {
		$terms = Storage::get( self::TERMS_PREFIX . self::key( $url ) );
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * State for all sources.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * State for one URL.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	public static function state_for( $url ) {
		$state = self::state();
		$key   = self::key( $url );
		return isset( $state[ $key ] ) ? $state[ $key ] : null;
	}

	/**
	 * Storage key for a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function key( $url ) {
		return substr( md5( (string) $url ), 0, 16 );
	}

	/**
	 * Removes every stored copy (used by uninstall).
	 */
	public static function delete_all() {
		foreach ( array_keys( self::state() ) as $key ) {
			Storage::delete( self::TERMS_PREFIX . $key );
		}
		delete_option( self::STATE_OPTION );
		delete_option( 'unified_blocklist_last_sync' );
	}
}
