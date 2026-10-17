<?php
/**
 * GTmetrix tool: list browsers.
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
 * List GTmetrix test browsers with per-feature support flags.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_List_Browsers extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_list_browsers';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix List Browsers', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List GTmetrix test browsers with platform and feature support (Lighthouse, throttling, video, custom DNS). Use to validate browser choices before starting tests.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			// Empty stdClass encodes as `{}`; an empty PHP array would encode
			// as `[]`, which strict providers (DeepSeek) reject.
			'properties' => new stdClass(),
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

		$browsers = $this->client()->get_browsers();
		if ( is_wp_error( $browsers ) ) {
			return $browsers;
		}

		$rows = array();
		foreach ( $browsers as $browser ) {
			$attrs  = isset( $browser['attributes'] ) ? $browser['attributes'] : array();
			$rows[] = array(
				'id'         => isset( $browser['id'] ) ? $browser['id'] : '',
				'name'       => isset( $attrs['name'] ) ? $attrs['name'] : '',
				'platform'   => isset( $attrs['platform'] ) ? $attrs['platform'] : '',
				'device'     => isset( $attrs['device'] ) ? $attrs['device'] : '',
				'is_default' => ! empty( $attrs['default'] ),
				'features'   => array(
					'lighthouse' => ! empty( $attrs['lighthouse'] ),
					'throttle'   => ! empty( $attrs['throttle'] ),
					'video'      => ! empty( $attrs['video'] ),
					'cookies'    => ! empty( $attrs['cookies'] ),
					'dns'        => ! empty( $attrs['dns'] ),
					'adblock'    => ! empty( $attrs['adblock'] ),
					'http_auth'  => ! empty( $attrs['http_auth'] ),
					'user_agent' => ! empty( $attrs['user_agent'] ),
				),
			);
		}

		return array(
			'success'  => true,
			'browsers' => $rows,
			'count'    => count( $rows ),
		);
	}
}
