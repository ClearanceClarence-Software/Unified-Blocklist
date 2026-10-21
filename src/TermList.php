<?php
/**
 * The compiled, normalized list used for matching.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and loads the single list every integration matches against.
 */
final class TermList {

	const OPTION      = 'unified_blocklist_compiled';
	const TERMS_KEY   = 'unified_blocklist_compiled_terms';
	const CACHE_GROUP = 'unified_blocklist';

	/**
	 * Request-level copy.
	 *
	 * @var array|null
	 */
	private static $loaded = null;

	/**
	 * Merges sources, local terms and (optionally) the core Discussion list, then removes exceptions.
	 *
	 * @return array Compiled record.
	 */
	public static function rebuild() {
		$settings   = Settings::all();
		$min_length = (int) $settings['min_length'];
		$terms      = array();
		$counts     = array(
			'sources' => 0,
			'local'   => 0,
			'core'    => 0,
			'skipped' => 0,
			'removed' => 0,
		);

		// Remote terms honor the minimum length. Terms the site owner typed are always kept.
		foreach ( (array) $settings['sources'] as $url ) {
			foreach ( Sources::terms_for( $url ) as $term ) {
				$norm = Matcher::normalize( $term );
				if ( '' === $norm ) {
					continue;
				}
				if ( mb_strlen( $norm ) < $min_length ) {
					++$counts['skipped'];
					continue;
				}
				$terms[ $norm ] = true;
			}
		}
		$counts['sources'] = count( $terms );

		foreach ( (array) $settings['local_terms'] as $term ) {
			$norm = Matcher::normalize( $term );
			if ( '' !== $norm ) {
				$terms[ $norm ] = true;
				++$counts['local'];
			}
		}

		if ( $settings['include_core'] ) {
			foreach ( self::core_terms() as $term ) {
				$norm = Matcher::normalize( $term );
				if ( '' !== $norm ) {
					$terms[ $norm ] = true;
					++$counts['core'];
				}
			}
		}

		foreach ( (array) $settings['exceptions'] as $term ) {
			$norm = Matcher::normalize( $term );
			if ( isset( $terms[ $norm ] ) ) {
				unset( $terms[ $norm ] );
				++$counts['removed'];
			}
		}

		/**
		 * Filters the final normalized term list.
		 *
		 * @param string[] $terms Terms.
		 */
		$list = array_values( array_unique( (array) apply_filters( 'unified_blocklist_terms', array_keys( $terms ) ) ) );

		$stored = Storage::put( self::TERMS_KEY, $list );
		$record = array(
			'count'    => is_wp_error( $stored ) ? 0 : count( $list ),
			'counts'   => $counts,
			'built_at' => time(),
			'error'    => is_wp_error( $stored ) ? $stored->get_error_message() : '',
		);

		update_option( self::OPTION, $record, false );
		self::$loaded = null;

		return self::record();
	}

	/**
	 * Rebuilds only when the core list is part of the compiled list.
	 */
	public static function rebuild_if_core_included() {
		if ( Settings::get( 'include_core' ) ) {
			self::rebuild();
		}
	}

	/**
	 * Lines from Settings > Discussion > Disallowed Comment Keys.
	 *
	 * @return string[]
	 */
	public static function core_terms() {
		return array_filter( Settings::lines( (string) get_option( 'disallowed_keys', '' ) ), 'strlen' );
	}

	/**
	 * The compiled record.
	 *
	 * @return array
	 */
	public static function record() {
		if ( null !== self::$loaded ) {
			return self::$loaded;
		}

		$record = get_option( self::OPTION, array() );
		$record = is_array( $record ) ? $record : array();
		unset( $record['terms'] ); // Pre-1.0 format kept the list in this option.

		self::$loaded = array_merge(
			array(
				'count'    => 0,
				'counts'   => array(),
				'built_at' => 0,
				'error'    => '',
			),
			$record
		);
		return self::$loaded;
	}

	/**
	 * The compiled terms. Loaded only when something needs matching.
	 *
	 * @return string[]
	 */
	public static function terms() {
		$terms = Storage::get( self::TERMS_KEY );
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Where a normalized term comes from, for the tester and log.
	 *
	 * @param string $term Normalized term.
	 * @return string[] Labels.
	 */
	public static function origins( $term ) {
		static $local = null, $core = null, $flips = array();

		if ( null === $local ) {
			$local = array_flip( array_map( array( Matcher::class, 'normalize' ), (array) Settings::get( 'local_terms' ) ) );
			$core  = Settings::get( 'include_core' ) ? array_flip( array_map( array( Matcher::class, 'normalize' ), self::core_terms() ) ) : array();
		}

		$origins = array();
		$sources = (array) Settings::get( 'sources' );
		$own     = isset( $local[ $term ] ) || isset( $core[ $term ] );

		foreach ( $sources as $url ) {
			// With one source and a term that is not one of the site's own, the source is the only
			// possible origin, so the 66k-line list does not have to be normalized for the lookup.
			if ( 1 === count( $sources ) && ! $own ) {
				$found = true;
			} else {
				if ( ! isset( $flips[ $url ] ) ) {
					$flips[ $url ] = array_flip( array_map( array( Matcher::class, 'normalize' ), Sources::terms_for( $url ) ) );
				}
				$found = isset( $flips[ $url ][ $term ] );
			}
			if ( $found ) {
				$origins[] = self::source_label( $url );
			}
		}
		if ( isset( $local[ $term ] ) ) {
			$origins[] = __( 'Your terms', 'unified-blocklist' );
		}
		if ( isset( $core[ $term ] ) ) {
			$origins[] = __( 'Discussion settings', 'unified-blocklist' );
		}

		return $origins;
	}

	/**
	 * Explains why words in a text that appear in one of the lists did not match: they are
	 * shorter than the minimum length, listed as exceptions, or only appear inside other words
	 * while whole-word matching is on. Used by the tester when nothing matched.
	 *
	 * @param string $text  Text that produced no hits.
	 * @param int    $limit Maximum number of explanations.
	 * @return array<int, array{term: string, reason: string}>
	 */
	public static function explain( $text, $limit = 10 ) {
		$norm = Matcher::normalize( $text );
		if ( '' === $norm || strlen( $norm ) > 5000 ) {
			return array();
		}

		$settings   = Settings::all();
		$compiled   = array_flip( self::terms() );
		$exceptions = array_flip( array_map( array( Matcher::class, 'normalize' ), (array) $settings['exceptions'] ) );
		$min        = (int) $settings['min_length'];
		$word_mode  = 'word' === $settings['match_mode'];

		$raw = array();
		foreach ( (array) $settings['sources'] as $url ) {
			$raw = array_merge( $raw, Sources::terms_for( $url ) );
		}
		$raw = array_merge( $raw, (array) $settings['local_terms'] );
		if ( $settings['include_core'] ) {
			$raw = array_merge( $raw, self::core_terms() );
		}

		$out = array();
		foreach ( $raw as $term ) {
			// Cheap pre-check before the full normalization.
			if ( false === strpos( $norm, mb_strtolower( trim( (string) $term ), 'UTF-8' ) ) ) {
				continue;
			}
			$n = Matcher::normalize( $term );
			if ( '' === $n || isset( $out[ $n ] ) || false === strpos( $norm, $n ) ) {
				continue;
			}
			if ( isset( $exceptions[ $n ] ) ) {
				$out[ $n ] = 'exception';
			} elseif ( isset( $compiled[ $n ] ) ) {
				if ( $word_mode ) {
					$out[ $n ] = 'word';
				}
			} elseif ( mb_strlen( $n ) < $min ) {
				$out[ $n ] = 'short';
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		// A short text such as "casino" often is not a term by itself, but part of listed phrases
		// ("casino bonus", "-casino-"). Show a few, so it is clear why the word alone passes.
		if ( count( $out ) < $limit && strlen( $norm ) <= 60 ) {
			$found = 0;
			foreach ( array_keys( $compiled ) as $term ) {
				$term = (string) $term;
				if ( $term !== $norm && false !== strpos( $term, $norm ) && ! isset( $out[ $term ] ) ) {
					$out[ $term ] = 'phrase';
					if ( ++$found >= 5 || count( $out ) >= $limit ) {
						break;
					}
				}
			}
		}

		$result = array();
		foreach ( $out as $term => $reason ) {
			$result[] = array(
				'term'   => (string) $term,
				'reason' => $reason,
			);
		}
		return $result;
	}

	/**
	 * Short, readable name for a source URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function source_label( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 'raw.githubusercontent.com' === $host ) {
			// owner/repo is the useful part of a raw GitHub URL.
			$parts = array_values( array_filter( explode( '/', $path ) ) );
			return isset( $parts[1] ) ? $parts[0] . '/' . $parts[1] : $host;
		}
		return $host . $path;
	}

	/**
	 * Removes the compiled list (used by uninstall).
	 */
	public static function delete() {
		Storage::delete( self::TERMS_KEY );
		delete_option( self::OPTION );
		self::$loaded = null;
	}
}
