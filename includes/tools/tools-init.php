<?php
/**
 * Bootstrap default tool registrations.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'plugins_loaded',
	function () {
		WP_MCP_AI_Tool_Registry::get_instance()->init();
	},
	5
);
