<?php
/**
 * Plugin Name: NV oOS Canvas Addon
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Platform-specific canvas native binaries for NV oOS Pro. Enables Tesseract PDF OCR by providing the canvas module's native binaries pre-compiled for your server platform. Requires NV oOS Pro addon to be installed and active.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary
 * License URI: https://nvdigitalsolutions.com/wpoos/license
 * Text Domain: nvoos-canvas
 * Domain Path: /languages
 *
 * @package NV_oOS_Canvas
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * All rights reserved. This is proprietary software.
 *
 * The bundled canvas native binaries (canvas.node) retain their upstream MIT
 * license; the linked Cairo graphics library is LGPL-2.1 (dynamically linked
 * at runtime). See assets/canvas/package.json and the repository-wide
 * CREDITS.md for the full attribution index.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_CANVAS_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_CANVAS_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_CANVAS_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_CANVAS_URL', plugin_dir_url( __FILE__ ) );

/** Absolute path to the bundled canvas module directory. */
define( 'NVOOS_CANVAS_MODULE_PATH', NVOOS_CANVAS_PATH . 'assets/canvas' );

require_once NVOOS_CANVAS_PATH . 'includes/class-nvoos-canvas.php';

/**
 * Get the absolute path to the bundled canvas module directory.
 *
 * This function is the main integration point for other plugins.
 * Returns the path only when the native binary is present (i.e. this
 * is a platform build, not just the JS-wrapper-only package).
 *
 * @return string Absolute path to canvas module, or empty string if binaries are missing.
 */
function nvoos_canvas_get_dir() {
	return apply_filters( 'nvoos_canvas_dir', NV_oOS_Canvas::get_canvas_dir() );
}

/**
 * Check whether the canvas native binary is available on this server.
 *
 * @return bool True if the binary exists and is executable.
 */
function nvoos_canvas_is_available() {
	return NV_oOS_Canvas::is_available();
}

// Boot the plugin.
NV_oOS_Canvas::init();
