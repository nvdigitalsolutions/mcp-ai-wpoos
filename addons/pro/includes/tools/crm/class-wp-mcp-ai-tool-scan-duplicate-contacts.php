<?php
/**
 * Tool for scanning the CRM for potential duplicate contacts.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans CRM contacts for potential duplicates.
 *
 * Compares contacts by email, phone, or name similarity using heuristic
 * matching to identify potential duplicate records. Returns grouped
 * results with similarity scores.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Scan_Duplicate_Contacts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'scan_duplicate_contacts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Scan Duplicate Contacts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Scans the CRM for potential duplicate contacts based on email, phone, or name similarity. Returns grouped duplicates with match confidence scores to help identify and merge redundant records.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'match_field'    => array(
					'type'        => 'string',
					'description' => __( 'Field to use for duplicate detection. Default: all.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'email', 'phone', 'name', 'all' ),
					'default'     => 'all',
				),
				'min_similarity' => array(
					'type'        => 'integer',
					'description' => __( 'Minimum similarity percentage (0-100) to consider a match. Default: 80.', 'mcp-ai-wpoos-pro' ),
					'default'     => 80,
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of duplicate groups to return. Default: 200.', 'mcp-ai-wpoos-pro' ),
					'default'     => 200,
					'minimum'     => 1,
					'maximum'     => 1000,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'post_type'             => 'mcp_ai_lead',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'sales_ops', 'data_manager' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
			'cacheable',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the CRM Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.9.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Scan Duplicate Contacts tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Duplicate scan results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'CRM Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$match_field    = isset( $arguments['match_field'] ) ? sanitize_text_field( $arguments['match_field'] ) : 'all';
		$min_similarity = isset( $arguments['min_similarity'] ) ? absint( $arguments['min_similarity'] ) : 80;
		$min_similarity = max( 0, min( 100, $min_similarity ) );
		$limit          = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 200;
		$limit          = min( max( $limit, 1 ), 1000 );

		$contacts = $this->get_all_contacts();
		$duplicates = array();

		if ( 'all' === $match_field || 'email' === $match_field ) {
			$email_dupes = $this->scan_by_field( $contacts, 'email', $min_similarity );
			$duplicates  = array_merge( $duplicates, $email_dupes );
		}

		if ( 'all' === $match_field || 'phone' === $match_field ) {
			$phone_dupes = $this->scan_by_field( $contacts, 'phone', $min_similarity );
			$duplicates  = array_merge( $duplicates, $phone_dupes );
		}

		if ( 'all' === $match_field || 'name' === $match_field ) {
			$name_dupes = $this->scan_by_name( $contacts, $min_similarity );
			$duplicates = array_merge( $duplicates, $name_dupes );
		}

		// Deduplicate the duplicate groups by contact ID pairs.
		$duplicates = $this->deduplicate_groups( $duplicates );

		// Limit results.
		$duplicates = array_slice( $duplicates, 0, $limit );

		return array(
			'success'           => true,
			'message'           => sprintf(
				/* translators: %d: number of duplicate groups found */
				__( 'Found %d potential duplicate groups.', 'mcp-ai-wpoos-pro' ),
				count( $duplicates )
			),
			'match_field'       => $match_field,
			'min_similarity'    => $min_similarity,
			'total_contacts'    => count( $contacts ),
			'duplicate_groups'  => count( $duplicates ),
			'duplicates'        => $duplicates,
		);
	}

	/**
	 * Get all CRM contacts (leads and customers).
	 *
	 * @since 2.9.0
	 * @return array Array of contact data arrays.
	 */
	private function get_all_contacts() {
		$contacts = array();

		$query = new WP_Query(
			array(
				'post_type'      => array( 'mcp_ai_lead', 'mcp_ai_customer' ),
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$contacts[] = array(
					'id'    => $post->ID,
					'type'  => $post->post_type,
					'title' => get_the_title( $post ),
					'email' => $this->normalize_email( get_post_meta( $post->ID, '_contact_email', true ) ),
					'phone' => $this->normalize_phone( get_post_meta( $post->ID, '_contact_phone', true ) ),
				);
			}
		}

		wp_reset_postdata();
		return $contacts;
	}

	/**
	 * Scan for duplicates by a specific field (email or phone).
	 *
	 * @since 2.9.0
	 * @param array  $contacts       Contact data array.
	 * @param string $field          Field to match on.
	 * @param int    $min_similarity Minimum similarity threshold.
	 * @return array Duplicate groups.
	 */
	private function scan_by_field( $contacts, $field, $min_similarity ) {
		$duplicates = array();
		$indexed    = array();

		// Index contacts by normalized field value.
		foreach ( $contacts as $contact ) {
			$value = $contact[ $field ];
			if ( empty( $value ) ) {
				continue;
			}
			if ( ! isset( $indexed[ $value ] ) ) {
				$indexed[ $value ] = array();
			}
			$indexed[ $value ][] = $contact;
		}

		// Find groups with more than one contact.
		foreach ( $indexed as $value => $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			$duplicates[] = array(
				'match_field' => $field,
				'match_value' => $field === 'email' ? $value : $this->mask_phone( $value ),
				'similarity'  => 100, // Exact match on normalized value.
				'contacts'    => array_map( function ( $c ) {
					return array(
						'id'    => $c['id'],
						'type'  => $c['type'],
						'title' => $c['title'],
					);
				}, $group ),
			);
		}

		return $duplicates;
	}

	/**
	 * Scan for duplicates by name similarity.
	 *
	 * @since 2.9.0
	 * @param array $contacts       Contact data array.
	 * @param int   $min_similarity Minimum similarity threshold (0-100).
	 * @return array Duplicate groups.
	 */
	private function scan_by_name( $contacts, $min_similarity ) {
		$duplicates = array();
		$seen_pairs = array();

		$count = count( $contacts );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$name_a = strtolower( trim( $contacts[ $i ]['title'] ) );
				$name_b = strtolower( trim( $contacts[ $j ]['title'] ) );

				if ( empty( $name_a ) || empty( $name_b ) ) {
					continue;
				}

				$pair_key = min( $contacts[ $i ]['id'], $contacts[ $j ]['id'] ) . '_' . max( $contacts[ $i ]['id'], $contacts[ $j ]['id'] );
				if ( isset( $seen_pairs[ $pair_key ] ) ) {
					continue;
				}
				$seen_pairs[ $pair_key ] = true;

				$similarity = $this->calculate_name_similarity( $name_a, $name_b );

				if ( $similarity >= $min_similarity ) {
					$duplicates[] = array(
						'match_field' => 'name',
						'match_value' => $name_a . ' ↔ ' . $name_b,
						'similarity'  => $similarity,
						'contacts'    => array(
							array(
								'id'    => $contacts[ $i ]['id'],
								'type'  => $contacts[ $i ]['type'],
								'title' => $contacts[ $i ]['title'],
							),
							array(
								'id'    => $contacts[ $j ]['id'],
								'type'  => $contacts[ $j ]['type'],
								'title' => $contacts[ $j ]['title'],
							),
						),
					);
				}
			}
		}

		return $duplicates;
	}

	/**
	 * Calculate similarity between two name strings.
	 *
	 * Uses a simple character-level similarity algorithm.
	 *
	 * @since 2.9.0
	 * @param string $a First string.
	 * @param string $b Second string.
	 * @return int Similarity percentage (0-100).
	 */
	private function calculate_name_similarity( $a, $b ) {
		if ( $a === $b ) {
			return 100;
		}

		// Levenshtein-based similarity.
		$max_len  = max( strlen( $a ), strlen( $b ) );
		if ( 0 === $max_len ) {
			return 100;
		}

		$levenshtein = levenshtein( $a, $b );
		return (int) round( ( 1 - ( $levenshtein / $max_len ) ) * 100 );
	}

	/**
	 * Deduplicate groups by removing overlapping contact pairs.
	 *
	 * @since 2.9.0
	 * @param array $groups Duplicate groups.
	 * @return array Deduplicated groups.
	 */
	private function deduplicate_groups( $groups ) {
		$seen_pairs = array();
		$unique     = array();

		foreach ( $groups as $group ) {
			if ( count( $group['contacts'] ) < 2 ) {
				continue;
			}

			$ids = array_column( $group['contacts'], 'id' );
			sort( $ids );
			$pair_key = implode( '_', $ids );

			if ( isset( $seen_pairs[ $pair_key ] ) ) {
				continue;
			}
			$seen_pairs[ $pair_key ] = true;
			$unique[] = $group;
		}

		return $unique;
	}

	/**
	 * Normalize an email address for comparison.
	 *
	 * @since 2.9.0
	 * @param string $email Raw email.
	 * @return string Normalized email or empty string.
	 */
	private function normalize_email( $email ) {
		$email = trim( strtolower( $email ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Normalize a phone number for comparison (strip non-digits).
	 *
	 * @since 2.9.0
	 * @param string $phone Raw phone number.
	 * @return string Digits-only phone or empty string.
	 */
	private function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D/', '', trim( $phone ) );
		return strlen( $digits ) >= 7 ? $digits : '';
	}

	/**
	 * Mask a phone number for safe display.
	 *
	 * @since 2.9.0
	 * @param string $phone Digits-only phone.
	 * @return string Partially masked phone.
	 */
	private function mask_phone( $phone ) {
		$len = strlen( $phone );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return str_repeat( '*', $len - 4 ) . substr( $phone, -4 );
	}
}
