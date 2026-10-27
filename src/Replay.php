<?php
/**
 * Replays terms against messages the site already accepted.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

use ClearanceClarence\UnifiedBlocklist\Integrations\Formidable;
use ClearanceClarence\UnifiedBlocklist\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "what would this have blocked?" before a list or term goes live, using recent approved
 * comments and form entries that were not spam. Nothing is logged or changed.
 */
final class Replay {

	/**
	 * Most messages read from each place.
	 */
	const PER_KIND = 300;

	/**
	 * Longest piece of one field that is checked.
	 */
	const MAX_FIELD = 5000;

	/**
	 * Most matching messages returned in full.
	 */
	const MAX_RESULTS = 100;

	/**
	 * Runs the check.
	 *
	 * @param string[]|null $terms Terms to try, or null for the active list.
	 * @param int           $limit Most messages per place.
	 * @return array
	 */
	public static function run( $terms = null, $limit = self::PER_KIND ) {
		$start = microtime( true );
		$limit = max( 1, min( 1000, (int) $limit ) );

		if ( null === $terms ) {
			$matcher = Matcher::from_settings();
			$count   = (int) TermList::record()['count'];
		} else {
			$list    = self::prepare( $terms );
			$matcher = new Matcher( $list, (string) Settings::get( 'match_mode' ) );
			$count   = count( $list );
		}

		$docs    = self::messages( $limit );
		$fields  = array();
		$scanned = array();
		foreach ( $docs as $i => $doc ) {
			$fields[ $i ]            = $doc['fields'];
			$scanned[ $doc['kind'] ] = ( isset( $scanned[ $doc['kind'] ] ) ? $scanned[ $doc['kind'] ] : 0 ) + 1;
		}

		$found   = $count ? $matcher->scan( $fields, 20 ) : array();
		$by_term = array();
		$matches = array();
		foreach ( $found as $i => $hits ) {
			foreach ( $hits as $hit ) {
				$by_term[ $hit->term ] = ( isset( $by_term[ $hit->term ] ) ? $by_term[ $hit->term ] : 0 ) + 1;
			}
			if ( count( $matches ) < self::MAX_RESULTS ) {
				$doc       = $docs[ $i ];
				$matches[] = array(
					'kind'  => $doc['kind'],
					'id'    => $doc['id'],
					'label' => $doc['label'],
					'date'  => $doc['date'],
					'url'   => $doc['url'],
					'hits'  => array_map(
						static function ( Hit $hit ) {
							return array(
								'term'    => $hit->term,
								'field'   => $hit->field,
								'excerpt' => $hit->excerpt,
							);
						},
						array_slice( $hits, 0, 5 )
					),
				);
			}
		}
		arsort( $by_term );

		$top = array();
		foreach ( array_slice( $by_term, 0, 20, true ) as $term => $n ) {
			$top[] = array(
				'term'     => (string) $term,
				'messages' => (int) $n,
			);
		}

		return array(
			'terms'   => $count,
			'scanned' => (object) $scanned,
			'total'   => count( $docs ),
			'matched' => count( $found ),
			'matches' => $matches,
			'byTerm'  => $top,
			'ms'      => round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	/**
	 * Candidate terms as the compiled list would hold them: normalized, without exceptions.
	 *
	 * @param string[] $terms Raw terms.
	 * @return string[]
	 */
	private static function prepare( array $terms ) {
		$exceptions = array_flip( array_map( array( Matcher::class, 'normalize' ), (array) Settings::get( 'exceptions' ) ) );
		$out        = array();
		foreach ( $terms as $term ) {
			$norm = Matcher::normalize( Settings::clean_term( (string) $term ) );
			if ( '' !== $norm && ! isset( $exceptions[ $norm ] ) ) {
				$out[ $norm ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Recent messages the site accepted, newest first within each place.
	 *
	 * @param int $limit Most messages per place.
	 * @return array<int, array{kind: string, id: int, label: string, date: string, url: string, fields: array}>
	 */
	public static function messages( $limit ) {
		$docs = array_merge(
			self::comments( $limit ),
			self::gravity_forms( $limit ),
			self::formidable( $limit ),
			self::flamingo( $limit )
		);

		/**
		 * Filters the messages the preview checks. Add entries from other form plugins here.
		 *
		 * @param array $docs  Messages: kind, id, label, date (ISO 8601), url and fields (label => value).
		 * @param int   $limit Most messages per place.
		 */
		$docs = (array) apply_filters( 'unified_blocklist_replay_messages', $docs, $limit );

		$out = array();
		foreach ( $docs as $doc ) {
			if ( ! is_array( $doc ) || empty( $doc['fields'] ) || ! is_array( $doc['fields'] ) ) {
				continue;
			}
			$fields = array();
			foreach ( $doc['fields'] as $label => $value ) {
				$fields[ (string) $label ] = is_array( $value ) ? $value : mb_substr( (string) $value, 0, self::MAX_FIELD );
			}
			$out[] = array(
				'kind'   => isset( $doc['kind'] ) ? (string) $doc['kind'] : 'other',
				'id'     => isset( $doc['id'] ) ? (int) $doc['id'] : 0,
				'label'  => isset( $doc['label'] ) ? (string) $doc['label'] : '',
				'date'   => isset( $doc['date'] ) ? (string) $doc['date'] : '',
				'url'    => isset( $doc['url'] ) ? (string) $doc['url'] : '',
				'fields' => $fields,
			);
		}
		return $out;
	}

	/**
	 * Approved comments.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private static function comments( $limit ) {
		$comments = get_comments(
			array(
				'status'  => 'approve',
				'type'    => 'comment',
				'number'  => $limit,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);

		$out = array();
		foreach ( (array) $comments as $comment ) {
			$out[] = array(
				'kind'   => 'comment',
				'id'     => (int) $comment->comment_ID,
				'label'  => (string) $comment->comment_author,
				'date'   => mysql2date( 'c', $comment->comment_date_gmt . ' +0000', false ),
				'url'    => admin_url( 'comment.php?action=editcomment&c=' . (int) $comment->comment_ID ),
				'fields' => array(
					__( 'Name', 'unified-blocklist' )    => $comment->comment_author,
					__( 'Email', 'unified-blocklist' )   => $comment->comment_author_email,
					__( 'Website', 'unified-blocklist' ) => $comment->comment_author_url,
					__( 'Comment', 'unified-blocklist' ) => $comment->comment_content,
				),
			);
		}
		return $out;
	}

	/**
	 * Gravity Forms entries that are not spam or trashed.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private static function gravity_forms( $limit ) {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_entries' ) ) {
			return array();
		}
		$entries = \GFAPI::get_entries(
			0,
			array( 'status' => 'active' ),
			array(
				'key'       => 'date_created',
				'direction' => 'DESC',
			),
			array(
				'offset'    => 0,
				'page_size' => $limit,
			)
		);
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$forms = array();
		$out   = array();
		foreach ( $entries as $entry ) {
			$form_id = isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0;
			if ( ! isset( $forms[ $form_id ] ) ) {
				$form              = \GFAPI::get_form( $form_id );
				$forms[ $form_id ] = is_array( $form ) ? $form : array();
			}
			$out[] = array(
				'kind'   => 'gravityforms',
				'id'     => (int) $entry['id'],
				'label'  => isset( $forms[ $form_id ]['title'] ) ? (string) $forms[ $form_id ]['title'] : '',
				'date'   => isset( $entry['date_created'] ) ? mysql2date( 'c', $entry['date_created'] . ' +0000', false ) : '',
				'url'    => admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . (int) $entry['id'] ),
				'fields' => GravityForms::fields( $forms[ $form_id ], $entry ),
			);
		}
		return $out;
	}

	/**
	 * Formidable entries. Formidable leaves its own spam out of this query.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private static function formidable( $limit ) {
		if ( ! class_exists( 'FrmEntry' ) || ! method_exists( 'FrmEntry', 'getAll' ) ) {
			return array();
		}
		$entries = \FrmEntry::getAll( array( 'it.is_draft' => 0 ), ' ORDER BY it.created_at DESC', (int) $limit, true, true );

		$labels = array();
		$out    = array();
		foreach ( (array) $entries as $entry ) {
			$fields = array();
			foreach ( isset( $entry->metas ) ? (array) $entry->metas : array() as $field_id => $value ) {
				if ( ! isset( $labels[ $field_id ] ) ) {
					$labels[ $field_id ] = Formidable::label( (int) $field_id );
				}
				$fields[ $labels[ $field_id ] ] = maybe_unserialize( $value );
			}
			$out[] = array(
				'kind'   => 'formidable',
				'id'     => (int) $entry->id,
				'label'  => isset( $entry->form_name ) ? (string) $entry->form_name : '',
				'date'   => isset( $entry->created_at ) ? mysql2date( 'c', $entry->created_at . ' +0000', false ) : '',
				'url'    => admin_url( 'admin.php?page=formidable-entries&frm_action=show&id=' . (int) $entry->id ),
				'fields' => $fields,
			);
		}
		return $out;
	}

	/**
	 * Contact Form 7 messages saved by Flamingo, without the ones in its spam folder.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private static function flamingo( $limit ) {
		if ( ! post_type_exists( 'flamingo_inbound' ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => 'flamingo_inbound',
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'kind'   => 'cf7',
				'id'     => (int) $post->ID,
				'label'  => (string) get_post_meta( $post->ID, '_from_name', true ),
				'date'   => mysql2date( 'c', $post->post_date_gmt . ' +0000', false ),
				'url'    => admin_url( 'admin.php?page=flamingo_inbound&post=' . (int) $post->ID . '&action=edit' ),
				'fields' => array(
					__( 'From', 'unified-blocklist' )    => get_post_meta( $post->ID, '_from', true ),
					__( 'Subject', 'unified-blocklist' ) => $post->post_title,
					__( 'Message', 'unified-blocklist' ) => $post->post_content,
				),
			);
		}
		return $out;
	}
}
