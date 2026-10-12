<?php
/**
 * GTmetrix tool: list report resources.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-tool-base.php';

/**
 * List a report's available resources with expiry, credit-cost and
 * sensitivity annotations. Sensitive resources are never downloadable.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_List_Report_Resources extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * Resource annotations: 24h-expiry and sensitivity.
	 *
	 * @var array<string, array>
	 */
	private $resource_meta = array(
		'screenshot.jpg'       => array(
			'expires_24h' => false,
			'sensitive'   => false,
			'notes'       => 'Report screenshot image.',
		),
		'filmstrip.json'       => array(
			'expires_24h' => false,
			'sensitive'   => false,
			'notes'       => 'Filmstrip frames captured during load.',
		),
		'net.har'              => array(
			'expires_24h' => false,
			'sensitive'   => true,
			'notes'       => 'Raw network capture — never downloaded by the toolkit.',
		),
		'analysis-options'     => array(
			'expires_24h' => false,
			'sensitive'   => true,
			'notes'       => 'Contains HTTP auth credentials — never downloaded by the toolkit.',
		),
		'video.mp4'            => array(
			'expires_24h' => false,
			'sensitive'   => false,
			'notes'       => 'Page-load video (extra credits when requested).',
		),
		'optimized-images.tar' => array(
			'expires_24h' => true,
			'sensitive'   => false,
			'notes'       => 'Optimized image assets; only available for 24 hours.',
		),
		'report.pdf'           => array(
			'expires_24h' => false,
			'sensitive'   => false,
			'notes'       => 'PDF render — costs extra credits.',
		),
		'lighthouse.json'      => array(
			'expires_24h' => false,
			'sensitive'   => false,
			'notes'       => 'Lighthouse audit JSON with GTmetrix extensions.',
		),
		'pagespeed.json'       => array(
			'expires_24h' => false,
			'sensitive'   => false,
			'notes'       => 'Legacy PageSpeed JSON.',
		),
		'pagespeed-files.tar'  => array(
			'expires_24h' => true,
			'sensitive'   => false,
			'notes'       => 'Legacy optimized files; only available for 24 hours.',
		),
		'yslow.json'           => array(
			'expires_24h' => false,
			'sensitive'   => false,
			'notes'       => 'Legacy YSlow JSON.',
		),
		'resource-summary'     => array(
			'expires_24h' => false,
			'sensitive'   => false,
			'notes'       => 'Per-type resource counts and sizes.',
		),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_list_report_resources';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix List Report Resources', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List the resources available for a GTmetrix report (screenshot, Lighthouse JSON, HAR, optimized images, PDF) with expiry and credit-cost annotations. Sensitive resources are never downloadable.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id' => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix report ID.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'report_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard_key();
		if ( $error ) {
			return $error;
		}

		$report_id = $this->arg_report_id( $arguments );
		if ( '' === $report_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_report', __( 'A report ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$report = $this->client()->get_report( $report_id );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$attrs = isset( $report['attributes'] ) ? $report['attributes'] : array();
		$links = isset( $attrs['links'] ) && is_array( $attrs['links'] ) ? $attrs['links'] : array();

		$resources = array();
		foreach ( $links as $name => $url ) {
			$meta        = isset( $this->resource_meta[ $name ] ) ? $this->resource_meta[ $name ] : array(
				'expires_24h' => false,
				'sensitive'   => false,
				'notes'       => '',
			);
			$resources[] = array(
				'name'         => $name,
				'downloadable' => ! $meta['sensitive'],
				'sensitive'    => $meta['sensitive'],
				'expires_24h'  => $meta['expires_24h'],
				'notes'        => $meta['notes'],
			);
		}

		return array(
			'success'   => true,
			'report_id' => $report_id,
			'resources' => $resources,
			'count'     => count( $resources ),
		);
	}
}
