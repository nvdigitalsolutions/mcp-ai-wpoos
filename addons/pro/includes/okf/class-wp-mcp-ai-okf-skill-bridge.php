<?php
/**
 * OKF → Skill Bridge (Pro).
 *
 * Resolves skill-load requests shaped `bundle:concept_id` into OKF concepts
 * and returns them skill-shaped to the Base `load_skill` tool. Enforces:
 *  - assistant allow-lists (post meta `_wp_mcp_ai_okf_concepts`),
 *  - lifecycle gating (draft concepts are never loadable),
 *  - optional minimum trust-tier gating (filter),
 *  - path/traversal safety via the Base OKF Bundle Manager + Reader.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.62
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges OKF concepts into the skill loader.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_OKF_Skill_Bridge {

	/**
	 * Post meta key holding an assistant's granted OKF concepts.
	 *
	 * Values are `bundle:concept_id` strings.
	 *
	 * @since 1.1.62
	 * @var string
	 */
	const META_GRANTS = '_wp_mcp_ai_okf_concepts';

	/**
	 * Register the load-skill filter.
	 *
	 * @since 1.1.62
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_mcp_ai_load_skill_external', array( __CLASS__, 'resolve' ), 10, 3 );
	}

	/**
	 * Resolve an OKF-shaped skill name.
	 *
	 * @param array|WP_Error|null $skill        Upstream resolution (defer when non-null).
	 * @param string              $name         Requested skill name.
	 * @param int                 $assistant_id Owning assistant post id (0 when none).
	 * @return array|WP_Error|null Skill-shaped array, WP_Error, or null to defer.
	 */
	public static function resolve( $skill, $name, $assistant_id ) {
		if ( null !== $skill ) {
			return $skill; // Respect earlier sources.
		}

		if ( ! class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			return null;
		}

		list( $bundle, $concept_id ) = self::parse_name( (string) $name );
		if ( '' === $bundle ) {
			return null; // Not OKF-shaped; defer to the skill registry.
		}

		// Allow-list: OKF concepts load only when the assistant has been
		// granted them explicitly (fail-closed, mirroring the skills allow-list).
		if ( ! $assistant_id ) {
			return new WP_Error(
				'wp_mcp_ai_okf_concept_no_assistant',
				__( 'OKF concepts can only be loaded within an assistant context.', 'mcp-ai-wpoos-pro' )
			);
		}

		$grants = get_post_meta( $assistant_id, self::META_GRANTS, true );
		if ( ! is_array( $grants ) || ! in_array( $bundle . ':' . $concept_id, $grants, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_okf_concept_not_assigned',
				sprintf(
					/* translators: %s: bundle:concept reference */
					__( 'The OKF concept "%s" is not assigned to this assistant.', 'mcp-ai-wpoos-pro' ),
					$bundle . ':' . $concept_id
				),
				array( 'status' => 403 )
			);
		}

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$root    = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$reader  = new WP_MCP_AI_OKF_Reader( $root );
		$concept = $reader->get_concept( $concept_id );
		if ( is_wp_error( $concept ) ) {
			return $concept;
		}

		$fm = $concept['frontmatter'];

		// Lifecycle gate: drafts are work-in-progress and never loadable.
		$status = isset( $fm['status'] ) ? strtolower( (string) $fm['status'] ) : 'stable';
		if ( 'draft' === $status ) {
			return new WP_Error(
				'wp_mcp_ai_okf_concept_draft',
				sprintf(
					/* translators: %s: bundle:concept reference */
					__( 'The OKF concept "%s" is a draft and cannot be loaded as a skill.', 'mcp-ai-wpoos-pro' ),
					$bundle . ':' . $concept_id
				)
			);
		}

		$trust_tier = $reader->get_trust_tier( $fm );

		/**
		 * Filter the minimum trust tier an OKF concept needs to be loadable
		 * as a skill. Empty string disables tier gating (drafts stay blocked).
		 * Valid values: '' (default), 'machine-confirmed', 'human-reviewed'.
		 *
		 * @since 1.1.62
		 *
		 * @param string $min_trust Minimum trust tier ('' = no gating).
		 */
		$min_trust = apply_filters( 'wp_mcp_ai_okf_skill_bridge_min_trust', '' );
		if ( '' !== $min_trust ) {
			$ranks     = array(
				'unverified'        => 0,
				'machine-confirmed' => 1,
				'human-reviewed'    => 2,
			);
			$tier_rank = isset( $ranks[ $trust_tier ] ) ? $ranks[ $trust_tier ] : 0;
			$min_rank  = isset( $ranks[ $min_trust ] ) ? $ranks[ $min_trust ] : 0;
			if ( $tier_rank < $min_rank ) {
				return new WP_Error(
					'wp_mcp_ai_okf_concept_untrusted',
					sprintf(
						/* translators: 1: bundle:concept reference, 2: trust tier, 3: required tier */
						__( 'The OKF concept "%1$s" is %2$s; at least %3$s is required to load it as a skill.', 'mcp-ai-wpoos-pro' ),
						$bundle . ':' . $concept_id,
						$trust_tier,
						$min_trust
					)
				);
			}
		}

		$title       = isset( $fm['title'] ) ? (string) $fm['title'] : $concept_id;
		$description = isset( $fm['description'] ) ? (string) $fm['description'] : '';

		// Skill-shaped payload with a provenance banner so the model can see
		// where the instructions came from and how much to trust them.
		$banner = sprintf(
			/* translators: 1: bundle:concept reference, 2: type, 3: status, 4: trust tier */
			__( '[OKF concept: %1$s | type: %2$s | status: %3$s | trust: %4$s]', 'mcp-ai-wpoos-pro' ) . "\n\n",
			$bundle . ':' . $concept_id,
			isset( $fm['type'] ) ? (string) $fm['type'] : '',
			$status,
			$trust_tier
		);

		return array(
			'name'         => $bundle . ':' . $concept_id,
			'description'  => '' !== $description ? $description : $title,
			'instructions' => $banner . $concept['body'],
			'source'       => 'okf',
		);
	}

	/**
	 * Parse a skill name into bundle + concept ID.
	 *
	 * @param string $name Skill name.
	 * @return array{0: string, 1: string} Bundle and concept ID ('' bundle = not OKF-shaped).
	 */
	private static function parse_name( $name ) {
		$colon = strpos( $name, ':' );

		if ( false === $colon || 0 === $colon || strlen( $name ) - 1 === $colon ) {
			return array( '', '' );
		}

		return array( substr( $name, 0, $colon ), substr( $name, $colon + 1 ) );
	}
}
