<?php
/**
 * File download (admin-post.php). Everything else goes through the REST API.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist\Admin;

use ClearanceClarence\UnifiedBlocklist\TermList;

defined( 'ABSPATH' ) || exit;

/**
 * Streams the compiled list as a text file. A plain link is the simplest way to hand the browser
 * a 1-2 MB download with a proper filename.
 */
final class Actions {

	const DOWNLOAD = 'unified_blocklist_download';

	/**
	 * Registers the handler.
	 */
	public static function register() {
		add_action( 'admin_post_' . self::DOWNLOAD, array( self::class, 'download' ) );
	}

	/**
	 * Nonce-protected URL for the download.
	 *
	 * @return string
	 */
	public static function download_url() {
		// Not wp_nonce_url(): that returns an HTML-escaped URL ("&amp;"), which is right for
		// printing into HTML but breaks the nonce when the app sets it as a link target.
		return add_query_arg(
			array(
				'action'   => self::DOWNLOAD,
				'_wpnonce' => wp_create_nonce( self::DOWNLOAD ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Sends the file.
	 */
	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'unified-blocklist' ), 403 );
		}
		check_admin_referer( self::DOWNLOAD );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="unified-blocklist-' . gmdate( 'Y-m-d' ) . '.txt"' );
		echo implode( "\n", TermList::terms() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text download.
		exit;
	}
}
