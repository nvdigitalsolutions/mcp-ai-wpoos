<?php
/**
 * Plugin Name:  NV oOS Graphify
 * Plugin URI:   https://github.com/nvdigitalsolutions/nvoos-graphify
 * Description:  Visual knowledge graph for WordPress. Maps your content into an interactive, navigable graph. Zero API keys required.
 * Version:      1.0.0-dev
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:       NV Digital Solutions
 * Author URI:   https://nvdigitalsolutions.com
 * License:      GPL-3.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:  nvoos-graphify
 * Domain Path:  /languages
 *
 * @package NvoosGraphify
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NVOOS_GRAPHIFY_VERSION', '1.0.0-dev' );
define( 'NVOOS_GRAPHIFY_FILE', __FILE__ );
define( 'NVOOS_GRAPHIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_GRAPHIFY_URL', plugin_dir_url( __FILE__ ) );
define( 'NVOOS_GRAPHIFY_DB_VERSION', '1' );

// ─── Autoloader ────────────────────────────────────────────────
$autoload = NVOOS_GRAPHIFY_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'NvoosGraphify\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $file     = NVOOS_GRAPHIFY_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ─── Boot ──────────────────────────────────────────────────────
add_action( 'plugins_loaded', static function (): void {
    if ( class_exists( 'NvoosGraphify\\Plugin' ) ) {
        ( new \NvoosGraphify\Plugin() )->register();
    }
}, 10 );
