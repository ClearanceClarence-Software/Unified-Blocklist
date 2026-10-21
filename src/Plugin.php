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
	 * Development builds stored each list as one serialized row, which MySQL refuses above
	 * max_allowed_packet. Convert anything in that format and rebuild once.
	 */
	public static function maybe_migrate_storage() {
		if ( '2' === get_option( 'unified_blocklist_storage_version' ) ) {
			return;
		}
		foreach ( array_keys( Sources::state() ) as $key ) {
			$old = get_option( Sources::TERMS_PREFIX . $key, null );
			if ( is_array( $old ) && ! isset( $old['format'] ) ) {
				Storage::put( Sources::TERMS_PREFIX . $key, array_values( array_filter( array_map( 'strval', $old ), 'strlen' ) ) );
			}
		}
		TermList::rebuild();
		update_option( 'unified_blocklist_storage_version', '2', false );
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
