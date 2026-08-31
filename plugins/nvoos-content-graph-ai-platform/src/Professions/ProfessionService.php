<?php
/**
 * Professions subsystem service.
 *
 * Composition root for the Professions subsystem: registers the admin UI in
 * every mode and owns the runtime wiring (CPT, cache invalidation, global
 * accessor shim) in standalone mode only. Also carries the ported domain
 * logic of the base plugin's WP_MCP_AI_Profession_Service (1:1 behaviour —
 * extraction Wave A).
 *
 * @package NvoosContentGraphAiPlatform
 * @since 1.0.0
 * @since 2.0.0 Owns profession runtime wiring in standalone mode (extraction).
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Professions;

final class ProfessionService {

	/**
	 * Profession repository instance.
	 *
	 * @var ProfessionRepository
	 */
	protected $repository;

	/**
	 * Singleton instance.
	 *
	 * @var ProfessionService|null
	 */
	private static ?self $instance = null;

	/**
	 * Constructor.
	 *
	 * @param ProfessionRepository|null $repository Profession repository. Defaults to a fresh instance.
	 */
	public function __construct( ?ProfessionRepository $repository = null ) {
		$this->repository = $repository ?: new ProfessionRepository();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return ProfessionService
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the Professions subsystem.
	 */
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\Professions\Admin\ProfessionAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Professions\Admin\ProfessionAdmin() )->register();
		}

		// Standalone mode: the base plugin is absent, so this addon owns the
		// profession runtime wiring — mirroring the base professions-init.php
		// (CPT on init priority 5, plus cache invalidation hooks). In
		// monolith mode the base plugin wires its own copy — never twice.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		// Global function surface shims (wp_mcp_ai_get_profession_service,
		// dataset-mapping helpers) — standalone mode only; the base plugin
		// owns these functions in monolith mode and may define them lazily.
		require_once __DIR__ . '/shim-functions.php';

		add_action(
			'init',
			static function (): void {
				new ProfessionCpt();
			},
			5
		);

		// Clear profession cache when professions are saved/deleted.
		add_action(
			'save_post_' . ProfessionCpt::POST_TYPE,
			static function ( $post_id ): void {
				$repository = new ProfessionRepository();
				$repository->clear_cache( $post_id );
			},
			10,
			1
		);

		add_action(
			'delete_post',
			static function ( $post_id ): void {
				$post = get_post( $post_id );
				if ( $post && ProfessionCpt::POST_TYPE === $post->post_type ) {
					$repository = new ProfessionRepository();
					$repository->clear_cache( $post_id );
				}
			},
			10,
			1
		);
	}

	/**
	 * Get all active professions.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of profession data.
	 */
	public function get_all_professions( $args = array() ) {
		$professions = $this->repository->find_all( $args );
		$result      = array();

		foreach ( $professions as $profession_post ) {
			$result[ $profession_post->post_name ] = $this->transform_profession_for_display( $profession_post );
		}

		return $result;
	}

	/**
	 * Get professions by category.
	 *
	 * @param string $category Category slug.
	 * @return array Array of profession data.
	 */
	public function get_professions_by_category( $category ) {
		$professions = $this->repository->find_by_category( $category );
		$result      = array();

		foreach ( $professions as $profession_post ) {
			$result[ $profession_post->post_name ] = $this->transform_profession_for_display( $profession_post );
		}

		return $result;
	}

	/**
	 * Get profession by slug or ID.
	 *
	 * @param string|int $profession Profession slug or ID.
	 * @return array|null Profession data or null if not found.
	 */
	public function get_profession( $profession ) {
		$profession_post = $this->repository->find_one( $profession );

		if ( ! $profession_post ) {
			return null;
		}

		return $this->transform_profession_for_assistant( $profession_post );
	}

	/**
	 * Get multiple professions by slugs or IDs.
	 *
	 * @param array $profession_ids Array of profession slugs or IDs.
	 * @return array Array of profession data indexed by slug.
	 */
	public function get_professions( array $profession_ids ) {
		$professions = $this->repository->find_many( $profession_ids );
		$result      = array();

		foreach ( $professions as $profession_post ) {
			$result[ $profession_post->post_name ] = $this->transform_profession_for_assistant( $profession_post );
		}

		return $result;
	}

	/**
	 * Transform profession post for display (dropdown, list).
	 *
	 * @param \WP_Post $profession_post Profession post object.
	 * @return string Profession display name.
	 */
	protected function transform_profession_for_display( $profession_post ) {
		return $profession_post->post_title;
	}

	/**
	 * Transform profession post for assistant creation.
	 *
	 * @param \WP_Post $profession_post Profession post object.
	 * @return array Profession data for assistant.
	 */
	protected function transform_profession_for_assistant( $profession_post ) {
		return array(
			'id'               => $profession_post->ID,
			'slug'             => $profession_post->post_name,
			'name'             => $profession_post->post_title,
			'description'      => $profession_post->post_content,
			'category'         => get_post_meta( $profession_post->ID, ProfessionCpt::META_CATEGORY, true ),
			'role_description' => get_post_meta( $profession_post->ID, ProfessionCpt::META_ROLE_DESCRIPTION, true ),
			'expertise'        => get_post_meta( $profession_post->ID, ProfessionCpt::META_EXPERTISE, true ),
			'warnings'         => get_post_meta( $profession_post->ID, ProfessionCpt::META_WARNINGS, true ),
			'knowledge_base'   => get_post_meta( $profession_post->ID, ProfessionCpt::META_KNOWLEDGE_BASE, true ),
			'default_tools'    => get_post_meta( $profession_post->ID, ProfessionCpt::META_DEFAULT_TOOLS, true ),
		);
	}

	/**
	 * Get professions formatted for dropdown/select.
	 *
	 * @param array $args Optional query arguments.
	 * @return array Array of slug => label pairs.
	 */
	public function get_professions_for_dropdown( $args = array() ) {
		return $this->get_all_professions( $args );
	}

	/**
	 * Merge profession data for multiple professions.
	 * Used when creating assistants with multiple professions.
	 *
	 * @param array $profession_slugs Array of profession slugs.
	 * @return array Merged profession data.
	 */
	public function merge_profession_data( array $profession_slugs ) {
		$professions = $this->get_professions( $profession_slugs );

		$merged = array(
			'names'     => array(),
			'roles'     => array(),
			'expertise' => array(),
			'warnings'  => array(),
			'knowledge' => array(),
			'tools'     => array(),
		);

		foreach ( $professions as $profession ) {
			$merged['names'][] = $profession['name'];

			if ( ! empty( $profession['role_description'] ) ) {
				$merged['roles'][] = $profession['role_description'];
			}

			if ( ! empty( $profession['expertise'] ) && is_array( $profession['expertise'] ) ) {
				$merged['expertise'] = array_merge( $merged['expertise'], $profession['expertise'] );
			}

			if ( ! empty( $profession['warnings'] ) && is_array( $profession['warnings'] ) ) {
				$merged['warnings'] = array_merge( $merged['warnings'], $profession['warnings'] );
			}

			if ( ! empty( $profession['knowledge_base'] ) ) {
				$merged['knowledge'][] = $profession['knowledge_base'];
			}

			if ( ! empty( $profession['default_tools'] ) && is_array( $profession['default_tools'] ) ) {
				$merged['tools'] = array_merge( $merged['tools'], $profession['default_tools'] );
			}
		}

		// Deduplicate arrays.
		$merged['expertise'] = array_values( array_unique( $merged['expertise'] ) );
		$merged['warnings']  = array_values( array_unique( $merged['warnings'] ) );
		$merged['tools']     = array_values( array_unique( $merged['tools'] ) );

		return $merged;
	}

	/**
	 * Check if profession exists.
	 *
	 * @param string|int $profession Profession slug or ID.
	 * @return bool True if exists, false otherwise.
	 */
	public function profession_exists( $profession ) {
		return null !== $this->repository->find_one( $profession );
	}

	/**
	 * Get profession count by category.
	 *
	 * @return array Category => count pairs.
	 */
	public function get_category_counts() {
		return $this->repository->get_category_counts();
	}

	/**
	 * Get profession configured for specific agent role.
	 *
	 * Retrieves profession data including orchestration configuration.
	 *
	 * @since 1.9.0
	 *
	 * @param string $profession_slug Profession slug.
	 * @param string $agent_role      Optional. Filter by role (planner, executor, critic, specialist).
	 * @return array|\WP_Error Profession with orchestration config, or error if not found or wrong role.
	 */
	public function get_profession_for_agent_role( $profession_slug, $agent_role = '' ) {
		$profession = $this->get_profession( $profession_slug );

		if ( is_wp_error( $profession ) ) {
			return $profession;
		}

		// Add orchestration configuration.
		$orchestration               = $this->get_orchestration_config( $profession['id'] );
		$profession['orchestration'] = $orchestration;

		// If role filter specified, validate it matches.
		if ( ! empty( $agent_role ) && $orchestration['agent_role'] !== $agent_role ) {
			return new \WP_Error(
				'wp_mcp_ai_wrong_agent_role',
				sprintf(
					/* translators: 1: profession slug, 2: expected role, 3: actual role */
					__( 'Profession "%1$s" has role "%3$s", expected "%2$s".', 'nvoos-content-graph-ai-platform' ),
					$profession_slug,
					$agent_role,
					$orchestration['agent_role']
				)
			);
		}

		return $profession;
	}

	/**
	 * Get all professions by agent role.
	 *
	 * @since 1.9.0
	 *
	 * @param string $agent_role Role filter (planner, executor, critic, specialist, generalist).
	 * @return array Array of professions with orchestration config.
	 */
	public function get_professions_by_agent_role( $agent_role ) {
		$args = array(
			'post_type'              => ProfessionCpt::POST_TYPE,
			'posts_per_page'         => -1,
			'post_status'            => 'publish',
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query required to filter profession CPT by configuration meta; no alternative index-based query available.
				array(
					'key'   => ProfessionCpt::META_AGENT_ROLE,
					'value' => sanitize_key( $agent_role ),
				),
			),
			'update_post_meta_cache' => false,
		);

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return array();
		}

		$professions = array();
		foreach ( $query->posts as $post ) {
			// Use the assistant transformer: this method returns profession
			// data arrays (callers treat each entry as an array), whereas
			// transform_profession_for_display() returns a display-name
			// string and would fatal with "Cannot access offset of type
			// string on string" on PHP 8.
			$profession                      = $this->transform_profession_for_assistant( $post );
			$profession['orchestration']     = $this->get_orchestration_config( $post->ID );
			$professions[ $post->post_name ] = $profession;
		}

		wp_reset_postdata();

		return $professions;
	}

	/**
	 * Get orchestration configuration for profession.
	 *
	 * @since 1.9.0
	 *
	 * @param int $profession_id Profession post ID.
	 * @return array Orchestration config with all fields.
	 */
	public function get_orchestration_config( $profession_id ) {
		return array(
			'agent_role'            => get_post_meta( $profession_id, ProfessionCpt::META_AGENT_ROLE, true ) ? get_post_meta( $profession_id, ProfessionCpt::META_AGENT_ROLE, true ) : 'generalist',
			'task_patterns'         => json_decode( get_post_meta( $profession_id, ProfessionCpt::META_TASK_PATTERNS, true ) ? get_post_meta( $profession_id, ProfessionCpt::META_TASK_PATTERNS, true ) : '{}', true ),
			'decision_criteria'     => json_decode( get_post_meta( $profession_id, ProfessionCpt::META_DECISION_CRITERIA, true ) ? get_post_meta( $profession_id, ProfessionCpt::META_DECISION_CRITERIA, true ) : '{}', true ),
			'orchestration_rules'   => json_decode( get_post_meta( $profession_id, ProfessionCpt::META_ORCHESTRATION_RULES, true ) ? get_post_meta( $profession_id, ProfessionCpt::META_ORCHESTRATION_RULES, true ) : '{}', true ),
			'quality_metrics'       => json_decode( get_post_meta( $profession_id, ProfessionCpt::META_QUALITY_METRICS, true ) ? get_post_meta( $profession_id, ProfessionCpt::META_QUALITY_METRICS, true ) : '{}', true ),
			'tool_execution_order'  => json_decode( get_post_meta( $profession_id, ProfessionCpt::META_TOOL_EXECUTION_ORDER, true ) ? get_post_meta( $profession_id, ProfessionCpt::META_TOOL_EXECUTION_ORDER, true ) : '[]', true ),
			'confidence_thresholds' => json_decode( get_post_meta( $profession_id, ProfessionCpt::META_CONFIDENCE_THRESHOLDS, true ) ? get_post_meta( $profession_id, ProfessionCpt::META_CONFIDENCE_THRESHOLDS, true ) : '{}', true ),
		);
	}

	/**
	 * Update orchestration configuration for profession.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $profession_id Profession post ID.
	 * @param array $config        Orchestration config to update.
	 * @return bool True on success, false on failure.
	 */
	public function update_orchestration_config( $profession_id, $config ) {
		$updated = true;

		if ( isset( $config['agent_role'] ) ) {
			$updated = $updated && update_post_meta( $profession_id, ProfessionCpt::META_AGENT_ROLE, sanitize_key( $config['agent_role'] ) );
		}

		if ( isset( $config['task_patterns'] ) ) {
			$json    = is_array( $config['task_patterns'] ) ? wp_json_encode( $config['task_patterns'] ) : $config['task_patterns'];
			$updated = $updated && update_post_meta( $profession_id, ProfessionCpt::META_TASK_PATTERNS, $json );
		}

		if ( isset( $config['decision_criteria'] ) ) {
			$json    = is_array( $config['decision_criteria'] ) ? wp_json_encode( $config['decision_criteria'] ) : $config['decision_criteria'];
			$updated = $updated && update_post_meta( $profession_id, ProfessionCpt::META_DECISION_CRITERIA, $json );
		}

		if ( isset( $config['orchestration_rules'] ) ) {
			$json    = is_array( $config['orchestration_rules'] ) ? wp_json_encode( $config['orchestration_rules'] ) : $config['orchestration_rules'];
			$updated = $updated && update_post_meta( $profession_id, ProfessionCpt::META_ORCHESTRATION_RULES, $json );
		}

		if ( isset( $config['quality_metrics'] ) ) {
			$json    = is_array( $config['quality_metrics'] ) ? wp_json_encode( $config['quality_metrics'] ) : $config['quality_metrics'];
			$updated = $updated && update_post_meta( $profession_id, ProfessionCpt::META_QUALITY_METRICS, $json );
		}

		if ( isset( $config['tool_execution_order'] ) ) {
			$json    = is_array( $config['tool_execution_order'] ) ? wp_json_encode( $config['tool_execution_order'] ) : $config['tool_execution_order'];
			$updated = $updated && update_post_meta( $profession_id, ProfessionCpt::META_TOOL_EXECUTION_ORDER, $json );
		}

		if ( isset( $config['confidence_thresholds'] ) ) {
			$json    = is_array( $config['confidence_thresholds'] ) ? wp_json_encode( $config['confidence_thresholds'] ) : $config['confidence_thresholds'];
			$updated = $updated && update_post_meta( $profession_id, ProfessionCpt::META_CONFIDENCE_THRESHOLDS, $json );
		}

		return $updated;
	}

	/**
	 * Transform profession for orchestration (includes orchestration metadata).
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $profession Profession post object, ID, or slug.
	 * @return array Profession data with orchestration config.
	 */
	public function transform_profession_for_orchestration( $profession ) {
		$base = $this->transform_profession_for_assistant( $profession );

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$orchestration = $this->get_orchestration_config( $base['id'] );

		return array_merge( $base, array( 'orchestration' => $orchestration ) );
	}

	/**
	 * Prevent cloning (singleton).
	 */
	private function __clone() {}
}
