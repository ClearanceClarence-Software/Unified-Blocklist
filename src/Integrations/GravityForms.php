<?php
/**
 * Gravity Forms.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist\Integrations;

use ClearanceClarence\UnifiedBlocklist\Guard;
use ClearanceClarence\UnifiedBlocklist\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Marks matching entries as spam. Gravity Forms keeps them under Entries > Spam, so nothing is lost.
 */
final class GravityForms {

	/**
	 * Registers the filter.
	 */
	public static function register() {
		add_filter( 'gform_entry_is_spam', array( self::class, 'filter' ), 11, 3 );
	}

	/**
	 * Filter callback.
	 *
	 * @param bool  $is_spam Already spam.
	 * @param array $form    Form.
	 * @param array $entry   Entry.
	 * @return bool
	 */
	public static function filter( $is_spam, $form, $entry ) {
		if ( $is_spam || ! self::enabled() || ! is_array( $form ) || ! is_array( $entry ) ) {
			return $is_spam;
		}

		$fields = self::fields( $form, $entry );

		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;
		$hit     = Guard::check( 'gravityforms', $fields, 'spam', $form_id ? 'form:' . $form_id : '' );
		if ( null === $hit ) {
			return $is_spam;
		}

		if ( method_exists( 'GFCommon', 'set_spam_filter' ) ) {
			/* translators: %s: matched term. */
			\GFCommon::set_spam_filter( $form_id, 'Unified Blocklist', sprintf( __( 'Matched "%s".', 'unified-blocklist' ), $hit->term ) );
		}

		return true;
	}

	/**
	 * Entry values by field label. Fields with several inputs (name, address) are grouped.
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @return array Label => values.
	 */
	public static function fields( array $form, array $entry ) {
		$fields = array();
		foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $field ) {
			$id    = is_object( $field ) ? $field->id : ( isset( $field['id'] ) ? $field['id'] : null );
			$label = is_object( $field ) ? $field->label : ( isset( $field['label'] ) ? $field['label'] : '' );
			if ( null === $id ) {
				continue;
			}
			$label = '' !== (string) $label ? (string) $label : 'Field ' . $id;

			$inputs = is_object( $field ) ? $field->inputs : ( isset( $field['inputs'] ) ? $field['inputs'] : null );
			$value  = array();
			if ( is_array( $inputs ) && $inputs ) {
				foreach ( $inputs as $input ) {
					$input_id = (string) ( isset( $input['id'] ) ? $input['id'] : '' );
					if ( '' !== $input_id && isset( $entry[ $input_id ] ) ) {
						$value[] = $entry[ $input_id ];
					}
				}
			} elseif ( isset( $entry[ (string) $id ] ) ) {
				$value[] = $entry[ (string) $id ];
			}

			if ( $value ) {
				$fields[ $label ] = $value;
			}
		}
		return $fields;
	}

	/**
	 * Whether this integration is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$forms = (array) Settings::get( 'forms' );
		return ! empty( $forms['gravityforms'] );
	}

	/**
	 * Whether Gravity Forms is active.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'GFForms' );
	}
}
