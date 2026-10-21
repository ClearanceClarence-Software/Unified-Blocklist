<?php
/**
 * Comments.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist\Integrations;

use ClearanceClarence\UnifiedBlocklist\Guard;
use ClearanceClarence\UnifiedBlocklist\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends matching comments to Spam (recoverable) by default instead of core's Trash.
 */
final class Comments {

	/**
	 * Hooks in after core's own disallowed check, which runs before this filter.
	 */
	public static function register() {
		add_filter( 'pre_comment_approved', array( self::class, 'filter' ), 20, 2 );
	}

	/**
	 * Filter callback.
	 *
	 * @param int|string|\WP_Error $approved    Current status.
	 * @param array                $commentdata Comment data.
	 * @return int|string|\WP_Error
	 */
	public static function filter( $approved, $commentdata ) {
		$action = (string) Settings::get( 'comment_action' );

		if ( 'off' === $action || is_wp_error( $approved ) || 'spam' === $approved || 'trash' === $approved ) {
			return $approved;
		}

		$fields = array(
			__( 'Name', 'unified-blocklist' )    => isset( $commentdata['comment_author'] ) ? $commentdata['comment_author'] : '',
			__( 'Email', 'unified-blocklist' )   => isset( $commentdata['comment_author_email'] ) ? $commentdata['comment_author_email'] : '',
			__( 'Website', 'unified-blocklist' ) => isset( $commentdata['comment_author_url'] ) ? $commentdata['comment_author_url'] : '',
			__( 'Comment', 'unified-blocklist' ) => isset( $commentdata['comment_content'] ) ? $commentdata['comment_content'] : '',
		);

		$post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
		$hit     = Guard::check( 'comment', $fields, $action, $post_id ? 'post:' . $post_id : '' );

		if ( null === $hit ) {
			return $approved;
		}

		switch ( $action ) {
			case 'trash':
				return 'trash';
			case 'hold':
				return 0;
			default:
				return 'spam';
		}
	}
}
