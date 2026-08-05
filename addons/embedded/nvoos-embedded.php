<?php
/**
 * Plugin Name: NV oOS Embedded AI Addon
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Embedded AI and WebChat extension for NV oOS. Enables server-side LLM inference via llama.cpp (GGUF models), client-side browser inference via WebLLM (WebGPU), and decentralised P2P WebChat rooms with WebRTC signaling. Requires NV oOS base plugin.
 * Version:     0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary
 * License URI: https://nvdigitalsolutions.com/wpoos/license
 * Text Domain: nvoos-embedded
 * Domain Path: /languages
 *
 * @package NV_oOS_Embedded
 *
 * ⚠️ PROPRIETARY SOFTWARE
 * This is commercial software licensed for authorized users only.
 * Patent Pending (Application #19/410,504)
 * © 2025 NV Digital Solutions - All Rights Reserved
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * All rights reserved. This is proprietary software.
 *
 * Bundled / referenced third-party engines (WebLLM Apache-2.0, llama.cpp MIT)
 * retain their upstream licenses; see readme.txt and the repository-wide
 * CREDITS.md for the full attribution index.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_EMBEDDED_VERSION', '0.2.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_EMBEDDED_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_EMBEDDED_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_EMBEDDED_URL', plugin_dir_url( __FILE__ ) );

// Load core classes.
require_once NVOOS_EMBEDDED_PATH . 'includes/class-nvoos-embedded.php';

// Load backend registry infrastructure (v0.2.0).
require_once NVOOS_EMBEDDED_PATH . 'includes/embedded/interface-nvoos-embedded-llm-backend.php';
require_once NVOOS_EMBEDDED_PATH . 'includes/embedded/class-nvoos-embedded-backend-registry.php';
require_once NVOOS_EMBEDDED_PATH . 'includes/embedded/class-nvoos-embedded-client-backend.php';
require_once NVOOS_EMBEDDED_PATH . 'includes/embedded/class-nvoos-embedded-server-backend.php';

// Load embedded LLM classes.
require_once NVOOS_EMBEDDED_PATH . 'includes/embedded/class-wp-mcp-ai-embedded-client.php';
require_once NVOOS_EMBEDDED_PATH . 'includes/embedded/class-nvoos-embedded-webllm-enqueue.php';

// Load embedded transcribe (Gemma 4 audio STT).
require_once NVOOS_EMBEDDED_PATH . 'includes/embedded/class-wp-mcp-ai-embedded-transcribe.php';

// Load abilities registration (WordPress 6.9+ Abilities API).
require_once NVOOS_EMBEDDED_PATH . 'includes/abilities/class-nvoos-embedded-abilities.php';

// Load admin classes.
if ( is_admin() ) {
	require_once NVOOS_EMBEDDED_PATH . 'includes/admin/class-wp-mcp-ai-webllm-settings-page.php';
	require_once NVOOS_EMBEDDED_PATH . 'includes/admin/class-wp-mcp-ai-embedded-model-ajax.php';

	// Load OCR health dashboard if self-hosted OCR client is available.
	if ( class_exists( 'WP_MCP_AI_Self_Hosted_OCR_Client' ) ) {
		require_once NVOOS_EMBEDDED_PATH . 'includes/admin/class-nvoos-embedded-ocr-dashboard.php';
		new NV_oOS_Embedded_OCR_Dashboard();
	}
}

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 0.1.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_embedded_is_base_active() {
	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Check whether the embedded addon is fully ready.
 *
 * @since 0.1.0
 *
 * @return bool True when the addon is operational.
 */
function nvoos_embedded_is_ready() {
	return nvoos_embedded_is_base_active() && NV_oOS_Embedded::is_enabled();
}

// Boot the plugin.
NV_oOS_Embedded::init();
