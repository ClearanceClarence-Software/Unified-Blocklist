<?php
/**
 * Contact Form 7.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist\Integrations;

use ClearanceClarence\UnifiedBlocklist\Guard;
use ClearanceClarence\UnifiedBlocklist\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Uses CF7's own spam pipeline, so the visitor sees CF7's spam message, no mail is sent,
 * and Flamingo (if installed) keeps the message in its spam folder.
 */
final class ContactForm7 {

	/**
	 * Registers the filter.
	 */
	public static function register() {
		add_filter( 'wpcf7_spam', array( self::class, 'filter' ), 20, 2 );
	}

	/**
	 * Filter callback.
	 *
	 * @param bool   $spam       Already spam.
	 * @param object $submission WPCF7_Submission.
	 * @return bool
	 */
	public static function filter( $spam, $submission ) {
		if ( $spam || ! self::enabled() || ! is_object( $submission ) || ! method_exists( $submission, 'get_posted_data' ) ) {
			return $spam;
		}

		$fields = array();
		foreach ( (array) $submission->get_posted_data() as $name => $value ) {
			if ( 0 === strpos( (string) $name, '_' ) ) {
				continue; // Internal CF7 fields.
			}
			$fields[ (string) $name ] = $value;
		}

		$form_id = 0;
		if ( method_exists( $submission, 'get_contact_form' ) ) {
			$form    = $submission->get_contact_form();
			$form_id = is_object( $form ) && method_exists( $form, 'id' ) ? (int) $form->id() : 0;
		}

		$hit = Guard::check( 'cf7', $fields, 'spam', $form_id ? 'form:' . $form_id : '' );
		if ( null === $hit ) {
			return $spam;
		}

		if ( method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log(
				array(
					'agent'  => 'unified_blocklist',
					/* translators: %s: matched term. */
					'reason' => sprintf( __( 'Unified Blocklist matched "%s".', 'unified-blocklist' ), $hit->term ),
				)
			);
		}

		return true;
	}

	/**
	 * Whether this integration is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$forms = (array) Settings::get( 'forms' );
		return ! empty( $forms['cf7'] );
	}

	/**
	 * Whether CF7 is active.
	 *
	 * @return bool
	 */
	public static function available() {
		return defined( 'WPCF7_VERSION' );
	}
}
