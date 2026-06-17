<?php
/**
 * Plugin Name: NV oOS Cornerstone3D Addon
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Pre-built Cornerstone3D ESM bundles for the NV oOS Pro Medical Imaging Viewer. Eliminates the runtime CDN dependency by providing locally-vendored JavaScript modules for DICOM rendering. Requires NV oOS Pro addon to be installed and active.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary
 * License URI: https://nvdigitalsolutions.com/wpoos/license
 * Text Domain: nvoos-cornerstone3d
 * Domain Path: /languages
 *
 * @package NV_oOS_Cornerstone3D
 *
 * ⚠️ PROPRIETARY SOFTWARE
 * This is commercial software licensed for authorized users only.
 * Patent Pending (Application #19/410,504)
 * © 2025 NV Digital Solutions - All Rights Reserved
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com).
 * All rights reserved.
 *
 * This addon is PROPRIETARY software of NV Digital Solutions. It is NOT
 * licensed under the GPL that covers the rest of the NV oOS repository,
 * and it is NOT distributed via WordPress.org. Use, reproduction, modification,
 * and redistribution are governed by the addon-local `LICENSE` file shipped in
 * this directory. See `LICENSE` for the full terms.
 *
 * The bundled Cornerstone3D ESM bundles retain their upstream MIT license.
 * See `THIRD_PARTY_NOTICES.md` and the repository-wide `CREDITS.md` for the
 * full attribution index.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_CORNERSTONE3D_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_CORNERSTONE3D_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_CORNERSTONE3D_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_CORNERSTONE3D_URL', plugin_dir_url( __FILE__ ) );

/** Absolute path to the bundled Cornerstone3D ESM module directory. */
define( 'NVOOS_CORNERSTONE3D_ASSETS_PATH', NVOOS_CORNERSTONE3D_PATH . 'assets/cornerstone' );

require_once NVOOS_CORNERSTONE3D_PATH . 'includes/class-nvoos-cornerstone3d.php';

/**
 * Get the absolute path to the bundled Cornerstone3D module directory.
 *
 * This function is the main integration point for the Pro addon.
 * Returns the path only when all five ESM bundles are present.
 *
 * @return string Absolute path to cornerstone directory, or empty string if bundles are missing.
 */
function nvoos_cornerstone3d_get_dir() {
	return apply_filters( 'nvoos_cornerstone3d_dir', NV_oOS_Cornerstone3D::get_assets_dir() );
}

/**
 * Check whether the Cornerstone3D ESM bundles are available.
 *
 * @return bool True if all five ESM bundles exist on disk.
 */
function nvoos_cornerstone3d_is_available() {
	return NV_oOS_Cornerstone3D::is_available();
}

/**
 * Get the URL base for the Cornerstone3D ESM bundles.
 *
 * @return string URL base (trailing slash) or empty string.
 */
function nvoos_cornerstone3d_get_url() {
	if ( NV_oOS_Cornerstone3D::is_available() ) {
		return NVOOS_CORNERSTONE3D_URL . 'assets/cornerstone/';
	}
	return '';
}

// Boot the plugin.
NV_oOS_Cornerstone3D::init();
