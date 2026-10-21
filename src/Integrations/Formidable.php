<?php
/**
 * Formidable Forms.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist\Integrations;

use ClearanceClarence\UnifiedBlocklist\Guard;
use ClearanceClarence\UnifiedBlocklist\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a spam error after Formidable's own validation and spam checks have passed.
 */
final class Formidable {

	/**
	 * Registers the filter.
	 */
	public static function register() {
		add_filter( 'frm_validate_entry', array( self::class, 'filter' ), 20, 2 );
	}

	/**
	 * Filter callback.
	 *
	 * @param array $errors Errors so far.
	 * @param array $values Posted values.
	 * @return array
	 */
	public static function filter( $errors, $values ) {
		if ( ! is_array( $errors ) || $errors || ! self::enabled() || ! is_array( $values ) || empty( $values['item_meta'] ) || ! is_array( $values['item_meta'] ) ) {
			return $errors;
		}

		// Only new submissions and edits made from the front end; admin imports are not checked.
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return $errors;
		}

		$fields = array();
		foreach ( $values['item_meta'] as $field_id => $value ) {
			if ( ! is_numeric( $field_id ) || 0 === (int) $field_id ) {
				continue;
			}
			$fields[ self::label( (int) $field_id ) ] = $value;
		}

		$form_id = isset( $values['form_id'] ) ? (int) $values['form_id'] : 0;
		$hit     = Guard::check( 'formidable', $fields, 'blocked', $form_id ? 'form:' . $form_id : '' );
		if ( null === $hit ) {
			return $errors;
		}

		$errors['spam'] = esc_html( Settings::form_message() );
		return $errors;
	}

	/**
	 * Field label for logs.
	 *
	 * @param int $field_id Field ID.
	 * @return string
	 */
	private static function label( $field_id ) {
		if ( class_exists( 'FrmField' ) && method_exists( 'FrmField', 'getOne' ) ) {
			$field = \FrmField::getOne( $field_id );
			if ( is_object( $field ) && ! empty( $field->name ) ) {
				return (string) $field->name;
			}
		}
		return 'Field ' . $field_id;
	}

	/**
	 * Whether this integration is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$forms = (array) Settings::get( 'forms' );
		return ! empty( $forms['formidable'] );
	}

	/**
	 * Whether Formidable is active.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'FrmAppController' );
	}
}
