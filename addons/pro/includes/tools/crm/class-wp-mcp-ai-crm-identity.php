<?php
/**
 * CRM Toolkit Identity Helpers
 *
 * Deduplication and canonicalization helpers for CRM records.
 *
 * Provides the identity primitives the create/update lead tools need to
 * refuse duplicates with a pointer to the existing record (JobNavigator's
 * one-application-per-job 409 pattern) and to link leads to canonical
 * company profiles (JobNavigator's company_canonical aliasing).
 *
 * @package WP_MCP_AI_Pro
 * @since   3.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM identity helpers.
 *
 * @since 3.2.0
 */
class WP_MCP_AI_CRM_Identity {

	/**
	 * Lead meta key holding the linked company post ID.
	 *
	 * @var string
	 */
	const META_COMPANY_ID = 'company_id';

	/**
	 * Company meta key holding the canonical (lowercased, whitespace-collapsed) name.
	 *
	 * @var string
	 */
	const META_COMPANY_CANONICAL = '_company_name_canonical';

	/**
	 * Normalize an email address for identity comparison.
	 *
	 * @param string $email Raw email.
	 * @return string Lowercased, trimmed email.
	 */
	public static function normalize_email( $email ) {
		return strtolower( trim( (string) sanitize_email( $email ) ) );
	}

	/**
	 * Canonicalize a company name.
	 *
	 * Lowercases, trims, and collapses internal whitespace so
	 * "ACME Corporation" and "acme   corporation" match.
	 *
	 * @param string $name Raw company name.
	 * @return string Canonical name (empty when input is empty).
	 */
	public static function canonical_company_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		return strtolower( preg_replace( '/\s+/', ' ', $name ) );
	}

	/**
	 * Find a lead by normalized email address.
	 *
	 * @param string $email Raw email.
	 * @return int Lead post ID or 0 when not found.
	 */
	public static function find_lead_by_email( $email ) {
		$email = self::normalize_email( $email );
		if ( '' === $email ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'        => 'mcp_ai_lead',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional identity lookup on indexed-ish email meta.
					array(
						'key'     => 'email',
						'value'   => $email,
						'compare' => '=',
					),
				),
			)
		);

		return ! empty( $posts ) ? absint( $posts[0] ) : 0;
	}

	/**
	 * Find a company by canonical name.
	 *
	 * Compares against the stored `_company_name_canonical` meta and falls
	 * back to a canonicalized title comparison so records created before the
	 * canonical meta existed still match.
	 *
	 * @param string $name Raw company name.
	 * @return int Company post ID or 0 when not found.
	 */
	public static function find_company_by_canonical_name( $name ) {
		$canonical = self::canonical_company_name( $name );
		if ( '' === $canonical ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'        => 'mcp_ai_company',
				'post_status'      => 'publish',
				'posts_per_page'   => 10,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional canonical lookup.
					array(
						'key'     => self::META_COMPANY_CANONICAL,
						'value'   => $canonical,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! empty( $posts ) ) {
			return absint( $posts[0] );
		}

		// Fallback: canonicalized title comparison for legacy records created
		// before the canonical meta existed.
		$legacy = get_posts(
			array(
				'post_type'        => 'mcp_ai_company',
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		foreach ( $legacy as $post_id ) {
			if ( self::canonical_company_name( get_the_title( $post_id ) ) === $canonical ) {
				return absint( $post_id );
			}
		}

		return 0;
	}

	/**
	 * Link a lead to a company profile, creating one when allowed.
	 *
	 * Resolution order:
	 * 1. Explicit company name → canonical lookup → link.
	 * 2. No match and `identity.auto_create_company` enabled → create a
	 *    prospect company and link.
	 * 3. No company name and `identity.auto_create_company_from_domain`
	 *    enabled → derive a name from the email domain, then step 1–2.
	 *
	 * @since 3.2.0
	 *
	 * @param int    $lead_id      Lead post ID.
	 * @param string $company_name Optional explicit company name.
	 * @param string $email        Lead email (used for domain fallback).
	 * @return int Linked company post ID or 0 when no link was made.
	 */
	public static function link_or_create_company( $lead_id, $company_name, $email = '' ) {
		$lead_id = absint( $lead_id );
		if ( ! $lead_id ) {
			return 0;
		}

		$settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();

		$identity          = isset( $settings['identity'] ) && is_array( $settings['identity'] ) ? $settings['identity'] : array();
		$canonical_enabled = array_key_exists( 'canonical_company_names', $identity ) ? (bool) $identity['canonical_company_names'] : true;
		$auto_create       = array_key_exists( 'auto_create_company', $identity ) ? (bool) $identity['auto_create_company'] : true;
		$auto_from_domain  = array_key_exists( 'auto_create_company_from_domain', $identity ) ? (bool) $identity['auto_create_company_from_domain'] : false;

		$company_name = trim( (string) $company_name );

		// Domain fallback when no explicit name was provided.
		if ( '' === $company_name && $auto_from_domain && ! empty( $email ) ) {
			$domain = strtolower( trim( (string) $email ) );
			$at_pos = strrpos( $domain, '@' );
			if ( false !== $at_pos ) {
				$domain = substr( $domain, $at_pos + 1 );
			}
			$company_name = ucfirst( preg_replace( '/\..*$/', '', $domain ) );
		}

		if ( '' === $company_name ) {
			return 0;
		}

		$company_id = $canonical_enabled ? self::find_company_by_canonical_name( $company_name ) : 0;

		// Auto-create when no canonical match and creation is enabled.
		if ( ! $company_id && $auto_create ) {
			$company_id = self::create_company( $company_name );
		}

		if ( ! $company_id ) {
			return 0;
		}

		update_post_meta( $lead_id, self::META_COMPANY_ID, $company_id );

		return $company_id;
	}

	/**
	 * Create a prospect company record with the canonical name meta.
	 *
	 * @param string $company_name Display name.
	 * @return int Company post ID or 0 on failure.
	 */
	private static function create_company( $company_name ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_company',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $company_name ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, self::META_COMPANY_CANONICAL, self::canonical_company_name( $company_name ) );
		update_post_meta( $post_id, '_company_status', 'prospect' );

		return absint( $post_id );
	}
}
