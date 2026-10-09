<?php
/**
 * Chat Profile — domain value object.
 *
 * A chat profile is a session-scoped permission boundary over tool execution:
 * it declares which tool capability flags are gated (blocked) and which tool
 * slugs are explicitly allowed despite their flags. Profiles are resolved
 * server-side per request (see WP_MCP_AI_Chat_Profile_Manager) and enforced
 * by WP_MCP_AI_Read_Only_Profile_Gate on `wp_mcp_ai_before_tool_execution`.
 *
 * Industry references:
 * - Claude Code permission modes (deny → ask → allow precedence)
 * - Cline Plan/Act modes (read-only plan vs write act)
 * - OWASP LLM06/LLM08 Excessive Agency (enforce at the tool boundary)
 * - Microsoft least-privilege agent guidance (roles as permission boundaries)
 *
 * Pure PHP — no WordPress or infrastructure dependencies.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat profile value object.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Chat_Profile {

	/**
	 * Full-access profile slug. Gates nothing — the historical default.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const PROFILE_WRITE = 'write';

	/**
	 * Read-only profile slug. Blocks writes, state changes and destructive
	 * actions; read-only tools run freely.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const PROFILE_READ_ONLY = 'read-only';

	/**
	 * Capability flags gated by the read-only profile. Mirrors the flag
	 * vocabulary of WP_MCP_AI_Tool_Capability_Flags_Interface and the
	 * destructive-ops gate so the whole stack speaks one language.
	 *
	 * @since 2.2.0
	 * @var array
	 */
	const READ_ONLY_GATED_FLAGS = array(
		'destructive',
		'data-destruction',
		'irreversible',
		'state-changing',
		'write',
		'financial-impact',
		'access-control-change',
		'mass-email',
	);

	/**
	 * Profile slug.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	private $slug;

	/**
	 * Human-readable label.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	private $label;

	/**
	 * Description shown in the UI and to the model when blocked.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	private $description;

	/**
	 * Capability flags that this profile gates (blocks).
	 *
	 * @since 2.2.0
	 * @var array
	 */
	private $gated_flags;

	/**
	 * Tool slugs explicitly allowed despite carrying a gated flag.
	 *
	 * @since 2.2.0
	 * @var array
	 */
	private $allowed_tool_slugs;

	/**
	 * Capability required to SELECT this profile (not to run under it).
	 *
	 * @since 2.2.0
	 * @var string
	 */
	private $required_capability;

	/**
	 * Whether this profile is the site fallback default.
	 *
	 * @since 2.2.0
	 * @var bool
	 */
	private $is_default;

	/**
	 * Constructor.
	 *
	 * @since 2.2.0
	 *
	 * @param string $slug               Profile slug.
	 * @param string $label              Human-readable label.
	 * @param string $description        Description.
	 * @param array  $gated_flags        Capability flags blocked by this profile.
	 * @param array  $allowed_tool_slugs Tool slugs explicitly allowed.
	 * @param string $required_capability Capability required to select the profile.
	 * @param bool   $is_default         Whether this is the fallback default.
	 */
	public function __construct( $slug, $label, $description, array $gated_flags = array(), array $allowed_tool_slugs = array(), $required_capability = '', $is_default = false ) {
		$this->slug                = self::sanitize_token( (string) $slug );
		$this->label               = (string) $label;
		$this->description         = (string) $description;
		$this->gated_flags         = array_values( array_unique( array_map( array( __CLASS__, 'sanitize_token' ), $gated_flags ) ) );
		$this->allowed_tool_slugs  = array_values( array_unique( array_map( array( __CLASS__, 'sanitize_token' ), $allowed_tool_slugs ) ) );
		$this->required_capability = (string) $required_capability;
		$this->is_default          = (bool) $is_default;
	}

	/**
	 * Normalise a slug token (lowercase alphanumerics, dashes, underscores).
	 *
	 * @since 2.2.0
	 *
	 * @param string $value Raw token.
	 * @return string
	 */
	private static function sanitize_token( $value ) {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '', (string) $value ) );
	}

	/**
	 * Build a profile from a registered array definition.
	 *
	 * Unknown keys are ignored so third-party registrations stay tolerant.
	 *
	 * @since 2.2.0
	 *
	 * @param array $definition Registration array.
	 * @return WP_MCP_AI_Chat_Profile|null Null when the definition lacks a slug.
	 */
	public static function from_array( array $definition ) {
		if ( empty( $definition['slug'] ) ) {
			return null;
		}
		return new self(
			$definition['slug'],
			isset( $definition['label'] ) ? $definition['label'] : $definition['slug'],
			isset( $definition['description'] ) ? $definition['description'] : '',
			isset( $definition['gated_flags'] ) && is_array( $definition['gated_flags'] ) ? $definition['gated_flags'] : array(),
			isset( $definition['allowed_tool_slugs'] ) && is_array( $definition['allowed_tool_slugs'] ) ? $definition['allowed_tool_slugs'] : array(),
			isset( $definition['required_capability'] ) ? $definition['required_capability'] : '',
			! empty( $definition['is_default'] )
		);
	}

	/**
	 * Get the profile slug.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get the label.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public function get_label() {
		return $this->label;
	}

	/**
	 * Get the description.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Get the gated capability flags.
	 *
	 * @since 2.2.0
	 *
	 * @return array
	 */
	public function get_gated_flags() {
		return $this->gated_flags;
	}

	/**
	 * Get the allowed tool slugs.
	 *
	 * @since 2.2.0
	 *
	 * @return array
	 */
	public function get_allowed_tool_slugs() {
		return $this->allowed_tool_slugs;
	}

	/**
	 * Get the capability required to select the profile.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return $this->required_capability;
	}

	/**
	 * Whether this is the fallback default profile.
	 *
	 * @since 2.2.0
	 *
	 * @return bool
	 */
	public function is_default() {
		return $this->is_default;
	}

	/**
	 * Whether this profile gates (blocks) anything.
	 *
	 * @since 2.2.0
	 *
	 * @return bool
	 */
	public function is_restrictive() {
		return ! empty( $this->gated_flags );
	}

	/**
	 * Whether a tool (by slug + flags) is blocked under this profile.
	 *
	 * @since 2.2.0
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $flags     Tool capability flags.
	 * @return bool True when the tool must be blocked.
	 */
	public function blocks_tool( $tool_slug, array $flags ) {
		if ( ! $this->is_restrictive() ) {
			return false;
		}
		if ( in_array( $tool_slug, $this->allowed_tool_slugs, true ) ) {
			return false;
		}
		return ! empty( array_intersect( $flags, $this->gated_flags ) );
	}

	/**
	 * Serialize for REST / localization.
	 *
	 * @since 2.2.0
	 *
	 * @param bool $selectable Whether the current user may select this profile.
	 * @return array
	 */
	public function to_array( $selectable = true ) {
		return array(
			'slug'        => $this->slug,
			'label'       => $this->label,
			'description' => $this->description,
			'selectable'  => (bool) $selectable,
			'is_default'  => $this->is_default,
		);
	}
}
