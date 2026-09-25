<?php
/**
 * Pro tool: TypeSafe Skill Select (Jev).
 *
 * Suggests the most appropriate bundled NV oOS skill for a task using the
 * TypeSafe "skill suggestion" pattern: one choice question ranks the full
 * bundled skill catalog against the task, then the top candidates are
 * re-checked with noul appropriateness questions. Returns the ranked,
 * re-checked suggestions for the caller to load via load_skill.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TypeSafe Skill Select Pro Tool.
 */
class WP_MCP_AI_Pro_Tool_Typesafe_Skill_Select implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum skills considered in the ranking stage (choice option cap).
	 *
	 * @var int
	 */
	const MAX_SKILLS = 255;

	/**
	 * Maximum description characters sent per skill.
	 *
	 * @var int
	 */
	const MAX_DESCRIPTION_LENGTH = 200;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'typesafe_skill_select';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'TypeSafe Skill Select (Jev)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Suggest the most appropriate bundled NV oOS skill for a task using the TypeSafe Jev decision model. The full bundled skill catalog is ranked against the task description in one choice question, then the top candidates are re-checked with yes/no appropriateness questions (TypeSafe\'s two-stage skill-suggestion pattern). Returns ranked, re-checked suggestions for the caller to load via load_skill.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Picking the right bundled skill before load_skill; helping an assistant discover which of the 74+ bundled skills fits a user request.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Selecting skills that are not bundled (the catalog is fixed); deciding that no skill is needed — the rankings are suggestions, and low re-check probabilities mean none fits well.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'load_skill', 'typesafe_decide', 'list_prompt_cues' ),
			'notes'           => __( 'Stage 1 ranks the whole catalog (≤255 options); stage 2 re-checks only the top candidates — the two-stage pattern from TypeSafe\'s skill-suggestion cookbook.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'task'       => array(
					'type'        => 'string',
					'description' => __( 'The task description to match skills against.', 'mcp-ai-wpoos-pro' ),
				),
				'max_skills' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 5,
					'default'     => 3,
					'description' => __( 'How many top candidates to re-check and return (1–5).', 'mcp-ai-wpoos-pro' ),
				),
				'model'      => array(
					'type'        => 'string',
					'description' => __( 'Optional model override.', 'mcp-ai-wpoos-pro' ),
				),
				'transport'  => array(
					'type'        => 'string',
					'enum'        => array( 'typesafe', 'openrouter' ),
					'default'     => 'typesafe',
					'description' => __( 'Which transport to use.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'task' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'external-api',
			'requires-capability',
			'consumes-tokens',
			'network-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Enumerate the bundled skill catalog (name => description).
	 *
	 * Scans the base and Pro bundled-skills directories, mirroring the skill
	 * installer's source resolution.
	 *
	 * @return array Skill name => description map.
	 */
	private function get_skill_catalog() {
		$catalog = array();
		$roots   = array();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$base_dir = trailingslashit( WP_MCP_AI_PATH ) . 'includes/bundled-skills';
			if ( is_dir( $base_dir ) ) {
				$roots[] = $base_dir;
			}
		}

		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$pro_dir = trailingslashit( WP_MCP_AI_PRO_PATH ) . 'includes/bundled-skills';
			if ( is_dir( $pro_dir ) ) {
				$roots[] = $pro_dir;
			}
		}

		foreach ( $roots as $root ) {
			foreach ( glob( trailingslashit( $root ) . '*/SKILL.md' ) as $skill_file ) {
				$name = sanitize_key( basename( dirname( $skill_file ) ) );
				if ( '' === $name || isset( $catalog[ $name ] ) ) {
					continue;
				}

				// Local bundled-skill read — not a remote URL.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled SKILL.md from the plugin directory.
				$content = file_get_contents( $skill_file );
				if ( false === $content ) {
					continue;
				}

				$description = $this->parse_skill_description( $content );
				if ( '' === $description ) {
					continue;
				}

				$catalog[ $name ] = $description;
			}
		}

		ksort( $catalog );

		return $catalog;
	}

	/**
	 * Parse the description out of a SKILL.md frontmatter block.
	 *
	 * @param string $content SKILL.md content.
	 * @return string Description, or empty string.
	 */
	private function parse_skill_description( $content ) {
		if ( ! preg_match( '/^---\s*\n(.*?)\n---/s', $content, $matches ) ) {
			return '';
		}

		if ( ! preg_match( '/^description:\s*(.+)$/m', $matches[1], $desc_match ) ) {
			return '';
		}

		$description = trim( $desc_match[1] );
		$description = function_exists( 'mb_substr' ) ? mb_substr( $description, 0, self::MAX_DESCRIPTION_LENGTH ) : substr( $description, 0, self::MAX_DESCRIPTION_LENGTH );

		return sanitize_text_field( $description );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to run skill selection requests. This tool requires administrator privileges.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['task'] ) || ! is_string( $arguments['task'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'A task description is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$task       = sanitize_text_field( $arguments['task'] );
		$max_skills = isset( $arguments['max_skills'] ) ? max( 1, min( 5, absint( $arguments['max_skills'] ) ) ) : 3;

		$transport = isset( $arguments['transport'] ) ? sanitize_key( $arguments['transport'] ) : 'typesafe';
		$transport = in_array( $transport, array( 'typesafe', 'openrouter' ), true ) ? $transport : 'typesafe';

		$options = array();
		if ( ! empty( $arguments['model'] ) && is_string( $arguments['model'] ) ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
		}

		$catalog = array_slice( $this->get_skill_catalog(), 0, self::MAX_SKILLS, true );

		if ( empty( $catalog ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_bundled_skills',
				__( 'No bundled skills were found to rank.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Stage 1: rank the whole catalog in one choice question.
		$stage1_questions = array(
			'skill' => array(
				'type'         => 'choice',
				'instructions' => sprintf(
					/* translators: %s: task description */
					__( 'Which skill is the most appropriate for this task? Task: %s', 'mcp-ai-wpoos-pro' ),
					$task
				),
				'criteria'     => array_merge(
					$catalog,
					array( 'none' => 'No bundled skill fits this task well' )
				),
			),
		);

		$stage1 = $this->decide( $transport, array( 'task' => $task ), $stage1_questions, $options );

		if ( is_wp_error( $stage1 ) ) {
			return $stage1;
		}

		$probabilities = isset( $stage1['answers']['skill']['probabilities'] ) && is_array( $stage1['answers']['skill']['probabilities'] )
			? $stage1['answers']['skill']['probabilities']
			: array();

		arsort( $probabilities );

		$top = array();
		foreach ( $probabilities as $name => $probability ) {
			if ( 'none' === $name || ! isset( $catalog[ $name ] ) ) {
				continue;
			}
			$top[ $name ] = floatval( $probability );
			if ( count( $top ) >= $max_skills ) {
				break;
			}
		}

		// Stage 2: re-check the top candidates with noul appropriateness.
		$suggestions = array();
		$rechecks    = array();

		if ( ! empty( $top ) ) {
			$stage2_questions = array();
			foreach ( $top as $name => $probability ) {
				$stage2_questions[ 'ok_' . $name ] = array(
					'type'         => 'noul',
					'instructions' => sprintf(
						/* translators: 1: skill name, 2: skill description */
						__( 'Is the "%1$s" skill (%2$s) appropriate for this task?', 'mcp-ai-wpoos-pro' ),
						$name,
						$catalog[ $name ]
					),
				);
			}

			$stage2 = $this->decide( $transport, array( 'task' => $task ), $stage2_questions, $options );

			if ( is_wp_error( $stage2 ) ) {
				// Fail-open on the re-check: keep the stage-1 ranking only.
				foreach ( $top as $name => $probability ) {
					$suggestions[] = array(
						'skill'            => $name,
						'description'      => $catalog[ $name ],
						'rank_probability' => $probability,
						'appropriateness'  => null,
					);
				}
			} else {
				foreach ( $top as $name => $probability ) {
					$suggestions[] = array(
						'skill'            => $name,
						'description'      => $catalog[ $name ],
						'rank_probability' => $probability,
						'appropriateness'  => isset( $stage2['answers'][ 'ok_' . $name ]['noul'] )
							? floatval( $stage2['answers'][ 'ok_' . $name ]['noul'] )
							: null,
					);
				}
			}
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'typesafe_skill_select_completed',
				'TypeSafe skill selection completed.',
				array(
					'transport'    => $transport,
					'catalog_size' => count( $catalog ),
					'suggestions'  => count( $suggestions ),
				)
			);
		}

		return array(
			'success'     => true,
			'model'       => isset( $stage1['model'] ) ? esc_html( $stage1['model'] ) : '',
			'provider'    => 'openrouter' === $transport ? 'openrouter' : 'typesafe',
			'task'        => esc_html( $task ),
			'suggestions' => $suggestions,
			'message'     => sprintf(
				/* translators: %d: suggestion count */
				__( 'Ranked %d bundled skill suggestion(s); load the best fit with load_skill.', 'mcp-ai-wpoos-pro' ),
				count( $suggestions )
			),
		);
	}

	/**
	 * Send a decision through the chosen transport.
	 *
	 * @param string $transport 'typesafe' or 'openrouter'.
	 * @param mixed  $state     Decision state.
	 * @param array  $questions Question map.
	 * @param array  $options   Client options.
	 * @return array|WP_Error Normalised decision or WP_Error.
	 */
	private function decide( $transport, $state, $questions, $options ) {
		if ( 'openrouter' === $transport ) {
			if ( ! class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
				return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'The OpenRouter client is not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$openrouter = new WP_MCP_AI_OpenRouter_Client();

			return $openrouter->create_decision( $state, $questions, $options );
		}

		if ( ! class_exists( 'WP_MCP_AI_Typesafe_Client' ) ) {
			return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'The TypeSafe client is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$typesafe = new WP_MCP_AI_Typesafe_Client();

		return $typesafe->decide( $state, $questions, $options );
	}
}
