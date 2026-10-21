<?php
/**
 * Plugin Name:       Unified Blocklist
 * Plugin URI:        https://github.com/ClearanceClarence/Unified-Blocklist
 * Description:       One blocklist for comments and forms. Syncs community spam lists, merges your own terms and exceptions, and logs every block so false positives are visible.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            ClearanceClarence
 * Author URI:        https://github.com/ClearanceClarence
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       unified-blocklist
 *
 * @package UnifiedBlocklist
 */

defined( 'ABSPATH' ) || exit;

define( 'UNIFIED_BLOCKLIST_VERSION', '1.0.0' );
define( 'UNIFIED_BLOCKLIST_FILE', __FILE__ );
define( 'UNIFIED_BLOCKLIST_DIR', plugin_dir_path( __FILE__ ) );
define( 'UNIFIED_BLOCKLIST_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'UNIFIED_BLOCKLIST_DEFAULT_SOURCE' ) ) {
	define( 'UNIFIED_BLOCKLIST_DEFAULT_SOURCE', 'https://raw.githubusercontent.com/splorp/wordpress-comment-blacklist/master/blacklist.txt' );
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'ClearanceClarence\\UnifiedBlocklist\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = UNIFIED_BLOCKLIST_DIR . 'src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'ClearanceClarence\\UnifiedBlocklist\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ClearanceClarence\\UnifiedBlocklist\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'ClearanceClarence\\UnifiedBlocklist\\Plugin', 'boot' ) );
