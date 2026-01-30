<?php
/**
 * Tool for checking product compliance in the Regulatory Registration system.
 *
 * Allows AI assistants to validate products against country requirements.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks product compliance against regulatory requirements.
 */
class WP_MCP_AI_Tool_Check_Product_Compliance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_product_compliance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Product Compliance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates a product against regulatory requirements for a specific country. Checks documents, tests, certifications, and ingredient restrictions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Product ID to check (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'country'           => array(
					'type'        => 'string',
					'description' => __( 'Country code to check compliance for (required)', 'mcp-ai-wpoos-pro' ),
				),
				'check_documents'   => array(
					'type'        => 'boolean',
					'description' => __( 'Check document requirements (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'check_ingredients' => array(
					'type'        => 'boolean',
					'description' => __( 'Check ingredient restrictions (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'product_id', 'country' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
			'idempotent',           // Can be called multiple times safely with same result.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( $arguments, $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['product_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Product ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( empty( $arguments['country'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Country is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$product_id = absint( $arguments['product_id'] );
		$country    = sanitize_text_field( $arguments['country'] );

		// Verify product exists.
		$product = get_post( $product_id );
		if ( ! $product || 'mcp_ai_reg_product' !== $product->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Product not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get product category.
		$categories       = wp_get_object_terms( $product_id, 'mcp_ai_reg_category', array( 'fields' => 'names' ) );
		$product_category = ! empty( $categories ) ? $categories[0] : '';

		// Get all requirements for this country.
		$requirements = $this->get_requirements( $country, $product_category );

		// Initialize compliance checks.
		$compliance_issues = array();
		$passed_checks     = array();

		// Check document requirements if enabled.
		if ( ! empty( $arguments['check_documents'] ) ) {
			$doc_results       = $this->check_document_compliance( $product_id, $requirements );
			$compliance_issues = array_merge( $compliance_issues, $doc_results['issues'] );
			$passed_checks     = array_merge( $passed_checks, $doc_results['passed'] );
		}

		// Check ingredient restrictions if enabled.
		if ( ! empty( $arguments['check_ingredients'] ) ) {
			$ingredient_results = $this->check_ingredient_compliance( $product_id, $requirements );
			$compliance_issues  = array_merge( $compliance_issues, $ingredient_results['issues'] );
			$passed_checks      = array_merge( $passed_checks, $ingredient_results['passed'] );
		}

		// Calculate compliance score.
		$total_checks     = count( $compliance_issues ) + count( $passed_checks );
		$compliance_score = $total_checks > 0 ? round( ( count( $passed_checks ) / $total_checks ) * 100, 2 ) : 0;

		// Determine overall status.
		$is_compliant = empty( $compliance_issues );
		$status       = $is_compliant ? 'compliant' : ( $compliance_score >= 50 ? 'partially_compliant' : 'non_compliant' );

		return array(
			'success'             => true,
			'product_id'          => $product_id,
			'country'             => $country,
			'is_compliant'        => $is_compliant,
			'status'              => $status,
			'compliance_score'    => $compliance_score,
			'total_checks'        => $total_checks,
			'passed_checks'       => count( $passed_checks ),
			'failed_checks'       => count( $compliance_issues ),
			'compliance_issues'   => $compliance_issues,
			'passed_requirements' => $passed_checks,
			'message'             => $is_compliant
				? __( 'Product is compliant with all regulatory requirements.', 'mcp-ai-wpoos-pro' )
				: sprintf(
					/* translators: %d: number of compliance issues */
					__( 'Product has %d compliance issue(s) that must be addressed.', 'mcp-ai-wpoos-pro' ),
					count( $compliance_issues )
				),
		);
	}

	/**
	 * Get requirements for a country and product category.
	 *
	 * @param string $country Country code.
	 * @param string $product_category Product category.
	 * @return array Array of requirements.
	 */
	private function get_requirements( $country, $product_category ) {
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => 'country',
				'value' => $country,
			),
		);

		// Add category filter if provided.
		if ( ! empty( $product_category ) ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'   => 'product_category',
					'value' => $product_category,
				),
				array(
					'key'     => 'product_category',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_requirement',
				'posts_per_page' => -1,
				'meta_query'     => $meta_query,
			)
		);

		$requirements = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$requirements[] = array(
					'id'               => $post->ID,
					'title'            => $post->post_title,
					'requirement_type' => get_post_meta( $post->ID, 'requirement_type', true ),
					'is_mandatory'     => (bool) get_post_meta( $post->ID, 'is_mandatory', true ),
				);
			}
		}

		return $requirements;
	}

	/**
	 * Check document compliance.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $requirements Requirements to check.
	 * @return array Array with 'issues' and 'passed' keys.
	 */
	private function check_document_compliance( $product_id, $requirements ) {
		$issues = array();
		$passed = array();

		// Get document requirements.
		$doc_requirements = array_filter(
			$requirements,
			function ( $req ) {
				return 'document' === $req['requirement_type'];
			}
		);

		if ( empty( $doc_requirements ) ) {
			return array(
				'issues' => $issues,
				'passed' => $passed,
			);
		}

		// Get product documents.
		$documents = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'posts_per_page' => -1,
				'meta_key'       => 'product_id',
				'meta_value'     => $product_id,
			)
		);

		$existing_doc_types = array();
		foreach ( $documents as $doc ) {
			$doc_type = get_post_meta( $doc->ID, 'document_type', true );
			if ( $doc_type ) {
				$existing_doc_types[] = $doc_type;
			}
		}

		// Check each requirement.
		foreach ( $doc_requirements as $req ) {
			if ( $req['is_mandatory'] ) {
				// For simplicity, check if requirement title matches any document type.
				$requirement_met = false;
				foreach ( $existing_doc_types as $doc_type ) {
					if ( stripos( $req['title'], $doc_type ) !== false || stripos( $doc_type, $req['title'] ) !== false ) {
						$requirement_met = true;
						break;
					}
				}

				if ( $requirement_met ) {
					$passed[] = array(
						'requirement' => $req['title'],
						'type'        => 'document',
					);
				} else {
					$issues[] = array(
						'requirement' => $req['title'],
						'type'        => 'document',
						'severity'    => 'critical',
						'message'     => sprintf(
							/* translators: %s: requirement title */
							__( 'Missing required document: %s', 'mcp-ai-wpoos-pro' ),
							$req['title']
						),
					);
				}
			}
		}

		return array(
			'issues' => $issues,
			'passed' => $passed,
		);
	}

	/**
	 * Check ingredient compliance.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $requirements Requirements to check.
	 * @return array Array with 'issues' and 'passed' keys.
	 */
	private function check_ingredient_compliance( $product_id, $requirements ) {
		$issues = array();
		$passed = array();

		// Get ingredient restriction requirements.
		$ingredient_requirements = array_filter(
			$requirements,
			function ( $req ) {
				return 'ingredient_restriction' === $req['requirement_type'];
			}
		);

		if ( empty( $ingredient_requirements ) ) {
			// If no restrictions, mark as passed.
			$passed[] = array(
				'requirement' => 'No ingredient restrictions',
				'type'        => 'ingredient',
			);
			return array(
				'issues' => $issues,
				'passed' => $passed,
			);
		}

		// Get product ingredients.
		$inci_ingredients = get_post_meta( $product_id, 'inci_ingredients', true );

		// Check each restriction.
		foreach ( $ingredient_requirements as $req ) {
			if ( $req['is_mandatory'] && ! empty( $inci_ingredients ) ) {
				// Check if restricted ingredient is present (basic check).
				if ( stripos( $inci_ingredients, $req['title'] ) !== false ) {
					$issues[] = array(
						'requirement' => $req['title'],
						'type'        => 'ingredient_restriction',
						'severity'    => 'critical',
						'message'     => sprintf(
							/* translators: %s: restricted ingredient */
							__( 'Product contains restricted ingredient: %s', 'mcp-ai-wpoos-pro' ),
							$req['title']
						),
					);
				} else {
					$passed[] = array(
						'requirement' => $req['title'],
						'type'        => 'ingredient_restriction',
					);
				}
			}
		}

		return array(
			'issues' => $issues,
			'passed' => $passed,
		);
	}
}
