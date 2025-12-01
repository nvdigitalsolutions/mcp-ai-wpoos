<?php
/**
 * Tool exposing pending core, plugin, and theme updates.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a structured snapshot of available WordPress updates.
 */
class WP_MCP_AI_Tool_Get_Update_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_update_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Update Status', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns pending core, plugin, and theme updates with version and download details.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'component_type' => array(
					'type'        => 'string',
					'enum'        => array( 'core', 'plugin', 'theme' ),
					'description' => __( 'Limit the response to core, plugin, or theme updates.', 'wp-mcp-ai' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'update_core' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to inspect update status.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/update.php';

		$component_type      = isset( $arguments['component_type'] ) ? sanitize_key( $arguments['component_type'] ) : '';
		$valid_component_map = array(
			'core'   => 'core',
			'plugin' => 'plugins',
			'theme'  => 'themes',
		);

		if ( $component_type && ! isset( $valid_component_map[ $component_type ] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_component', __( 'Invalid component type supplied.', 'wp-mcp-ai' ) );
		}

		$update_summary = wp_get_update_data();
		$counts         = isset( $update_summary['counts'] ) && is_array( $update_summary['counts'] ) ? $update_summary['counts'] : array();

		$response = array(
			'summary'    => array(
				'total'   => (int) ( isset( $counts['total'] ) ? $counts['total'] : 0 ),
				'core'    => (int) ( isset( $counts['wordpress'] ) ? $counts['wordpress'] : 0 ),
				'plugins' => (int) ( isset( $counts['plugins'] ) ? $counts['plugins'] : 0 ),
				'themes'  => (int) ( isset( $counts['themes'] ) ? $counts['themes'] : 0 ),
			),
			'components' => array(),
		);

		if ( $component_type ) {
			$response['filters'] = array( 'component_type' => $component_type );
		}

		// Core updates.
		if ( ! $component_type || 'core' === $component_type ) {
			$core_updates = array();

			if ( function_exists( 'get_core_updates' ) ) {
				$available_core_updates = get_core_updates();

				if ( is_array( $available_core_updates ) ) {
					foreach ( $available_core_updates as $update ) {
						if ( isset( $update->response ) && 'latest' === $update->response ) {
							continue;
						}

						$core_updates[] = array(
							'name'            => 'WordPress',
							'current_version' => get_bloginfo( 'version' ),
							'new_version'     => isset( $update->current ) ? $update->current : ( isset( $update->version ) ? $update->version : '' ),
							'download_url'    => isset( $update->download ) ? $update->download : '',
							'locale'          => isset( $update->locale ) ? $update->locale : get_locale(),
							'php_version'     => isset( $update->php_version ) ? $update->php_version : '',
							'mysql_version'   => isset( $update->mysql_version ) ? $update->mysql_version : '',
						);
					}
				}
			}

			$response['components']['core'] = $core_updates;
		}

		// Plugin updates.
		if ( ! $component_type || 'plugin' === $component_type ) {
			$plugin_updates = array();
			$available      = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();

			if ( is_array( $available ) ) {
				foreach ( $available as $plugin_file => $plugin ) {
					$update_data = isset( $plugin->update ) && is_object( $plugin->update ) ? $plugin->update : $plugin;

					$plugin_updates[] = array(
						'name'            => isset( $plugin->Name ) ? $plugin->Name : ( isset( $update_data->name ) ? $update_data->name : $plugin_file ),
						'slug'            => isset( $plugin->slug ) ? $plugin->slug : ( isset( $update_data->slug ) ? $update_data->slug : '' ),
						'plugin_file'     => $plugin_file,
						'current_version' => isset( $plugin->Version ) ? $plugin->Version : ( isset( $plugin->version ) ? $plugin->version : '' ),
						'new_version'     => isset( $plugin->new_version ) ? $plugin->new_version : ( isset( $update_data->new_version ) ? $update_data->new_version : '' ),
						'homepage'        => isset( $plugin->url ) ? $plugin->url : ( isset( $update_data->url ) ? $update_data->url : '' ),
						'download_url'    => isset( $plugin->package ) ? $plugin->package : ( isset( $update_data->package ) ? $update_data->package : '' ),
					);
				}
			}

			$response['components']['plugins'] = $plugin_updates;
		}

		// Theme updates.
		if ( ! $component_type || 'theme' === $component_type ) {
			$theme_updates = array();
			$available     = function_exists( 'get_theme_updates' ) ? get_theme_updates() : array();

			if ( is_array( $available ) ) {
				foreach ( $available as $stylesheet => $theme ) {
					$update_data = isset( $theme->update ) && is_object( $theme->update ) ? $theme->update : $theme;

					$theme_updates[] = array(
						'name'            => isset( $theme->display_name ) ? $theme->display_name : ( isset( $update_data->name ) ? $update_data->name : $stylesheet ),
						'slug'            => isset( $theme->theme ) ? $theme->theme : $stylesheet,
						'stylesheet'      => $stylesheet,
						'current_version' => isset( $theme->Version ) ? $theme->Version : ( isset( $theme->version ) ? $theme->version : '' ),
						'new_version'     => isset( $update_data->new_version ) ? $update_data->new_version : '',
						'homepage'        => isset( $update_data->url ) ? $update_data->url : '',
						'download_url'    => isset( $update_data->package ) ? $update_data->package : '',
					);
				}
			}

			$response['components']['themes'] = $theme_updates;
		}

		if ( $component_type ) {
			$component_key          = $valid_component_map[ $component_type ];
			$response['components'] = array(
				$component_key => isset( $response['components'][ $component_key ] ) ? $response['components'][ $component_key ] : array(),
			);
		}

		return $response;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
