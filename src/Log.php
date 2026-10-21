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

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'unified_blocklist_db_version';

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
	 * Creates or updates the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				context varchar(32) NOT NULL,
				action varchar(16) NOT NULL,
				term varchar(500) NOT NULL,
				field varchar(100) NOT NULL DEFAULT '',
				ref varchar(64) NOT NULL DEFAULT '',
				excerpt text NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY context (context)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Installs the table after an update that changed the schema.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Adds a row.
	 *
	 * @param string $context Integration slug.
	 * @param string $action  Action taken.
	 * @param Hit    $hit     Match.
	 * @param string $ref     Reference.
	 */
	public static function add( $context, $action, Hit $hit, $ref = '' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'context'    => substr( (string) $context, 0, 32 ),
				'action'     => substr( (string) $action, 0, 16 ),
				'term'       => mb_substr( $hit->term, 0, 500 ),
				'field'      => mb_substr( $hit->field, 0, 100 ),
				'ref'        => substr( (string) $ref, 0, 64 ),
				'excerpt'    => Settings::get( 'log_excerpts' ) ? mb_substr( $hit->excerpt, 0, 400 ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
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
		$table   = self::table();
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
				"SELECT COUNT(*) FROM {$table}
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
				"SELECT * FROM {$table}
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
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
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
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$by_context = $wpdb->get_results( $wpdb->prepare( "SELECT context, action, COUNT(*) AS hits FROM {$table} WHERE created_at >= %s GROUP BY context, action", $since ), ARRAY_A );
		$top_terms  = $wpdb->get_results( $wpdb->prepare( "SELECT term, COUNT(*) AS hits, MAX(created_at) AS last_seen FROM {$table} WHERE created_at >= %s GROUP BY term ORDER BY hits DESC LIMIT 10", $since ), ARRAY_A );
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
		$table = self::table();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( (int) $days - 1 ) * DAY_IN_SECONDS );

		// SUBSTR works the same on MySQL, MariaDB and SQLite, unlike DATE().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTR(created_at, 1, 10) AS day, SUM(CASE WHEN action = 'logged' THEN 1 ELSE 0 END) AS logged, SUM(CASE WHEN action <> 'logged' THEN 1 ELSE 0 END) AS stopped FROM {$table} WHERE created_at >= %s GROUP BY SUBSTR(created_at, 1, 10)", $since ), ARRAY_A );

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
		$table = self::table();
		$days  = (int) Settings::get( 'log_retention' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Empties the log.
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Drops the table (used by uninstall).
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::DB_VERSION_OPTION );
	}
}
