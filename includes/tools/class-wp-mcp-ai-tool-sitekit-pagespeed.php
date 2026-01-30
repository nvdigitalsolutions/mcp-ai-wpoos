<?php
/**
 * Site Kit PageSpeed Insights Tool
 *
 * Provides access to PageSpeed Insights data through Site Kit.
 *
 * @package    WP_MCP_AI
 * @subpackage Tools
 * @since      1.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Kit PageSpeed Insights Tool Class
 *
 * Retrieves PageSpeed performance data including scores,
 * Core Web Vitals, and optimization recommendations.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_SiteKit_PageSpeed implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Check whether the tool can be registered.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'Google\\Site_Kit\\Plugin' );
	}

	/**
	 * Provide a message explaining why the tool is unavailable.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Google Site Kit plugin must be installed and configured to use PageSpeed Insights tools.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool slug
	 *
	 * @since 1.2.0
	 * @return string Tool slug
	 */
	public function get_slug() {
		return 'sitekit_get_pagespeed';
	}

	/**
	 * Get tool name
	 *
	 * @since 1.2.0
	 * @return string Tool name
	 */
	public function get_name() {
		return __( 'Get PageSpeed Insights', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description
	 *
	 * @since 1.2.0
	 * @return string Tool description
	 */
	public function get_description() {
		return __( 'Retrieve PageSpeed Insights performance data including performance scores, Core Web Vitals (LCP, FID, CLS), and optimization recommendations for mobile and desktop.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get parameters schema
	 *
	 * @since 1.2.0
	 * @return array Parameters schema
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'      => array(
					'type'        => 'string',
					'description' => __( 'Page URL to analyze. Defaults to homepage if not provided.', 'mcp-ai-wpoos' ),
					'format'      => 'uri',
				),
				'strategy' => array(
					'type'        => 'string',
					'description' => __( 'Device strategy to analyze', 'mcp-ai-wpoos' ),
					'enum'        => array( 'mobile', 'desktop' ),
					'default'     => 'mobile',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool
	 *
	 * @since 1.2.0
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if Site Kit is available.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'sitekit_not_available',
				__( 'Google Site Kit is not active or configured', 'mcp-ai-wpoos' )
			);
		}

		// Check user permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to access PageSpeed data', 'mcp-ai-wpoos' )
			);
		}

		// Get Site Kit integration instance.
		if ( ! class_exists( 'WP_MCP_AI_SiteKit_Integration' ) ) {
			return new WP_Error(
				'integration_not_loaded',
				__( 'Site Kit integration is not properly loaded', 'mcp-ai-wpoos' )
			);
		}

		$sitekit = WP_MCP_AI_SiteKit_Integration::get_instance();

		// Parse arguments.
		$url      = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : home_url( '/' );
		$strategy = isset( $arguments['strategy'] ) ? sanitize_text_field( $arguments['strategy'] ) : 'mobile';

		// Build Site Kit API request.
		$endpoint = '/wp-json/google-site-kit/v1/modules/pagespeed-insights/data/pagespeed';
		$api_args = array(
			'url'      => $url,
			'strategy' => $strategy,
		);

		// Make request to Site Kit.
		$response = $sitekit->make_sitekit_request( $endpoint, $api_args );

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Format response for AI assistant.
		return $this->format_pagespeed_response( $response, $url, $strategy );
	}

	/**
	 * Format PageSpeed response for AI
	 *
	 * @since 1.2.0
	 * @param array  $response  Site Kit API response.
	 * @param string $url       Analyzed URL.
	 * @param string $strategy  Device strategy.
	 * @return array Formatted response
	 */
	private function format_pagespeed_response( $response, $url, $strategy ) {
		$formatted = array(
			'success'  => true,
			'url'      => $url,
			'strategy' => $strategy,
		);

		// Extract performance score.
		if ( isset( $response['lighthouseResult']['categories']['performance']['score'] ) ) {
			$score                     = $response['lighthouseResult']['categories']['performance']['score'];
			$formatted['score']        = round( $score * 100 );
			$formatted['score_rating'] = $this->get_score_rating( $formatted['score'] );
		}

		// Extract Core Web Vitals.
		$formatted['core_web_vitals'] = array();

		$metrics = array(
			'largest-contentful-paint'  => 'LCP',
			'first-input-delay'         => 'FID',
			'cumulative-layout-shift'   => 'CLS',
			'first-contentful-paint'    => 'FCP',
			'interaction-to-next-paint' => 'INP',
			'time-to-first-byte'        => 'TTFB',
		);

		if ( isset( $response['lighthouseResult']['audits'] ) ) {
			foreach ( $metrics as $audit_key => $metric_name ) {
				if ( isset( $response['lighthouseResult']['audits'][ $audit_key ] ) ) {
					$audit = $response['lighthouseResult']['audits'][ $audit_key ];

					$metric_data = array(
						'name'  => $metric_name,
						'title' => isset( $audit['title'] ) ? $audit['title'] : $metric_name,
					);

					if ( isset( $audit['displayValue'] ) ) {
						$metric_data['value'] = $audit['displayValue'];
					}

					if ( isset( $audit['score'] ) ) {
						$metric_data['score']  = round( $audit['score'] * 100 );
						$metric_data['rating'] = $this->get_metric_rating( $audit['score'] );
					}

					$formatted['core_web_vitals'][ $metric_name ] = $metric_data;
				}
			}
		}

		// Extract opportunities (optimization suggestions).
		$formatted['opportunities'] = array();

		if ( isset( $response['lighthouseResult']['audits'] ) ) {
			foreach ( $response['lighthouseResult']['audits'] as $audit_key => $audit ) {
				if ( isset( $audit['details']['type'] ) && 'opportunity' === $audit['details']['type'] ) {
					if ( isset( $audit['numericValue'] ) && $audit['numericValue'] > 0 ) {
						$opportunity = array(
							'id'          => $audit_key,
							'title'       => isset( $audit['title'] ) ? $audit['title'] : '',
							'description' => isset( $audit['description'] ) ? wp_strip_all_tags( $audit['description'] ) : '',
						);

						if ( isset( $audit['displayValue'] ) ) {
							$opportunity['savings'] = $audit['displayValue'];
						}

						$formatted['opportunities'][] = $opportunity;
					}
				}
			}
		}

		// Add summary.
		$formatted['summary'] = $this->generate_summary( $formatted, $url, $strategy );

		return $formatted;
	}

	/**
	 * Get score rating
	 *
	 * @since 1.2.0
	 * @param int $score Performance score (0-100).
	 * @return string Rating
	 */
	private function get_score_rating( $score ) {
		if ( $score >= 90 ) {
			return __( 'Good', 'mcp-ai-wpoos' );
		} elseif ( $score >= 50 ) {
			return __( 'Needs Improvement', 'mcp-ai-wpoos' );
		} else {
			return __( 'Poor', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * Get metric rating
	 *
	 * @since 1.2.0
	 * @param float $score Metric score (0-1).
	 * @return string Rating
	 */
	private function get_metric_rating( $score ) {
		if ( $score >= 0.9 ) {
			return __( 'Good', 'mcp-ai-wpoos' );
		} elseif ( $score >= 0.5 ) {
			return __( 'Needs Improvement', 'mcp-ai-wpoos' );
		} else {
			return __( 'Poor', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * Generate human-readable summary
	 *
	 * @since 1.2.0
	 * @param array  $formatted Formatted response.
	 * @param string $url       Analyzed URL.
	 * @param string $strategy  Device strategy.
	 * @return string Summary text
	 */
	private function generate_summary( $formatted, $url, $strategy ) {
		if ( ! isset( $formatted['score'] ) ) {
			return __( 'Unable to retrieve PageSpeed data', 'mcp-ai-wpoos' );
		}

		$score  = $formatted['score'];
		$rating = $formatted['score_rating'];

		$summary = sprintf(
			/* translators: 1: URL, 2: strategy (mobile/desktop), 3: score, 4: rating */
			__( '%1$s has a %2$s performance score of %3$d/100 (%4$s)', 'mcp-ai-wpoos' ),
			$url,
			$strategy,
			$score,
			$rating
		);

		if ( ! empty( $formatted['opportunities'] ) ) {
			$summary .= '. ' . sprintf(
				/* translators: %d: number of optimization opportunities */
				_n(
					'Found %d optimization opportunity',
					'Found %d optimization opportunities',
					count( $formatted['opportunities'] ),
					'mcp-ai-wpoos'
				),
				count( $formatted['opportunities'] )
			);
		}

		return $summary;
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.2.0
	 * @return int Capability flags.
	 */
	public function get_capability_flags() {
		return WP_MCP_AI_Tool_Capability_Flags_Interface::CAPABILITY_CAN_USE_IF_ADMIN;
	}

/**
 * Get extended tool definition including toolkit metadata.
 *
 * @since 1.1.0
 *
 * @return array Tool definition with metadata.
 */
public function get_definition() {
	return array(
		'name'                  => $this->get_name(),
		'description'           => $this->get_description(),
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'web_developer', 'performance_engineer' ),
		'risk_level'            => 'info',
	);
}

}
