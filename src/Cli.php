<?php
/**
 * WP-CLI commands.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Unified Blocklist from the command line.
 *
 * ## EXAMPLES
 *
 *     wp unified-blocklist sync
 *     wp unified-blocklist status
 *     wp unified-blocklist test "Buy cheap watches here"
 */
final class Cli {

	/**
	 * Downloads all sources and rebuilds the list.
	 *
	 * ## OPTIONS
	 *
	 * [--accept-smaller]
	 * : Accept lists that shrank by more than half since the last sync.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function sync( $args, $assoc_args ) {
		$results = Sources::sync_all( ! empty( $assoc_args['accept-smaller'] ) );
		$failed  = 0;

		foreach ( $results as $url => $state ) {
			if ( 'ok' === $state['status'] ) {
				WP_CLI::log( sprintf( 'OK       %s (%d terms, %s)', $url, $state['count'], $state['note'] ) );
			} else {
				++$failed;
				WP_CLI::warning( sprintf( '%s: %s', $url, $state['error'] ) );
			}
		}

		$record = TermList::record();
		$line   = sprintf( 'Compiled list has %d terms.', $record['count'] );
		if ( $failed ) {
			WP_CLI::warning( $line . sprintf( ' %d source(s) failed; their last good copies are in use.', $failed ) );
			return;
		}
		WP_CLI::success( $line );
	}

	/**
	 * Shows the current state.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		$record  = TermList::record();
		$summary = Log::summary( 30 );
		$next    = Schedule::next();

		WP_CLI::log( sprintf( 'Mode:            %s', Settings::get( 'mode' ) ) );
		WP_CLI::log( sprintf( 'Active terms:    %d', $record['count'] ) );
		WP_CLI::log( sprintf( 'Matching:        %s', Settings::get( 'match_mode' ) ) );
		WP_CLI::log( sprintf( 'Last sync:       %s', ( (int) get_option( 'unified_blocklist_last_sync' ) ) ? wp_date( 'Y-m-d H:i', (int) get_option( 'unified_blocklist_last_sync' ) ) : 'never' ) );
		WP_CLI::log( sprintf( 'Next sync:       %s', $next ? wp_date( 'Y-m-d H:i', $next ) : 'not scheduled' ) );
		WP_CLI::log( sprintf( 'Matches (30d):   %d', $summary['total'] ) );

		$rows = array();
		foreach ( (array) Settings::get( 'sources' ) as $url ) {
			$state  = Sources::state_for( $url );
			$rows[] = array(
				'source' => $url,
				'status' => $state ? $state['status'] : 'pending',
				'terms'  => $state ? $state['count'] : 0,
				'error'  => $state ? $state['error'] : '',
			);
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'source', 'status', 'terms', 'error' ) );
		}
	}

	/**
	 * Checks a piece of text against the list without logging anything.
	 *
	 * ## OPTIONS
	 *
	 * <text>
	 * : The text to check.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function test( $args, $assoc_args ) {
		$hits = Matcher::from_settings()->find( array( 'text' => (string) $args[0] ), 50 );
		if ( ! $hits ) {
			WP_CLI::success( 'No matches.' );
			return;
		}
		$rows = array();
		foreach ( $hits as $hit ) {
			$rows[] = array(
				'term'    => $hit->term,
				'from'    => implode( ', ', TermList::origins( $hit->term ) ),
				'excerpt' => $hit->excerpt,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'term', 'from', 'excerpt' ) );
		WP_CLI::halt( 1 );
	}

	/**
	 * Rebuilds the compiled list from stored copies without downloading.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function rebuild( $args, $assoc_args ) {
		$record = TermList::rebuild();
		WP_CLI::success( sprintf( 'Compiled list has %d terms.', $record['count'] ) );
	}
}
