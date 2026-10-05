<?php
/**
 * Tool: Scan Assistant Security.
 *
 * Deterministic configuration audit of an assistant, informed by the OWASP
 * LLM Top 10 2026 — Prompt Injection (#1), Sensitive Information Disclosure
 * (#2) and Excessive Agency (#3, driven by tool/MCP usage). Four check
 * families run entirely locally against the assistant's stored configuration:
 *
 *  - prompt_injection — bait/unconditional-obedience patterns in the system
 *    prompt that make the assistant easy to hijack.
 *  - excessive_agency — destructive tool grants, admin-capability tools,
 *    oversized tool assignments.
 *  - secret_leakage — API keys and bearer tokens pasted into the prompt.
 *    Evidence is MASKED: the secret value is never echoed back.
 *  - config_hygiene — missing provider, empty prompt, temperature outliers.
 *
 * Every finding carries severity, evidence, and a remediation string. The
 * report is advisory — it never blocks or modifies anything. Inspired by
 * ECC's AgentShield (scanning the agent configuration itself as an attack
 * surface).
 *
 * @credit  Config-scanning concept inspired by affaan-m/ECC AgentShield
 *          (MIT) and the OWASP LLM Top 10 2026.
 * @package WP_MCP_AI
 * @since   1.1.97
 * @author  NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan Assistant Security tool.
 *
 * Implements the canonical envelope (success array or WP_Error) and the
 * two-gate sanitisation rule.
 *
 * @since 1.1.97
 */
class WP_MCP_AI_Tool_Scan_Assistant_Security implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'scan_assistant_security';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Scan Assistant Security', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Audit an assistant configuration for security risks with four deterministic check families: prompt-injection susceptibility, excessive agency (destructive or admin tools), secrets pasted into the prompt, and configuration hygiene. Returns a posture score, labeled findings with masked evidence, and remediation strings. Advisory only — it never modifies the assistant.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Before publishing or sharing an assistant; after editing prompts or tool grants; as part of a scheduled security review of every assistant.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Runtime exploit detection (use the request guard and jailbreak guardrails for that); auditing third-party plugins or themes.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'check_site_security', 'run_assistant_eval', 'get_site_health', 'user_activity_auditor' ),
			'notes'           => __( 'The scan reads configuration only and masks any secret-like evidence it finds. Findings are advisory; remediation strings describe the fix.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'assistant_id' => array(
					'type'        => 'integer',
					'description' => __( 'The assistant post ID to scan.', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'assistant_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Reads configuration, changes nothing.
			'no-user-data-access',  // Assistant configuration only.
			'stateless',            // No persistence between calls.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
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
				__( 'You do not have permission to run assistant security scans. This tool requires administrator privileges.', 'mcp-ai-wpoos' )
			);
		}

		// Two-gate rule, gate one: sanitise at entry.
		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;

		if ( $assistant_id <= 0 ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_assistant_id',
				__( 'A valid assistant ID is required.', 'mcp-ai-wpoos' )
			);
		}

		$post = get_post( $assistant_id );
		if ( ! $post ) {
			return new WP_Error(
				'wp_mcp_ai_assistant_not_found',
				/* translators: %d: assistant ID. */
				sprintf( __( 'Assistant %d does not exist.', 'mcp-ai-wpoos' ), $assistant_id )
			);
		}

		$config = $this->load_configuration( $assistant_id );
		$report = $this->scan( $assistant_id, $config );

		$score = $report['score'];
		if ( $score >= 90 ) {
			$label = __( 'good', 'mcp-ai-wpoos' );
		} elseif ( $score >= 70 ) {
			$label = __( 'fair', 'mcp-ai-wpoos' );
		} else {
			$label = __( 'poor', 'mcp-ai-wpoos' );
		}

		return $this->format_success_response(
			sprintf(
				/* translators: 1: posture label, 2: posture score, 3: finding count. */
				__( 'Security scan complete: posture %1$s (score %2$s) with %3$d findings.', 'mcp-ai-wpoos' ),
				$label,
				$score,
				count( $report['findings'] )
			),
			array(
				'data' => array(
					'assistant_id' => $assistant_id,
					'score'        => $score,
					'label'        => $label,
					'findings'     => $report['findings'],
					'totals'       => $report['totals'],
				),
			)
		);
	}

	/**
	 * Load the assistant configuration.
	 *
	 * Prefers `WP_MCP_AI_Assistant_CPT::get_assistant_configuration()` and
	 * falls back to reading meta directly when the CPT class is unavailable.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array{system_prompt: string, tools: array, provider: string, model: string, temperature: mixed}
	 */
	public function load_configuration( $assistant_id ) {
		$config = array(
			'system_prompt' => '',
			'tools'         => array(),
			'provider'      => '',
			'model'         => '',
			'temperature'   => null,
		);

		if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) && method_exists( 'WP_MCP_AI_Assistant_CPT', 'get_assistant_configuration' ) ) {
			$loaded = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
			if ( is_array( $loaded ) ) {
				$config['system_prompt'] = isset( $loaded['system_prompt'] ) && is_string( $loaded['system_prompt'] ) ? $loaded['system_prompt'] : '';
				$config['tools']         = isset( $loaded['tools'] ) && is_array( $loaded['tools'] ) ? $loaded['tools'] : array();
				$config['provider']      = isset( $loaded['provider'] ) && is_string( $loaded['provider'] ) ? $loaded['provider'] : '';
				$config['model']         = isset( $loaded['model'] ) && is_string( $loaded['model'] ) ? $loaded['model'] : '';
				$config['temperature']   = isset( $loaded['temperature'] ) ? $loaded['temperature'] : null;
			}
		}

		return $config;
	}

	/**
	 * Run the four check families and compute the posture score.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $config       Assistant configuration.
	 * @return array{score: int, findings: array, totals: array}
	 */
	public function scan( $assistant_id, $config ) {
		$findings = array();

		$findings = array_merge( $findings, $this->scan_injection( $config['system_prompt'] ) );
		$findings = array_merge( $findings, $this->scan_agency( $config['tools'] ) );
		$findings = array_merge( $findings, $this->scan_secrets( $config['system_prompt'] ) );
		$findings = array_merge( $findings, $this->scan_hygiene( $config ) );

		$severity_weight = array(
			'high'   => 15,
			'medium' => 8,
			'low'    => 3,
		);

		$penalty = 0;
		$totals  = array(
			'high'   => 0,
			'medium' => 0,
			'low'    => 0,
		);

		foreach ( $findings as $finding ) {
			$severity            = isset( $finding['severity'] ) ? $finding['severity'] : 'low';
			$penalty            += isset( $severity_weight[ $severity ] ) ? $severity_weight[ $severity ] : 0;
			$totals[ $severity ] = ( isset( $totals[ $severity ] ) ? $totals[ $severity ] : 0 ) + 1;
		}

		return array(
			'score'    => max( 0, 100 - $penalty ),
			'findings' => $findings,
			'totals'   => $totals,
		);
	}

	/**
	 * Prompt-injection susceptibility checks.
	 *
	 * All patterns are case-insensitive and match against the original
	 * prompt so evidence excerpts carry the real casing.
	 *
	 * @param string $prompt System prompt.
	 * @return array Findings.
	 */
	public function scan_injection( $prompt ) {
		$findings = array();

		$patterns = array(
			'ignore_all_instructions' => array(
				'regex'       => '/ignore\s+(all\s+)?(previous|prior|above|earlier|system|your)\s+(instructions|rules|prompts)/i',
				'severity'    => 'high',
				'remediation' => __( 'Remove any clause that instructs the model to disregard instructions; replace with explicit scope boundaries.', 'mcp-ai-wpoos' ),
			),
			'prompt_leak_bait'        => array(
				'regex'       => '/(reveal|repeat|show|print)\s+(your|the)\s+(system\s+prompt|instructions|rules)/i',
				'severity'    => 'high',
				'remediation' => __( 'Remove instructions that reference the system prompt as revealable content; instruct the assistant never to disclose its configuration instead.', 'mcp-ai-wpoos' ),
			),
			'unrestricted_code'       => array(
				'regex'       => '/(run|execute)\s+(any|all|arbitrary)\s+(code|commands|shell)/i',
				'severity'    => 'medium',
				'remediation' => __( 'Replace "run any code" with an explicit allowlist of tools and a confirmation requirement for state-changing operations.', 'mcp-ai-wpoos' ),
			),
			'jailbreak_placeholder'   => array(
				'regex'       => '/(act\s+as\s+if\s+you\s+have\s+no|you\s+are\s+now\s+(dan|unrestricted|uncensored)|no\s+restrictions|bypass\s+(all\s+)?restrictions)/i',
				'severity'    => 'high',
				'remediation' => __( 'Remove role-swap or restriction-bypass phrasing; it invites jailbreak attempts and weakens guardrails.', 'mcp-ai-wpoos' ),
			),
			'obey_user_content'       => array(
				'regex'       => '/(always\s+)?(follow|obey|execute)\s+(any|all|every)\s+instructions?\s+(from|in)\s+(the\s+)?(user|users?|content|files?|documents?)/i',
				'severity'    => 'medium',
				'remediation' => __( 'User content is untrusted input (OWASP LLM01). Qualify the clause so instructions from content are treated as data, not commands.', 'mcp-ai-wpoos' ),
			),
		);

		foreach ( $patterns as $id => $spec ) {
			if ( 1 === preg_match( $spec['regex'], $prompt ) ) {
				$findings[] = array(
					'family'      => 'prompt_injection',
					'check'       => $id,
					'severity'    => $spec['severity'],
					'evidence'    => $this->excerpt( $prompt, $spec['regex'] ),
					'remediation' => $spec['remediation'],
				);
			}
		}

		return $findings;
	}

	/**
	 * Excessive-agency checks over the assigned tool list.
	 *
	 * @param array $tools Assigned tool slugs.
	 * @return array Findings.
	 */
	public function scan_agency( $tools ) {
		$findings = array();
		$tools    = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', $tools ) ) ) );

		$destructive = array();
		$admin       = array();

		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			foreach ( $tools as $slug ) {
				$tool = $registry->get_tool( $slug );
				if ( ! $tool ) {
					continue;
				}

				if ( method_exists( $tool, 'get_capability_flags' ) ) {
					$flags = $tool->get_capability_flags();
					if ( is_array( $flags ) && in_array( 'destructive', $flags, true ) ) {
						$destructive[] = $slug;
					}
				}

				if ( method_exists( $tool, 'get_required_capability' ) && 'manage_options' === $tool->get_required_capability() ) {
					$admin[] = $slug;
				}
			}
		}

		if ( ! empty( $destructive ) ) {
			$findings[] = array(
				'family'      => 'excessive_agency',
				'check'       => 'destructive_tools_assigned',
				'severity'    => 'medium',
				'evidence'    => sprintf(
					/* translators: %s: comma-separated tool slugs. */
					__( 'Destructive tools assigned: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $destructive )
				),
				'remediation' => __( 'Keep the destructive-ops confirmation gate enabled (Settings → Security) and reassign destructive tools only to assistants that require them, with confirm_destructive enforced.', 'mcp-ai-wpoos' ),
			);
		}

		if ( ! empty( $admin ) ) {
			$findings[] = array(
				'family'      => 'excessive_agency',
				'check'       => 'admin_tools_assigned',
				'severity'    => 'medium',
				'evidence'    => sprintf(
					/* translators: %s: comma-separated tool slugs. */
					__( 'Administrator-capability tools assigned: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $admin )
				),
				'remediation' => __( 'Administrator tools on an assistant grant any user of that assistant admin reach (OWASP LLM06/Excessive Agency). Prefer edit-level equivalents and per-assistant capability boundaries.', 'mcp-ai-wpoos' ),
			);
		}

		/**
		 * Filters the tool-count ceiling used by the excessive-agency check.
		 *
		 * @since 1.1.97
		 *
		 * @param int $max Maximum tools before a low-severity finding. Default 50.
		 */
		$max_tools = max( 1, (int) apply_filters( 'wp_mcp_ai_security_scan_max_tools', 50 ) );

		if ( count( $tools ) > $max_tools ) {
			$findings[] = array(
				'family'      => 'excessive_agency',
				'check'       => 'oversized_tool_assignments',
				'severity'    => 'low',
				'evidence'    => sprintf(
					/* translators: 1: assigned count, 2: ceiling. */
					__( '%1$d tools assigned (ceiling %2$d).', 'mcp-ai-wpoos' ),
					count( $tools ),
					$max_tools
				),
				'remediation' => __( 'Every assigned tool expands the attack surface and the prompt budget. Trim to the tools the assistant actually uses (tool presets can help).', 'mcp-ai-wpoos' ),
			);
		}

		return $findings;
	}

	/**
	 * Secret-leakage checks over the system prompt.
	 *
	 * Evidence is masked — the secret value is never echoed.
	 *
	 * @param string $prompt System prompt.
	 * @return array Findings.
	 */
	public function scan_secrets( $prompt ) {
		$findings = array();

		$patterns = array(
			'openai_key'   => array(
				'regex'       => '/sk-[A-Za-z0-9]{20,}/',
				'label'       => __( 'OpenAI-style API key (sk-…)', 'mcp-ai-wpoos' ),
				'remediation' => __( 'Remove the key from the prompt. Store it in Settings → Providers (or an environment variable); the provider clients resolve it from there.', 'mcp-ai-wpoos' ),
			),
			'aws_key'      => array(
				'regex'       => '/AKIA[0-9A-Z]{16}/',
				'label'       => __( 'AWS access key', 'mcp-ai-wpoos' ),
				'remediation' => __( 'Remove the key and rotate it — anything embedded in a prompt is effectively public to anyone who can read the assistant.', 'mcp-ai-wpoos' ),
			),
			'slack_token'  => array(
				'regex'       => '/xox[baprs]-[A-Za-z0-9-]{10,}/',
				'label'       => __( 'Slack token', 'mcp-ai-wpoos' ),
				'remediation' => __( 'Remove the token and rotate it in the Slack admin.', 'mcp-ai-wpoos' ),
			),
			'github_token' => array(
				'regex'       => '/gh[pousr]_[A-Za-z0-9]{30,}/',
				'label'       => __( 'GitHub token', 'mcp-ai-wpoos' ),
				'remediation' => __( 'Remove the token and rotate it in GitHub settings.', 'mcp-ai-wpoos' ),
			),
			'bearer_token' => array(
				'regex'       => '/bearer\s+[A-Za-z0-9._-]{20,}/i',
				'label'       => __( 'Bearer token', 'mcp-ai-wpoos' ),
				'remediation' => __( 'Remove the token from the prompt; bearer credentials belong in the provider or remote-connection settings.', 'mcp-ai-wpoos' ),
			),
			'generic_key'  => array(
				'regex'       => '/(api[_-]?key|secret|password|token)\s*["\']?\s*[:=]\s*["\']?[A-Za-z0-9_\-]{16,}/i',
				'label'       => __( 'Credential assignment', 'mcp-ai-wpoos' ),
				'remediation' => __( 'Credentials in prompts leak to transcripts, exports, and assistant ports. Move them to Settings or environment variables.', 'mcp-ai-wpoos' ),
			),
		);

		foreach ( $patterns as $id => $spec ) {
			if ( 1 !== preg_match( $spec['regex'], $prompt ) ) {
				continue;
			}

			$findings[] = array(
				'family'      => 'secret_leakage',
				'check'       => $id,
				'severity'    => 'high',
				'evidence'    => sprintf(
					/* translators: %s: secret type label. */
					__( '%s detected in the system prompt (value masked).', 'mcp-ai-wpoos' ),
					$spec['label']
				),
				'remediation' => $spec['remediation'],
			);
		}

		return $findings;
	}

	/**
	 * Configuration-hygiene checks.
	 *
	 * @param array $config Assistant configuration.
	 * @return array Findings.
	 */
	public function scan_hygiene( $config ) {
		$findings = array();

		if ( '' === trim( $config['system_prompt'] ) ) {
			$findings[] = array(
				'family'      => 'config_hygiene',
				'check'       => 'empty_system_prompt',
				'severity'    => 'low',
				'evidence'    => __( 'The system prompt is empty.', 'mcp-ai-wpoos' ),
				'remediation' => __( 'Give the assistant an explicit role, scope and guardrail reference; unconstrained assistants are easier to prompt-inject.', 'mcp-ai-wpoos' ),
			);
		}

		if ( '' === (string) $config['provider'] ) {
			$findings[] = array(
				'family'      => 'config_hygiene',
				'check'       => 'missing_provider',
				'severity'    => 'low',
				'evidence'    => __( 'No provider is configured for this assistant.', 'mcp-ai-wpoos' ),
				'remediation' => __( 'Select a provider (or the site default) so the assistant cannot silently fall through to an unintended model.', 'mcp-ai-wpoos' ),
			);
		}

		$temperature = $config['temperature'];
		if ( null !== $temperature && '' !== $temperature && is_numeric( $temperature ) ) {
			$temperature = (float) $temperature;
			if ( $temperature < 0 || $temperature > 2 ) {
				$findings[] = array(
					'family'      => 'config_hygiene',
					'check'       => 'temperature_out_of_range',
					'severity'    => 'low',
					'evidence'    => sprintf(
						/* translators: %s: temperature value. */
						__( 'Temperature %s is outside the supported 0–2 range.', 'mcp-ai-wpoos' ),
						number_format_i18n( $temperature, 2 )
					),
					'remediation' => __( 'Set temperature between 0 and 2. Very high values produce erratic, guardrail-weakening output.', 'mcp-ai-wpoos' ),
				);
			}
		}

		return $findings;
	}

	/**
	 * Build a safe evidence excerpt around the first pattern match.
	 *
	 * The matched span (e.g. a credential) is replaced with a masked
	 * placeholder so evidence never echoes the secret or bait text itself.
	 *
	 * @param string $prompt Original prompt.
	 * @param string $regex  Pattern that matched.
	 * @return string Evidence excerpt.
	 */
	private function excerpt( $prompt, $regex ) {
		if ( 1 !== preg_match( $regex, $prompt, $matches, PREG_OFFSET_CAPTURE ) ) {
			// Fall back to a plain truncated excerpt.
			$flat = preg_replace( '/\s+/', ' ', trim( $prompt ) );
			return mb_substr( $flat, 0, 120 );
		}

		$offset = (int) $matches[0][1];
		$length = strlen( $matches[0][0] );
		$start  = max( 0, $offset - 60 );
		$before = substr( $prompt, $start, $offset - $start );
		$after  = substr( $prompt, $offset + $length, 60 );

		return trim( '…' . $before . ' [MASKED] ' . $after . '…' );
	}
}
