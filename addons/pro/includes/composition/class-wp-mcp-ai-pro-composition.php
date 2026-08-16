<?php
/**
 * Composition value object — the resolved "how this agent is built" record
 * (Proposal 029, Phase 5.2).
 *
 * A composition answers three questions for one assistant:
 *   1. Which tools are visible?        (ToolScope over the tool resolver)
 *   2. Which prompt sections apply?    (system prompt + preset sections)
 *   3. Which provider route + guards?  (provider/model + guard slugs)
 *
 * The generation_id is a deterministic fingerprint of the whole
 * composition. Histories (chat transcripts, session logs) record the
 * generation they were produced under, so a later request can prove the
 * toolset has not drifted out from under a conversation. composeFrom()
 * (in the service) binds a child agent to its parent's exact generation,
 * extending the chain instead of re-resolving from scratch — the
 * deepseek-harness "composeFrom" semantics.
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
 * Immutable composition record for one assistant.
 *
 * @since 1.1.57
 */
class WP_MCP_AI_Pro_Composition {

	/**
	 * Source marker: composed directly from the assistant config.
	 */
	const SOURCE_COMPOSED = 'composed';

	/**
	 * Source marker: bound to a parent's generation (composeFrom).
	 */
	const SOURCE_COMPOSED_FROM = 'composed-from';

	/**
	 * Resolved tool scope (null when lib/core is absent).
	 *
	 * @var \Nvoos\Core\Application\Tool\ToolScope|null
	 */
	private $tool_scope;

	/**
	 * Assistant post ID this composition was resolved for.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Deterministic fingerprint of the whole composition.
	 *
	 * @var string
	 */
	private $generation_id;

	/**
	 * Source marker (composed or composed-from).
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Allow-listed tool slugs (restriction input).
	 *
	 * @var string[]
	 */
	private $allow_slugs;

	/**
	 * Deny-listed tool slugs (restriction input).
	 *
	 * @var string[]
	 */
	private $deny_slugs;

	/**
	 * Slugs registered scope-local (restriction-exempt shadowing).
	 *
	 * @var string[]
	 */
	private $scope_local_slugs;

	/**
	 * Guard slugs participating in the tool policy pipeline.
	 *
	 * @var string[]
	 */
	private $guard_slugs;

	/**
	 * Provider route key ('' = site default).
	 *
	 * @var string
	 */
	private $provider;

	/**
	 * Model key ('' = provider default).
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Ordered prompt sections.
	 *
	 * @var array
	 */
	private $prompt_sections;

	/**
	 * Provenance chain of generations, oldest first.
	 *
	 * @var string[]
	 */
	private $generation_chain;

	/**
	 * The generation this composition was bound from, if any.
	 *
	 * @var string|null
	 */
	private $parent_generation_id;

	/**
	 * Constructor.
	 *
	 * Note: plain (untyped) parameters with explicit casts keep this file
	 * PHP 7.4-compatible — the Pro addon supports 7.4 even though the
	 * composition service only activates alongside lib/core (8.1+).
	 *
	 * @param int                                         $assistant_id         Assistant post ID.
	 * @param string                                      $generation_id        Deterministic composition fingerprint.
	 * @param string                                      $source               SOURCE_COMPOSED or SOURCE_COMPOSED_FROM.
	 * @param array                                       $allow_slugs          Allow-listed tool slugs (restriction input).
	 * @param array                                       $deny_slugs           Deny-listed tool slugs (restriction input).
	 * @param array                                       $scope_local_slugs    Slugs registered scope-local (restriction-exempt).
	 * @param array                                       $guard_slugs          Guard slugs participating in the tool policy pipeline.
	 * @param string                                      $provider             Provider route key ('' = site default).
	 * @param string                                      $model                Model key ('' = provider default).
	 * @param array                                       $prompt_sections      Ordered prompt sections: each {role,title,content}.
	 * @param array                                       $generation_chain     Provenance: parent generations, oldest first.
	 * @param string|null                                 $parent_generation_id Generation this composition was bound from, if any.
	 * @param \Nvoos\Core\Application\Tool\ToolScope|null $tool_scope Resolved tool scope.
	 */
	public function __construct(
		$assistant_id,
		$generation_id,
		$source,
		$allow_slugs,
		$deny_slugs,
		$scope_local_slugs,
		$guard_slugs,
		$provider,
		$model,
		$prompt_sections,
		$generation_chain,
		$parent_generation_id = null,
		?\Nvoos\Core\Application\Tool\ToolScope $tool_scope = null
	) {
		$this->assistant_id         = (int) $assistant_id;
		$this->generation_id        = (string) $generation_id;
		$this->source               = (string) $source;
		$this->allow_slugs          = (array) $allow_slugs;
		$this->deny_slugs           = (array) $deny_slugs;
		$this->scope_local_slugs    = (array) $scope_local_slugs;
		$this->guard_slugs          = (array) $guard_slugs;
		$this->provider             = (string) $provider;
		$this->model                = (string) $model;
		$this->prompt_sections      = (array) $prompt_sections;
		$this->generation_chain     = (array) $generation_chain;
		$this->parent_generation_id = null === $parent_generation_id ? null : (string) $parent_generation_id;
		$this->tool_scope           = $tool_scope;
	}

	/**
	 * The resolved tool scope (null when lib/core is absent).
	 *
	 * @return \Nvoos\Core\Application\Tool\ToolScope|null
	 */
	public function tool_scope() {
		return $this->tool_scope;
	}

	/**
	 * Assistant post ID this composition was resolved for.
	 *
	 * @return int
	 */
	public function assistant_id(): int {
		return $this->assistant_id;
	}

	/**
	 * Deterministic fingerprint of the whole composition.
	 *
	 * @return string
	 */
	public function generation_id(): string {
		return $this->generation_id;
	}

	/**
	 * Source marker (composed or composed-from).
	 *
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * The generation this composition was bound from, if any.
	 *
	 * @return string|null
	 */
	public function parent_generation_id() {
		return $this->parent_generation_id;
	}

	/**
	 * Provenance chain of generations, oldest first (excluding this one).
	 *
	 * @return string[]
	 */
	public function generation_chain(): array {
		return $this->generation_chain;
	}

	/**
	 * Allow-listed tool slugs (restriction input, not the resolved view).
	 *
	 * @return string[]
	 */
	public function allow_slugs(): array {
		return $this->allow_slugs;
	}

	/**
	 * Deny-listed tool slugs (restriction input).
	 *
	 * @return string[]
	 */
	public function deny_slugs(): array {
		return $this->deny_slugs;
	}

	/**
	 * Slugs registered scope-local (restriction-exempt shadowing).
	 *
	 * @return string[]
	 */
	public function scope_local_slugs(): array {
		return $this->scope_local_slugs;
	}

	/**
	 * Guard slugs participating in the tool policy pipeline.
	 *
	 * @return string[]
	 */
	public function guard_slugs(): array {
		return $this->guard_slugs;
	}

	/**
	 * Provider route key ('' = site default).
	 *
	 * @return string
	 */
	public function provider(): string {
		return $this->provider;
	}

	/**
	 * Model key ('' = provider default).
	 *
	 * @return string
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Ordered prompt sections.
	 *
	 * @return array<int, array{role: string, title: string, content: string}>
	 */
	public function prompt_sections(): array {
		return $this->prompt_sections;
	}

	/**
	 * Effective tool view: slugs visible through the scope after
	 * restriction intersection. Empty when no scope is available.
	 *
	 * @return string[]
	 */
	public function visible_slugs(): array {
		if ( null === $this->tool_scope ) {
			return array();
		}

		return $this->tool_scope->visibleSlugs();
	}

	/**
	 * Whether this composition is the same generation as the given one.
	 *
	 * @param string $generation_id Generation recorded against a history.
	 * @return bool
	 */
	public function matches_generation( string $generation_id ): bool {
		return hash_equals( $this->generation_id, $generation_id );
	}

	/**
	 * Effective-composition dump (Proposal 029, Phase 5.3) — the
	 * --dump-config equivalent for debugging assistant tool configs.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$sections = array();

		foreach ( $this->prompt_sections as $section ) {
			$sections[] = array(
				'role'          => isset( $section['role'] ) ? (string) $section['role'] : 'system',
				'title'         => isset( $section['title'] ) ? (string) $section['title'] : '',
				'content_hash'  => isset( $section['content'] ) ? hash( 'sha256', (string) $section['content'] ) : '',
				'content_chars' => isset( $section['content'] ) ? strlen( (string) $section['content'] ) : 0,
			);
		}

		$visible = $this->visible_slugs();

		return array(
			'assistant_id'         => $this->assistant_id,
			'source'               => $this->source,
			'generation_id'        => $this->generation_id,
			'parent_generation_id' => $this->parent_generation_id,
			'generation_chain'     => $this->generation_chain,
			'allow_slugs'          => $this->allow_slugs,
			'deny_slugs'           => $this->deny_slugs,
			'scope_local_slugs'    => $this->scope_local_slugs,
			'guard_slugs'          => $this->guard_slugs,
			'provider'             => $this->provider,
			'model'                => $this->model,
			'prompt_sections'      => $sections,
			'visible_slugs'        => $visible,
			'visible_tool_count'   => count( $visible ),
		);
	}
}
