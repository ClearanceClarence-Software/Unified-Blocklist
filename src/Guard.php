<?php
/**
 * Shared decision point for every integration.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a submission, logs what matched, and tells the integration whether to act.
 */
final class Guard {

	/**
	 * Checks fields. Returns a hit only when the submission should be stopped; in monitor mode the
	 * match is logged and null is returned so the submission goes through.
	 *
	 * @param string $context Integration slug, such as "comment" or "cf7".
	 * @param array  $fields  Label => value.
	 * @param string $action  What the integration will do on a hit (logged), such as "spam" or "blocked".
	 * @param string $ref     Optional reference, such as a post or form ID.
	 * @return Hit|null
	 */
	public static function check( $context, array $fields, $action, $ref = '' ) {
		if ( Settings::get( 'skip_trusted' ) && current_user_can( 'moderate_comments' ) ) {
			return null;
		}

		if ( 0 === (int) TermList::record()['count'] ) {
			return null;
		}

		/**
		 * Filters the fields checked for one submission.
		 *
		 * @param array  $fields  Label => value.
		 * @param string $context Integration slug.
		 */
		$fields = (array) apply_filters( 'unified_blocklist_fields', $fields, $context );

		$hit = Matcher::from_settings()->first( $fields );
		if ( null === $hit ) {
			return null;
		}

		$enforce = 'enforce' === Settings::get( 'mode' );

		/**
		 * Lets code overrule a match, for example to allow a specific form.
		 *
		 * @param bool   $enforce Whether to act on the match.
		 * @param Hit    $hit     The match.
		 * @param string $context Integration slug.
		 * @param array  $fields  Fields that were checked.
		 */
		$enforce = (bool) apply_filters( 'unified_blocklist_enforce', $enforce, $hit, $context, $fields );

		Log::add( $context, $enforce ? $action : 'logged', $hit, $ref );

		/**
		 * Fires after a match is found.
		 *
		 * @param Hit    $hit     The match.
		 * @param string $context Integration slug.
		 * @param bool   $enforce Whether the integration will act on it.
		 */
		do_action( 'unified_blocklist_matched', $hit, $context, $enforce );

		return $enforce ? $hit : null;
	}
}
