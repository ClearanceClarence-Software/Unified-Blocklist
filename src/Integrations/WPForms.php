<?php
/**
 * WPForms.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist\Integrations;

use ClearanceClarence\UnifiedBlocklist\Guard;
use ClearanceClarence\UnifiedBlocklist\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a form-level error, which stops the entry from being saved or emailed.
 */
final class WPForms {

	/**
	 * Registers the action.
	 */
	public static function register() {
		add_action( 'wpforms_process', array( self::class, 'process' ), 20, 3 );
	}

	/**
	 * Action callback.
	 *
	 * @param array $fields    Sanitized fields.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form data.
	 */
	public static function process( $fields, $entry, $form_data ) {
		if ( ! self::enabled() || ! is_array( $fields ) || ! is_array( $form_data ) ) {
			return;
		}

		$process = self::process_object();
		$form_id = isset( $form_data['id'] ) ? absint( $form_data['id'] ) : 0;

		// Respect earlier validation failures; the form is already being rejected.
		if ( ! $process || ! empty( $process->errors[ $form_id ] ) ) {
			return;
		}

		$check = array();
		foreach ( $fields as $id => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['value'] ) ) {
				continue;
			}
			$label           = ! empty( $field['name'] ) ? (string) $field['name'] : 'Field ' . $id;
			$check[ $label ] = $field['value'];
		}

		$hit = Guard::check( 'wpforms', $check, 'blocked', $form_id ? 'form:' . $form_id : '' );
		if ( null === $hit ) {
			return;
		}

		$process->errors[ $form_id ]['header'] = esc_html( Settings::form_message() );
	}

	/**
	 * The WPForms process object across versions.
	 *
	 * @return object|null
	 */
	private static function process_object() {
		if ( ! function_exists( 'wpforms' ) ) {
			return null;
		}
		$wpforms = wpforms();
		if ( method_exists( $wpforms, 'obj' ) ) {
			$process = $wpforms->obj( 'process' );
		} else {
			$process = isset( $wpforms->process ) ? $wpforms->process : null;
		}
		return is_object( $process ) && property_exists( $process, 'errors' ) ? $process : null;
	}

	/**
	 * Whether this integration is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$forms = (array) Settings::get( 'forms' );
		return ! empty( $forms['wpforms'] );
	}

	/**
	 * Whether WPForms is active.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wpforms' );
	}
}
