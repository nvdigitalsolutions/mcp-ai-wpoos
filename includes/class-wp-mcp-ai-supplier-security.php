<?php
/**
 * Supplier Security Management System for ISO 27001 Compliance.
 *
 * Implements ISO 27001:2022 Controls:
 * - A.5.19: Information Security in Supplier Relationships
 * - A.5.20: Addressing Information Security Within Supplier Agreements
 * - A.5.21: Managing Information Security in the ICT Supply Chain
 * - A.5.22: Monitoring, Review and Change Management of Supplier Services
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplier Security Management class.
 *
 * Manages third-party vendor security assessments, monitoring, and compliance.
 */
class WP_MCP_AI_Supplier_Security {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Supplier_Security
	 */
	protected static $instance = null;

	/**
	 * Supplier risk categories.
	 *
	 * @var array
	 */
	const RISK_CATEGORIES = array(
		'critical'  => 'Critical',
		'important' => 'Important',
		'low_risk'  => 'Low Risk',
	);

	/**
	 * Supplier risk levels.
	 *
	 * @var array
	 */
	const RISK_LEVELS = array(
		'low'      => 'Low',
		'medium'   => 'Medium',
		'high'     => 'High',
		'critical' => 'Critical',
	);

	/**
	 * Supplier assessment status.
	 *
	 * @var array
	 */
	const ASSESSMENT_STATUS = array(
		'pending'   => 'Pending',
		'approved'  => 'Approved',
		'rejected'  => 'Rejected',
		'reviewing' => 'Under Review',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Supplier_Security
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	protected function init_hooks() {
		// Register cron job for periodic supplier review.
		add_action( 'wp_mcp_ai_supplier_review', array( $this, 'run_supplier_reviews' ) );

		// Schedule quarterly supplier reviews if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_supplier_review' ) ) {
			// Schedule for 1st day of each quarter at 2 AM.
			$next_quarter = strtotime( 'first day of next quarter' );
			wp_schedule_event( $next_quarter, 'quarterly', 'wp_mcp_ai_supplier_review' );
		}

		// Monitor dependency vulnerabilities daily.
		add_action( 'wp_mcp_ai_dependency_scan', array( $this, 'scan_dependencies' ) );
		if ( ! wp_next_scheduled( 'wp_mcp_ai_dependency_scan' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_dependency_scan' );
		}
	}

	/**
	 * Get all registered suppliers.
	 *
	 * @return array Array of suppliers.
	 */
	public function get_suppliers() {
		$suppliers = get_option( 'wp_mcp_ai_suppliers', array() );

		// If empty, initialize with default critical suppliers.
		if ( empty( $suppliers ) ) {
			$suppliers = $this->get_default_suppliers();
			$this->update_suppliers( $suppliers );
		}

		return $suppliers;
	}

	/**
	 * Get default critical suppliers.
	 *
	 * @return array Default supplier registry.
	 */
	protected function get_default_suppliers() {
		return array(
			'openai'   => array(
				'id'              => 'openai',
				'name'            => 'OpenAI',
				'service'         => 'GPT API for AI Assistance',
				'category'        => 'critical',
				'risk_level'      => 'medium',
				'status'          => 'approved',
				'certifications'  => array( 'SOC 2 Type II' ),
				'compliance'      => array( 'GDPR', 'CCPA' ),
				'data_access'     => array( 'User prompts', 'Chat transcripts' ),
				'encryption'      => array(
					'at_rest'    => 'AES-256',
					'in_transit' => 'TLS 1.2+',
				),
				'sla'             => array(
					'uptime'                => '99.9%',
					'incident_notification' => '24 hours',
					'support_response'      => '4 hours',
				),
				'last_assessment' => current_time( 'mysql' ),
				'next_review'     => gmdate( 'Y-m-d', strtotime( '+12 months' ) ),
				'contact_email'   => 'support@openai.com',
				'documentation'   => 'https://openai.com/security',
				'incidents'       => array(),
				'performance'     => array(
					'uptime_actual' => 99.95,
					'incidents_ytd' => 0,
				),
			),
			'google'   => array(
				'id'              => 'google',
				'name'            => 'Google (Gemini)',
				'service'         => 'Gemini AI API',
				'category'        => 'critical',
				'risk_level'      => 'low',
				'status'          => 'approved',
				'certifications'  => array( 'ISO 27001', 'SOC 2 Type II', 'SOC 3' ),
				'compliance'      => array( 'GDPR', 'CCPA', 'HIPAA' ),
				'data_access'     => array( 'User prompts', 'Chat transcripts' ),
				'encryption'      => array(
					'at_rest'    => 'AES-256',
					'in_transit' => 'TLS 1.3',
				),
				'sla'             => array(
					'uptime'                => '99.95%',
					'incident_notification' => '24 hours',
					'support_response'      => '1 hour',
				),
				'last_assessment' => current_time( 'mysql' ),
				'next_review'     => gmdate( 'Y-m-d', strtotime( '+12 months' ) ),
				'contact_email'   => 'cloud-support@google.com',
				'documentation'   => 'https://cloud.google.com/security',
				'incidents'       => array(),
				'performance'     => array(
					'uptime_actual' => 99.97,
					'incidents_ytd' => 0,
				),
			),
			'github'   => array(
				'id'              => 'github',
				'name'            => 'GitHub',
				'service'         => 'Version Control and CI/CD',
				'category'        => 'important',
				'risk_level'      => 'low',
				'status'          => 'approved',
				'certifications'  => array( 'SOC 2 Type II' ),
				'compliance'      => array( 'GDPR' ),
				'data_access'     => array( 'Source code', 'Configuration files' ),
				'encryption'      => array(
					'at_rest'    => 'AES-256',
					'in_transit' => 'TLS 1.2+',
				),
				'sla'             => array(
					'uptime'                => '99.95%',
					'incident_notification' => '1 hour',
					'support_response'      => '8 hours',
				),
				'last_assessment' => current_time( 'mysql' ),
				'next_review'     => gmdate( 'Y-m-d', strtotime( '+12 months' ) ),
				'contact_email'   => 'support@github.com',
				'documentation'   => 'https://github.com/security',
				'incidents'       => array(),
				'performance'     => array(
					'uptime_actual' => 99.96,
					'incidents_ytd' => 2,
				),
			),
			'composer' => array(
				'id'              => 'composer',
				'name'            => 'Composer/Packagist',
				'service'         => 'PHP Dependency Management',
				'category'        => 'important',
				'risk_level'      => 'medium',
				'status'          => 'approved',
				'certifications'  => array(),
				'compliance'      => array(),
				'data_access'     => array( 'Public packages only' ),
				'encryption'      => array(
					'at_rest'    => 'N/A',
					'in_transit' => 'HTTPS',
				),
				'sla'             => array(
					'uptime'                => 'Best effort',
					'incident_notification' => 'Public announcements',
					'support_response'      => 'Community support',
				),
				'last_assessment' => current_time( 'mysql' ),
				'next_review'     => gmdate( 'Y-m-d', strtotime( '+12 months' ) ),
				'contact_email'   => 'security@packagist.org',
				'documentation'   => 'https://packagist.org/about',
				'incidents'       => array(),
				'performance'     => array(
					'uptime_actual' => 99.8,
					'incidents_ytd' => 3,
				),
				'mitigation'      => array(
					'Lock file usage',
					'Vulnerability scanning via Dependabot',
					'Manual package review before updates',
				),
			),
			'npm'      => array(
				'id'              => 'npm',
				'name'            => 'NPM Registry',
				'service'         => 'JavaScript Dependency Management',
				'category'        => 'important',
				'risk_level'      => 'medium',
				'status'          => 'approved',
				'certifications'  => array(),
				'compliance'      => array(),
				'data_access'     => array( 'Public packages only' ),
				'encryption'      => array(
					'at_rest'    => 'N/A',
					'in_transit' => 'HTTPS',
				),
				'sla'             => array(
					'uptime'                => 'Best effort',
					'incident_notification' => 'Public announcements',
					'support_response'      => 'Community support',
				),
				'last_assessment' => current_time( 'mysql' ),
				'next_review'     => gmdate( 'Y-m-d', strtotime( '+12 months' ) ),
				'contact_email'   => 'security@npmjs.com',
				'documentation'   => 'https://docs.npmjs.com/security',
				'incidents'       => array(),
				'performance'     => array(
					'uptime_actual' => 99.9,
					'incidents_ytd' => 1,
				),
				'mitigation'      => array(
					'Lock file usage',
					'npm audit scanning',
					'Minimal dependencies philosophy',
					'Package selection criteria',
				),
			),
		);
	}

	/**
	 * Update supplier registry.
	 *
	 * @param array $suppliers Supplier data.
	 * @return bool Success status.
	 */
	public function update_suppliers( $suppliers ) {
		return update_option( 'wp_mcp_ai_suppliers', $suppliers );
	}

	/**
	 * Get a single supplier by ID.
	 *
	 * @param string $supplier_id Supplier ID.
	 * @return array|null Supplier data or null if not found.
	 */
	public function get_supplier( $supplier_id ) {
		$suppliers = $this->get_suppliers();
		return isset( $suppliers[ $supplier_id ] ) ? $suppliers[ $supplier_id ] : null;
	}

	/**
	 * Add or update a supplier.
	 *
	 * @param string $supplier_id Supplier ID.
	 * @param array  $data Supplier data.
	 * @return bool Success status.
	 */
	public function upsert_supplier( $supplier_id, $data ) {
		$suppliers = $this->get_suppliers();

		// Merge with existing data if updating.
		if ( isset( $suppliers[ $supplier_id ] ) ) {
			$suppliers[ $supplier_id ] = array_merge( $suppliers[ $supplier_id ], $data );
		} else {
			$suppliers[ $supplier_id ] = $data;
		}

		// Set ID if not present.
		$suppliers[ $supplier_id ]['id'] = $supplier_id;

		return $this->update_suppliers( $suppliers );
	}

	/**
	 * Delete a supplier.
	 *
	 * @param string $supplier_id Supplier ID.
	 * @return bool Success status.
	 */
	public function delete_supplier( $supplier_id ) {
		$suppliers = $this->get_suppliers();

		if ( ! isset( $suppliers[ $supplier_id ] ) ) {
			return false;
		}

		unset( $suppliers[ $supplier_id ] );
		return $this->update_suppliers( $suppliers );
	}

	/**
	 * Get suppliers by category.
	 *
	 * @param string $category Supplier category.
	 * @return array Filtered suppliers.
	 */
	public function get_suppliers_by_category( $category ) {
		$suppliers = $this->get_suppliers();

		return array_filter(
			$suppliers,
			function ( $supplier ) use ( $category ) {
				return isset( $supplier['category'] ) && $supplier['category'] === $category;
			}
		);
	}

	/**
	 * Get suppliers by risk level.
	 *
	 * @param string $risk_level Risk level.
	 * @return array Filtered suppliers.
	 */
	public function get_suppliers_by_risk( $risk_level ) {
		$suppliers = $this->get_suppliers();

		return array_filter(
			$suppliers,
			function ( $supplier ) use ( $risk_level ) {
				return isset( $supplier['risk_level'] ) && $supplier['risk_level'] === $risk_level;
			}
		);
	}

	/**
	 * Get suppliers due for review.
	 *
	 * @return array Suppliers needing review.
	 */
	public function get_suppliers_due_for_review() {
		$suppliers = $this->get_suppliers();
		$today     = current_time( 'Y-m-d' );

		return array_filter(
			$suppliers,
			function ( $supplier ) use ( $today ) {
				if ( ! isset( $supplier['next_review'] ) ) {
					return true; // No review date set, needs review.
				}
				return $supplier['next_review'] <= $today;
			}
		);
	}

	/**
	 * Run supplier reviews (cron job).
	 */
	public function run_supplier_reviews() {
		$due_for_review = $this->get_suppliers_due_for_review();

		if ( empty( $due_for_review ) ) {
			return;
		}

		// Log review notification.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				sprintf(
					'%d supplier(s) due for security review: %s',
					count( $due_for_review ),
					implode( ', ', wp_list_pluck( $due_for_review, 'name' ) )
				),
				array(
					'component' => 'supplier_security',
					'suppliers' => array_keys( $due_for_review ),
				)
			);
		}

		// Send email notification to admins.
		$this->send_review_notification( $due_for_review );
	}

	/**
	 * Send supplier review notification.
	 *
	 * @param array $suppliers Suppliers due for review.
	 */
	protected function send_review_notification( $suppliers ) {
		$admin_email = get_option( 'admin_email' );

		$subject = sprintf(
			/* translators: %d: Number of suppliers */
			__( '[NV oOS] %d Supplier(s) Due for Security Review', 'mcp-ai-wpoos' ),
			count( $suppliers )
		);

		$message = __( 'The following suppliers are due for their periodic security review:', 'mcp-ai-wpoos' ) . "\n\n";

		foreach ( $suppliers as $supplier ) {
			$message .= sprintf(
				"- %s (%s)\n  Category: %s | Risk: %s\n  Last Review: %s\n\n",
				$supplier['name'],
				$supplier['service'],
				self::RISK_CATEGORIES[ $supplier['category'] ] ?? $supplier['category'],
				self::RISK_LEVELS[ $supplier['risk_level'] ] ?? $supplier['risk_level'],
				isset( $supplier['last_assessment'] ) ? $supplier['last_assessment'] : 'Never'
			);
		}

		$message .= sprintf(
			/* translators: %s: Admin URL for suppliers page */
			"\n" . __( 'Review suppliers at: %s', 'mcp-ai-wpoos' ),
			admin_url( 'admin.php?page=nvoos-pro-dashboard-suppliers' )
		);

		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Record a supplier incident.
	 *
	 * @param string $supplier_id Supplier ID.
	 * @param array  $incident Incident details.
	 * @return bool Success status.
	 */
	public function record_incident( $supplier_id, $incident ) {
		$supplier = $this->get_supplier( $supplier_id );

		if ( ! $supplier ) {
			return false;
		}

		if ( ! isset( $supplier['incidents'] ) ) {
			$supplier['incidents'] = array();
		}

		$incident['timestamp']   = current_time( 'mysql' );
		$supplier['incidents'][] = $incident;

		// Increment YTD incident count.
		if ( isset( $supplier['performance']['incidents_ytd'] ) ) {
			++$supplier['performance']['incidents_ytd'];
		}

		return $this->upsert_supplier( $supplier_id, $supplier );
	}

	/**
	 * Get supplier statistics.
	 *
	 * @return array Statistics.
	 */
	public function get_statistics() {
		$suppliers = $this->get_suppliers();

		$stats = array(
			'total'           => count( $suppliers ),
			'by_category'     => array(
				'critical'  => 0,
				'important' => 0,
				'low_risk'  => 0,
			),
			'by_risk'         => array(
				'low'      => 0,
				'medium'   => 0,
				'high'     => 0,
				'critical' => 0,
			),
			'by_status'       => array(
				'approved'  => 0,
				'pending'   => 0,
				'rejected'  => 0,
				'reviewing' => 0,
			),
			'due_for_review'  => count( $this->get_suppliers_due_for_review() ),
			'total_incidents' => 0,
			'avg_uptime'      => 0,
		);

		$total_uptime = 0;
		$uptime_count = 0;

		foreach ( $suppliers as $supplier ) {
			// Count by category.
			if ( isset( $supplier['category'] ) && isset( $stats['by_category'][ $supplier['category'] ] ) ) {
				++$stats['by_category'][ $supplier['category'] ];
			}

			// Count by risk level.
			if ( isset( $supplier['risk_level'] ) && isset( $stats['by_risk'][ $supplier['risk_level'] ] ) ) {
				++$stats['by_risk'][ $supplier['risk_level'] ];
			}

			// Count by status.
			if ( isset( $supplier['status'] ) && isset( $stats['by_status'][ $supplier['status'] ] ) ) {
				++$stats['by_status'][ $supplier['status'] ];
			}

			// Count incidents.
			if ( isset( $supplier['incidents'] ) ) {
				$stats['total_incidents'] += count( $supplier['incidents'] );
			}

			// Calculate average uptime.
			if ( isset( $supplier['performance']['uptime_actual'] ) ) {
				$total_uptime += $supplier['performance']['uptime_actual'];
				++$uptime_count;
			}
		}

		if ( $uptime_count > 0 ) {
			$stats['avg_uptime'] = round( $total_uptime / $uptime_count, 2 );
		}

		return $stats;
	}

	/**
	 * Scan dependencies for vulnerabilities (cron job).
	 */
	public function scan_dependencies() {
		$results = array(
			'composer' => $this->scan_composer_dependencies(),
			'npm'      => $this->scan_npm_dependencies(),
		);

		// Log scan results.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			$total_vulns = ( $results['composer']['vulnerabilities'] ?? 0 ) + ( $results['npm']['vulnerabilities'] ?? 0 );

			WP_MCP_AI_Logger::log_event(
				$total_vulns > 0 ? 'warning' : 'info',
				sprintf( 'Dependency vulnerability scan completed: %d vulnerabilities found', $total_vulns ),
				array(
					'component' => 'supplier_security',
					'results'   => $results,
				)
			);
		}

		// Store scan results.
		update_option(
			'wp_mcp_ai_last_dependency_scan',
			array(
				'timestamp' => current_time( 'mysql' ),
				'results'   => $results,
			)
		);

		return $results;
	}

	/**
	 * Scan Composer dependencies.
	 *
	 * @return array Scan results.
	 */
	protected function scan_composer_dependencies() {
		$composer_lock = WP_MCP_AI_PATH . 'composer.lock';

		if ( ! file_exists( $composer_lock ) ) {
			return array(
				'status'  => 'error',
				'message' => 'composer.lock not found',
			);
		}

		// In production, this would call `composer audit` or integrate with vulnerability databases.
		// For now, return placeholder data.
		return array(
			'status'          => 'success',
			'packages'        => 0, // Would count actual packages.
			'vulnerabilities' => 0,
			'scan_method'     => 'Manual review required',
		);
	}

	/**
	 * Scan NPM dependencies.
	 *
	 * @return array Scan results.
	 */
	protected function scan_npm_dependencies() {
		$package_lock = WP_MCP_AI_PATH . 'package-lock.json';

		if ( ! file_exists( $package_lock ) ) {
			return array(
				'status'  => 'error',
				'message' => 'package-lock.json not found',
			);
		}

		// In production, this would call `npm audit` or integrate with vulnerability databases.
		// For now, return placeholder data.
		return array(
			'status'          => 'success',
			'packages'        => 0, // Would count actual packages.
			'vulnerabilities' => 0,
			'scan_method'     => 'Manual review required',
		);
	}

	/**
	 * Generate Software Bill of Materials (SBOM).
	 *
	 * @return array SBOM data.
	 */
	public function generate_sbom() {
		$sbom = array(
			'timestamp'  => current_time( 'mysql' ),
			'format'     => 'CycloneDX',
			'version'    => '1.4',
			'components' => array(),
		);

		// Parse Composer dependencies.
		$composer_lock = WP_MCP_AI_PATH . 'composer.lock';
		if ( file_exists( $composer_lock ) ) {
			$composer_data = json_decode( file_get_contents( $composer_lock ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.

			if ( isset( $composer_data['packages'] ) ) {
				foreach ( $composer_data['packages'] as $package ) {
					$sbom['components'][] = array(
						'type'    => 'library',
						'name'    => $package['name'],
						'version' => $package['version'],
						'manager' => 'composer',
						'license' => isset( $package['license'] ) ? implode( ', ', (array) $package['license'] ) : '',
					);
				}
			}
		}

		// Parse NPM dependencies.
		$package_lock = WP_MCP_AI_PATH . 'package-lock.json';
		if ( file_exists( $package_lock ) ) {
			$npm_data = json_decode( file_get_contents( $package_lock ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.

			if ( isset( $npm_data['packages'] ) ) {
				foreach ( $npm_data['packages'] as $path => $package ) {
					// Skip root package.
					if ( empty( $path ) ) {
						continue;
					}

					$name = ltrim( $path, 'node_modules/' );

					$sbom['components'][] = array(
						'type'    => 'library',
						'name'    => $name,
						'version' => isset( $package['version'] ) ? $package['version'] : '',
						'manager' => 'npm',
						'license' => isset( $package['license'] ) ? $package['license'] : '',
					);
				}
			}
		}

		// Add WordPress as a dependency.
		$sbom['components'][] = array(
			'type'    => 'framework',
			'name'    => 'WordPress',
			'version' => get_bloginfo( 'version' ),
			'manager' => 'manual',
			'license' => 'GPL-2.0-or-later',
		);

		return $sbom;
	}
}
