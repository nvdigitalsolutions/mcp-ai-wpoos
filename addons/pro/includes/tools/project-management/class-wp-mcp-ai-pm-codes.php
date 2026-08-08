<?php
/**
 * Project Management Toolkit Standards Registry
 *
 * Lightweight registry of PM-standardised codesets shared across all
 * PM sub-modules.  Mirrors WP_MCP_AI_CRM_Codes in the CRM toolkit:
 * default in-memory catalogue, filter-extensible so partners can
 * register custom estimation systems, risk categories, methodology
 * frameworks, and activity types.
 *
 * The registry ships small "seed" code packs — enough to validate the
 * system and back smoke tests. Production deployments register full
 * catalogues via the wp_mcp_ai_pm_code_packs filter.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PM standards registry.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_PM_Codes {

	/**
	 * Valid project statuses.
	 *
	 * @var string[]
	 */
	const PROJECT_STATUSES = array( 'idea', 'planning', 'active', 'at-risk', 'on-hold', 'completed', 'cancelled', 'archived' );

	/**
	 * Valid task statuses.
	 *
	 * @var string[]
	 */
	const TASK_STATUSES = array( 'backlog', 'todo', 'in-progress', 'review', 'blocked', 'completed', 'cancelled' );

	/**
	 * Valid task priorities.
	 *
	 * @var string[]
	 */
	const TASK_PRIORITIES = array( 'lowest', 'low', 'medium', 'high', 'highest', 'critical' );

	/**
	 * Estimation method identifiers.
	 *
	 * @var string[]
	 */
	const ESTIMATION_METHODS = array( 'story_points', 'hours', 't_shirt' );

	/**
	 * Risk level identifiers.
	 *
	 * @var string[]
	 */
	const RISK_LEVELS = array( 'low', 'medium', 'high', 'critical' );

	/**
	 * Activity / audit event types.
	 *
	 * @var string[]
	 */
	const ACTIVITY_TYPES = array(
		'status_change',
		'assignment',
		'comment',
		'dependency_added',
		'dependency_removed',
		'due_date_change',
		'priority_change',
		'blocker_raised',
		'blocker_resolved',
		'task_created',
		'task_completed',
		'project_created',
		'milestone_reached',
	);

	/**
	 * Cached, filtered code-pack catalogue.
	 *
	 * @var array<string,array>
	 */
	private static $cache = array();

	/**
	 * Default in-memory code-pack seed catalogue.
	 *
	 * Each pack is keyed by `<system>:<id>` with:
	 *  - system : Canonical system slug (e.g. 'story_points', 'scrum').
	 *  - title  : Human-readable title.
	 *  - codes  : Map of code => display name.
	 *
	 * @return array<string,array>
	 */
	public static function default_packs() {
		return array(
			// --------------------------------------------------------
			// Estimation systems
			// --------------------------------------------------------
			'estimation:story_points' => array(
				'system' => 'story_points',
				'title'  => 'Story Points (Fibonacci)',
				'codes'  => array(
					'1'  => '1 — Trivial',
					'2'  => '2 — Small',
					'3'  => '3 — Medium',
					'5'  => '5 — Large',
					'8'  => '8 — XL',
					'13' => '13 — Too Large (split)',
					'21' => '21 — Epic (split)',
				),
			),
			'estimation:t_shirt'      => array(
				'system' => 't_shirt',
				'title'  => 'T-Shirt Sizing',
				'codes'  => array(
					'xs' => 'XS — Hours',
					's'  => 'S — 1-2 days',
					'm'  => 'M — 3-5 days',
					'l'  => 'L — 1-2 weeks',
					'xl' => 'XL — 2-4 weeks',
				),
			),

			// --------------------------------------------------------
			// Methodology frameworks
			// --------------------------------------------------------
			'methodology:scrum'       => array(
				'system' => 'scrum',
				'title'  => 'Scrum Ceremonies',
				'codes'  => array(
					'sprint_planning'  => 'Sprint Planning — Select backlog items for sprint.',
					'daily_standup'    => 'Daily Standup — 15-min sync.',
					'sprint_review'    => 'Sprint Review — Demo completed work.',
					'sprint_retro'     => 'Sprint Retrospective — Process improvement.',
					'backlog_grooming' => 'Backlog Refinement — Keep backlog healthy.',
				),
			),

			// --------------------------------------------------------
			// Risk categories
			// --------------------------------------------------------
			'risk:standard'           => array(
				'system' => 'risk_category',
				'title'  => 'Standard Risk Categories',
				'codes'  => array(
					'schedule'   => 'Schedule — Timeline slippage.',
					'scope'      => 'Scope — Scope creep or gold-plating.',
					'resource'   => 'Resource — Staffing, skills, availability.',
					'technical'  => 'Technical — Architecture, debt, complexity.',
					'dependency' => 'Dependency — External or cross-team blockers.',
					'budget'     => 'Budget — Cost overrun.',
					'quality'    => 'Quality — Defects, tech debt.',
				),
			),
		);
	}

	/**
	 * Get a single code pack by key.
	 *
	 * @param string $key Code pack key, e.g. 'estimation:story_points'.
	 * @return array|null Pack definition or null if not found.
	 */
	public static function get_pack( $key ) {
		$packs = self::get_all_packs();
		return isset( $packs[ $key ] ) ? $packs[ $key ] : null;
	}

	/**
	 * Get all registered code packs (filterable).
	 *
	 * @return array<string,array>
	 */
	public static function get_all_packs() {
		if ( ! empty( self::$cache ) ) {
			return self::$cache;
		}

		$packs = self::default_packs();

		/**
		 * Filter: register additional PM code packs.
		 *
		 * E.g. custom estimation systems, methodology frameworks,
		 * risk categories.
		 *
		 * @param array $packs Default packs.
		 */
		$filtered    = apply_filters( 'wp_mcp_ai_pm_code_packs', $packs );
		self::$cache = is_array( $filtered ) ? $filtered : $packs;

		return self::$cache;
	}

	/**
	 * Check whether a project status is valid.
	 *
	 * @param string $status Project status slug.
	 * @return bool
	 */
	public static function is_valid_project_status( $status ) {
		return in_array( sanitize_key( $status ), self::PROJECT_STATUSES, true );
	}

	/**
	 * Check whether a task status is valid.
	 *
	 * @param string $status Task status slug.
	 * @return bool
	 */
	public static function is_valid_task_status( $status ) {
		return in_array( sanitize_key( $status ), self::TASK_STATUSES, true );
	}

	/**
	 * Check whether a task priority is valid.
	 *
	 * @param string $priority Priority slug.
	 * @return bool
	 */
	public static function is_valid_task_priority( $priority ) {
		return in_array( sanitize_key( $priority ), self::TASK_PRIORITIES, true );
	}

	/**
	 * Check whether a risk level is valid.
	 *
	 * @param string $level Risk level slug.
	 * @return bool
	 */
	public static function is_valid_risk_level( $level ) {
		return in_array( sanitize_key( $level ), self::RISK_LEVELS, true );
	}
}
