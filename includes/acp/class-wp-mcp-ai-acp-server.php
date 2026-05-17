<?php
/**
 * Main ACP (Agent Client Protocol) Server class.
 *
 * Orchestrates the ACP protocol surface for external IDEs (Zed, JetBrains, etc.)
 * to interact with NV oOS assistants natively.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrator for the Agent Client Protocol implementation.
 */
class WP_MCP_AI_ACP_Server {

	/**
	 * JSON-RPC Dispatcher.
	 *
	 * @var WP_MCP_AI_ACP_JSONRPC_Dispatcher
	 */
	protected $dispatcher;

	/**
	 * Session Manager.
	 *
	 * @var WP_MCP_AI_ACP_Session_Manager
	 */
	protected $session_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Dependencies will be injected or initialized here.
	}

	/**
	 * Initialize the ACP Server.
	 */
	public function init() {
		// Initialize the transport controllers, e.g., HTTP transport.
	}
}
