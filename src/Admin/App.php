<?php
/**
 * Settings > Unified Blocklist. The page itself is a React app; this mounts it.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist\Admin;

use ClearanceClarence\UnifiedBlocklist\Legacy;
use ClearanceClarence\UnifiedBlocklist\Rest;
use ClearanceClarence\UnifiedBlocklist\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The admin screen.
 */
final class App {

	const SLUG   = 'unified-blocklist';
	const HANDLE = 'unified-blocklist-admin';

	/**
	 * Hook suffix from add_options_page(), so assets only load on our page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hooks into WordPress.
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( UNIFIED_BLOCKLIST_FILE ), array( self::class, 'action_links' ) );
		add_action( 'admin_notices', array( self::class, 'legacy_notice' ) );
	}

	/**
	 * Adds Settings > Unified Blocklist.
	 */
	public static function menu() {
		self::$hook = (string) add_options_page(
			__( 'Unified Blocklist', 'unified-blocklist' ),
			__( 'Unified Blocklist', 'unified-blocklist' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Page URL, optionally to one view.
	 *
	 * @param string $view View id used in the hash.
	 * @return string
	 */
	public static function url( $view = '' ) {
		return admin_url( 'options-general.php?page=' . self::SLUG ) . ( '' !== $view ? '#' . $view : '' );
	}

	/**
	 * The stylesheet uses this class to take over the content area on our screen only.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && '' !== self::$hook && $screen->id === self::$hook ) {
			$classes .= ' ubl-screen';
		}
		return $classes;
	}

	/**
	 * "Open" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'unified-blocklist' ) . '</a>' );
		return $links;
	}

	/**
	 * Points admins at the cleanup when the old plugin left data behind. Not shown on our own
	 * screen, where the overview has the full cleanup card.
	 */
	public static function legacy_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! Legacy::detected() ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && $screen->id === self::$hook ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Unified Blocklist found data left by Dynamic Blacklist Updater, including a very large list stored in your Discussion settings.', 'unified-blocklist' ),
			esc_url( self::url( 'overview' ) ),
			esc_html__( 'Review and clean up', 'unified-blocklist' )
		);
	}

	/**
	 * Mount point for the app.
	 */
	public static function render() {
		// The hidden h1 and wp-header-end make WordPress put admin notices above the app, not inside it.
		echo '<h1 class="screen-reader-text">' . esc_html__( 'Unified Blocklist', 'unified-blocklist' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		if ( ! file_exists( self::asset_file() ) ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Unified Blocklist assets are missing. Run "npm install" and "npm run build" in the plugin folder.', 'unified-blocklist' );
			echo '</p></div>';
			return;
		}

		echo '<div id="unified-blocklist-root"></div>';
	}

	/**
	 * Loads the app on our page only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		if ( $hook !== self::$hook || ! file_exists( self::asset_file() ) ) {
			return;
		}

		$asset = require self::asset_file();

		wp_enqueue_script( self::HANDLE, UNIFIED_BLOCKLIST_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( self::HANDLE, UNIFIED_BLOCKLIST_URL . 'build/index.css', array(), $asset['version'] );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );

		// Inlined so the first paint already has real numbers and the right theme.
		wp_add_inline_script(
			self::HANDLE,
			'window.unifiedBlocklist = ' . wp_json_encode(
				array(
					'version'     => UNIFIED_BLOCKLIST_VERSION,
					'theme'       => Rest::theme(),
					'settings'    => Settings::all(),
					'state'       => Rest::state(),
					'downloadUrl' => Actions::download_url(),
					'discussion'  => admin_url( 'options-discussion.php' ),
				),
				JSON_HEX_TAG | JSON_HEX_AMP
			) . ';',
			'before'
		);

		wp_set_script_translations( self::HANDLE, 'unified-blocklist' );
	}

	/**
	 * Asset file written by wp-scripts next to the bundle.
	 *
	 * @return string
	 */
	private static function asset_file() {
		return UNIFIED_BLOCKLIST_DIR . 'build/index.asset.php';
	}
}
