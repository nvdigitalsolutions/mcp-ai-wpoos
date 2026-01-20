#!/usr/bin/env php
<?php
/**
 * Generate compliance data PHP class from markdown files.
 *
 * This script parses the Statement of Applicability markdown files
 * and generates a PHP data class with embedded compliance data.
 *
 * This is a CLI script, not WordPress plugin code. PHPCS rules for
 * escaping output and using WordPress functions don't apply here.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 *
 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
 * phpcs:disable WordPress.WP.AlternativeFunctions
 */

// Define paths.
$plugin_root = dirname( __DIR__ );
$data_dir    = $plugin_root . '/includes/data';
$docs_dir    = $plugin_root . '/docs/compliance';

/**
 * Parse ISO 27001 Statement of Applicability.
 *
 * @param string $file Path to markdown file.
 * @return array Array of controls.
 */
function parse_iso27001_controls( $file ) {
	if ( ! file_exists( $file ) ) {
		echo "Error: File not found: $file\n";
		return array();
	}

	$content = file_get_contents( $file );
	if ( empty( $content ) ) {
		echo "Error: Empty file: $file\n";
		return array();
	}

	$lines                = explode( "\n", $content );
	$controls             = array();
	$current_control      = null;
	$in_implementation    = false;
	$implementation_lines = array();

	foreach ( $lines as $line ) {
		// Match control ID header (e.g., "### A.5.1 Policies for Information Security").
		if ( preg_match( '/^###\s+(A\.\d+\.\d+(?:\.\d+)?)\s+(.+)$/', $line, $matches ) ) {
			// Save previous control if exists.
			if ( $current_control ) {
				// Process collected implementation lines.
				if ( ! empty( $implementation_lines ) ) {
					$current_control['description'] = implode( "\n", $implementation_lines );
				}
				$controls[] = $current_control;
			}

			// Start new control.
			$current_control = array(
				'id'            => $matches[1],
				'name'          => trim( $matches[2] ),
				'status'        => '',
				'status_key'    => '',
				'applicable'    => true,
				'justification' => '',
				'description'   => '',
			);
			$in_implementation    = false;
			$implementation_lines = array();
		} elseif ( $current_control && preg_match( '/^\*\*Status:\*\*\s+(.+)$/', $line, $matches ) ) {
			$status_text               = trim( $matches[1] );
			$current_control['status'] = $status_text;

			// Map status to key.
			if ( strpos( $status_text, 'Implemented' ) !== false ) {
				$current_control['status_key'] = 'implemented';
			} elseif ( strpos( $status_text, 'Partial' ) !== false ) {
				$current_control['status_key'] = 'partial';
			} elseif ( strpos( $status_text, 'Planned' ) !== false ) {
				$current_control['status_key'] = 'planned';
			} elseif ( strpos( $status_text, 'Not Applicable' ) !== false ) {
				$current_control['status_key'] = 'not_applicable';
				$current_control['applicable'] = false;
			}
		} elseif ( $current_control && preg_match( '/^\*\*Applicability:\*\*\s+(.+)$/', $line, $matches ) ) {
			$applicable_text                = trim( $matches[1] );
			$current_control['applicable'] = ( strcasecmp( $applicable_text, 'Yes' ) === 0 );
		} elseif ( $current_control && preg_match( '/^\*\*Justification:\*\*\s+(.+)$/', $line, $matches ) ) {
			$current_control['justification'] = trim( $matches[1] );
		} elseif ( $current_control && preg_match( '/^\*\*Implementation:\*\*\s*$/', $line ) ) {
			// Start collecting implementation lines.
			$in_implementation = true;
		} elseif ( $current_control && preg_match( '/^\*\*Evidence:\*\*/', $line ) ) {
			// Stop collecting implementation lines when we hit Evidence section.
			$in_implementation = false;
		} elseif ( $in_implementation && ! empty( trim( $line ) ) && ! preg_match( '/^\*\*/', $line ) ) {
			// Collect implementation bullet points and text.
			$line = trim( $line );
			if ( preg_match( '/^-\s+(.+)$/', $line, $matches ) ) {
				// Bullet point - extract the text.
				$implementation_lines[] = trim( $matches[1] );
			}
		}
	}

	// Save last control.
	if ( $current_control ) {
		// Process collected implementation lines for the last control.
		if ( ! empty( $implementation_lines ) ) {
			$current_control['description'] = implode( "\n", $implementation_lines );
		}
		$controls[] = $current_control;
	}

	return $controls;
}

/**
 * Calculate statistics for controls.
 *
 * @param array $controls Array of controls.
 * @return array Statistics.
 */
function calculate_stats( $controls ) {
	$stats = array(
		'implemented'    => 0,
		'partial'        => 0,
		'planned'        => 0,
		'not_applicable' => 0,
		'total'          => count( $controls ),
	);

	foreach ( $controls as $control ) {
		$status_key = $control['status_key'] ?? '';
		if ( isset( $stats[ $status_key ] ) ) {
			++$stats[ $status_key ];
		}
	}

	return $stats;
}

/**
 * Generate PHP class content.
 *
 * @param array $iso27001_controls ISO 27001 controls.
 * @return string PHP class content.
 */
function generate_php_class( $iso27001_controls ) {
	$iso27001_stats = calculate_stats( $iso27001_controls );

	$class_content = <<<'PHP_CLASS'
<?php
/**
 * Compliance Data Provider
 *
 * This class provides embedded compliance data for ISO 27001, SOC 2, and HIPAA
 * frameworks. The data is sourced from Statement of Applicability markdown files
 * and embedded here to ensure it's available in deployed plugin packages.
 *
 * DO NOT EDIT THIS FILE MANUALLY.
 * This file is auto-generated by bin/generate-compliance-data.php
 * Run `php bin/generate-compliance-data.php` to regenerate.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Compliance_Data' ) ) {
	/**
	 * Provides embedded compliance framework data.
	 *
	 * @since 1.5.0
	 */
	class WP_MCP_AI_Compliance_Data {
		/**
		 * Get ISO 27001:2022 controls.
		 *
		 * Returns all 93 controls from Annex A with their implementation status,
		 * applicability, justification, and descriptions.
		 *
		 * @return array Array of controls with id, name, status, status_key, applicable, justification, and description.
		 */
		public static function get_iso27001_controls() {
			return %ISO27001_DATA%;
		}

		/**
		 * Get ISO 27001 statistics.
		 *
		 * @return array Statistics with counts for each status.
		 */
		public static function get_iso27001_stats() {
			return %ISO27001_STATS%;
		}

		/**
		 * Get SOC 2 compliance percentage.
		 *
		 * Calculates compliance percentage based on implemented Trust Services Criteria.
		 * This is a placeholder that should be updated with actual SOC 2 parsing.
		 *
		 * @return int Compliance percentage (0-100).
		 */
		public static function get_soc2_compliance() {
			// This would be calculated from SOC 2 SoA file.
			// For now, return a default based on ISO 27001 implementation.
			$iso_stats = self::get_iso27001_stats();
			$total_applicable = $iso_stats['total'] - $iso_stats['not_applicable'];
			
			if ( $total_applicable > 0 ) {
				return round( ( $iso_stats['implemented'] / $total_applicable ) * 100 );
			}
			
			return 0;
		}

		/**
		 * Get HIPAA compliance percentage.
		 *
		 * Calculates compliance percentage based on implemented HIPAA Security Rule safeguards.
		 * This is a placeholder that should be updated with actual HIPAA parsing.
		 *
		 * @return int Compliance percentage (0-100).
		 */
		public static function get_hipaa_compliance() {
			// This would be calculated from HIPAA SoA file.
			// For now, return a default based on ISO 27001 implementation.
			$iso_stats = self::get_iso27001_stats();
			$total_applicable = $iso_stats['total'] - $iso_stats['not_applicable'];
			
			if ( $total_applicable > 0 ) {
				return round( ( $iso_stats['implemented'] / $total_applicable ) * 100 );
			}
			
			return 0;
		}
	}
}

PHP_CLASS;

	// Replace placeholders with actual data.
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Legitimate use in code generator.
	$iso27001_data_export  = var_export( $iso27001_controls, true );
	$iso27001_stats_export = var_export( $iso27001_stats, true );

	$class_content = str_replace(
		array( '%ISO27001_DATA%', '%ISO27001_STATS%' ),
		array( $iso27001_data_export, $iso27001_stats_export ),
		$class_content
	);

	return $class_content;
}

// Main execution.
echo "Parsing ISO 27001 Statement of Applicability...\n";
$iso27001_file = $docs_dir . '/iso27001/Statement-of-Applicability.md';
$iso27001_controls = parse_iso27001_controls( $iso27001_file );

if ( empty( $iso27001_controls ) ) {
	echo "Error: No controls parsed from ISO 27001 file.\n";
	exit( 1 );
}

$iso27001_stats = calculate_stats( $iso27001_controls );
echo "Parsed " . count( $iso27001_controls ) . " ISO 27001 controls:\n";
echo "  - Implemented: " . $iso27001_stats['implemented'] . "\n";
echo "  - Partial: " . $iso27001_stats['partial'] . "\n";
echo "  - Planned: " . $iso27001_stats['planned'] . "\n";
echo "  - Not Applicable: " . $iso27001_stats['not_applicable'] . "\n";

echo "\nGenerating PHP class...\n";
$php_content = generate_php_class( $iso27001_controls );

$output_file = $data_dir . '/class-wp-mcp-ai-compliance-data.php';
if ( file_put_contents( $output_file, $php_content ) ) {
	echo "Successfully generated: $output_file\n";
	echo "File size: " . filesize( $output_file ) . " bytes\n";
	exit( 0 );
} else {
	echo "Error: Failed to write output file.\n";
	exit( 1 );
}
