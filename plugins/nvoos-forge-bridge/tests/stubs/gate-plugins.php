<?php
// phpcs:ignoreFile
/**
 * Class stubs for the gate-detection tests.
 *
 * The bridge only reports a plugin gate when that plugin is ACTIVE — its class
 * loaded. The unit suite has neither Elementor nor WP Maintenance Mode
 * installed, so these empty classes stand in for them and let the detection
 * branches run against their real option shapes.
 *
 * @package NvoosForgeBridge
 */

namespace Elementor {

	/**
	 * Stub of Elementor's main class.
	 */
	class Plugin {}
}

namespace {

	/**
	 * Stub of WP Maintenance Mode's main class.
	 */
	class WP_Maintenance_Mode {}
}
