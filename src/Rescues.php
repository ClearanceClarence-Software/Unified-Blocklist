<?php
/**
 * Learns from moderators who rescue blocked messages.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * When a moderator marks a blocked comment as "Not spam", restores it from the trash or approves
 * it, or marks a Gravity Forms entry as not spam, the term that caught it is suggested as an
 * exception. Nothing is allowed automatically: the suggestion waits for one click.
 */
final class Rescues {

	/**
	 * Suggestions, keyed by normalized term. Small (capped), never autoloaded.
	 */
	const OPTION = 'unified_blocklist_rescues';

	/**
	 * Comment meta and Gravity Forms entry meta holding the term that stopped the message.
	 */
	const META = '_unified_blocklist_term';

	/**
	 * Most suggestions kept.
	 */
	const MAX = 50;

	/**
	 * The latest enforced match in this request, waiting for its comment or entry ID.
	 *
	 * @var array{context: string, term: string}|null
	 */
	private static $pending = null;

	/**
	 * Hooks into WordPress and Gravity Forms.
	 */
	public static function register() {
		add_action( 'unified_blocklist_matched', array( self::class, 'remember' ), 10, 3 );
		add_action( 'wp_insert_comment', array( self::class, 'tag_comment' ), 10, 1 );
		add_action( 'transition_comment_status', array( self::class, 'comment_status' ), 10, 3 );
		add_action( 'gform_entry_created', array( self::class, 'tag_entry' ), 10, 1 );
		add_action( 'gform_update_status', array( self::class, 'entry_status' ), 10, 3 );
	}

	/**
	 * Keeps the match until WordPress or Gravity Forms has saved the message.
	 *
	 * @param Hit    $hit     Match.
	 * @param string $context Integration slug.
	 * @param bool   $enforce Whether the match was acted on.
	 */
	public static function remember( $hit, $context, $enforce ) {
		self::$pending = $enforce && $hit instanceof Hit && in_array( $context, array( 'comment', 'gravityforms' ), true )
			? array(
				'context' => (string) $context,
				'term'    => $hit->term,
			)
			: null;
	}

	/**
	 * Notes the term on a comment the plugin stopped.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function tag_comment( $comment_id ) {
		if ( null !== self::$pending && 'comment' === self::$pending['context'] ) {
			add_comment_meta( (int) $comment_id, self::META, self::$pending['term'], true );
		}
		self::$pending = null;
	}

	/**
	 * Comment moved out of Spam, Trash or Pending by a moderator.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 */
	public static function comment_status( $new_status, $old_status, $comment ) {
		if ( ! is_object( $comment ) || empty( $comment->comment_ID ) ) {
			return;
		}
		$rescued = 'approved' === $new_status || ( 'unapproved' === $new_status && in_array( $old_status, array( 'spam', 'trash' ), true ) );
		if ( ! $rescued ) {
			return;
		}
		$term = (string) get_comment_meta( (int) $comment->comment_ID, self::META, true );
		if ( '' === $term ) {
			return;
		}
		// Counted once per comment, even if it is approved after being restored.
		delete_comment_meta( (int) $comment->comment_ID, self::META );
		self::record( $term, 'comment', (string) $comment->comment_content );
	}

	/**
	 * Notes the term on a Gravity Forms entry the plugin marked as spam.
	 *
	 * @param array $entry Entry.
	 */
	public static function tag_entry( $entry ) {
		if ( null !== self::$pending && 'gravityforms' === self::$pending['context'] && is_array( $entry ) && ! empty( $entry['id'] ) && function_exists( 'gform_update_meta' ) ) {
			gform_update_meta( (int) $entry['id'], self::META, self::$pending['term'] );
		}
		self::$pending = null;
	}

	/**
	 * Gravity Forms entry marked "Not spam".
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $status   New status.
	 * @param string $previous Previous status.
	 */
	public static function entry_status( $entry_id, $status, $previous ) {
		if ( 'spam' !== $previous || 'active' !== $status || ! function_exists( 'gform_get_meta' ) ) {
			return;
		}
		$term = (string) gform_get_meta( (int) $entry_id, self::META );
		if ( '' === $term ) {
			return;
		}
		if ( function_exists( 'gform_delete_meta' ) ) {
			gform_delete_meta( (int) $entry_id, self::META );
		}
		self::record( $term, 'gravityforms', '' );
	}

	/**
	 * Adds one rescue for a term.
	 *
	 * @param string $term    Normalized term.
	 * @param string $context Where the message came from.
	 * @param string $text    The rescued message, for a short example.
	 */
	public static function record( $term, $context, $text = '' ) {
		$term = Matcher::normalize( $term );
		if ( '' === $term || self::is_exception( $term ) ) {
			return;
		}

		$list = self::stored();
		$item = isset( $list[ $term ] ) ? $list[ $term ] : array(
			'term'  => $term,
			'count' => 0,
		);

		++$item['count'];
		$item['last']    = time();
		$item['context'] = (string) $context;
		$item['example'] = Settings::get( 'log_excerpts' ) ? self::example( $text, $term ) : '';

		unset( $list[ $term ] );
		$list = array( $term => $item ) + $list;
		update_option( self::OPTION, array_slice( $list, 0, self::MAX, true ), false );
	}

	/**
	 * Open suggestions, most recent first, without terms that have been allowed since.
	 *
	 * @return array<int, array{term: string, count: int, last: int, context: string, example: string}>
	 */
	public static function all() {
		$out = array();
		foreach ( self::stored() as $item ) {
			if ( ! self::is_exception( $item['term'] ) ) {
				$out[] = array(
					'term'    => (string) $item['term'],
					'count'   => (int) $item['count'],
					'last'    => isset( $item['last'] ) ? (int) $item['last'] : 0,
					'context' => isset( $item['context'] ) ? (string) $item['context'] : 'comment',
					'example' => isset( $item['example'] ) ? (string) $item['example'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Removes a suggestion (dismissed, or allowed).
	 *
	 * @param string $term Term.
	 */
	public static function forget( $term ) {
		$list = self::stored();
		$key  = Matcher::normalize( $term );
		if ( isset( $list[ $key ] ) ) {
			unset( $list[ $key ] );
			update_option( self::OPTION, $list, false );
		}
	}

	/**
	 * Stored suggestions.
	 *
	 * @return array
	 */
	private static function stored() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Whether a term is already an exception.
	 *
	 * @param string $term Normalized term.
	 * @return bool
	 */
	private static function is_exception( $term ) {
		foreach ( (array) Settings::get( 'exceptions' ) as $exception ) {
			if ( Matcher::normalize( $exception ) === $term ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A short piece of the rescued message around the term, so the suggestion has context.
	 *
	 * @param string $text Message.
	 * @param string $term Term.
	 * @return string
	 */
	private static function example( $text, $term ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
		if ( '' === $text ) {
			return '';
		}
		$pos   = mb_stripos( $text, $term, 0, 'UTF-8' );
		$start = false === $pos ? 0 : max( 0, $pos - 50 );
		$cut   = mb_substr( $text, $start, 160, 'UTF-8' );
		return ( $start > 0 ? '…' : '' ) . trim( $cut ) . ( $start + mb_strlen( $cut, 'UTF-8' ) < mb_strlen( $text, 'UTF-8' ) ? '…' : '' );
	}
}
