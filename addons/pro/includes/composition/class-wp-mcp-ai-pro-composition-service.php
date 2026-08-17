<?php
/**
 * Composition service — resolves an assistant's effective composition and
 * binds child agents to their parent's exact generation (Proposal 029,
 * Phase 5.2).
 *
 * Two entry points:
 *   - compose():     resolve the assistant config (tool allow/deny lists,
 *                    prompt sections, provider route, guard slugs) into a
 *                    scoped ToolScope + deterministic generation id.
 *   - compose_from(): deepseek-harness "composeFrom" semantics — the child
 *                    scope chains ONTO the parent's resolved scope instead
 *                    of re-resolving its own config from scratch, so the
 *                    restriction intersection narrows the parent's exact
 *                    toolset rather than replacing it. This fixes the
 *                    correctness gap where a delegated child could end up
 *                    with a different toolset than the one its parent's
 *                    history was produced under.
 *
 * Generation ids are deterministic fingerprints (assistant id + tool
 * inputs + prompt hash + provider route + provenance). A history that
 * records its generation can be verified with assert_same_generation().
 *
 * Everything here is read-only against assistant meta — no writes, no
 * request-state mutation — so the service is safe to construct on demand
 * from the CLI, admin screens, or tests.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Composition
 * @since   1.1.57
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and binds assistant compositions.
 *
 * @since 1.1.57
 */
class WP_MCP_AI_Pro_Composition_Service {

	/**
	 * Tool resolver used for fresh (non-bound) scopes.
	 *
	 * Defaults lazily to the legacy-tool resolver so the service covers
	 * the full WP-side tool surface out of the box.
	 *
	 * @var \Nvoos\Core\Domain\Contract\ToolResolverInterface|null
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param \Nvoos\Core\Domain\Contract\ToolResolverInterface|null $resolver Optional resolver override.
	 */
	public function __construct( ?\Nvoos\Core\Domain\Contract\ToolResolverInterface $resolver = null ) {
		$this->resolver = $resolver;
	}

	/**
	 * The tool resolver, lazily defaulted to the legacy adapter.
	 *
	 * @return \Nvoos\Core\Domain\Contract\ToolResolverInterface
	 */
	public function resolver(): \Nvoos\Core\Domain\Contract\ToolResolverInterface {
		if ( null === $this->resolver ) {
			$this->resolver = new WP_MCP_AI_Pro_Legacy_Tool_Resolver();
		}

		return $this->resolver;
	}

	/**
	 * Compose an assistant's effective toolset + config into a generation.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $overrides    Optional overrides: allow, deny, guard_slugs,
	 *                            provider, model, prompt_sections, seed_slugs,
	 *                            local_tools.
	 * @return WP_MCP_AI_Pro_Composition
	 */
	public function compose( int $assistant_id, array $overrides = array() ): WP_MCP_AI_Pro_Composition {
		$config = $this->resolve_config( $assistant_id, $overrides );

		$scope = $this->build_scope( null, $config );

		return new WP_MCP_AI_Pro_Composition(
			$assistant_id,
			self::generation_id( $this->generation_inputs( $config, $assistant_id, 'composed', null ) ),
			WP_MCP_AI_Pro_Composition::SOURCE_COMPOSED,
			$config['allow'],
			$config['deny'],
			$config['local_slugs'],
			$config['guards'],
			$config['provider'],
			$config['model'],
			$this->prompt_sections_for( $config ),
			array(),
			null,
			$scope
		);
	}

	/**
	 * Bind a child agent to its parent's exact composition generation.
	 *
	 * The child scope chains onto the parent's resolved scope: inherited
	 * tools are exactly the parent's visible set, further narrowed by the
	 * child's own restriction inputs. Scope-local child tools shadow
	 * freely. The child's generation id derives from the parent's, so a
	 * history produced under the parent remains attributable through the
	 * chain.
	 *
	 * @param WP_MCP_AI_Pro_Composition $parent_composition Parent composition.
	 * @param int                       $child_assistant_id Child assistant post ID.
	 * @param array                     $overrides          Optional overrides.
	 * @return WP_MCP_AI_Pro_Composition
	 */
	public function compose_from( WP_MCP_AI_Pro_Composition $parent_composition, int $child_assistant_id, array $overrides = array() ): WP_MCP_AI_Pro_Composition {
		$overrides = apply_filters( 'wp_mcp_ai_pro_compose_from_overrides', $overrides, $child_assistant_id, $parent_composition );

		$config = $this->resolve_config( $child_assistant_id, $overrides );

		$base_scope = $parent_composition->tool_scope();

		if ( null === $base_scope ) {
			// Degraded mode (lib/core absent): approximate the parent's
			// toolset from its restriction inputs on the shared resolver,
			// then intersect the child inputs on top.
			$base_scope = new \Nvoos\Core\Application\Tool\ToolScope(
				$this->resolver(),
				array_unique( array_merge( $parent_composition->allow_slugs(), $parent_composition->deny_slugs() ) )
			);

			$parent_restriction = $this->restriction_for_inputs( $parent_composition->allow_slugs(), $parent_composition->deny_slugs() );

			if ( null !== $parent_restriction ) {
				$base_scope->restrict( $parent_restriction );
			}
		}

		$scope = new \Nvoos\Core\Application\Tool\ToolScope(
			$base_scope,
			array_unique( array_merge( $config['allow'], $config['deny'] ) )
		);

		$restriction = $this->restriction_for( $config );

		if ( null !== $restriction ) {
			$scope->restrict( $restriction );
		}

		foreach ( $config['local_tools'] as $tool ) {
			$scope->register( $tool );
		}

		$chain   = $parent_composition->generation_chain();
		$chain[] = $parent_composition->generation_id();

		return new WP_MCP_AI_Pro_Composition(
			$child_assistant_id,
			self::generation_id( $this->generation_inputs( $config, $child_assistant_id, 'composed-from', $parent_composition->generation_id() ) ),
			WP_MCP_AI_Pro_Composition::SOURCE_COMPOSED_FROM,
			$config['allow'],
			$config['deny'],
			$config['local_slugs'],
			$config['guards'],
			$config['provider'],
			$config['model'],
			$this->prompt_sections_for( $config ),
			$chain,
			$parent_composition->generation_id(),
			$scope
		);
	}

	/**
	 * Effective-composition dump (Phase 5.3).
	 *
	 * @param WP_MCP_AI_Pro_Composition $composition Composition to dump.
	 * @return array<string, mixed>
	 */
	public function effective( WP_MCP_AI_Pro_Composition $composition ): array {
		return $composition->to_array();
	}

	/**
	 * Verify a history's recorded generation against a composition.
	 *
	 * @param WP_MCP_AI_Pro_Composition $composition           Current composition.
	 * @param string                    $history_generation_id Generation the history was produced under.
	 * @return bool True when the toolset has not drifted.
	 */
	public function assert_same_generation( WP_MCP_AI_Pro_Composition $composition, string $history_generation_id ): bool {
		return $composition->matches_generation( $history_generation_id );
	}

	/**
	 * Deterministic fingerprint for a canonical input array.
	 *
	 * Keys and nested arrays are sorted before hashing so semantically
	 * identical inputs always produce the same id.
	 *
	 * @param array $inputs Inputs to fingerprint.
	 * @return string "gen_" + 20 hex chars.
	 */
	public static function generation_id( array $inputs ): string {
		$canonical = self::canonicalize( $inputs );

		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $canonical ) : json_encode( $canonical ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode preferred when WP is loaded; plain json_encode is the intentional fallback for non-WP contexts.

		return 'gen_' . substr( hash( 'sha256', (string) $json ), 0, 20 );
	}

	/**
	 * Sort an array recursively for canonical serialization.
	 *
	 * @param array $inputs Inputs.
	 * @return array
	 */
	private static function canonicalize( array $inputs ): array {
		ksort( $inputs );

		foreach ( $inputs as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = array_values( array_unique( $value ) );
				sort( $value );
				$inputs[ $key ] = self::canonicalize( $value );
			}
		}

		return $inputs;
	}

	/**
	 * Resolve assistant meta + overrides into a normalized config.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $overrides    Overrides.
	 * @return array{allow: array, deny: array, guards: array, provider: string, model: string, prompt: string, prompt_sections: array, seed_slugs: array, local_tools: array, local_slugs: array}
	 */
	private function resolve_config( int $assistant_id, array $overrides ): array {
		$allow_meta  = get_post_meta( $assistant_id, '_wp_mcp_ai_tools', true );
		$deny_meta   = get_post_meta( $assistant_id, '_wp_mcp_ai_denied_tools', true );
		$guards_meta = get_post_meta( $assistant_id, '_wp_mcp_ai_guard_slugs', true );
		$prompt_meta = (string) get_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', true );
		$provider    = (string) get_post_meta( $assistant_id, '_wp_mcp_ai_provider', true );
		$model       = (string) get_post_meta( $assistant_id, '_wp_mcp_ai_model', true );

		$config = array(
			'allow'           => isset( $overrides['allow'] ) && is_array( $overrides['allow'] ) ? $overrides['allow'] : $this->normalize_slugs( $allow_meta ),
			'deny'            => isset( $overrides['deny'] ) && is_array( $overrides['deny'] ) ? $overrides['deny'] : $this->normalize_slugs( $deny_meta ),
			'guards'          => isset( $overrides['guard_slugs'] ) && is_array( $overrides['guard_slugs'] ) ? $overrides['guard_slugs'] : $this->normalize_slugs( $guards_meta ),
			'provider'        => isset( $overrides['provider'] ) ? (string) $overrides['provider'] : $provider,
			'model'           => isset( $overrides['model'] ) ? (string) $overrides['model'] : $model,
			'prompt'          => isset( $overrides['prompt'] ) ? (string) $overrides['prompt'] : $prompt_meta,
			'prompt_sections' => isset( $overrides['prompt_sections'] ) && is_array( $overrides['prompt_sections'] ) ? $overrides['prompt_sections'] : array(),
			'seed_slugs'      => isset( $overrides['seed_slugs'] ) && is_array( $overrides['seed_slugs'] ) ? $overrides['seed_slugs'] : array(),
			'local_tools'     => isset( $overrides['local_tools'] ) && is_array( $overrides['local_tools'] ) ? $overrides['local_tools'] : array(),
		);

		$config = apply_filters( 'wp_mcp_ai_pro_composition_config', $config, $assistant_id, $overrides );

		$config['allow']  = $this->normalize_slugs( $config['allow'] );
		$config['deny']   = $this->normalize_slugs( $config['deny'] );
		$config['guards'] = $this->normalize_slugs( $config['guards'] );

		$local_slugs = array();

		foreach ( $config['local_tools'] as $tool ) {
			if ( $tool instanceof \Nvoos\Core\Domain\Contract\ToolInterface ) {
				$local_slugs[] = $tool->getSlug();
			}
		}

		$config['local_slugs'] = $this->normalize_slugs( $local_slugs );
		$config['local_tools'] = array_values(
			array_filter(
				$config['local_tools'],
				static function ( $tool ): bool {
					return $tool instanceof \Nvoos\Core\Domain\Contract\ToolInterface;
				}
			)
		);

		return $config;
	}

	/**
	 * Normalize an arbitrary meta value into a sorted, unique slug list.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string[]
	 */
	private function normalize_slugs( $value ): array {
		if ( ! is_array( $value ) ) {
			if ( is_string( $value ) && '' !== $value ) {
				// Registered array metas may be stored JSON-encoded;
				// legacy storage may be PHP-serialized. Accept both.
				$value = maybe_unserialize( $value );

				if ( ! is_array( $value ) ) {
					$decoded = json_decode( $value, true );
					$value   = is_array( $decoded ) ? $decoded : array();
				}
			} else {
				$value = array();
			}
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$slugs = array();

		foreach ( $value as $slug ) {
			$slug = trim( (string) $slug );

			if ( '' !== $slug ) {
				$slugs[] = sanitize_key( $slug );
			}
		}

		$slugs = array_unique( array_filter( $slugs ) );
		sort( $slugs );

		return array_values( $slugs );
	}

	/**
	 * Build a fresh scope over the resolver with the config's restriction.
	 *
	 * @param \Nvoos\Core\Application\Tool\ToolScope|null $base_scope Base scope to chain onto (composeFrom), or null.
	 * @param array                                       $config     Resolved config.
	 * @return \Nvoos\Core\Application\Tool\ToolScope
	 */
	private function build_scope( ?\Nvoos\Core\Application\Tool\ToolScope $base_scope, array $config ): \Nvoos\Core\Application\Tool\ToolScope {
		$seed = array_unique( array_merge( $config['allow'], $config['deny'], $config['seed_slugs'] ) );

		$parent = $base_scope ?? $this->resolver();

		$scope = new \Nvoos\Core\Application\Tool\ToolScope( $parent, array_values( $seed ) );

		$restriction = $this->restriction_for( $config );

		if ( null !== $restriction ) {
			$scope->restrict( $restriction );
		}

		foreach ( $config['local_tools'] as $tool ) {
			$scope->register( $tool );
		}

		return $scope;
	}

	/**
	 * Build the ToolRestriction for a config, if any inputs exist.
	 *
	 * @param array $config Resolved config.
	 * @return \Nvoos\Core\Domain\ValueObject\ToolRestriction|null
	 */
	private function restriction_for( array $config ) {
		return $this->restriction_for_inputs( $config['allow'], $config['deny'] );
	}

	/**
	 * Build the ToolRestriction for raw allow/deny inputs, if any.
	 *
	 * @param array $allow Allow slugs.
	 * @param array $deny  Deny slugs.
	 * @return \Nvoos\Core\Domain\ValueObject\ToolRestriction|null
	 */
	private function restriction_for_inputs( array $allow, array $deny ) {
		$allow = $this->normalize_slugs( $allow );
		$deny  = $this->normalize_slugs( $deny );

		if ( array() === $allow && array() === $deny ) {
			return null;
		}

		return new \Nvoos\Core\Domain\ValueObject\ToolRestriction(
			array() === $allow ? null : $allow,
			$deny
		);
	}

	/**
	 * Prompt sections: system prompt first, then override sections.
	 *
	 * @param array $config Resolved config.
	 * @return array<int, array{role: string, title: string, content: string}>
	 */
	private function prompt_sections_for( array $config ): array {
		$sections = array();

		if ( '' !== $config['prompt'] ) {
			$sections[] = array(
				'role'    => 'system',
				'title'   => 'system_prompt',
				'content' => $config['prompt'],
			);
		}

		foreach ( $config['prompt_sections'] as $section ) {
			if ( is_array( $section ) && isset( $section['content'] ) ) {
				$sections[] = array(
					'role'    => isset( $section['role'] ) ? (string) $section['role'] : 'system',
					'title'   => isset( $section['title'] ) ? (string) $section['title'] : '',
					'content' => (string) $section['content'],
				);
			}
		}

		return $sections;
	}

	/**
	 * Generation fingerprint inputs for a config.
	 *
	 * @param array       $config            Resolved config.
	 * @param int         $assistant_id      Assistant post ID.
	 * @param string      $source            Source marker.
	 * @param string|null $parent_generation Parent generation id for bound compositions.
	 * @return array<string, mixed>
	 */
	private function generation_inputs( array $config, int $assistant_id, string $source, ?string $parent_generation ): array {
		$inputs = array(
			'source'       => $source,
			'assistant_id' => $assistant_id,
			'allow'        => $config['allow'],
			'deny'         => $config['deny'],
			'guards'       => $config['guards'],
			'provider'     => $config['provider'],
			'model'        => $config['model'],
			'prompt_hash'  => '' !== $config['prompt'] ? hash( 'sha256', $config['prompt'] ) : '',
			'local_slugs'  => $config['local_slugs'],
		);

		if ( null !== $parent_generation ) {
			$inputs['parent_generation'] = $parent_generation;
		}

		return $inputs;
	}
}
