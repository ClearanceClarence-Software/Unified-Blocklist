<?php
/**
 * Block log stored in its own table.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * Records matches so false positives can be found and fixed.
 */
final class Log {

	const DB_VERSION        = '3';
	const DB_VERSION_OPTION = 'unified_blocklist_db_version';

	/**
	 * Matches of the same term in the same place this close together share one row.
	 */
	const COLLAPSE_SECONDS = 60;

	/**
	 * A shared row is closed after this long, so a steady flood still shows up day by day.
	 */
	const COLLAPSE_MAX_SECONDS = 3600;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'unified_blocklist_log';
	}

	/**
	 * Creates or updates the plugin's two tables: the log, and the term lists.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}unified_blocklist_lists (
				list_key varchar(64) NOT NULL,
				chunk_no smallint(5) unsigned NOT NULL DEFAULT 0,
				total_chunks smallint(5) unsigned NOT NULL DEFAULT 1,
				term_count int(10) unsigned NOT NULL DEFAULT 0,
				hash char(32) NOT NULL DEFAULT '',
				data longtext NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (list_key, chunk_no)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}unified_blocklist_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				context varchar(32) NOT NULL,
				action varchar(16) NOT NULL,
				term varchar(500) NOT NULL,
				field varchar(100) NOT NULL DEFAULT '',
				ref varchar(64) NOT NULL DEFAULT '',
				excerpt text NULL,
				hits int(10) unsigned NOT NULL DEFAULT 1,
				last_at datetime NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY last_at (last_at),
				KEY context (context)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Installs the tables after an update that changed the schema.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Adds a row, or counts the match on a recent row for the same term in the same place.
	 * A spam wave then becomes one row with a count instead of thousands of identical rows.
	 *
	 * @param string $context Integration slug.
	 * @param string $action  Action taken.
	 * @param Hit    $hit     Match.
	 * @param string $ref     Reference.
	 * @return int Row ID.
	 */
	public static function add( $context, $action, Hit $hit, $ref = '' ) {
		global $wpdb;

		$now     = time();
		$context = substr( (string) $context, 0, 32 );
		$action  = substr( (string) $action, 0, 16 );
		$term    = mb_substr( $hit->term, 0, 500 );
		$field   = mb_substr( $hit->field, 0, 100 );
		$ref     = substr( (string) $ref, 0, 64 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; its name is not user input.
		$recent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}unified_blocklist_log
				WHERE last_at >= %s AND created_at >= %s
				AND context = %s AND action = %s AND term = %s AND field = %s
				ORDER BY id DESC LIMIT 1",
				gmdate( 'Y-m-d H:i:s', $now - self::COLLAPSE_SECONDS ),
				gmdate( 'Y-m-d H:i:s', $now - self::COLLAPSE_MAX_SECONDS ),
				$context,
				$action,
				$term,
				$field
			)
		);

		if ( $recent ) {
			// A wave across several posts or forms keeps one row; the reference is then cleared.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}unified_blocklist_log SET hits = hits + 1, last_at = %s, ref = CASE WHEN ref = %s THEN ref ELSE '' END WHERE id = %d",
					gmdate( 'Y-m-d H:i:s', $now ),
					$ref,
					$recent
				)
			);
			return $recent;
		}

		$wpdb->insert(
			self::table(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'last_at'    => gmdate( 'Y-m-d H:i:s', $now ),
				'hits'       => 1,
				'context'    => $context,
				'action'     => $action,
				'term'       => $term,
				'field'      => $field,
				'ref'        => $ref,
				'excerpt'    => Settings::get( 'log_excerpts' ) ? mb_substr( $hit->excerpt, 0, 400 ) : null,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable

		return (int) $wpdb->insert_id;
	}

	/**
	 * Paged rows.
	 *
	 * @param int          $page     1-based page.
	 * @param int          $per_page Rows per page.
	 * @param string|array $filters  Context slug, or array with optional context, outcome ("stopped" or "logged") and search.
	 * @return array{rows: array, total: int}
	 */
	public static function query( $page = 1, $per_page = 50, $filters = array() ) {
		global $wpdb;
		$offset  = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		$filters = is_array( $filters ) ? $filters : array( 'context' => (string) $filters );

		$context = isset( $filters['context'] ) ? (string) $filters['context'] : '';
		$outcome = isset( $filters['outcome'] ) && in_array( $filters['outcome'], array( 'stopped', 'logged' ), true ) ? $filters['outcome'] : '';
		$search  = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
		$like    = '%' . $wpdb->esc_like( $search ) . '%';

		// One fixed statement: an empty filter value turns its condition off.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; its name is not user input.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}unified_blocklist_log
				WHERE ( %s = '' OR context = %s )
				AND ( %s = '' OR ( %s = 'logged' AND action = 'logged' ) OR ( %s = 'stopped' AND action <> 'logged' ) )
				AND ( %s = '' OR term LIKE %s OR excerpt LIKE %s OR field LIKE %s )",
				$context,
				$context,
				$outcome,
				$outcome,
				$outcome,
				$search,
				$like,
				$like,
				$like
			)
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}unified_blocklist_log
				WHERE ( %s = '' OR context = %s )
				AND ( %s = '' OR ( %s = 'logged' AND action = 'logged' ) OR ( %s = 'stopped' AND action <> 'logged' ) )
				AND ( %s = '' OR term LIKE %s OR excerpt LIKE %s OR field LIKE %s )
				ORDER BY id DESC LIMIT %d OFFSET %d",
				$context,
				$context,
				$outcome,
				$outcome,
				$outcome,
				$search,
				$like,
				$like,
				$like,
				(int) $per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * One row.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}unified_blocklist_log WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Summary for the overview: totals per context and top terms over a period.
	 *
	 * @param int $days Days back.
	 * @return array{total: int, by_context: array, top_terms: array}
	 */
	public static function summary( $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$by_context = $wpdb->get_results( $wpdb->prepare( "SELECT context, action, SUM(hits) AS hits FROM {$wpdb->prefix}unified_blocklist_log WHERE created_at >= %s GROUP BY context, action", $since ), ARRAY_A );
		$top_terms  = $wpdb->get_results( $wpdb->prepare( "SELECT term, SUM(hits) AS hits, MAX(COALESCE(last_at, created_at)) AS last_seen FROM {$wpdb->prefix}unified_blocklist_log WHERE created_at >= %s GROUP BY term ORDER BY hits DESC LIMIT 10", $since ), ARRAY_A );
		// phpcs:enable

		$total = 0;
		foreach ( (array) $by_context as $row ) {
			$total += (int) $row['hits'];
		}

		return array(
			'total'      => $total,
			'by_context' => is_array( $by_context ) ? $by_context : array(),
			'top_terms'  => is_array( $top_terms ) ? $top_terms : array(),
		);
	}

	/**
	 * Matches per day, oldest first, with empty days filled in.
	 *
	 * @param int $days Days back, including today.
	 * @return array<int, array{date: string, stopped: int, logged: int}>
	 */
	public static function daily( $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( (int) $days - 1 ) * DAY_IN_SECONDS );

		// SUBSTR works the same on MySQL, MariaDB and SQLite, unlike DATE().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTR(created_at, 1, 10) AS day, SUM(CASE WHEN action = 'logged' THEN hits ELSE 0 END) AS logged, SUM(CASE WHEN action <> 'logged' THEN hits ELSE 0 END) AS stopped FROM {$wpdb->prefix}unified_blocklist_log WHERE created_at >= %s GROUP BY SUBSTR(created_at, 1, 10)", $since ), ARRAY_A );

		$by_day = array();
		foreach ( (array) $rows as $row ) {
			$by_day[ $row['day'] ] = $row;
		}

		$out = array();
		for ( $i = (int) $days - 1; $i >= 0; $i-- ) {
			$day   = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$out[] = array(
				'date'    => $day,
				'stopped' => isset( $by_day[ $day ] ) ? (int) $by_day[ $day ]['stopped'] : 0,
				'logged'  => isset( $by_day[ $day ] ) ? (int) $by_day[ $day ]['logged'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Deletes rows older than the retention period.
	 */
	public static function prune() {
		global $wpdb;
		$days = (int) Settings::get( 'log_retention' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}unified_blocklist_log WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Empties the log.
	 */
	public static function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}unified_blocklist_log" );
	}

	/**
	 * Drops the table (used by uninstall).
	 */
	public static function drop() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}unified_blocklist_log" );
		delete_option( self::DB_VERSION_OPTION );
	}
}
