<?php
/**
 * Bootstrap and lifecycle.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

use ClearanceClarence\UnifiedBlocklist\Admin\App;
use ClearanceClarence\UnifiedBlocklist\Admin\Widget;
use ClearanceClarence\UnifiedBlocklist\Admin\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Wires hooks together.
 */
final class Plugin {

	const SYNC_HOOK  = 'unified_blocklist_sync';
	const PRUNE_HOOK = 'unified_blocklist_prune';

	/**
	 * Registers all runtime hooks.
	 */
	public static function boot() {
		Log::maybe_upgrade();
		add_action( 'init', array( self::class, 'maybe_migrate_storage' ), 5 );
		add_action( 'init', array( Schedule::class, 'ensure' ) );

		add_action( self::SYNC_HOOK, array( Sources::class, 'sync_all' ) );
		add_action( self::PRUNE_HOOK, array( Log::class, 'prune' ) );

		// Keep the compiled list in step with the core Discussion setting when it is included.
		add_action( 'update_option_disallowed_keys', array( TermList::class, 'rebuild_if_core_included' ) );
		add_action( 'add_option_disallowed_keys', array( TermList::class, 'rebuild_if_core_included' ) );

		Integrations\Comments::register();
		Integrations\ContactForm7::register();
		Integrations\GravityForms::register();
		Integrations\WPForms::register();
		Integrations\Formidable::register();
		Rescues::register();

		Rest::register();

		if ( is_admin() ) {
			App::register();
			Actions::register();
			Widget::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'unified-blocklist', Cli::class );
		}
	}

	/**
	 * Earlier builds kept the lists in wp_options, first as one serialized row and then as
	 * compressed chunks. Move anything still there into the lists table, then remove the rows.
	 */
	public static function maybe_migrate_storage() {
		if ( '3' === get_option( 'unified_blocklist_storage_version' ) ) {
			return;
		}
		Log::maybe_upgrade();

		$keys = array( TermList::TERMS_KEY );
		foreach ( array_keys( Sources::state() ) as $key ) {
			$keys[] = Sources::TERMS_PREFIX . $key;
		}
		foreach ( $keys as $key ) {
			$old = get_option( $key, null );
			if ( is_array( $old ) && ! isset( $old['format'] ) && TermList::TERMS_KEY !== $key ) {
				// Format 1: a plain array of terms.
				Storage::put( $key, array_values( array_filter( array_map( 'strval', $old ), 'strlen' ) ) );
			} elseif ( is_array( $old ) && isset( $old['format'], $old['chunks'] ) ) {
				// Format 2: compressed chunks in options.
				$blob = '';
				for ( $i = 0; $i < (int) $old['chunks']; $i++ ) {
					$blob .= (string) get_option( $key . '_c' . $i, '' );
				}
				$raw = $blob ? gzuncompress( base64_decode( $blob ) ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Our own encoding.
				if ( false !== $raw && '' !== $raw ) {
					Storage::put( $key, explode( "\n", $raw ) );
				}
			}
		}

		// Remove every list row from wp_options, whichever format it was in.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time cleanup.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'unified_blocklist_src_' ) . '%', $wpdb->esc_like( 'unified_blocklist_compiled_terms' ) . '%' ) );
		foreach ( (array) $names as $name ) {
			delete_option( $name );
		}

		TermList::rebuild();
		update_option( 'unified_blocklist_storage_version', '3', false );
	}

	/**
	 * Activation: table, defaults, schedules, and a first sync shortly after.
	 */
	public static function activate() {
		Log::install();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', true );
		}

		// The first sync runs on the next cron tick, so activation never waits on the network.
		Schedule::ensure();
	}

	/**
	 * Deactivation: stop scheduled work. Data stays until uninstall.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::SYNC_HOOK );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}
}
