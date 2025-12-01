<?php
/**
 * Tool that triggers OpenAI workflows or assistants via the Responses API.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-admin-settings.php';

/**
 * Provides an integration point for OpenAI external actions.
 */
class WP_MCP_AI_Tool_Run_OpenAI_External_Action implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'run_openai_external_action';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Run OpenAI External Action', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Invokes a predefined OpenAI workflow or assistant using the Responses API.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action_type'     => array(
					'type'        => 'string',
					'enum'        => array( 'workflow', 'assistant' ),
					'description' => __( 'Whether to invoke a workflow or an assistant.', 'wp-mcp-ai' ),
				),
				'identifier'      => array(
					'type'        => 'string',
					'description' => __( 'The workflow_id or assistant_id to trigger.', 'wp-mcp-ai' ),
				),
				'input_text'      => array(
					'type'        => 'string',
					'description' => __( 'Optional user instruction or message to include in the request.', 'wp-mcp-ai' ),
				),
				'input_variables' => array(
					'type'                 => 'object',
					'description'          => __( 'Optional key/value inputs forwarded to the workflow or assistant.', 'wp-mcp-ai' ),
					'additionalProperties' => array(
						'anyOf' => array(
							array(
								'type' => array( 'string', 'number', 'integer', 'boolean', 'null' ),
							),
							array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
							array(
								'type'  => 'array',
								'items' => array(
									'anyOf' => array(
										array(
											'type' => array( 'string', 'number', 'integer', 'boolean', 'null' ),
										),
										array(
											'type' => 'object',
											'additionalProperties' => true,
										),
									),
								),
							),
						),
					),
				),
			),
			'required'             => array( 'action_type', 'identifier' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( isset( $context['assistant_config'] ) && is_array( $context['assistant_config'] ) ) {
			if ( empty( $arguments['action_type'] ) && ! empty( $context['assistant_config']['external_action_type'] ) ) {
				$arguments['action_type'] = $context['assistant_config']['external_action_type'];
			}

			if ( empty( $arguments['identifier'] ) && ! empty( $context['assistant_config']['external_action_identifier'] ) ) {
				$arguments['identifier'] = $context['assistant_config']['external_action_identifier'];
			}
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to run external OpenAI actions.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$action_type = isset( $arguments['action_type'] ) ? strtolower( sanitize_key( $arguments['action_type'] ) ) : '';

		if ( ! in_array( $action_type, array( 'workflow', 'assistant' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'The provided action type is not supported.', 'wp-mcp-ai' ) );
		}

		$identifier = isset( $arguments['identifier'] ) ? sanitize_text_field( $arguments['identifier'] ) : '';

		if ( empty( $identifier ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_identifier', __( 'A workflow_id or assistant_id must be supplied.', 'wp-mcp-ai' ) );
		}

		$input_text = isset( $arguments['input_text'] ) ? sanitize_textarea_field( $arguments['input_text'] ) : '';

		$input_variables = array();
		if ( isset( $arguments['input_variables'] ) ) {
			if ( ! is_array( $arguments['input_variables'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_variables', __( 'Input variables must be provided as an object.', 'wp-mcp-ai' ) );
			}

			$input_variables = $this->sanitize_input_variables( $arguments['input_variables'] );
		}

		$client  = new WP_MCP_AI_OpenAI_Client();
		$api_key = $client->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_openai_api_key' => __( 'Add an OpenAI API key in the WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$payload = $this->build_payload( $action_type, $identifier, $input_text, $input_variables );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$encoded_body = wp_json_encode( $payload );

		if ( false === $encoded_body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the OpenAI request payload.', 'wp-mcp-ai' ) );
		}

		$timeout = $this->resolve_request_timeout( $arguments, $context );

		$request_args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'timeout' => $timeout,
			'body'    => $encoded_body,
		);

		WP_MCP_AI_Logger::log_event(
			'openai_external_action_request',
			'Sending external action request to OpenAI.',
			array(
				'payload' => $this->get_log_safe_payload( $payload ),
			)
		);

		$response = wp_remote_post( self::RESPONSES_ENDPOINT, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'OpenAI external action request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_http_error',
				__( 'The OpenAI API request failed to complete.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI external action response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The OpenAI API returned malformed JSON.', 'wp-mcp-ai' ) );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from OpenAI.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'OpenAI external action returned an error.',
				array(
					'status'   => $status_code,
					'response' => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_api_error',
				$message,
				array(
					'status' => $status_code,
					'body'   => $decoded,
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'openai_external_action_response',
			'OpenAI external action completed.',
			array(
				'response' => $decoded,
			)
		);

		return array(
			'id'       => isset( $decoded['id'] ) ? $decoded['id'] : '',
			'status'   => isset( $decoded['status'] ) ? $decoded['status'] : '',
			'output'   => isset( $decoded['output'] ) ? $decoded['output'] : null,
			'metadata' => isset( $decoded['metadata'] ) ? $decoded['metadata'] : null,
			'raw'      => $decoded,
		);
	}

	/**
	 * Determine the HTTP request timeout for the external action call.
	 *
	 * @param array $arguments Tool arguments supplied for execution.
	 * @param array $context   Execution context for the tool run.
	 * @return int
	 */
	protected function resolve_request_timeout( array $arguments, array $context ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$timeout  = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;

		$candidates = array();

		if ( isset( $arguments['timeout'] ) ) {
			$candidates[] = $arguments['timeout'];
		}

		if ( isset( $arguments['request_timeout'] ) ) {
			$candidates[] = $arguments['request_timeout'];
		}

		if ( isset( $context['assistant_config'] ) && is_array( $context['assistant_config'] ) ) {
			if ( isset( $context['assistant_config']['external_action_request_timeout'] ) ) {
				$candidates[] = $context['assistant_config']['external_action_request_timeout'];
			}

			if ( isset( $context['assistant_config']['request_timeout'] ) ) {
				$candidates[] = $context['assistant_config']['request_timeout'];
			}
		}

		if ( isset( $context['request_timeout'] ) ) {
			$candidates[] = $context['request_timeout'];
		}

		foreach ( $candidates as $candidate ) {
			$candidate = absint( $candidate );

			if ( $candidate > 0 ) {
				$timeout = $candidate;
				break;
			}
		}

		return max( 5, $timeout );
	}

	/**
	 * Ensure input variables are serialisable and sanitised.
	 *
	 * @param array $variables User supplied variables.
	 * @return array
	 */
	protected function sanitize_input_variables( array $variables ) {
		$sanitised = array();

		foreach ( $variables as $key => $value ) {
			if ( is_int( $key ) ) {
				$clean_key = $key;
			} else {
				$clean_key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );

				if ( '' === $clean_key ) {
					continue;
				}
			}

			$sanitised[ $clean_key ] = $this->sanitize_variable_value( $value );
		}

		return $sanitised;
	}

	/**
	 * Sanitise a single variable value so it can be safely encoded.
	 *
	 * @param mixed $value Value to sanitise.
	 * @return mixed
	 */
	protected function sanitize_variable_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}

		if ( is_array( $value ) ) {
			$sanitised = array();
			foreach ( $value as $key => $nested_value ) {
				$sanitised[ $key ] = $this->sanitize_variable_value( $nested_value );
			}

			return $sanitised;
		}

		if ( $value instanceof JsonSerializable ) {
			return $value->jsonSerialize();
		}

		if ( is_object( $value ) ) {
			$decoded = json_decode( wp_json_encode( $value ), true );

			return is_array( $decoded ) ? $decoded : array();
		}

		return json_decode( wp_json_encode( $value ), true );
	}

	/**
	 * Build the request payload for the Responses API.
	 *
	 * @param string $action_type     Selected action type.
	 * @param string $identifier      Workflow or assistant identifier.
	 * @param string $input_text      Optional input text.
	 * @param array  $input_variables Optional input variables.
	 * @return array|WP_Error
	 */
	protected function build_payload( $action_type, $identifier, $input_text, array $input_variables ) {
		$payload = array(
			'metadata' => array(
				'source'      => 'wp-mcp-ai',
				'action_type' => $action_type,
			),
		);

		if ( 'workflow' === $action_type ) {
			$payload['workflow_id'] = $identifier;

			if ( ! empty( $input_variables ) ) {
				$payload['inputs'] = $input_variables;
			}

			if ( '' !== $input_text ) {
				$payload['input'] = $input_text;
			}
		} else {
			$payload['assistant_id'] = $identifier;

			$messages = array();

			if ( '' !== $input_text ) {
				$messages[] = array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $input_text,
						),
					),
				);
			}

			if ( ! empty( $input_variables ) ) {
				$messages[] = array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => wp_json_encode( $input_variables ),
						),
					),
				);
			}

			if ( empty( $messages ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_input', __( 'Assistant actions require either input text or variables.', 'wp-mcp-ai' ) );
			}

			$payload['input'] = $messages;
		}

		return $payload;
	}

	/**
	 * Reduce payload noise for logging while keeping context.
	 *
	 * @param array $payload Payload that will be logged.
	 * @return array
	 */
	protected function get_log_safe_payload( array $payload ) {
		$log_payload = $payload;

		if ( isset( $log_payload['input'] ) && is_array( $log_payload['input'] ) ) {
			$log_payload['input'] = '[truncated array]';
		}

		if ( isset( $log_payload['inputs'] ) && is_array( $log_payload['inputs'] ) ) {
			$log_payload['inputs'] = array_keys( $log_payload['inputs'] );
		}

		return $log_payload;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
