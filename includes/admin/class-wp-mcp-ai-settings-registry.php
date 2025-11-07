<?php
/**
 * Settings Registry System for WP oOS
 *
 * Central registry for all plugin settings, organized by tabs and sections.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
	/**
	 * Manages registration and retrieval of settings sections.
	 */
	class WP_MCP_AI_Settings_Registry {
		/**
		 * Registered section instances.
		 *
		 * @var array<string, WP_MCP_AI_Settings_Section>
		 */
		private static $sections = array();

		/**
		 * Tab definitions.
		 *
		 * @var array
		 */
		private static $tabs = array(
			'general'        => array(
				'title' => 'General',
				'icon'  => 'dashicons-admin-settings',
			),
			'providers'      => array(
				'title' => 'AI Providers',
				'icon'  => 'dashicons-admin-generic',
			),
			'authentication' => array(
				'title' => 'Authentication',
				'icon'  => 'dashicons-lock',
			),
			'tools'          => array(
				'title' => 'Tools & Features',
				'icon'  => 'dashicons-admin-tools',
			),
			'integrations'   => array(
				'title' => 'Integrations',
				'icon'  => 'dashicons-admin-plugins',
			),
			'security'       => array(
				'title' => 'Security',
				'icon'  => 'dashicons-shield',
			),
			'advanced'       => array(
				'title' => 'Advanced',
				'icon'  => 'dashicons-admin-generic',
			),
		);

		/**
		 * Register a settings section.
		 *
		 * @param WP_MCP_AI_Settings_Section $section Section instance.
		 */
		public static function register_section( $section ) {
			if ( ! $section instanceof WP_MCP_AI_Settings_Section ) {
				return;
			}

			$section_id                 = $section->get_id();
			self::$sections[ $section_id ] = $section;
		}

		/**
		 * Get all registered sections for a specific tab.
		 *
		 * @param string $tab Tab ID.
		 * @return array<WP_MCP_AI_Settings_Section>
		 */
		public static function get_sections( $tab = null ) {
			if ( null === $tab ) {
				return self::$sections;
			}

			$filtered = array();
			foreach ( self::$sections as $section ) {
				if ( $section->get_tab() === $tab ) {
					$filtered[] = $section;
				}
			}

			// Sort by priority.
			usort(
				$filtered,
				function ( $a, $b ) {
					return $a->get_priority() - $b->get_priority();
				}
			);

			return $filtered;
		}

		/**
		 * Get a specific section by ID.
		 *
		 * @param string $section_id Section ID.
		 * @return WP_MCP_AI_Settings_Section|null
		 */
		public static function get_section( $section_id ) {
			return isset( self::$sections[ $section_id ] ) ? self::$sections[ $section_id ] : null;
		}

		/**
		 * Get all tab definitions.
		 *
		 * @return array
		 */
		public static function get_tabs() {
			return self::$tabs;
		}

		/**
		 * Get a setting value.
		 *
		 * @param string $key Setting key.
		 * @param mixed  $default Default value.
		 * @return mixed
		 */
		public static function get_setting( $key, $default = null ) {
			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
		}

		/**
		 * Update a setting value.
		 *
		 * @param string $key Setting key.
		 * @param mixed  $value Setting value.
		 * @return bool
		 */
		public static function update_setting( $key, $value ) {
			$settings         = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$settings[ $key ] = $value;
			return update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		}

		/**
		 * Update multiple settings at once.
		 *
		 * @param array $settings Associative array of settings.
		 * @return bool
		 */
		public static function update_settings( $settings ) {
			$current = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$updated = array_merge( $current, $settings );
			return update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $updated );
		}
	}
}
