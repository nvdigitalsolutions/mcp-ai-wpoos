<?php
/**
 * Tool that returns the full instructions for a single Agent Skill.
 *
 * Used by the progressive-disclosure mode (see
 * `WP_MCP_AI_Skill_Registry::build_skills_index_prompt()`) so that the LLM
 * can pull a full SKILL.md on demand instead of having every assigned skill
 * dumped into the system prompt up front.
 *
 * Security model:
 *   - The skill must be installed in the registry (rejects arbitrary names).
 *   - The skill must be assigned to the current assistant
 *     (`_wp_mcp_ai_skills` post meta). User-supplied names that have not been
 *     pre-approved by an admin cannot leak instruction text.
 *   - When the assistant id is missing from the context (e.g. a direct
 *     /tools call), only authenticated `read`-capable users may load skills.
 *
 * @package WP_MCP_AI
 * @since 1.11.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the full SKILL.md instructions for a single assigned skill.
 *
 * @since 1.11.0
 */
class WP_MCP_AI_Tool_Load_Skill implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum length of a skill name accepted from the model.
	 *
	 * Mirrors the registry's frontmatter sanitisation (sanitize_text_field
	 * already truncates at 1k chars; we cap lower for defence in depth).
	 *
	 * @var int
	 */
	const MAX_SKILL_NAME_LEN = 80;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'load_skill';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Load Agent Skill', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Loads the full instructions for one of the assistant\'s assigned Agent Skills. Call this tool when the user\'s request matches a skill listed under "Available Skills" in your system prompt. Pass the exact skill name as the `name` argument. Only skills explicitly assigned to this assistant by an administrator can be loaded.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array(
					'type'        => 'string',
					'description' => __( 'Exact name of the skill to load (must match one of the names listed in "Available Skills").', 'mcp-ai-wpoos' ),
					'minLength'   => 1,
					'maxLength'   => self::MAX_SKILL_NAME_LEN,
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads installed skill content, never writes.
			'local-only',           // No external API calls.
			'cacheable',            // Same name -> same content within a request.
			'idempotent',           // Safe to call multiple times.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.11.0
	 * @since 1.1.62 — External skill sources may resolve names via the
	 *                `wp_mcp_ai_load_skill_external` filter (Pro OKF bridge).
	 * @param array $arguments Tool arguments. Must contain 'name'.
	 * @param array $context   Execution context. Recognised keys:
	 *                         - 'assistant_id' (int): owning assistant; used to scope which skills may be loaded.
	 *                         - 'user_id'      (int): falls back to get_current_user_id().
	 *                         - 'guest_request' (bool) + 'assistant_id': allows public chat surfaces to load skills.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$name = isset( $arguments['name'] ) ? trim( (string) $arguments['name'] ) : '';
		$name = sanitize_text_field( wp_unslash( $name ) );

		if ( '' === $name ) {
			return new WP_Error(
				'wp_mcp_ai_load_skill_missing_name',
				__( 'A skill name is required.', 'mcp-ai-wpoos' )
			);
		}

		if ( strlen( $name ) > self::MAX_SKILL_NAME_LEN ) {
			return new WP_Error(
				'wp_mcp_ai_load_skill_name_too_long',
				__( 'Skill name is too long.', 'mcp-ai-wpoos' )
			);
		}

		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		$is_guest     = ! empty( $context['guest_request'] );

		// When called outside an assistant context, require an authenticated user.
		if ( ! $assistant_id && ! $is_guest ) {
			$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
				return new WP_Error(
					'wp_mcp_ai_load_skill_forbidden',
					__( 'You do not have permission to load skills.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Pull the assistant's allow-list. The administrator who assigned the
		// skills via the metabox is the source of truth — the model cannot
		// load anything outside that list.
		$allowed = array();
		if ( $assistant_id ) {
			$meta = get_post_meta( $assistant_id, '_wp_mcp_ai_skills', true );
			if ( is_array( $meta ) ) {
				$allowed = array_values( array_filter( array_map( 'sanitize_text_field', $meta ) ) );
			}
		}

		$registry = WP_MCP_AI_Skill_Registry::instance();

		/**
		 * Resolve a skill from an external source before the installed-skill
		 * registry is consulted.
		 *
		 * External sources (e.g. the Pro OKF → Skill bridge, which resolves
		 * names shaped `bundle:concept_id`) enforce their own allow-lists and
		 * trust gating. Return one of:
		 *  - a skill-shaped array with at least `name` and `instructions`,
		 *  - a WP_Error to reject the load with a precise reason,
		 *  - null to defer to the installed-skill registry.
		 *
		 * @since 1.1.62
		 *
		 * @param array|WP_Error|null $skill        External resolution (null = defer).
		 * @param string              $name         Requested skill name.
		 * @param int                 $assistant_id Owning assistant post id (0 when none).
		 */
		$skill = apply_filters( 'wp_mcp_ai_load_skill_external', null, $name, $assistant_id );
		if ( is_wp_error( $skill ) ) {
			return $skill;
		}

		if ( ! is_array( $skill ) || empty( $skill['instructions'] ) ) {
			// Not resolved externally: fall back to the installed-skill registry,
			// still scoped by the assistant's allow-list.
			if ( $assistant_id && ! in_array( $name, $allowed, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_load_skill_not_assigned',
					/* translators: %s: skill name */
					sprintf( __( 'The skill "%s" is not assigned to this assistant.', 'mcp-ai-wpoos' ), $name ),
					array( 'status' => 403 )
				);
			}

			$skill = $registry->get_skill( $name );

			if ( ! $skill || empty( $skill['instructions'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_load_skill_not_found',
					/* translators: %s: skill name */
					sprintf( __( 'Skill "%s" is not installed on this site.', 'mcp-ai-wpoos' ), $name )
				);
			}
		}

		/**
		 * Fire a side-channel event so observability tooling can record skill
		 * loads. Listeners receive the skill name and the resolving assistant
		 * (0 when called outside an assistant context).
		 *
		 * @since 1.11.0
		 * @param string $name         Skill name.
		 * @param int    $assistant_id Assistant post id (0 when none).
		 */
		do_action( 'wp_mcp_ai_skill_loaded', $skill['name'], $assistant_id );

		return array(
			'name'         => $skill['name'],
			'description'  => $skill['description'],
			'license'      => isset( $skill['license'] ) ? $skill['license'] : '',
			'instructions' => $skill['instructions'],
			'message'      => sprintf(
				/* translators: %s: skill name */
				__( 'Loaded skill "%s". Follow these instructions to handle the user\'s request.', 'mcp-ai-wpoos' ),
				$skill['name']
			),
		);
	}
}
