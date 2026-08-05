<?php
/**
 * Server-Side llama.cpp Backend
 *
 * Implements NV_oOS_Embedded_LLM_Backend for server-side GGUF inference
 * via llama.cpp. Wraps the existing WP_MCP_AI_Embedded_Client as an
 * internal implementation detail — the client class is NOT deprecated;
 * it remains the canonical server-side inference engine.
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side llama.cpp backend.
 *
 * @since 0.2.0
 */
class NV_oOS_Embedded_Server_Backend implements NV_oOS_Embedded_LLM_Backend {

	/**
	 * Internal client instance (existing WP_MCP_AI_Embedded_Client).
	 *
	 * @since 0.2.0
	 *
	 * @var WP_MCP_AI_Embedded_Client|null
	 */
	private $client = null;

	/**
	 * Get the internal client, creating if needed.
	 *
	 * @since 0.2.0
	 *
	 * @return WP_MCP_AI_Embedded_Client|null Client instance or null if class unavailable.
	 */
	private function get_client() {
		if ( null === $this->client && class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			$this->client = new WP_MCP_AI_Embedded_Client();
		}
		return $this->client;
	}

	/**
	 * Get the backend slug.
	 *
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'server_side';
	}

	/**
	 * Get the backend display label.
	 *
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Server-Side llama.cpp (VPS/Dedicated)', 'nvoos-embedded' );
	}

	/**
	 * Get the backend description.
	 *
	 * @inheritDoc
	 */
	public function get_description() {
		return __(
			'Runs AI models on your WordPress server using llama.cpp with GGUF models. '
			. 'Requires shell_exec access and sufficient server RAM. Best for VPS and '
			. 'dedicated servers. Provides consistent performance regardless of client device.',
			'nvoos-embedded'
		);
	}

	/**
	 * Check if the backend is available.
	 *
	 * @inheritDoc
	 */
	public function is_available() {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		$disabled = explode( ',', ini_get( 'disable_functions' ) );
		$disabled = array_map( 'trim', $disabled );

		if ( in_array( 'shell_exec', $disabled, true ) ) {
			return false;
		}

		$client = $this->get_client();
		if ( ! $client ) {
			return false;
		}

		$binary_result = $client->get_binary_status();
		return ! empty( $binary_result['available'] );
	}

	/**
	 * Get backend requirements list.
	 *
	 * @inheritDoc
	 */
	public function get_requirements() {
		$shell_available = function_exists( 'shell_exec' );
		if ( $shell_available ) {
			$disabled        = explode( ',', ini_get( 'disable_functions' ) );
			$disabled        = array_map( 'trim', $disabled );
			$shell_available = ! in_array( 'shell_exec', $disabled, true );
		}

		$client              = $this->get_client();
		$binary_status       = $client ? $client->get_binary_status() : array( 'available' => false );
		$binary_available    = ! empty( $binary_status['available'] );
		$proc_open_available = function_exists( 'proc_open' );

		return array(
			'shell_exec'   => array(
				'label'  => __( 'shell_exec() available', 'nvoos-embedded' ),
				'status' => $shell_available,
				'note'   => $shell_available
					? __( 'PHP shell_exec() is enabled.', 'nvoos-embedded' )
					: __( 'shell_exec() is disabled. Contact your hosting provider.', 'nvoos-embedded' ),
			),
			'binary_found' => array(
				'label'  => __( 'llama.cpp binary found', 'nvoos-embedded' ),
				'status' => $binary_available,
				'note'   => $binary_available
					? __( 'llama.cpp binary detected.', 'nvoos-embedded' )
					: __( 'Binary not found. Install llama.cpp or use client-side WebLLM.', 'nvoos-embedded' ),
			),
			'proc_open'    => array(
				'label'  => __( 'proc_open() available', 'nvoos-embedded' ),
				'status' => $proc_open_available,
				'note'   => $proc_open_available
					? __( 'Streaming support available.', 'nvoos-embedded' )
					: __( 'proc_open() not available. Streaming disabled.', 'nvoos-embedded' ),
			),
		);
	}

	/**
	 * Execute a chat completion via server-side llama.cpp.
	 *
	 * @inheritDoc
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Request options.
	 * @return array|WP_Error
	 */
	public function create_chat_completion( array $messages, array $options ) {
		$client = $this->get_client();
		if ( ! $client ) {
			return new WP_Error(
				'embedded_client_unavailable',
				__( 'Server-side embedded client is not available.', 'nvoos-embedded' )
			);
		}

		return $client->create_chat_completion( $messages, $options );
	}

	/**
	 * Get available models for this backend.
	 *
	 * @inheritDoc
	 */
	public function get_available_models() {
		$client = $this->get_client();
		if ( ! $client ) {
			return array();
		}

		$models = $client->get_available_models();
		return is_array( $models ) ? $models : array();
	}

	/**
	 * Get health status for Site Health integration.
	 *
	 * @inheritDoc
	 */
	public function get_health_status() {
		$reqs   = $this->get_requirements();
		$all_ok = true;

		foreach ( $reqs as $req ) {
			if ( ! $req['status'] ) {
				$all_ok = false;
				break;
			}
		}

		$notes = array();
		foreach ( $reqs as $req ) {
			$notes[] = esc_html( $req['note'] );
		}

		return array(
			'status'      => $all_ok ? 'good' : 'critical',
			'label'       => __( 'Server-Side llama.cpp Backend', 'nvoos-embedded' ),
			'description' => $all_ok
				? __( 'Server-side inference is operational.', 'nvoos-embedded' )
				: __( 'One or more requirements are not met.', 'nvoos-embedded' ),
			'actions'     => $all_ok ? '' : __( 'Install llama.cpp binary and ensure shell_exec is enabled, or switch to the client-side WebLLM backend.', 'nvoos-embedded' ),
			'test'        => array(
				'label'       => __( 'Server-side embedded requirements', 'nvoos-embedded' ),
				'status'      => $all_ok ? 'good' : 'critical',
				'badge'       => $all_ok ? array(
					'label' => __( 'Operational', 'nvoos-embedded' ),
					'color' => 'green',
				) : array(
					'label' => __( 'Not available', 'nvoos-embedded' ),
					'color' => 'red',
				),
				'description' => '<ul><li>' . implode( '</li><li>', $notes ) . '</li></ul>',
				'test'        => 'nvoos_embedded_server_requirements',
			),
		);
	}
}
