<?php
/**
 * Team Custom Post Type.
 *
 * Handles registration and management of the team CPT.
 * Teams group multiple professionals together for deployment as a set of assistants.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Teams;

use NvoosContentGraphAiPlatform\Schema\Sanitize;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the team custom post type and manages its WordPress integration.
 */
class TeamCpt {
	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'mcp_ai_team';

	/**
	 * Meta key for team members (array of profession post IDs).
	 */
	const META_TEAM_MEMBERS = '_wp_mcp_ai_team_members';

	/**
	 * Meta key for team description.
	 */
	const META_TEAM_DESCRIPTION = '_wp_mcp_ai_team_description';

	/**
	 * Meta key for default provider for all team members.
	 */
	const META_DEFAULT_PROVIDER = '_wp_mcp_ai_team_default_provider';

	/**
	 * Meta key for default model for all team members.
	 */
	const META_DEFAULT_MODEL = '_wp_mcp_ai_team_default_model';

	/**
	 * Meta key for default temperature for all team members.
	 */
	const META_DEFAULT_TEMPERATURE = '_wp_mcp_ai_team_default_temperature';

	/**
	 * Meta key for orchestration mode (single, sequential, parallel, swarm).
	 *
	 * @since 1.9.0
	 */
	const META_ORCHESTRATION_MODE = '_wp_mcp_ai_team_orchestration_mode';

	/**
	 * Meta key for workflow template (JSON: workflow steps and dependencies).
	 *
	 * @since 1.9.0
	 */
	const META_WORKFLOW_TEMPLATE = '_wp_mcp_ai_team_workflow_template';

	/**
	 * Meta key for result aggregation strategy (consensus, weighted, hierarchical, first, best).
	 *
	 * @since 1.9.0
	 */
	const META_RESULT_AGGREGATION_STRATEGY = '_wp_mcp_ai_team_result_aggregation';

	/**
	 * Meta key for driver assistant ID (the assistant that orchestrates the team).
	 *
	 * @since 1.9.1
	 */
	const META_DRIVER_ASSISTANT = '_wp_mcp_ai_team_driver_assistant';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register hooks.
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_columns' ), 10, 2 );
	}

	/**
	 * Register the team custom post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Teams', 'Post type general name', 'nvoos-content-graph-ai-platform' ),
			'singular_name'      => _x( 'Team', 'Post type singular name', 'nvoos-content-graph-ai-platform' ),
			'menu_name'          => _x( 'Teams', 'Admin Menu text', 'nvoos-content-graph-ai-platform' ),
			'name_admin_bar'     => _x( 'Team', 'Add New on Toolbar', 'nvoos-content-graph-ai-platform' ),
			'add_new'            => __( 'Add New', 'nvoos-content-graph-ai-platform' ),
			'add_new_item'       => __( 'Add New Team', 'nvoos-content-graph-ai-platform' ),
			'new_item'           => __( 'New Team', 'nvoos-content-graph-ai-platform' ),
			'edit_item'          => __( 'Edit Team', 'nvoos-content-graph-ai-platform' ),
			'view_item'          => __( 'View Team', 'nvoos-content-graph-ai-platform' ),
			'all_items'          => __( 'Teams', 'nvoos-content-graph-ai-platform' ),
			'search_items'       => __( 'Search Teams', 'nvoos-content-graph-ai-platform' ),
			'not_found'          => __( 'No teams found.', 'nvoos-content-graph-ai-platform' ),
			'not_found_in_trash' => __( 'No teams found in Trash.', 'nvoos-content-graph-ai-platform' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-groups',
			'supports'           => array( 'title', 'editor' ),
			'show_in_rest'       => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register meta fields for the team post type.
	 */
	public function register_meta() {
		// Team members.
		register_post_meta(
			self::POST_TYPE,
			self::META_TEAM_MEMBERS,
			array(
				'type'              => 'array',
				'description'       => __( 'Team member profession IDs', 'nvoos-content-graph-ai-platform' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_team_members' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Team description.
		register_post_meta(
			self::POST_TYPE,
			self::META_TEAM_DESCRIPTION,
			array(
				'type'              => 'string',
				'description'       => __( 'Team description', 'nvoos-content-graph-ai-platform' ),
				'single'            => true,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Default provider.
		register_post_meta(
			self::POST_TYPE,
			self::META_DEFAULT_PROVIDER,
			array(
				'type'              => 'string',
				'description'       => __( 'Default AI provider for team members', 'nvoos-content-graph-ai-platform' ),
				'single'            => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Default model.
		register_post_meta(
			self::POST_TYPE,
			self::META_DEFAULT_MODEL,
			array(
				'type'              => 'string',
				'description'       => __( 'Default model for team members', 'nvoos-content-graph-ai-platform' ),
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Default temperature.
		register_post_meta(
			self::POST_TYPE,
			self::META_DEFAULT_TEMPERATURE,
			array(
				'type'              => 'number',
				'description'       => __( 'Default temperature for team members', 'nvoos-content-graph-ai-platform' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_temperature' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Orchestration mode.
		register_post_meta(
			self::POST_TYPE,
			self::META_ORCHESTRATION_MODE,
			array(
				'type'              => 'string',
				'description'       => __( 'Multi-agent orchestration mode', 'nvoos-content-graph-ai-platform' ),
				'single'            => true,
				'default'           => 'single',
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Workflow template.
		register_post_meta(
			self::POST_TYPE,
			self::META_WORKFLOW_TEMPLATE,
			array(
				'type'              => 'string',
				'description'       => __( 'Workflow template for multi-agent coordination', 'nvoos-content-graph-ai-platform' ),
				'single'            => true,
				'default'           => '{}',
				'sanitize_callback' => array( $this, 'sanitize_json_field' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Result aggregation strategy.
		register_post_meta(
			self::POST_TYPE,
			self::META_RESULT_AGGREGATION_STRATEGY,
			array(
				'type'              => 'string',
				'description'       => __( 'Strategy for aggregating results from multiple agents', 'nvoos-content-graph-ai-platform' ),
				'single'            => true,
				'default'           => 'consensus',
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Driver assistant.
		register_post_meta(
			self::POST_TYPE,
			self::META_DRIVER_ASSISTANT,
			array(
				'type'              => 'integer',
				'description'       => __( 'The assistant that orchestrates and drives the team', 'nvoos-content-graph-ai-platform' ),
				'single'            => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize JSON field.
	 *
	 * @param string $value Raw JSON value.
	 * @return string Validated JSON string.
	 * @since 1.9.0
	 */
	public function sanitize_json_field( $value ) {
		// Decode to validate JSON syntax.
		$decoded = json_decode( $value, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Invalid JSON, return empty object.
			return '{}';
		}
		// Re-encode for consistency.
		return wp_json_encode( $decoded );
	}

	/**
	 * Sanitize team members meta field.
	 *
	 * @param mixed $members Raw members value.
	 * @return array
	 */
	public function sanitize_team_members( $members ) {
		if ( ! is_array( $members ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $members as $member_id ) {
			$member_id = absint( $member_id );
			if ( $member_id && 'mcp_ai_profession' === get_post_type( $member_id ) ) {
				$sanitized[] = $member_id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitize temperature meta field.
	 *
	 * @param mixed $temperature Raw temperature value.
	 * @return float|null
	 */
	public function sanitize_temperature( $temperature ) {
		if ( is_string( $temperature ) ) {
			$temperature = trim( $temperature );
		}

		if ( '' === $temperature || null === $temperature ) {
			return null;
		}

		if ( is_numeric( $temperature ) ) {
			$temperature = floatval( $temperature );
			if ( $temperature < 0 || $temperature > 2 ) {
				return null;
			}
			return $temperature;
		}

		return null;
	}

	/**
	 * Disable the block editor for the team post type.
	 *
	 * @param bool   $use_block_editor Whether the block editor should be used.
	 * @param string $post_type        Current post type being edited.
	 * @return bool
	 */
	public function disable_block_editor( $use_block_editor, $post_type ) {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}
		return $use_block_editor;
	}

	/**
	 * Register meta boxes for the team CPT.
	 */
	public function register_meta_boxes() {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		add_meta_box(
			'wp-mcp-ai-team-members',
			__( 'Team Members', 'nvoos-content-graph-ai-platform' ),
			array( $this, 'render_team_members_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wp-mcp-ai-team-orchestration',
			__( 'Multi-Agent Orchestration', 'nvoos-content-graph-ai-platform' ),
			array( $this, 'render_orchestration_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wp-mcp-ai-team-driver-assistant',
			__( 'Driver Assistant', 'nvoos-content-graph-ai-platform' ),
			array( $this, 'render_driver_assistant_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'wp-mcp-ai-team-defaults',
			__( 'Default Settings', 'nvoos-content-graph-ai-platform' ),
			array( $this, 'render_defaults_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the team members meta box.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_team_members_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_team_members_meta', 'wp_mcp_ai_team_members_meta_nonce' );

		$team_members = get_post_meta( $post->ID, self::META_TEAM_MEMBERS, true );
		if ( ! is_array( $team_members ) ) {
			$team_members = array();
		}

		// Get all available professions.
		$professions = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		?>
		<div class="wp-mcp-ai-team-members">
			<p class="description">
				<?php esc_html_e( 'Select the professionals that make up this team. When you deploy this team, an assistant will be created for each selected professional.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<?php if ( empty( $professions ) ) : ?>
				<p class="notice notice-warning inline">
					<?php
					printf(
						/* translators: %s: URL to create profession */
						esc_html__( 'No professions found. Please %s first.', 'nvoos-content-graph-ai-platform' ),
						'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ) . '">' . esc_html__( 'create a profession', 'nvoos-content-graph-ai-platform' ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<div class="wp-mcp-ai-team-members-list" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
					<?php foreach ( $professions as $profession ) : ?>
						<label style="display: block; padding: 5px 0;">
							<input
								type="checkbox"
								name="wp_mcp_ai_team_members[]"
								value="<?php echo esc_attr( $profession->ID ); ?>"
								<?php checked( in_array( (int) $profession->ID, $team_members, true ) ); ?>
							/>
							<strong><?php echo esc_html( $profession->post_title ); ?></strong>
							<?php if ( $profession->post_excerpt ) : ?>
								<span class="description"> - <?php echo esc_html( wp_trim_words( $profession->post_excerpt, 15 ) ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="description" style="margin-top: 10px;">
					<?php
					printf(
						/* translators: %d: number of selected members */
						esc_html( _n( '%d professional selected', '%d professionals selected', count( $team_members ), 'nvoos-content-graph-ai-platform' ) ),
						absint( count( $team_members ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the multi-agent orchestration meta box.
	 *
	 * @param \WP_Post $post Post object.
	 * @since 1.9.0
	 */
	public function render_orchestration_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_team_orchestration_meta', 'wp_mcp_ai_team_orchestration_meta_nonce' );

		$orchestration_mode   = get_post_meta( $post->ID, self::META_ORCHESTRATION_MODE, true ) ? get_post_meta( $post->ID, self::META_ORCHESTRATION_MODE, true ) : 'single';
		$workflow_template    = get_post_meta( $post->ID, self::META_WORKFLOW_TEMPLATE, true ) ? get_post_meta( $post->ID, self::META_WORKFLOW_TEMPLATE, true ) : '{}';
		$aggregation_strategy = get_post_meta( $post->ID, self::META_RESULT_AGGREGATION_STRATEGY, true ) ? get_post_meta( $post->ID, self::META_RESULT_AGGREGATION_STRATEGY, true ) : 'consensus';

		// Format JSON for display.
		$decoded_workflow = json_decode( $workflow_template, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			$workflow_template = wp_json_encode( $decoded_workflow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		?>
		<div class="wp-mcp-ai-team-orchestration">
			<p class="description" style="margin-bottom: 20px;">
				<?php
				esc_html_e(
					'Configure how team members coordinate in multi-agent workflows. Orchestration mode determines execution pattern (single, sequential, parallel, or swarm). Result aggregation defines how outputs from multiple agents are combined.',
					'nvoos-content-graph-ai-platform'
				);
				?>
			</p>

			<?php
				wp_add_inline_style(
					'wp-mcp-ai-team-cpt',
					'.wp-mcp-ai-team-orchestration-field{margin-bottom:20px;}'
					. '.wp-mcp-ai-team-orchestration-field label{display:block;font-weight:600;margin-bottom:8px;}'
					. '.wp-mcp-ai-team-orchestration-field textarea{width:100%;font-family:\'Courier New\',Courier,monospace;font-size:13px;}'
					. '.wp-mcp-ai-team-orchestration-field .description{margin-top:5px;font-style:italic;}'
				);
			?>

			<!-- Orchestration Mode -->
			<div class="wp-mcp-ai-team-orchestration-field">
				<label for="wp_mcp_ai_orchestration_mode">
					<?php esc_html_e( 'Orchestration Mode', 'nvoos-content-graph-ai-platform' ); ?>
					<span class="required" style="color: #dc3232;">*</span>
				</label>
				<select 
					name="wp_mcp_ai_orchestration_mode" 
					id="wp_mcp_ai_orchestration_mode" 
					class="widefat"
					style="max-width: 400px;"
				>
					<option value="single" <?php selected( $orchestration_mode, 'single' ); ?>>
						<?php esc_html_e( 'Single - One agent handles entire task', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="sequential" <?php selected( $orchestration_mode, 'sequential' ); ?>>
						<?php esc_html_e( 'Sequential - Agents execute in order (pipeline)', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="parallel" <?php selected( $orchestration_mode, 'parallel' ); ?>>
						<?php esc_html_e( 'Parallel - Agents execute simultaneously', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="swarm" <?php selected( $orchestration_mode, 'swarm' ); ?>>
						<?php esc_html_e( 'Swarm - Redundant agents for consensus/validation', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Execution pattern for team members. Sequential chains outputs (A→B→C), parallel runs concurrently, swarm uses redundancy for validation.', 'nvoos-content-graph-ai-platform' ); ?>
				</p>
			</div>

			<!-- Workflow Template -->
			<div class="wp-mcp-ai-team-orchestration-field">
				<label for="wp_mcp_ai_workflow_template">
					<?php esc_html_e( 'Workflow Template (JSON)', 'nvoos-content-graph-ai-platform' ); ?>
				</label>
				<textarea 
					name="wp_mcp_ai_workflow_template" 
					id="wp_mcp_ai_workflow_template" 
					rows="10"
					placeholder='{"workflow_name": "research_pipeline", "steps": [{"step_id": "1", "agent_role": "planner"}, {"step_id": "2", "agent_role": "executor", "depends_on": "1"}]}'
				><?php echo esc_textarea( $workflow_template ); ?></textarea>
				<p class="description">
					<?php
					esc_html_e(
						'Define workflow steps, agent role assignments, and dependencies. Example: {"workflow_name": "research", "steps": [{"step_id": "1", "agent_role": "planner", "action": "decompose"}, {"step_id": "2", "agent_role": "executor", "depends_on": "1"}]}',
						'nvoos-content-graph-ai-platform'
					);
					?>
				</p>
			</div>

			<!-- Result Aggregation Strategy -->
			<div class="wp-mcp-ai-team-orchestration-field">
				<label for="wp_mcp_ai_result_aggregation">
					<?php esc_html_e( 'Result Aggregation Strategy', 'nvoos-content-graph-ai-platform' ); ?>
					<span class="required" style="color: #dc3232;">*</span>
				</label>
				<select 
					name="wp_mcp_ai_result_aggregation" 
					id="wp_mcp_ai_result_aggregation" 
					class="widefat"
					style="max-width: 400px;"
				>
					<option value="consensus" <?php selected( $aggregation_strategy, 'consensus' ); ?>>
						<?php esc_html_e( 'Consensus - Majority agreement from all agents', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="weighted" <?php selected( $aggregation_strategy, 'weighted' ); ?>>
						<?php esc_html_e( 'Weighted - Agents weighted by confidence scores', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="hierarchical" <?php selected( $aggregation_strategy, 'hierarchical' ); ?>>
						<?php esc_html_e( 'Hierarchical - Priority order (planner > specialist > executor)', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="first" <?php selected( $aggregation_strategy, 'first' ); ?>>
						<?php esc_html_e( 'First - Use first successful result', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="best" <?php selected( $aggregation_strategy, 'best' ); ?>>
						<?php esc_html_e( 'Best - Highest confidence score wins', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
				</select>
				<p class="description">
					<?php esc_html_e( 'How to combine outputs from multiple agents. Consensus requires agreement, weighted uses confidence scores, hierarchical respects role priority.', 'nvoos-content-graph-ai-platform' ); ?>
				</p>
			</div>

			<p class="description" style="margin-top: 20px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
				<strong><?php esc_html_e( 'Note:', 'nvoos-content-graph-ai-platform' ); ?></strong>
				<?php
				esc_html_e(
					'These settings enable DeepSeek V4 multi-agent orchestration. Team members should have assigned agent roles (planner/executor/critic/specialist) via the Profession CPT. Use create_agent_team tool to deploy teams with orchestration.',
					'nvoos-content-graph-ai-platform'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the driver assistant meta box.
	 *
	 * @param \WP_Post $post Post object.
	 * @since 1.9.1
	 */
	public function render_driver_assistant_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_team_driver_assistant_meta', 'wp_mcp_ai_team_driver_assistant_meta_nonce' );

		$driver_assistant_id = get_post_meta( $post->ID, self::META_DRIVER_ASSISTANT, true );

		// Get all published assistants.
		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		?>
		<div class="wp-mcp-ai-driver-assistant">
			<p class="description">
				<?php esc_html_e( 'Optional: Associate an assistant ID for tracking and logging team coordination activities.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<?php if ( empty( $assistants ) ) : ?>
				<p class="notice notice-warning inline" style="margin: 10px 0;">
					<?php
					printf(
						/* translators: %s: URL to create assistant */
						esc_html__( 'No assistants found. Please %s first.', 'nvoos-content-graph-ai-platform' ),
						'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) ) . '">' . esc_html__( 'create an assistant', 'nvoos-content-graph-ai-platform' ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<p>
					<label for="wp-mcp-ai-driver-assistant">
						<strong><?php esc_html_e( 'Select Assistant', 'nvoos-content-graph-ai-platform' ); ?></strong>
					</label>
				</p>
				<select name="wp_mcp_ai_driver_assistant" id="wp-mcp-ai-driver-assistant" class="widefat" style="margin-bottom: 10px;">
					<option value=""><?php esc_html_e( '-- Select Driver Assistant --', 'nvoos-content-graph-ai-platform' ); ?></option>
					<?php foreach ( $assistants as $assistant ) : ?>
						<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $driver_assistant_id, $assistant->ID ); ?>>
							<?php echo esc_html( $assistant->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				
				<?php if ( $driver_assistant_id ) : ?>
					<p style="margin-top: 10px;">
						<a href="<?php echo esc_url( get_edit_post_link( $driver_assistant_id ) ); ?>" class="button button-small">
							<?php esc_html_e( 'Edit Driver Assistant', 'nvoos-content-graph-ai-platform' ); ?>
						</a>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the default settings meta box.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_defaults_meta_box( $post ) {
		// Enqueue model selector JavaScript.
		// Script is registered globally in WP_MCP_AI_Admin_Scripts with localization.
		// We just need to enqueue it here for this metabox.
		wp_enqueue_script( 'wp-mcp-ai-model-selector' );

		wp_nonce_field( 'wp_mcp_ai_team_defaults_meta', 'wp_mcp_ai_team_defaults_meta_nonce' );

		$default_provider    = get_post_meta( $post->ID, self::META_DEFAULT_PROVIDER, true );
		$default_model       = get_post_meta( $post->ID, self::META_DEFAULT_MODEL, true );
		$default_temperature = get_post_meta( $post->ID, self::META_DEFAULT_TEMPERATURE, true );

		// Load model service if available (base plugin, monolith mode).
		// Standalone mode has no model service — the model select degrades
		// to the saved value / empty list instead of fataling.
		$models = array();
		if ( ! empty( $default_provider ) && class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			$model_service = new \WP_MCP_AI_Model_Service();
			$models        = $model_service->get_models_for_provider( $default_provider );
		}

		?>
		<div class="wp-mcp-ai-team-defaults">
			<p class="description">
				<?php esc_html_e( 'These settings will be applied to all assistants created from this team.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<p>
				<label for="wp-mcp-ai-default-provider">
					<strong><?php esc_html_e( 'AI Provider', 'nvoos-content-graph-ai-platform' ); ?></strong>
				</label><br>
				<select name="wp_mcp_ai_default_provider" id="wp-mcp-ai-default-provider" class="widefat wp-mcp-ai-provider-select" data-model-target="#wp-mcp-ai-default-model">
					<option value=""><?php esc_html_e( '-- Use Professional Default --', 'nvoos-content-graph-ai-platform' ); ?></option>
					<?php
					$available_providers = class_exists( 'WP_MCP_AI_Admin_Settings' )
						? \WP_MCP_AI_Admin_Settings::get_available_providers()
						: array();
					foreach ( $available_providers as $provider_slug => $provider_label ) {
						?>
						<option value="<?php echo esc_attr( $provider_slug ); ?>" <?php selected( $default_provider, $provider_slug ); ?>><?php echo esc_html( $provider_label ); ?></option>
						<?php
					}
					?>
				</select>
			</p>

			<p>
				<label for="wp-mcp-ai-default-model">
					<strong><?php esc_html_e( 'Model', 'nvoos-content-graph-ai-platform' ); ?></strong>
				</label><br>
				<select name="wp_mcp_ai_default_model" id="wp-mcp-ai-default-model" class="widefat">
					<option value=""><?php esc_html_e( '— Select Model —', 'nvoos-content-graph-ai-platform' ); ?></option>
					<?php if ( ! empty( $models ) ) : ?>
						<?php foreach ( $models as $model_id => $model_name ) : ?>
							<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $default_model, $model_id ); ?>>
								<?php echo esc_html( $model_name ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( $default_model && ( empty( $models ) || ! isset( $models[ $default_model ] ) ) ) : ?>
						<option value="<?php echo esc_attr( $default_model ); ?>" selected="selected">
							<?php echo esc_html( $default_model ); ?><?php echo ! empty( $models ) ? ' (custom)' : ''; ?>
						</option>
					<?php endif; ?>
				</select>
				<span class="description"><?php esc_html_e( 'Leave empty to use professional default', 'nvoos-content-graph-ai-platform' ); ?></span>
			</p>

			<p>
				<label for="wp-mcp-ai-default-temperature">
					<strong><?php esc_html_e( 'Temperature', 'nvoos-content-graph-ai-platform' ); ?></strong>
				</label><br>
				<input type="number" name="wp_mcp_ai_default_temperature" id="wp-mcp-ai-default-temperature" class="widefat" value="<?php echo esc_attr( $default_temperature ); ?>" min="0" max="2" step="0.1" placeholder="0.7">
				<span class="description"><?php esc_html_e( '0-2. Leave empty to use professional default', 'nvoos-content-graph-ai-platform' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Save post meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_post( $post_id, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save team members.
		if ( isset( $_POST['wp_mcp_ai_team_members_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_members_meta_nonce'] ) ), 'wp_mcp_ai_team_members_meta' ) ) {
			$team_members = array();
			if ( isset( $_POST['wp_mcp_ai_team_members'] ) && is_array( $_POST['wp_mcp_ai_team_members'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_team_members().
				$team_members = $this->sanitize_team_members( wp_unslash( $_POST['wp_mcp_ai_team_members'] ) );
			}
			update_post_meta( $post_id, self::META_TEAM_MEMBERS, $team_members );
		}

		// Save default settings.
		if ( isset( $_POST['wp_mcp_ai_team_defaults_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_defaults_meta_nonce'] ) ), 'wp_mcp_ai_team_defaults_meta' ) ) {
			$default_provider = isset( $_POST['wp_mcp_ai_default_provider'] ) ? sanitize_key( wp_unslash( $_POST['wp_mcp_ai_default_provider'] ) ) : '';
			if ( '' === $default_provider ) {
				delete_post_meta( $post_id, self::META_DEFAULT_PROVIDER );
			} else {
				update_post_meta( $post_id, self::META_DEFAULT_PROVIDER, $default_provider );
			}

			$default_model = isset( $_POST['wp_mcp_ai_default_model'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_default_model'] ) ) : '';
			if ( '' === $default_model ) {
				delete_post_meta( $post_id, self::META_DEFAULT_MODEL );
			} else {
				update_post_meta( $post_id, self::META_DEFAULT_MODEL, $default_model );
			}

			$default_temperature = null;
			if ( isset( $_POST['wp_mcp_ai_default_temperature'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_temperature().
				$default_temperature = $this->sanitize_temperature( wp_unslash( $_POST['wp_mcp_ai_default_temperature'] ) );
			}

			if ( null === $default_temperature ) {
				delete_post_meta( $post_id, self::META_DEFAULT_TEMPERATURE );
			} else {
				update_post_meta( $post_id, self::META_DEFAULT_TEMPERATURE, $default_temperature );
			}
		}

		// Save orchestration settings.
		if ( isset( $_POST['wp_mcp_ai_team_orchestration_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_orchestration_meta_nonce'] ) ), 'wp_mcp_ai_team_orchestration_meta' ) ) {
			// Orchestration mode.
			if ( isset( $_POST['wp_mcp_ai_orchestration_mode'] ) ) {
				$orchestration_mode = sanitize_key( wp_unslash( $_POST['wp_mcp_ai_orchestration_mode'] ) );
				update_post_meta( $post_id, self::META_ORCHESTRATION_MODE, $orchestration_mode );
			}

			// Workflow template (JSON) — decode, sanitize all nested values, then re-encode.
			if ( isset( $_POST['wp_mcp_ai_workflow_template'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON string must be decoded first; all nested values are sanitized recursively via Schema\Sanitize::recursive() below.
				$raw_template     = wp_unslash( $_POST['wp_mcp_ai_workflow_template'] );
				$decoded_template = json_decode( $raw_template, true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded_template ) ) {
					$decoded_template  = Sanitize::recursive( $decoded_template );
					$workflow_template = wp_json_encode( $decoded_template );
				} else {
					$workflow_template = '{}';
				}
				update_post_meta( $post_id, self::META_WORKFLOW_TEMPLATE, $workflow_template );
			}

			// Result aggregation strategy.
			if ( isset( $_POST['wp_mcp_ai_result_aggregation'] ) ) {
				$aggregation_strategy = sanitize_key( wp_unslash( $_POST['wp_mcp_ai_result_aggregation'] ) );
				update_post_meta( $post_id, self::META_RESULT_AGGREGATION_STRATEGY, $aggregation_strategy );
			}
		}

		// Save driver assistant.
		if ( isset( $_POST['wp_mcp_ai_team_driver_assistant_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_driver_assistant_meta_nonce'] ) ), 'wp_mcp_ai_team_driver_assistant_meta' ) ) {
			$driver_assistant_id = isset( $_POST['wp_mcp_ai_driver_assistant'] ) ? absint( wp_unslash( $_POST['wp_mcp_ai_driver_assistant'] ) ) : 0;
			if ( $driver_assistant_id > 0 ) {
				update_post_meta( $post_id, self::META_DRIVER_ASSISTANT, $driver_assistant_id );
			} else {
				delete_post_meta( $post_id, self::META_DRIVER_ASSISTANT );
			}
		}
	}

	/**
	 * Add custom admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_admin_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'title' === $key ) {
				$new_columns['team_members'] = __( 'Team Members', 'nvoos-content-graph-ai-platform' );
				$new_columns['provider']     = __( 'Provider', 'nvoos-content-graph-ai-platform' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_columns( $column, $post_id ) {
		if ( 'team_members' === $column ) {
			$team_members = get_post_meta( $post_id, self::META_TEAM_MEMBERS, true );
			if ( is_array( $team_members ) && ! empty( $team_members ) ) {
				echo esc_html( count( $team_members ) ) . ' ' . esc_html( _n( 'professional', 'professionals', count( $team_members ), 'nvoos-content-graph-ai-platform' ) );
			} else {
				echo '<span class="description">' . esc_html__( 'No members', 'nvoos-content-graph-ai-platform' ) . '</span>';
			}
		} elseif ( 'provider' === $column ) {
			$provider = get_post_meta( $post_id, self::META_DEFAULT_PROVIDER, true );
			if ( $provider ) {
				$provider_labels = array(
					'openai'     => 'OpenAI',
					'gemini'     => 'Gemini',
					'anthropic'  => 'Claude',
					'ollama'     => 'Ollama',
					'lm_studio'  => 'LM Studio',
					'cloudflare' => 'Cloudflare',
				);
				echo esc_html( isset( $provider_labels[ $provider ] ) ? $provider_labels[ $provider ] : $provider );
			} else {
				echo '<span class="description">' . esc_html__( 'Default', 'nvoos-content-graph-ai-platform' ) . '</span>';
			}
		}
	}
}
