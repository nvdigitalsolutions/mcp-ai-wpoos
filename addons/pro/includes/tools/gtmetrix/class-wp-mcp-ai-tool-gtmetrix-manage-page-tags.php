<?php
/**
 * GTmetrix tool: manage page tags.
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
 * Manage GTmetrix page tags: list, create, update, attach and detach.
 * Tag deletion is deliberately not exposed (proposal decision §7.6).
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Manage_Page_Tags extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_manage_page_tags';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Manage Page Tags', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manage GTmetrix page tags (list, create, update, attach, detach) to segment pages by environment, purpose or client. Tag names are unique, max 45 characters; colors are 6-digit hex without the leading #.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'external-api', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'      => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'create', 'update', 'attach', 'detach' ),
					'description' => __( 'Tag operation to perform.', 'mcp-ai-wpoos-pro' ),
				),
				'tag_id'      => array(
					'type'        => 'string',
					'description' => __( 'Tag ID (update/attach/detach).', 'mcp-ai-wpoos-pro' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Tag name (create/update; unique, max 45 chars).', 'mcp-ai-wpoos-pro' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Tag description (max 255 chars).', 'mcp-ai-wpoos-pro' ),
				),
				'color'       => array(
					'type'        => 'string',
					'description' => __( 'Tag color as 6-digit hex without # (e.g. FF8800).', 'mcp-ai-wpoos-pro' ),
				),
				'page_ids'    => array(
					'type'        => 'array',
					'description' => __( 'Page IDs to attach to or detach from the tag (max 500).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'action' ),
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

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
		switch ( $action ) {
			case 'list':
				$tags = $this->client()->list_tags();
				if ( is_wp_error( $tags ) ) {
					return $tags;
				}
				$rows = array();
				foreach ( $tags as $tag ) {
					$attrs  = isset( $tag['attributes'] ) ? $tag['attributes'] : array();
					$rows[] = array(
						'tag_id'      => isset( $tag['id'] ) ? $tag['id'] : '',
						'name'        => isset( $attrs['name'] ) ? $attrs['name'] : '',
						'description' => isset( $attrs['description'] ) ? $attrs['description'] : '',
						'color'       => isset( $attrs['color'] ) ? $attrs['color'] : '',
					);
				}

				return array(
					'success' => true,
					'tags'    => $rows,
					'count'   => count( $rows ),
				);

			case 'create':
				return $this->create_or_update( $arguments, false );

			case 'update':
				return $this->create_or_update( $arguments, true );

			case 'attach':
			case 'detach':
				$tag_id = isset( $arguments['tag_id'] ) ? sanitize_text_field( (string) $arguments['tag_id'] ) : '';
				if ( '' === $tag_id ) {
					return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_tag', __( 'A tag ID is required for attach/detach.', 'mcp-ai-wpoos-pro' ) );
				}
				$page_ids = isset( $arguments['page_ids'] ) && is_array( $arguments['page_ids'] ) ? $arguments['page_ids'] : array();
				if ( empty( $page_ids ) || count( $page_ids ) > 500 ) {
					return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_pages', __( 'Provide 1–500 page IDs.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = $this->attach_or_detach( $tag_id, $page_ids, 'detach' === $action );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'success' => true,
					'tag_id'  => $tag_id,
					'action'  => $action,
					'pages'   => count( $page_ids ),
				);

			default:
				return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_action', __( 'Unknown tag action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Create or update a tag through the page_tags endpoint.
	 *
	 * @param array $arguments Tool arguments.
	 * @param bool  $is_update Whether this is an update (requires tag_id).
	 * @return array|WP_Error
	 */
	private function create_or_update( $arguments, $is_update ) {
		$tag_id = isset( $arguments['tag_id'] ) ? sanitize_text_field( (string) $arguments['tag_id'] ) : '';
		$name   = isset( $arguments['name'] ) ? sanitize_text_field( (string) $arguments['name'] ) : '';
		$desc   = isset( $arguments['description'] ) ? sanitize_text_field( (string) $arguments['description'] ) : '';
		$color  = isset( $arguments['color'] ) ? sanitize_text_field( (string) $arguments['color'] ) : '';

		if ( $is_update && '' === $tag_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_tag', __( 'A tag ID is required for update.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( '' !== $name && mb_strlen( $name ) > 45 ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_tag', __( 'Tag names are limited to 45 characters.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( mb_strlen( $desc ) > 255 ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_tag', __( 'Tag descriptions are limited to 255 characters.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( '' !== $color && ! preg_match( '/^[0-9A-Fa-f]{6}$/', $color ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_tag', __( 'Tag colors are 6-digit hex without the leading #.', 'mcp-ai-wpoos-pro' ) );
		}

		$attributes = array();
		if ( '' !== $name ) {
			$attributes['name'] = $name;
		}
		if ( '' !== $desc ) {
			$attributes['description'] = $desc;
		}
		if ( '' !== $color ) {
			$attributes['color'] = $color;
		}
		if ( $is_update && '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_tag', __( 'A name is required for update.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->client()->create_tag( $attributes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$attrs = isset( $result['attributes'] ) ? $result['attributes'] : array();

		return array(
			'success' => true,
			'tag_id'  => isset( $result['id'] ) ? $result['id'] : '',
			'name'    => isset( $attrs['name'] ) ? $attrs['name'] : '',
		);
	}

	/**
	 * Attach or detach pages to/from a tag via the page-side endpoint.
	 *
	 * @param string   $tag_id   Tag ID.
	 * @param string[] $page_ids Page IDs.
	 * @param bool     $detach   Detach instead of attach.
	 * @return array|WP_Error
	 */
	private function attach_or_detach( $tag_id, $page_ids, $detach ) {
		$results = array();
		foreach ( $page_ids as $page_id ) {
			$page_id = sanitize_text_field( (string) $page_id );
			if ( '' === $page_id ) {
				continue;
			}
			$result = $this->client()->update_page_tags( $page_id, array( $tag_id ), $detach );
			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'page_id' => $page_id,
					'error'   => $result->get_error_message(),
				);
			} else {
				$results[] = array(
					'page_id' => $page_id,
					'ok'      => true,
				);
			}
		}

		return array(
			'results' => $results,
		);
	}
}
