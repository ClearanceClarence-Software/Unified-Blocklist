<?php
/**
 * Cleanup for sites that ran Dynamic Blacklist Updater.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic Blacklist Updater wrote ~66,000 lines into two autoloaded core options and, through a
 * bug, could store the whole list in the "comment_moderation" checkbox (which forces manual approval
 * of every comment). This class detects and undoes that.
 */
final class Legacy {

	const OPTIONS = array(
		'dbu_update_interval',
		'dbu_menu_location',
		'dbu_blacklist_url',
		'dbu_blacklist_fallback_url',
		'dbu_last_updated',
		'dbu_blacklist_count',
		'dbu_blacklist_hits',
		'dbu_user_defined_blacklist',
	);

	const CRON_HOOK = 'dbu_update_blacklist_event';

	/**
	 * Whether any trace of the old plugin is left.
	 *
	 * @return bool
	 */
	public static function detected() {
		return self::has_options() || '' !== self::plugin_file();
	}

	/**
	 * Whether old options remain.
	 *
	 * @return bool
	 */
	public static function has_options() {
		foreach ( self::OPTIONS as $option ) {
			if ( false !== get_option( $option, false ) ) {
				return true;
			}
		}
		return (bool) wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Basename of the old plugin if it is installed and active.
	 *
	 * @return string
	 */
	public static function plugin_file() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( 'Dynamic Blacklist Updater' === $data['Name'] && is_plugin_active( $file ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Facts shown before cleanup.
	 *
	 * @return array
	 */
	public static function report() {
		$moderation_flag = get_option( 'comment_moderation' );
		$user_terms      = get_option( 'dbu_user_defined_blacklist', array() );

		return array(
			'active_plugin'       => self::plugin_file(),
			'disallowed_lines'    => count( TermList::core_terms() ),
			'moderation_lines'    => count( array_filter( Settings::lines( (string) get_option( 'moderation_keys', '' ) ), 'strlen' ) ),
			'moderation_flag_bad' => ! in_array( (string) $moderation_flag, array( '', '0', '1' ), true ),
			'user_terms'          => is_array( $user_terms ) ? array_values( array_filter( array_map( 'strval', $user_terms ), 'strlen' ) ) : array(),
			'old_options'         => self::has_options(),
		);
	}

	/**
	 * Runs the selected cleanup steps.
	 *
	 * @param array $steps Step => bool.
	 * @return string[] Done steps.
	 */
	public static function clean( array $steps ) {
		$done = array();

		if ( ! empty( $steps['deactivate'] ) ) {
			$file = self::plugin_file();
			if ( '' !== $file ) {
				deactivate_plugins( $file );
				$done[] = 'deactivate';
			}
		}

		if ( ! empty( $steps['import_terms'] ) ) {
			$report = self::report();
			if ( $report['user_terms'] ) {
				Settings::set( 'local_terms', array_merge( (array) Settings::get( 'local_terms' ), $report['user_terms'] ) );
				$done[] = 'import_terms';
			}
		}

		if ( ! empty( $steps['clear_disallowed'] ) ) {
			update_option( 'disallowed_keys', '' );
			$done[] = 'clear_disallowed';
		}

		if ( ! empty( $steps['clear_moderation'] ) ) {
			update_option( 'moderation_keys', '' );
			$done[] = 'clear_moderation';
		}

		if ( ! empty( $steps['fix_flag'] ) && self::report()['moderation_flag_bad'] ) {
			update_option( 'comment_moderation', '0' );
			$done[] = 'fix_flag';
		}

		if ( ! empty( $steps['remove_options'] ) ) {
			foreach ( self::OPTIONS as $option ) {
				delete_option( $option );
			}
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$done[] = 'remove_options';
		}

		TermList::rebuild();

		return $done;
	}
}
