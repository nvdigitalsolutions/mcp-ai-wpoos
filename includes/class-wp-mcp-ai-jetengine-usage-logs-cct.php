<?php
/**
 * JetEngine Custom Content Type for AI usage logs.
 *
 * Provides detailed usage logging with queryable REST API at:
 * /wp-json/jet-cct/ai_usage_logs
 *
 * This allows:
 * - Historical usage analysis
 * - Cost tracking and forecasting
 * - Orchestration dashboard integration
 * - External reporting systems
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register and manage AI usage logs CCT.
 */
class WP_MCP_AI_JetEngine_Usage_Logs_CCT {
	const SLUG = 'ai_usage_logs';

	/**
	 * Hook into JetEngine to provision the usage logs content type.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 0 );
		add_action( 'wp_mcp_ai_embedded_usage_tracked', array( __CLASS__, 'log_embedded_usage' ), 10, 5 );
		add_action( 'wp_mcp_ai_after_usage_recorded', array( __CLASS__, 'log_server_usage' ), 10, 6 );
	}

	/**
	 * Get the CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Register the usage logs CCT if it doesn't exist.
	 */
	public static function maybe_register_cct() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return;
		}

		// Check if already registered.
		if ( self::cct_exists( $module ) ) {
			return;
		}

		$request = self::get_registration_request();

		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			return;
		}

		$module->manager->data->set_request( $request );
		$module->manager->data->create_item( false );
	}

	/**
	 * Check if the CCT is already registered.
	 *
	 * @param object $module CCT module instance.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		if ( empty( $module->manager ) || empty( $module->manager->data ) || empty( $module->manager->data->db ) ) {
			return false;
		}

		$data = $module->manager->data;

		$records = $data->db->query(
			'post_types',
			array(
				'slug'   => self::SLUG,
				'status' => 'content-type',
			),
			null,
			false
		);

		return ! empty( $records );
	}

	/**
	 * Get the CCT module instance.
	 *
	 * @return object|null
	 */
	protected static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return null;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return null;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );

		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return null;
		}

		return $module_wrapper->instance;
	}

	/**
	 * Build the registration request.
	 *
	 * @return array
	 */
	protected static function get_registration_request() {
		$label = __( 'AI Usage Logs', 'mcp-ai-wpoos' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Get CCT arguments.
	 *
	 * @param string $label Content type label.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-chart-line',
			'capability'          => 'manage_options',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => false,
			'rest_post_enabled'   => true,
			'rest_delete_enabled' => true,
			'rest_get_access'     => 'manage_options',
			'rest_put_access'     => 'manage_options',
			'rest_post_access'    => 'edit_posts',
			'rest_delete_access'  => 'manage_options',
			'admin_columns'       => array(
				'_ID'          => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'timestamp'    => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'user_id'      => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'provider'     => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'model'        => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'total_tokens' => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
			),
		);
	}

	/**
	 * Get meta fields for usage logs.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		return array(
			array(
				'title'       => __( 'Timestamp', 'mcp-ai-wpoos' ),
				'name'        => 'timestamp',
				'type'        => 'datetime-local',
				'is_required' => true,
			),
			array(
				'title'       => __( 'User ID', 'mcp-ai-wpoos' ),
				'name'        => 'user_id',
				'type'        => 'number',
				'is_required' => true,
			),
			array(
				'title'       => __( 'Assistant ID', 'mcp-ai-wpoos' ),
				'name'        => 'assistant_id',
				'type'        => 'number',
				'is_required' => true,
			),
			array(
				'title'       => __( 'Provider', 'mcp-ai-wpoos' ),
				'name'        => 'provider',
				'type'        => 'text',
				'is_required' => true,
			),
			array(
				'title'       => __( 'Model', 'mcp-ai-wpoos' ),
				'name'        => 'model',
				'type'        => 'text',
				'is_required' => true,
			),
			array(
				'title' => __( 'Prompt Tokens', 'mcp-ai-wpoos' ),
				'name'  => 'prompt_tokens',
				'type'  => 'number',
			),
			array(
				'title' => __( 'Completion Tokens', 'mcp-ai-wpoos' ),
				'name'  => 'completion_tokens',
				'type'  => 'number',
			),
			array(
				'title' => __( 'Total Tokens', 'mcp-ai-wpoos' ),
				'name'  => 'total_tokens',
				'type'  => 'number',
			),
			array(
				'title' => __( 'Finish Reason', 'mcp-ai-wpoos' ),
				'name'  => 'finish_reason',
				'type'  => 'text',
			),
			array(
				'title'       => __( 'Source', 'mcp-ai-wpoos' ),
				'name'        => 'source',
				'type'        => 'text',
				'description' => __( 'embedded or server', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Log embedded LLM usage to JetEngine CCT.
	 *
	 * @param int    $user_id       User ID.
	 * @param int    $assistant_id  Assistant ID.
	 * @param string $model         Model identifier.
	 * @param array  $usage         Usage statistics.
	 * @param string $finish_reason Finish reason.
	 */
	public static function log_embedded_usage( $user_id, $assistant_id, $model, $usage, $finish_reason ) {
		self::create_usage_log(
			$user_id,
			$assistant_id,
			'embedded',
			$model,
			$usage,
			$finish_reason,
			'embedded'
		);
	}

	/**
	 * Log server-side usage to JetEngine CCT.
	 *
	 * @param int    $user_id       User ID.
	 * @param int    $assistant_id  Assistant ID.
	 * @param string $provider      Provider key.
	 * @param string $model         Model identifier.
	 * @param array  $totals        Updated totals (ignored, we log the delta).
	 * @param array  $usage         Usage delta.
	 */
	public static function log_server_usage( $user_id, $assistant_id, $provider, $model, $totals, $usage ) {
		self::create_usage_log(
			$user_id,
			$assistant_id,
			$provider,
			$model,
			$usage,
			'',
			'server'
		);
	}

	/**
	 * Create a usage log entry via JetEngine REST API.
	 *
	 * @param int    $user_id       User ID.
	 * @param int    $assistant_id  Assistant ID.
	 * @param string $provider      Provider key.
	 * @param string $model         Model identifier.
	 * @param array  $usage         Usage statistics.
	 * @param string $finish_reason Finish reason.
	 * @param string $source        Source (embedded/server).
	 */
	protected static function create_usage_log( $user_id, $assistant_id, $provider, $model, $usage, $finish_reason, $source ) {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return;
		}

		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return;
		}

		// Prepare data.
		$data = array(
			'timestamp'         => current_time( 'mysql' ),
			'user_id'           => absint( $user_id ),
			'assistant_id'      => absint( $assistant_id ),
			'provider'          => sanitize_text_field( $provider ),
			'model'             => sanitize_text_field( $model ),
			'prompt_tokens'     => isset( $usage['prompt_tokens'] ) ? absint( $usage['prompt_tokens'] ) : 0,
			'completion_tokens' => isset( $usage['completion_tokens'] ) ? absint( $usage['completion_tokens'] ) : 0,
			'total_tokens'      => isset( $usage['total_tokens'] ) ? absint( $usage['total_tokens'] ) : 0,
			'finish_reason'     => sanitize_text_field( $finish_reason ),
			'source'            => sanitize_text_field( $source ),
		);

		// Create item via JetEngine.
		try {
			$handler->update_item( false, $data );
		} catch ( Exception $e ) {
			// Log error but don't fail.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'error',
					'Failed to create usage log entry',
					array(
						'exception' => $e->getMessage(),
						'data'      => $data,
					)
				);
			}
		}
	}

	/**
	 * Get the item handler for usage logs CCT.
	 *
	 * @return object|null
	 */
	protected static function get_item_handler() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return null;
		}

		if ( empty( $module->manager ) ) {
			return null;
		}

		$instance = $module->manager->get_content_types( self::SLUG );

		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}
}

WP_MCP_AI_JetEngine_Usage_Logs_CCT::bootstrap();
