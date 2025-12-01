<?php
/**
 * Minimal Elementor stubs for unit tests.
 *
 * @package WP_MCP_AI
 */

namespace Elementor {
	if ( ! class_exists( '\\Elementor\\Plugin', false ) ) {
		class Plugin {
			/**
			 * Retrieve the singleton instance.
			 *
			 * @return Plugin
			 */
			public static function instance() {
				static $instance = null;

				if ( null === $instance ) {
					$instance = new self();
				}

				return $instance;
			}
		}
	}

	if ( ! class_exists( '\\Elementor\\Widget_Base', false ) ) {
		/**
		 * Minimal Widget_Base stub for testing.
		 */
		abstract class Widget_Base {
			/**
			 * Widget settings.
			 *
			 * @var array
			 */
			protected $settings = array();

			/**
			 * Set widget settings.
			 *
			 * @param array $settings Widget settings.
			 */
			public function set_settings( $settings ) {
				$this->settings = $settings;
			}

			/**
			 * Get widget settings.
			 *
			 * @param string $setting_key Optional. Setting key to retrieve.
			 * @return mixed
			 */
			public function get_settings( $setting_key = null ) {
				if ( null === $setting_key ) {
					return $this->settings;
				}
				return isset( $this->settings[ $setting_key ] ) ? $this->settings[ $setting_key ] : null;
			}

			/**
			 * Get settings for display (alias of get_settings).
			 *
			 * @param string $setting_key Optional. Setting key to retrieve.
			 * @return mixed
			 */
			public function get_settings_for_display( $setting_key = null ) {
				return $this->get_settings( $setting_key );
			}

			/**
			 * Abstract methods that must be implemented by widgets.
			 */
			abstract public function get_name();
			abstract public function get_title();
			abstract protected function register_controls();
			abstract protected function render();
		}
	}
}

namespace ElementorPro {
	if ( ! class_exists( '\\ElementorPro\\Plugin', false ) ) {
		class Plugin {
			/**
			 * Retrieve the singleton instance.
			 *
			 * @return Plugin
			 */
			public static function instance() {
				static $instance = null;

				if ( null === $instance ) {
					$instance = new self();
				}

				return $instance;
			}
		}
	}
}

namespace {
	if ( ! function_exists( 'wp_mcp_ai_register_elementor_library_post_type' ) ) {
		/**
		 * Register a simplified Elementor library post type for testing.
		 */
		function wp_mcp_ai_register_elementor_library_post_type() {
			if ( post_type_exists( 'elementor_library' ) ) {
				return;
			}

			register_post_type(
				'elementor_library',
				array(
					'label'              => 'Elementor Library',
					'public'             => false,
					'show_ui'            => true,
					'capability_type'    => 'page',
					'map_meta_cap'       => true,
					'supports'           => array( 'title', 'editor' ),
					'rewrite'            => false,
					'publicly_queryable' => false,
					'query_var'          => false,
					'show_in_rest'       => false,
				)
			);
		}
	}
}
