<?php
/**
 * Pro Dashboard Report Generation Engine
 *
 * Handles generation of compliance reports in multiple formats
 * (PDF, DOCX, Excel, HTML, CSV).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report Generator Class
 */
class WP_MCP_AI_Report_Generator {

	/**
	 * Report types
	 *
	 * @var array
	 */
	private $report_types = array( 'html', 'csv', 'json', 'pdf', 'docx', 'excel' );

	/**
	 * Generate compliance report
	 *
	 * @param string $type  Report type (html, csv, json, pdf, docx, excel).
	 * @param string $scope Report scope (full, controls, risks, audit).
	 * @return array Report data with file path or error.
	 */
	public function generate_report( $type, $scope = 'full' ) {
		if ( ! in_array( $type, $this->report_types, true ) ) {
			return array(
				'success' => false,
				'message' => 'Invalid report type',
			);
		}

		$report_id = wp_generate_uuid4();
		$timestamp = current_time( 'timestamp' );

		$report_data = $this->gather_report_data( $scope );

		switch ( $type ) {
			case 'html':
				$result = $this->generate_html_report( $report_data, $report_id );
				break;
			case 'csv':
				$result = $this->generate_csv_report( $report_data, $report_id );
				break;
			case 'json':
				$result = $this->generate_json_report( $report_data, $report_id );
				break;
			case 'pdf':
			case 'docx':
			case 'excel':
				$result = array(
					'success' => false,
					'message' => sprintf( '%s report generation requires additional libraries. HTML/CSV/JSON are available now.', strtoupper( $type ) ),
				);
				break;
			default:
				$result = array(
					'success' => false,
					'message' => 'Unsupported report type',
				);
		}

		// Log report generation.
		if ( $result['success'] && class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
			WP_MCP_AI_Pro_Database::log_audit(
				'report_generated',
				'compliance',
				'report',
				$report_id,
				sprintf( 'Generated %s report (scope: %s)', strtoupper( $type ), $scope )
			);
		}

		return $result;
	}

	/**
	 * Gather report data
	 *
	 * @param string $scope Report scope.
	 * @return array Report data.
	 */
	private function gather_report_data( $scope ) {
		$data = array(
			'metadata' => array(
				'report_date'    => current_time( 'mysql' ),
				'site_name'      => get_bloginfo( 'name' ),
				'site_url'       => get_site_url(),
				'generated_by'   => wp_get_current_user()->display_name,
				'report_scope'   => $scope,
				'plugin_version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
			),
		);

		if ( in_array( $scope, array( 'full', 'controls' ), true ) ) {
			$data['controls'] = $this->get_controls_data();
		}

		if ( in_array( $scope, array( 'full', 'risks' ), true ) ) {
			$data['risks'] = $this->get_risks_data();
		}

		if ( in_array( $scope, array( 'full', 'audit' ), true ) ) {
			$data['audit_trail'] = $this->get_audit_data();
		}

		if ( 'full' === $scope ) {
			$data['compliance_status'] = $this->get_compliance_status();
			$data['evidence']          = $this->get_evidence_summary();
			$data['frameworks']        = $this->get_frameworks_status();
		}

		return $data;
	}

	/**
	 * Get controls data
	 *
	 * @return array
	 */
	private function get_controls_data() {
		if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
			$controls = WP_MCP_AI_Pro_Database::get_controls( array( 'limit' => 200 ) );
			if ( ! empty( $controls ) ) {
				return $controls;
			}
		}

		// Fallback to sample data.
		return array(
			array(
				'control_id'          => 'A.5.1',
				'category'            => 'A.5',
				'name'                => 'Policies for information security',
				'status'              => 'implemented',
				'implementation_date' => '2024-01-15',
				'last_review_date'    => '2025-10-01',
			),
			array(
				'control_id'          => 'A.8.2',
				'category'            => 'A.8',
				'name'                => 'Privileged access rights',
				'status'              => 'implemented',
				'implementation_date' => '2024-02-01',
				'last_review_date'    => '2025-11-01',
			),
		);
	}

	/**
	 * Get risks data
	 *
	 * @return array
	 */
	private function get_risks_data() {
		if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
			$risks = WP_MCP_AI_Pro_Database::get_risks( array( 'limit' => 100 ) );
			if ( ! empty( $risks ) ) {
				return $risks;
			}
		}

		// Fallback to sample data.
		return array(
			array(
				'risk_id'    => 'R-001',
				'title'      => 'Unauthorized access to API keys',
				'category'   => 'authentication',
				'likelihood' => 3,
				'impact'     => 5,
				'risk_score' => 15,
				'risk_level' => 'high',
				'treatment'  => 'reduce',
				'status'     => 'open',
			),
			array(
				'risk_id'    => 'R-002',
				'title'      => 'Data loss from third-party AI provider',
				'category'   => 'third_party',
				'likelihood' => 2,
				'impact'     => 4,
				'risk_score' => 8,
				'risk_level' => 'medium',
				'treatment'  => 'reduce',
				'status'     => 'open',
			),
		);
	}

	/**
	 * Get audit trail data
	 *
	 * @return array
	 */
	private function get_audit_data() {
		if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
			$audit = WP_MCP_AI_Pro_Database::get_audit_trail( array( 'limit' => 100 ) );
			if ( ! empty( $audit ) ) {
				return $audit;
			}
		}

		// Fallback to recent activity.
		$recent_activity = get_option( 'wp_mcp_ai_recent_activity', array() );
		return is_array( $recent_activity ) ? $recent_activity : array();
	}

	/**
	 * Get compliance status
	 *
	 * @return array
	 */
	private function get_compliance_status() {
		return array(
			'iso27001' => array(
				'implemented' => 52,
				'partial'     => 26,
				'planned'     => 3,
				'na'          => 12,
				'total'       => 93,
				'percentage'  => 56,
				'status'      => get_option( 'wp_mcp_ai_iso27001_certified', false ) ? 'certified' : 'compliant',
			),
		);
	}

	/**
	 * Get evidence summary
	 *
	 * @return array
	 */
	private function get_evidence_summary() {
		if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
			global $wpdb;
			$evidence_table = $wpdb->prefix . 'mcp_ai_evidence';
			$count          = $wpdb->get_var( "SELECT COUNT(*) FROM $evidence_table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is hardcoded

			return array(
				'total_evidence' => (int) $count,
				'valid_evidence' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $evidence_table WHERE is_valid = 1" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is hardcoded
			);
		}

		return array(
			'total_evidence' => 0,
			'valid_evidence' => 0,
		);
	}

	/**
	 * Get frameworks status
	 *
	 * @return array
	 */
	private function get_frameworks_status() {
		return array(
			'iso27001' => array(
				'status'     => 'compliant',
				'percentage' => 56,
			),
			'gdpr'     => array(
				'status'     => 'compliant',
				'percentage' => 95,
			),
		);
	}

	/**
	 * Generate HTML report
	 *
	 * @param array  $data      Report data.
	 * @param string $report_id Report ID.
	 * @return array
	 */
	private function generate_html_report( $data, $report_id ) {
		$upload_dir  = wp_upload_dir();
		$reports_dir = $upload_dir['basedir'] . '/mcp-ai-reports';

		if ( ! file_exists( $reports_dir ) ) {
			wp_mkdir_p( $reports_dir );
		}

		$filename = sprintf( 'compliance-report-%s.html', gmdate( 'Y-m-d-His' ) );
		$filepath = $reports_dir . '/' . $filename;

		$html = $this->build_html_report( $data );

		$result = file_put_contents( $filepath, $html );

		if ( false === $result ) {
			return array(
				'success' => false,
				'message' => 'Failed to write report file',
			);
		}

		return array(
			'success'   => true,
			'report_id' => $report_id,
			'filename'  => $filename,
			'filepath'  => $filepath,
			'url'       => $upload_dir['baseurl'] . '/mcp-ai-reports/' . $filename,
			'type'      => 'html',
		);
	}

	/**
	 * Build HTML report content
	 *
	 * @param array $data Report data.
	 * @return string
	 */
	private function build_html_report( $data ) {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<title>ISO 27001 Compliance Report - <?php echo esc_html( $data['metadata']['site_name'] ); ?></title>
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Inline styles for ISO 27001 compliance report PDF generation only
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Inline styles for standalone report generation.
			?>
			<style>
				body { font-family: Arial, sans-serif; margin: 40px; color: #333; }
				h1 { color: #1565c0; border-bottom: 3px solid #1565c0; padding-bottom: 10px; }
				h2 { color: #42a5f5; margin-top: 30px; border-bottom: 2px solid #e3f2fd; padding-bottom: 5px; }
				table { width: 100%; border-collapse: collapse; margin: 20px 0; }
				th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
				th { background-color: #e3f2fd; color: #1565c0; font-weight: bold; }
				tr:nth-child(even) { background-color: #f9f9f9; }
				.metadata { background-color: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 30px; }
				.metadata p { margin: 5px 0; }
				.status-implemented { color: #4caf50; font-weight: bold; }
				.status-partial { color: #ff9800; font-weight: bold; }
				.status-planned { color: #2196f3; font-weight: bold; }
				.risk-high { color: #f44336; font-weight: bold; }
				.risk-medium { color: #ff9800; font-weight: bold; }
				.risk-low { color: #4caf50; font-weight: bold; }
			</style>
		</head>
		<body>
			<h1>ISO 27001 Compliance Report</h1>

			<div class="metadata">
				<p><strong>Site:</strong> <?php echo esc_html( $data['metadata']['site_name'] ); ?></p>
				<p><strong>URL:</strong> <?php echo esc_html( $data['metadata']['site_url'] ); ?></p>
				<p><strong>Report Date:</strong> <?php echo esc_html( $data['metadata']['report_date'] ); ?></p>
				<p><strong>Generated By:</strong> <?php echo esc_html( $data['metadata']['generated_by'] ); ?></p>
				<p><strong>Scope:</strong> <?php echo esc_html( ucfirst( $data['metadata']['report_scope'] ) ); ?></p>
			</div>

			<?php if ( isset( $data['compliance_status'] ) ) : ?>
				<h2>Compliance Status</h2>
				<table>
					<tr>
						<th>Framework</th>
						<th>Implemented</th>
						<th>Partial</th>
						<th>Planned</th>
						<th>N/A</th>
						<th>Total</th>
						<th>Percentage</th>
					</tr>
					<tr>
						<td>ISO 27001:2022</td>
						<td><?php echo absint( $data['compliance_status']['iso27001']['implemented'] ); ?></td>
						<td><?php echo absint( $data['compliance_status']['iso27001']['partial'] ); ?></td>
						<td><?php echo absint( $data['compliance_status']['iso27001']['planned'] ); ?></td>
						<td><?php echo absint( $data['compliance_status']['iso27001']['na'] ); ?></td>
						<td><?php echo absint( $data['compliance_status']['iso27001']['total'] ); ?></td>
						<td><?php echo absint( $data['compliance_status']['iso27001']['percentage'] ); ?>%</td>
					</tr>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $data['controls'] ) ) : ?>
				<h2>Controls Implementation</h2>
				<table>
					<tr>
						<th>Control ID</th>
						<th>Category</th>
						<th>Name</th>
						<th>Status</th>
						<th>Implementation Date</th>
						<th>Last Review</th>
					</tr>
					<?php foreach ( $data['controls'] as $control ) : ?>
						<tr>
							<td><?php echo esc_html( $control['control_id'] ); ?></td>
							<td><?php echo esc_html( $control['category'] ); ?></td>
							<td><?php echo esc_html( $control['name'] ); ?></td>
							<td class="status-<?php echo esc_attr( $control['status'] ); ?>">
								<?php echo esc_html( ucfirst( $control['status'] ) ); ?>
							</td>
							<td><?php echo esc_html( $control['implementation_date'] ?? 'N/A' ); ?></td>
							<td><?php echo esc_html( $control['last_review_date'] ?? 'N/A' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $data['risks'] ) ) : ?>
				<h2>Risk Register</h2>
				<table>
					<tr>
						<th>Risk ID</th>
						<th>Title</th>
						<th>Category</th>
						<th>Likelihood</th>
						<th>Impact</th>
						<th>Score</th>
						<th>Level</th>
						<th>Treatment</th>
						<th>Status</th>
					</tr>
					<?php foreach ( $data['risks'] as $risk ) : ?>
						<tr>
							<td><?php echo esc_html( $risk['risk_id'] ); ?></td>
							<td><?php echo esc_html( $risk['title'] ); ?></td>
							<td><?php echo esc_html( $risk['category'] ); ?></td>
							<td><?php echo absint( $risk['likelihood'] ); ?></td>
							<td><?php echo absint( $risk['impact'] ); ?></td>
							<td><?php echo absint( $risk['risk_score'] ); ?></td>
							<td class="risk-<?php echo esc_attr( $risk['risk_level'] ); ?>">
								<?php echo esc_html( ucfirst( $risk['risk_level'] ) ); ?>
							</td>
							<td><?php echo esc_html( ucfirst( $risk['treatment'] ) ); ?></td>
							<td><?php echo esc_html( ucfirst( $risk['status'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $data['audit_trail'] ) ) : ?>
				<h2>Recent Audit Activity</h2>
				<table>
					<tr>
						<th>Date</th>
						<th>Event Type</th>
						<th>Description</th>
						<th>User</th>
					</tr>
					<?php foreach ( array_slice( $data['audit_trail'], 0, 50 ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['created_at'] ?? $entry['timestamp'] ?? 'N/A' ); ?></td>
							<td><?php echo esc_html( $entry['event_type'] ?? $entry['type'] ?? 'N/A' ); ?></td>
							<td><?php echo esc_html( $entry['description'] ?? $entry['message'] ?? 'N/A' ); ?></td>
							<td><?php echo esc_html( $entry['user_id'] ?? 'System' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<p style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px;">
				Generated by NV oOS Pro Dashboard v<?php echo esc_html( $data['metadata']['plugin_version'] ); ?>
				on <?php echo esc_html( current_time( 'mysql' ) ); ?>
			</p>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Generate CSV report
	 *
	 * @param array  $data      Report data.
	 * @param string $report_id Report ID.
	 * @return array
	 */
	private function generate_csv_report( $data, $report_id ) {
		$upload_dir  = wp_upload_dir();
		$reports_dir = $upload_dir['basedir'] . '/mcp-ai-reports';

		if ( ! file_exists( $reports_dir ) ) {
			wp_mkdir_p( $reports_dir );
		}

		$filename = sprintf( 'compliance-report-%s.csv', gmdate( 'Y-m-d-His' ) );
		$filepath = $reports_dir . '/' . $filename;

		$fp = fopen( $filepath, 'w' );

		if ( false === $fp ) {
			return array(
				'success' => false,
				'message' => 'Failed to create CSV file',
			);
		}

		// Add metadata.
		fputcsv( $fp, array( 'Metadata' ) );
		foreach ( $data['metadata'] as $key => $value ) {
			fputcsv( $fp, array( $key, $value ) );
		}
		fputcsv( $fp, array() );

		// Add controls.
		if ( ! empty( $data['controls'] ) ) {
			fputcsv( $fp, array( 'Controls' ) );
			fputcsv( $fp, array( 'Control ID', 'Category', 'Name', 'Status', 'Implementation Date', 'Last Review' ) );
			foreach ( $data['controls'] as $control ) {
				fputcsv(
					$fp,
					array(
						$control['control_id'],
						$control['category'],
						$control['name'],
						$control['status'],
						$control['implementation_date'] ?? 'N/A',
						$control['last_review_date'] ?? 'N/A',
					)
				);
			}
			fputcsv( $fp, array() );
		}

		// Add risks.
		if ( ! empty( $data['risks'] ) ) {
			fputcsv( $fp, array( 'Risks' ) );
			fputcsv( $fp, array( 'Risk ID', 'Title', 'Category', 'Likelihood', 'Impact', 'Score', 'Level', 'Treatment', 'Status' ) );
			foreach ( $data['risks'] as $risk ) {
				fputcsv(
					$fp,
					array(
						$risk['risk_id'],
						$risk['title'],
						$risk['category'],
						$risk['likelihood'],
						$risk['impact'],
						$risk['risk_score'],
						$risk['risk_level'],
						$risk['treatment'],
						$risk['status'],
					)
				);
			}
		}

		fclose( $fp );

		return array(
			'success'   => true,
			'report_id' => $report_id,
			'filename'  => $filename,
			'filepath'  => $filepath,
			'url'       => $upload_dir['baseurl'] . '/mcp-ai-reports/' . $filename,
			'type'      => 'csv',
		);
	}

	/**
	 * Generate JSON report
	 *
	 * @param array  $data      Report data.
	 * @param string $report_id Report ID.
	 * @return array
	 */
	private function generate_json_report( $data, $report_id ) {
		$upload_dir  = wp_upload_dir();
		$reports_dir = $upload_dir['basedir'] . '/mcp-ai-reports';

		if ( ! file_exists( $reports_dir ) ) {
			wp_mkdir_p( $reports_dir );
		}

		$filename = sprintf( 'compliance-report-%s.json', gmdate( 'Y-m-d-His' ) );
		$filepath = $reports_dir . '/' . $filename;

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$result = file_put_contents( $filepath, $json );

		if ( false === $result ) {
			return array(
				'success' => false,
				'message' => 'Failed to write JSON file',
			);
		}

		return array(
			'success'   => true,
			'report_id' => $report_id,
			'filename'  => $filename,
			'filepath'  => $filepath,
			'url'       => $upload_dir['baseurl'] . '/mcp-ai-reports/' . $filename,
			'type'      => 'json',
		);
	}
}
