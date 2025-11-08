<?php
/**
 * Chat Service for WP oOS
 *
 * Orchestrates chat operations using Rate_Limit_Manager and Token_Budget_Manager.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Chat_Service' ) ) {
	/**
	 * Service layer for chat orchestration.
	 *
	 * This service handles the orchestration of chat operations, coordinating
	 * between rate limiting, token budget management, and model routing.
	 */
	class WP_MCP_AI_Chat_Service {
		/**
		 * Rate limit manager instance.
		 *
		 * @var WP_MCP_AI_Rate_Limit_Manager
		 */
		private $rate_limit_manager;

		/**
		 * Token budget manager instance.
		 *
		 * @var WP_MCP_AI_Token_Budget_Manager
		 */
		private $token_budget_manager;

		/**
		 * Language model router instance.
		 *
		 * @var WP_MCP_AI_Language_Model_Router
		 */
		private $model_router;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_Rate_Limit_Manager    $rate_limit_manager   Rate limit manager instance.
		 * @param WP_MCP_AI_Token_Budget_Manager  $token_budget_manager Token budget manager instance.
		 * @param WP_MCP_AI_Language_Model_Router $model_router         Model router instance.
		 */
		public function __construct( $rate_limit_manager = null, $token_budget_manager = null, $model_router = null ) {
			$this->rate_limit_manager   = $rate_limit_manager ?? new WP_MCP_AI_Rate_Limit_Manager();
			$this->token_budget_manager = $token_budget_manager ?? new WP_MCP_AI_Token_Budget_Manager();
			$this->model_router         = $model_router ?? WP_MCP_AI_Language_Model_Router::instance();
		}

		/**
		 * Process a chat request with orchestration.
		 *
		 * @param array $params Chat request parameters.
		 * @return array|WP_Error Chat response or error.
		 */
		public function process_chat( $params ) {
			// Validate required parameters.
			if ( empty( $params['messages'] ) ) {
				return new WP_Error( 'missing_messages', __( 'Messages array is required.', 'wp-mcp-ai' ) );
			}

			if ( empty( $params['assistant_id'] ) && empty( $params['model'] ) ) {
				return new WP_Error( 'missing_model', __( 'Assistant ID or model is required.', 'wp-mcp-ai' ) );
			}

			// Apply rate limiting.
			$rate_limit_result = $this->apply_rate_limiting( $params );
			if ( is_wp_error( $rate_limit_result ) ) {
				return $rate_limit_result;
			}

			// Calculate and validate token budget.
			$budget_result = $this->validate_token_budget( $params );
			if ( is_wp_error( $budget_result ) ) {
				return $budget_result;
			}

			// Route to appropriate model.
			$response = $this->route_to_model( $params, $budget_result );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Update usage metrics.
			$this->update_usage_metrics( $params, $response );

			return $response;
		}

		/**
		 * Apply rate limiting to the chat request.
		 *
		 * @param array $params Chat request parameters.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function apply_rate_limiting( $params ) {
			// Get user ID for rate limiting.
			$user_id = $params['user_id'] ?? get_current_user_id();

			// Check rate limit.
			$rate_limit_key = 'chat_' . $user_id;
			
			/**
			 * Filter the rate limit for chat requests.
			 *
			 * @param array  $rate_limit Rate limit configuration.
			 * @param int    $user_id    User ID.
			 * @param array  $params     Chat request parameters.
			 */
			$rate_limit = apply_filters(
				'wp_mcp_ai_chat_rate_limit',
				array(
					'max_requests' => 60,
					'time_window'  => 60,
				),
				$user_id,
				$params
			);

			// Note: Rate limit manager's actual implementation will be used here.
			// For now, we'll just validate the structure.
			if ( ! is_array( $rate_limit ) || ! isset( $rate_limit['max_requests'] ) ) {
				return new WP_Error( 'invalid_rate_limit', __( 'Invalid rate limit configuration.', 'wp-mcp-ai' ) );
			}

			return true;
		}

		/**
		 * Validate token budget for the request.
		 *
		 * @param array $params Chat request parameters.
		 * @return array|WP_Error Token budget allocation or error.
		 */
		private function validate_token_budget( $params ) {
			$messages = $params['messages'] ?? array();
			
			// Estimate token count for request.
			$estimated_tokens = $this->token_budget_manager->estimate_tokens( $messages );

			// Get available budget.
			$model           = $params['model'] ?? 'gpt-4o-mini';
			$available_budget = $this->token_budget_manager->get_available_budget( $model );

			// Check if request fits within budget.
			if ( $estimated_tokens > $available_budget ) {
				return new WP_Error(
					'budget_exceeded',
					sprintf(
						/* translators: 1: estimated tokens, 2: available budget */
						__( 'Request requires %1$d tokens but only %2$d are available.', 'wp-mcp-ai' ),
						$estimated_tokens,
						$available_budget
					)
				);
			}

			return array(
				'estimated_tokens' => $estimated_tokens,
				'available_budget' => $available_budget,
				'max_tokens'       => min( $available_budget - $estimated_tokens, $params['max_tokens'] ?? 2048 ),
			);
		}

		/**
		 * Route the request to the appropriate model.
		 *
		 * @param array $params        Chat request parameters.
		 * @param array $budget_result Token budget allocation.
		 * @return array|WP_Error Model response or error.
		 */
		private function route_to_model( $params, $budget_result ) {
			// Prepare request for model router.
			$request = array(
				'messages'   => $params['messages'],
				'model'      => $params['model'] ?? 'gpt-4o-mini',
				'max_tokens' => $budget_result['max_tokens'],
				'stream'     => $params['stream'] ?? false,
			);

			// Add optional parameters.
			if ( isset( $params['temperature'] ) ) {
				$request['temperature'] = $params['temperature'];
			}

			if ( isset( $params['tools'] ) ) {
				$request['tools'] = $params['tools'];
			}

			// Route to model.
			return $this->model_router->route( $request );
		}

		/**
		 * Update usage metrics after successful chat.
		 *
		 * @param array $params   Chat request parameters.
		 * @param array $response Model response.
		 * @return void
		 */
		private function update_usage_metrics( $params, $response ) {
			// Extract usage information from response.
			$usage = $response['usage'] ?? array();
			
			if ( empty( $usage ) ) {
				return;
			}

			$user_id = $params['user_id'] ?? get_current_user_id();
			$model   = $params['model'] ?? 'gpt-4o-mini';

			/**
			 * Action fired after chat usage is recorded.
			 *
			 * @param array $usage   Usage information.
			 * @param int   $user_id User ID.
			 * @param string $model  Model name.
			 * @param array $params  Chat request parameters.
			 */
			do_action( 'wp_mcp_ai_chat_usage_recorded', $usage, $user_id, $model, $params );
		}

		/**
		 * Get the rate limit manager instance.
		 *
		 * @return WP_MCP_AI_Rate_Limit_Manager
		 */
		public function get_rate_limit_manager() {
			return $this->rate_limit_manager;
		}

		/**
		 * Get the token budget manager instance.
		 *
		 * @return WP_MCP_AI_Token_Budget_Manager
		 */
		public function get_token_budget_manager() {
			return $this->token_budget_manager;
		}

		/**
		 * Get the model router instance.
		 *
		 * @return WP_MCP_AI_Language_Model_Router
		 */
		public function get_model_router() {
			return $this->model_router;
		}
	}
}
