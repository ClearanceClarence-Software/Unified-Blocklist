<?php
/**
 * Settings schema, defaults, and sanitizing.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * All settings live in one small autoloaded option. Term lists are stored elsewhere and never autoloaded.
 */
final class Settings {

	const OPTION = 'unified_blocklist_settings';

	const INTERVALS = array( 'hourly', 'twicedaily', 'daily', 'weekly' );

	const FORM_INTEGRATIONS = array( 'cf7', 'gravityforms', 'wpforms', 'formidable' );

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'sources'        => array( UNIFIED_BLOCKLIST_DEFAULT_SOURCE ),
			'interval'       => 'daily',
			'shrink_guard'   => true,
			'local_terms'    => array(),
			'exceptions'     => array(),
			'include_core'   => true,
			'match_mode'     => 'substring',
			'min_length'     => 3,
			'mode'           => 'enforce',
			'comment_action' => 'spam',
			'forms'          => array_fill_keys( self::FORM_INTEGRATIONS, true ),
			'form_message'   => '',
			'skip_trusted'   => true,
			'log_retention'  => 30,
			'log_excerpts'   => true,
		);
	}

	/**
	 * Current settings merged over defaults, with wp-config overrides applied.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored          = get_option( self::OPTION, array() );
			$merged          = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
			$merged['forms'] = array_merge( self::defaults()['forms'], (array) $merged['forms'] );

			if ( self::sources_locked() ) {
				$merged['sources'] = self::clean_urls( (array) UNIFIED_BLOCKLIST_SOURCES );
			}

			/**
			 * Filters the effective settings. Useful for agencies that manage many sites from code.
			 *
			 * @param array $merged Settings.
			 */
			self::$cache = (array) apply_filters( 'unified_blocklist_settings', $merged );
		}
		return self::$cache;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether sources are defined in wp-config.php.
	 *
	 * @return bool
	 */
	public static function sources_locked() {
		return defined( 'UNIFIED_BLOCKLIST_SOURCES' );
	}

	/**
	 * Saves a partial set of values (one admin tab) over the stored settings.
	 *
	 * @param array $values Raw values, already unslashed.
	 * @param array $keys   Keys owned by the submitting tab. Missing checkboxes in this list become false.
	 */
	public static function save_partial( array $values, array $keys ) {
		$stored  = get_option( self::OPTION, array() );
		$current = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

		foreach ( $keys as $key ) {
			$current[ $key ] = self::sanitize_value( $key, isset( $values[ $key ] ) ? $values[ $key ] : null );
		}

		update_option( self::OPTION, $current, true );
		self::flush();
	}

	/**
	 * Writes one value directly.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value (sanitized here).
	 */
	public static function set( $key, $value ) {
		self::save_partial( array( $key => $value ), array( $key ) );
	}

	/**
	 * Clears the request cache.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitizes one value by key.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	public static function sanitize_value( $key, $value ) {
		$defaults = self::defaults();

		switch ( $key ) {
			case 'sources':
				return self::clean_urls( is_array( $value ) ? $value : self::lines( (string) $value ) );

			case 'local_terms':
			case 'exceptions':
				$lines = is_array( $value ) ? $value : self::lines( (string) $value );
				$lines = array_map( array( self::class, 'clean_term' ), $lines );
				return array_values( array_unique( array_filter( $lines, 'strlen' ) ) );

			case 'interval':
				return in_array( $value, self::INTERVALS, true ) ? $value : $defaults['interval'];

			case 'match_mode':
				return in_array( $value, array( 'substring', 'word' ), true ) ? $value : $defaults['match_mode'];

			case 'mode':
				return in_array( $value, array( 'enforce', 'monitor' ), true ) ? $value : $defaults['mode'];

			case 'comment_action':
				return in_array( $value, array( 'spam', 'hold', 'trash', 'off' ), true ) ? $value : $defaults['comment_action'];

			case 'min_length':
				return max( 1, min( 20, absint( $value ) ) );

			case 'log_retention':
				return max( 1, min( 365, absint( $value ) ) );

			case 'form_message':
				return sanitize_text_field( (string) $value );

			case 'forms':
				$value = is_array( $value ) ? $value : array();
				$out   = array();
				foreach ( self::FORM_INTEGRATIONS as $integration ) {
					$out[ $integration ] = ! empty( $value[ $integration ] );
				}
				return $out;

			case 'shrink_guard':
			case 'include_core':
			case 'skip_trusted':
			case 'log_excerpts':
				return ! empty( $value );
		}

		return null;
	}

	/**
	 * Splits text into trimmed lines.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$lines = preg_split( '/\R/u', (string) $text );
		return array_map( 'trim', is_array( $lines ) ? $lines : array() );
	}

	/**
	 * Strips control characters from a term while keeping its punctuation.
	 *
	 * @param string $term Term.
	 * @return string
	 */
	public static function clean_term( $term ) {
		$term = wp_check_invalid_utf8( (string) $term );
		$term = preg_replace( '/[\x00-\x1F\x7F]/u', '', $term );
		return trim( (string) $term );
	}

	/**
	 * Validates and dedupes source URLs. Only http(s) with a host is accepted here; the
	 * request itself goes through wp_safe_remote_get(), which refuses local and private addresses.
	 *
	 * @param array $urls URLs.
	 * @return string[]
	 */
	public static function clean_urls( array $urls ) {
		$out = array();
		foreach ( $urls as $url ) {
			$url  = esc_url_raw( trim( (string) $url ), array( 'https', 'http' ) );
			$host = '' !== $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
			if ( is_string( $host ) && '' !== $host ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The message shown when a form is blocked.
	 *
	 * @return string
	 */
	public static function form_message() {
		$message = (string) self::get( 'form_message' );
		return '' !== $message ? $message : __( 'Your message could not be sent because it contains blocked content.', 'unified-blocklist' );
	}
}
