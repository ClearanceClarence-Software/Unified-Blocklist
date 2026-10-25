<?php
/**
 * REST API for the admin app.
 *
 * Everything lives under /wp-json/unified-blocklist/v1 and needs manage_options.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes used by the React admin.
 */
final class Rest {

	const NS         = 'unified-blocklist/v1';
	const THEME_META = 'unified_blocklist_theme';

	/**
	 * Settings that change what goes into the compiled list.
	 */
	const COMPILE_KEYS = array( 'local_terms', 'exceptions', 'include_core', 'min_length' );

	/**
	 * Hooks into WordPress.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Registers routes.
	 */
	public static function routes() {
		$admin = array( self::class, 'can_manage' );

		register_rest_route(
			self::NS,
			'/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( self::state() );
				},
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static function () {
						return rest_ensure_response( Settings::all() );
					},
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'save_settings' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'sync' ),
				'permission_callback' => $admin,
				'args'                => array(
					'acceptSmaller' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'log' ),
					'permission_callback' => $admin,
					'args'                => array(
						'page'    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'perPage' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						),
						'context' => array(
							'type'    => 'string',
							'enum'    => array( '', 'comment', 'cf7', 'gravityforms', 'wpforms', 'formidable' ),
							'default' => '',
						),
						'outcome' => array(
							'type'    => 'string',
							'enum'    => array( '', 'stopped', 'logged' ),
							'default' => '',
						),
						'search'  => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 200,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => static function () {
						Log::clear();
						return rest_ensure_response( array( 'state' => self::state() ) );
					},
					'permission_callback' => $admin,
				),
			)
		);

		$term_arg = array(
			'term' => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
				'maxLength' => 500,
			),
		);
		register_rest_route(
			self::NS,
			'/exceptions',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'add_exception' ),
					'permission_callback' => $admin,
					'args'                => $term_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'remove_exception' ),
					'permission_callback' => $admin,
					'args'                => $term_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'test' ),
				'permission_callback' => $admin,
				'args'                => array(
					'text' => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 20000,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/legacy',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'legacy' ),
				'permission_callback' => $admin,
				'args'                => array(
					'steps' => array(
						'type'    => 'array',
						'items'   => array(
							'type' => 'string',
							'enum' => array( 'deactivate', 'import_terms', 'clear_disallowed', 'clear_moderation', 'fix_flag', 'remove_options' ),
						),
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/preferences',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => static function ( WP_REST_Request $request ) {
					update_user_meta( get_current_user_id(), self::THEME_META, $request['theme'] );
					return rest_ensure_response( array( 'theme' => self::theme() ) );
				},
				'permission_callback' => $admin,
				'args'                => array(
					'theme' => array(
						'type'     => 'string',
						'enum'     => array( 'light', 'dark' ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The current user's theme. A preference, so it is per user like in Routemark.
	 *
	 * @return string
	 */
	public static function theme() {
		return 'dark' === get_user_meta( get_current_user_id(), self::THEME_META, true ) ? 'dark' : 'light';
	}

	/**
	 * Saves a partial settings object and applies side effects.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ) {
		$patch   = (array) $request->get_json_params();
		$allowed = array_keys( Settings::defaults() );
		$keys    = array_values( array_intersect( array_keys( $patch ), $allowed ) );
		if ( Settings::sources_locked() ) {
			$keys = array_values( array_diff( $keys, array( 'sources' ) ) );
		}

		$before = Settings::all();
		Settings::save_partial( $patch, $keys );
		$after = Settings::all();

		$sync = null;
		if ( $before['interval'] !== $after['interval'] ) {
			Schedule::reschedule();
		}
		if ( $before['sources'] !== $after['sources'] ) {
			$sync = self::sync_results( Sources::sync_all() );
		} elseif ( array_intersect( $keys, self::COMPILE_KEYS ) ) {
			TermList::rebuild();
		}

		return rest_ensure_response(
			array(
				'settings' => $after,
				'state'    => self::state(),
				'sync'     => $sync,
			)
		);
	}

	/**
	 * Syncs now.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function sync( WP_REST_Request $request ) {
		$results = Sources::sync_all( (bool) $request['acceptSmaller'] );
		return rest_ensure_response(
			array(
				'sync'  => self::sync_results( $results ),
				'state' => self::state(),
			)
		);
	}

	/**
	 * Log page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function log( WP_REST_Request $request ) {
		$result = Log::query(
			(int) $request['page'],
			(int) $request['perPage'],
			array(
				'context' => (string) $request['context'],
				'outcome' => (string) $request['outcome'],
				'search'  => (string) $request['search'],
			)
		);

		$rows = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = array(
				'id'       => (int) $row['id'],
				'time'     => gmdate( 'c', (int) strtotime( $row['created_at'] . ' UTC' ) ),
				'context'  => $row['context'],
				'action'   => $row['action'],
				'term'     => $row['term'],
				'field'    => $row['field'],
				'ref'      => $row['ref'],
				'refLabel' => self::ref_label( (string) $row['ref'] ),
				'refUrl'   => self::ref_url( (string) $row['ref'] ),
				'excerpt'  => null === $row['excerpt'] ? null : (string) $row['excerpt'],
			);
		}

		return rest_ensure_response(
			array(
				'rows'  => $rows,
				'total' => $result['total'],
			)
		);
	}

	/**
	 * Adds a term to the exceptions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function add_exception( WP_REST_Request $request ) {
		$exceptions   = (array) Settings::get( 'exceptions' );
		$exceptions[] = Settings::clean_term( (string) $request['term'] );
		Settings::set( 'exceptions', $exceptions );
		TermList::rebuild();
		return self::settings_and_state();
	}

	/**
	 * Removes a term from the exceptions (undo).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function remove_exception( WP_REST_Request $request ) {
		$target = Matcher::normalize( (string) $request['term'] );
		$kept   = array_filter(
			(array) Settings::get( 'exceptions' ),
			static function ( $term ) use ( $target ) {
				return Matcher::normalize( $term ) !== $target;
			}
		);
		Settings::set( 'exceptions', array_values( $kept ) );
		TermList::rebuild();
		return self::settings_and_state();
	}

	/**
	 * Checks text against the list. Nothing is logged.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function test( WP_REST_Request $request ) {
		$text  = (string) $request['text'];
		$start = microtime( true );
		$hits  = Matcher::from_settings()->find( array( 'text' => $text ), 50 );
		$ms    = ( microtime( true ) - $start ) * 1000;

		$out = array();
		foreach ( $hits as $hit ) {
			$out[] = array(
				'term'    => $hit->term,
				'excerpt' => $hit->excerpt,
				'origins' => TermList::origins( $hit->term ),
			);
		}

		return rest_ensure_response(
			array(
				'hits'    => $out,
				'checked' => (int) TermList::record()['count'],
				'ms'      => round( $ms, 1 ),
				// When nothing matched, say which listed words were skipped and why.
				'skipped' => $out ? array() : TermList::explain( $text ),
			)
		);
	}

	/**
	 * Runs the Dynamic Blacklist Updater cleanup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function legacy( WP_REST_Request $request ) {
		$steps = array_fill_keys( (array) $request['steps'], true );
		$done  = Legacy::clean( $steps );
		return rest_ensure_response(
			array(
				'done'     => $done,
				'settings' => Settings::all(),
				'state'    => self::state(),
			)
		);
	}

	/**
	 * Everything the overview and status areas need, in one object.
	 *
	 * @return array
	 */
	public static function state() {
		$record  = TermList::record();
		$counts  = wp_parse_args(
			$record['counts'],
			array(
				'sources' => 0,
				'local'   => 0,
				'core'    => 0,
				'skipped' => 0,
				'removed' => 0,
			)
		);
		$summary = Log::summary( 30 );

		$by_context = array();
		$stopped    = 0;
		$logged     = 0;
		foreach ( $summary['by_context'] as $row ) {
			$by_context[ $row['context'] ] = ( isset( $by_context[ $row['context'] ] ) ? $by_context[ $row['context'] ] : 0 ) + (int) $row['hits'];
			if ( 'logged' === $row['action'] ) {
				$logged += (int) $row['hits'];
			} else {
				$stopped += (int) $row['hits'];
			}
		}

		$sources = array();
		foreach ( (array) Settings::get( 'sources' ) as $url ) {
			$s         = Sources::state_for( $url );
			$sources[] = array(
				'url'       => $url,
				'label'     => TermList::source_label( $url ),
				'status'    => $s ? (string) $s['status'] : 'pending',
				'note'      => $s && isset( $s['note'] ) ? (string) $s['note'] : '',
				'error'     => $s && isset( $s['error'] ) ? (string) $s['error'] : '',
				'count'     => $s ? (int) $s['count'] : 0,
				'updatedAt' => $s ? (int) $s['updated_at'] : 0,
				'checkedAt' => $s && isset( $s['checked_at'] ) ? (int) $s['checked_at'] : 0,
			);
		}

		$top = array();
		foreach ( $summary['top_terms'] as $row ) {
			$top[] = array(
				'term'     => $row['term'],
				'hits'     => (int) $row['hits'],
				'lastSeen' => gmdate( 'c', (int) strtotime( $row['last_seen'] . ' UTC' ) ),
			);
		}

		return array(
			'now'           => time(),
			'terms'         => array(
				'count'   => (int) $record['count'],
				'counts'  => array_map( 'intval', $counts ),
				'builtAt' => (int) $record['built_at'],
				'error'   => isset( $record['error'] ) ? (string) $record['error'] : '',
			),
			'sync'          => array(
				'last' => (int) get_option( 'unified_blocklist_last_sync', 0 ),
				'next' => (int) Schedule::next(),
			),
			'sources'       => $sources,
			'sourcesLocked' => Settings::sources_locked(),
			'activity'      => array(
				'total'     => (int) $summary['total'],
				'stopped'   => $stopped,
				'logged'    => $logged,
				'byContext' => (object) $by_context,
				'daily'     => Log::daily( 30 ),
				'topTerms'  => $top,
			),
			'available'     => array(
				'comment'      => true,
				'cf7'          => Integrations\ContactForm7::available(),
				'gravityforms' => Integrations\GravityForms::available(),
				'wpforms'      => Integrations\WPForms::available(),
				'formidable'   => Integrations\Formidable::available(),
			),
			'coreLines'     => count( TermList::core_terms() ),
			'legacy'        => Legacy::detected() ? Legacy::report() : null,
		);
	}

	/**
	 * Sync results in the app's shape.
	 *
	 * @param array $results Results from Sources::sync_all().
	 * @return array
	 */
	private static function sync_results( array $results ) {
		$out = array();
		foreach ( $results as $url => $s ) {
			$out[] = array(
				'url'    => $url,
				'label'  => TermList::source_label( $url ),
				'status' => (string) $s['status'],
				'note'   => isset( $s['note'] ) ? (string) $s['note'] : '',
				'error'  => isset( $s['error'] ) ? (string) $s['error'] : '',
				'count'  => (int) $s['count'],
			);
		}
		return $out;
	}

	/**
	 * Common response for actions that change settings.
	 *
	 * @return WP_REST_Response
	 */
	private static function settings_and_state() {
		return rest_ensure_response(
			array(
				'settings' => Settings::all(),
				'state'    => self::state(),
			)
		);
	}

	/**
	 * Readable reference.
	 *
	 * @param string $ref Reference such as "post:12" or "form:3".
	 * @return string
	 */
	private static function ref_label( $ref ) {
		if ( 0 === strpos( $ref, 'post:' ) ) {
			$title = get_the_title( (int) substr( $ref, 5 ) );
			return '' !== $title ? $title : $ref;
		}
		if ( 0 === strpos( $ref, 'form:' ) ) {
			/* translators: %d: form ID. */
			return sprintf( __( 'Form #%d', 'unified-blocklist' ), (int) substr( $ref, 5 ) );
		}
		return $ref;
	}

	/**
	 * Link for a reference, when there is a sensible one.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	private static function ref_url( $ref ) {
		if ( 0 === strpos( $ref, 'post:' ) ) {
			$url = get_edit_post_link( (int) substr( $ref, 5 ), 'raw' );
			return $url ? $url : '';
		}
		return '';
	}
}
