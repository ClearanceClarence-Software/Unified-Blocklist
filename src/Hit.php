<?php
/**
 * One match.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing what matched and where.
 */
final class Hit {

	/**
	 * Matched term (normalized).
	 *
	 * @var string
	 */
	public $term;

	/**
	 * Field label.
	 *
	 * @var string
	 */
	public $field;

	/**
	 * Text around the match.
	 *
	 * @var string
	 */
	public $excerpt;

	/**
	 * Constructor.
	 *
	 * @param string $term    Term.
	 * @param string $field   Field label.
	 * @param string $excerpt Excerpt.
	 */
	public function __construct( $term, $field, $excerpt ) {
		$this->term    = (string) $term;
		$this->field   = (string) $field;
		$this->excerpt = (string) $excerpt;
	}
}
