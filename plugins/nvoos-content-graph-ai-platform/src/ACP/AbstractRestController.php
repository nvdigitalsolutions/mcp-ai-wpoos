<?php
/**
 * Minimal abstract REST controller base for the Platform addon.
 *
 * Provides only what the ported ACP transport controller needs. The base
 * plugin's WP_MCP_AI_REST_Controller_Base is intentionally NOT ported — it
 * depends on the monolith's container, authenticator, validator, and
 * security manager, all of which stay in the base plugin.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ACP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for platform-owned REST controllers.
 */
abstract class AbstractRestController {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * REST API namespace (instance-level override).
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai/v1';

	/**
	 * REST API base route.
	 *
	 * @var string
	 */
	protected $rest_base = '';

	/**
	 * Register REST API routes.
	 *
	 * Must be implemented by child controllers.
	 *
	 * @return void
	 */
	abstract public function register_routes();

	/**
	 * Format error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable error message.
	 * @param int    $status  HTTP status code (default: 400).
	 * @param array  $actions Optional actions for error recovery.
	 * @return \WP_Error WP_Error instance.
	 */
	protected function error( $code, $message, $status = 400, $actions = array() ) {
		$data = array(
			'status' => $status,
		);

		if ( ! empty( $actions ) ) {
			$data['actions'] = $actions;
		}

		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Format success response.
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status code (default: 200).
	 * @return \WP_REST_Response REST response instance.
	 */
	protected function success( $data, $status = 200 ) {
		$response = new \WP_REST_Response( $data, $status );

		// Add version header.
		$response->set_headers(
			array(
				'X-NVOOS-Platform-Version' => defined( 'NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION' ) ? NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION : 'dev',
			)
		);

		return $response;
	}
}
