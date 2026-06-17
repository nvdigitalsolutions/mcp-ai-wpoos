<?php
/**
 * REST API controller for retroactive transcript-to-memory mining jobs.
 *
 * Provides three thin endpoints under `/mcp-ai/v1/transcript-mining/` that
 * drive the {@see WP_MCP_AI_Transcript_Mining_Job} service:
 *
 * - `POST   /transcript-mining/jobs`              — enqueue a mining job
 * - `GET    /transcript-mining/jobs/{id}`         — poll progress
 * - `POST   /transcript-mining/jobs/{id}/cancel`  — cancel
 *
 * Every endpoint requires `manage_options`. WP REST cookie auth supplies
 * the nonce check automatically when the admin UI sends `X-WP-Nonce`,
 * matching the rest of the admin REST surface.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Transcript_Mining_Job' ) ) {
	$transcript_mining_job_path = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-transcript-mining-job.php';
	if ( file_exists( $transcript_mining_job_path ) ) {
		require_once $transcript_mining_job_path;
	}
}

/**
 * Transcript mining REST controller.
 */
class WP_MCP_AI_REST_Transcript_Mining_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/transcript-mining/jobs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_job' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'agent_id'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'wing'             => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'room'             => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'verbatim'         => array( 'type' => 'boolean' ),
					'dry_run'          => array( 'type' => 'boolean' ),
					'ttl'              => array( 'type' => 'integer' ),
					'chunk_size'       => array( 'type' => 'integer' ),
					'transcript_query' => array( 'type' => 'object' ),
					'session_keys'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'batch_size'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/transcript-mining/jobs/(?P<id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/transcript-mining/jobs/(?P<id>[a-zA-Z0-9-]+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_job' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission gate: site admins only.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * POST /transcript-mining/jobs
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( WP_REST_Request $request ) {
		try {
			$agent_id = (string) $request->get_param( 'agent_id' );
			if ( '' === $agent_id ) {
				return new WP_Error( 'missing_agent_id', __( 'agent_id is required.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}

			$args = array( 'agent_id' => $agent_id );
			foreach ( array( 'wing', 'room' ) as $key ) {
				$value = $request->get_param( $key );
				if ( null !== $value && '' !== $value ) {
					$args[ $key ] = sanitize_text_field( (string) $value );
				}
			}
			foreach ( array( 'verbatim', 'dry_run' ) as $key ) {
				$value = $request->get_param( $key );
				if ( null !== $value ) {
					$args[ $key ] = (bool) $value;
				}
			}
			foreach ( array( 'ttl', 'chunk_size' ) as $key ) {
				$value = $request->get_param( $key );
				if ( null !== $value ) {
					$args[ $key ] = (int) $value;
				}
			}
			$transcript_query = $request->get_param( 'transcript_query' );
			if ( is_array( $transcript_query ) ) {
				$args['transcript_query'] = $transcript_query;
			}

			$session_keys = $request->get_param( 'session_keys' );
			$batch_size   = $request->get_param( 'batch_size' );

			$config = array();
			if ( is_array( $session_keys ) && ! empty( $session_keys ) ) {
				$config['session_keys'] = $session_keys;
			}
			if ( null !== $batch_size ) {
				$config['batch_size'] = (int) $batch_size;
			}

			$state = WP_MCP_AI_Transcript_Mining_Job::enqueue( $args, $config );
			if ( is_wp_error( $state ) ) {
				$state->add_data( array( 'status' => 400 ) );
				return $state;
			}

			return rest_ensure_response( WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] ) );
		} catch ( Throwable $e ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Transcript mining REST: create_job threw an exception.',
					array(
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
					)
				);
			}
			return new WP_Error(
				'transcript_mining_create_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to enqueue mining job: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * GET /transcript-mining/jobs/{id}
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		try {
			$id       = (string) $request->get_param( 'id' );
			$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $id );
			if ( null === $progress ) {
				return new WP_Error( 'job_not_found', __( 'Job not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
			}

			// Self-healing inline kick: if the job has been stuck in
			// `queued` past the stale threshold, schedule an inline tick
			// to run after this response is flushed. Guarantees forward
			// progress on hosts where the WP-Cron loopback never fires
			// (DISABLE_WP_CRON sites, firewalled loopback, etc.). The
			// response payload itself is unchanged — callers see the
			// same progress projection they would have without this.
			if ( 'queued' === $progress['status']
				&& isset( $progress['created_at'] )
				&& ( time() - (int) $progress['created_at'] ) > WP_MCP_AI_Transcript_Mining_Job::STALE_QUEUED_THRESHOLD_SECONDS
			) {
				// `$id` is already sanitized by the route's `sanitize_text_field`
				// arg callback; capture it directly for the shutdown closure.
				add_action(
					'shutdown',
					static function () use ( $id ) {
						WP_MCP_AI_Transcript_Mining_Job::kick_inline( $id );
					},
					20
				);
			}

			return rest_ensure_response( $progress );
		} catch ( Throwable $e ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Transcript mining REST: get_job threw an exception.',
					array(
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
					)
				);
			}
			return new WP_Error(
				'transcript_mining_get_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to load mining job: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * POST /transcript-mining/jobs/{id}/cancel
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( WP_REST_Request $request ) {
		try {
			$id     = (string) $request->get_param( 'id' );
			$result = WP_MCP_AI_Transcript_Mining_Job::cancel( $id );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 404 ) );
				return $result;
			}
			return rest_ensure_response( WP_MCP_AI_Transcript_Mining_Job::get_progress( $id ) );
		} catch ( Throwable $e ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Transcript mining REST: cancel_job threw an exception.',
					array(
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
					)
				);
			}
			return new WP_Error(
				'transcript_mining_cancel_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to cancel mining job: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}
	}
}
