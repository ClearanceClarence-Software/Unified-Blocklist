<?php
/**
 * Fast term matching.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * Matches normalized terms against submitted fields.
 *
 * Core WordPress runs one regular expression per term per field. Here all fields are joined into a
 * single normalized haystack and each term is a plain strpos() call, which is roughly two orders of
 * magnitude faster on large community lists. Whole-word checks only run on candidate hits.
 */
final class Matcher {

	/**
	 * Normalized terms.
	 *
	 * @var string[]
	 */
	private $terms;

	/**
	 * Substring or whole-word matching.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Constructor.
	 *
	 * @param string[] $terms Normalized terms.
	 * @param string   $mode  'substring' or 'word'.
	 */
	public function __construct( array $terms, $mode = 'substring' ) {
		$this->terms = $terms;
		$this->mode  = 'word' === $mode ? 'word' : 'substring';
	}

	/**
	 * Matcher for the current compiled list and settings.
	 *
	 * @return self
	 */
	public static function from_settings() {
		return new self( TermList::terms(), (string) Settings::get( 'match_mode' ) );
	}

	/**
	 * Lowercases, decodes entities, removes invisible characters and collapses whitespace,
	 * so terms and submissions compare the same way.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_check_invalid_utf8( $text, true );
		// Zero-width characters, soft hyphen and BOM are common ways to slip past keyword filters.
		$text = preg_replace( '/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( mb_strtolower( (string) $text, 'UTF-8' ) );
	}

	/**
	 * Finds matches.
	 *
	 * @param array $fields Label => value. Values may be arrays; they are flattened.
	 * @param int   $limit  Maximum number of hits to return (0 for all).
	 * @return Hit[]
	 */
	public function find( array $fields, $limit = 1 ) {
		$haystack = '';
		$ranges   = array();

		foreach ( $fields as $label => $value ) {
			$value = self::normalize( self::stringify( $value ) );
			if ( '' === $value ) {
				continue;
			}
			$start    = strlen( $haystack );
			$ranges[] = array( $start, $start + strlen( $value ), (string) $label );
			// A newline separates fields, so a term can never span two of them.
			$haystack .= $value . "\n";
		}

		if ( '' === $haystack ) {
			return array();
		}

		$hits = array();
		foreach ( $this->terms as $term ) {
			$pos = strpos( $haystack, $term );
			while ( false !== $pos ) {
				if ( 'substring' === $this->mode || $this->at_word_boundaries( $haystack, $term, $pos ) ) {
					$hits[] = $this->hit( $haystack, $ranges, $term, $pos );
					break;
				}
				$pos = strpos( $haystack, $term, $pos + 1 );
			}
			if ( $limit > 0 && count( $hits ) >= $limit ) {
				break;
			}
		}

		return $hits;
	}

	/**
	 * Finds matches in many documents at once. All documents go into one haystack, so each term
	 * costs one strpos() pass however many documents there are.
	 *
	 * @param array<int, array> $docs    Documents, each an array of label => value.
	 * @param int               $per_doc Most hits kept per document.
	 * @return array<int, Hit[]> Hits by document index. Documents without hits are left out.
	 */
	public function scan( array $docs, $per_doc = 3 ) {
		$haystack = '';
		$ranges   = array();
		$starts   = array();
		$owners   = array();

		foreach ( $docs as $index => $fields ) {
			$starts[] = strlen( $haystack );
			$owners[] = $index;
			foreach ( (array) $fields as $label => $value ) {
				$value = self::normalize( self::stringify( $value ) );
				if ( '' === $value ) {
					continue;
				}
				$start     = strlen( $haystack );
				$ranges[]  = array( $start, $start + strlen( $value ), (string) $label );
				$haystack .= $value . "\n";
			}
			// An empty line between documents. Normalized terms never contain a newline.
			$haystack .= "\n";
		}

		$hits = array();
		if ( '' === trim( $haystack ) ) {
			return $hits;
		}

		// Every 4-byte sequence in the haystack. A term with a sequence that is not in this set
		// cannot occur, so most of a large list is ruled out with a few lookups instead of a
		// strpos() over the whole haystack.
		$grams = array();
		$last  = strlen( $haystack ) - 4;
		for ( $i = 0; $i <= $last; $i++ ) {
			$grams[ substr( $haystack, $i, 4 ) ] = true;
		}

		foreach ( $this->terms as $term ) {
			$len = strlen( $term );
			if ( $len >= 4 && ( ! isset( $grams[ substr( $term, 0, 4 ) ] ) || ! isset( $grams[ substr( $term, -4 ) ] ) || ( $len >= 8 && ! isset( $grams[ substr( $term, ( $len >> 1 ) - 2, 4 ) ] ) ) ) ) {
				continue;
			}
			$pos = strpos( $haystack, $term );
			while ( false !== $pos ) {
				$doc = self::owner( $starts, $pos );
				if ( 'substring' === $this->mode || $this->at_word_boundaries( $haystack, $term, $pos ) ) {
					$owner = $owners[ $doc ];
					if ( ! isset( $hits[ $owner ] ) || count( $hits[ $owner ] ) < $per_doc ) {
						$hits[ $owner ][] = $this->hit( $haystack, self::field_range( $ranges, $pos ), $term, $pos );
					}
					// One hit per term per document is enough: go on from the next document.
					if ( ! isset( $starts[ $doc + 1 ] ) ) {
						break;
					}
					$pos = strpos( $haystack, $term, $starts[ $doc + 1 ] );
					continue;
				}
				$pos = strpos( $haystack, $term, $pos + 1 );
			}
		}

		ksort( $hits );
		return $hits;
	}

	/**
	 * Index of the document that contains a byte offset.
	 *
	 * @param int[] $starts Document start offsets, ascending.
	 * @param int   $pos    Offset.
	 * @return int
	 */
	private static function owner( array $starts, $pos ) {
		$lo = 0;
		$hi = count( $starts ) - 1;
		while ( $lo < $hi ) {
			$mid = ( $lo + $hi + 1 ) >> 1;
			if ( $starts[ $mid ] <= $pos ) {
				$lo = $mid;
			} else {
				$hi = $mid - 1;
			}
		}
		return $lo;
	}

	/**
	 * The one field range that contains an offset, as a list for hit().
	 *
	 * @param array $ranges Ranges, ascending.
	 * @param int   $pos    Offset.
	 * @return array
	 */
	private static function field_range( array $ranges, $pos ) {
		$lo = 0;
		$hi = count( $ranges ) - 1;
		while ( $lo < $hi ) {
			$mid = ( $lo + $hi + 1 ) >> 1;
			if ( $ranges[ $mid ][0] <= $pos ) {
				$lo = $mid;
			} else {
				$hi = $mid - 1;
			}
		}
		return isset( $ranges[ $lo ] ) ? array( $ranges[ $lo ] ) : array();
	}

	/**
	 * First match, or null.
	 *
	 * @param array $fields Label => value.
	 * @return Hit|null
	 */
	public function first( array $fields ) {
		$hits = $this->find( $fields, 1 );
		return $hits ? $hits[0] : null;
	}

	/**
	 * Whole-word check. A boundary is only required on a side where the term starts or ends with a
	 * letter or digit, so terms such as ":)" or "$$$" still work.
	 *
	 * @param string $haystack Haystack.
	 * @param string $term     Term.
	 * @param int    $pos      Byte offset.
	 * @return bool
	 */
	private function at_word_boundaries( $haystack, $term, $pos ) {
		if ( preg_match( '/^[\p{L}\p{N}]/u', $term ) && preg_match( '/\G(?<=[\p{L}\p{N}])/u', $haystack, $m, 0, $pos ) ) {
			return false;
		}
		if ( preg_match( '/[\p{L}\p{N}]$/u', $term ) && preg_match( '/\G[\p{L}\p{N}]/u', $haystack, $m, 0, $pos + strlen( $term ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Builds a hit with the field label and a short excerpt around the match.
	 *
	 * @param string $haystack Haystack.
	 * @param array  $ranges   Field ranges.
	 * @param string $term     Term.
	 * @param int    $pos      Byte offset.
	 * @return Hit
	 */
	private function hit( $haystack, array $ranges, $term, $pos ) {
		$label = '';
		$from  = 0;
		$to    = strlen( $haystack );
		foreach ( $ranges as $range ) {
			if ( $pos >= $range[0] && $pos < $range[1] ) {
				list( $from, $to, $label ) = $range;
				break;
			}
		}

		$pad     = 60;
		$start   = max( $from, $pos - $pad );
		$end     = min( $to, $pos + strlen( $term ) + $pad );
		$excerpt = mb_strcut( $haystack, $start, $end - $start, 'UTF-8' );
		$excerpt = ( $start > $from ? '…' : '' ) . trim( $excerpt ) . ( $end < $to ? '…' : '' );

		return new Hit( $term, $label, $excerpt );
	}

	/**
	 * Turns nested values into a single string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function stringify( $value ) {
		if ( is_array( $value ) ) {
			$parts = array();
			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$parts ) {
					if ( is_scalar( $item ) ) {
						$parts[] = (string) $item;
					}
				}
			);
			return implode( ' ', $parts );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
