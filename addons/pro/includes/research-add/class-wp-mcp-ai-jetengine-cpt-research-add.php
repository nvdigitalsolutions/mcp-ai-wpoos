<?php
/**
 * JetEngine CPT Research & Add
 *
 * Dynamic Research & Add implementation for JetEngine custom post types.
 * Works with any CPT created through JetEngine, adapting to their fields dynamically.
 *
 * Best Practices (Based on JetEngine 2024 Documentation):
 * - Uses jet_engine()->meta_boxes->get_fields_for_context() for proper field retrieval
 * - Supports all JetEngine field types: text, textarea, wysiwyg, select, radio, checkbox, etc.
 * - Integrates with JetEngine's meta field validation and sanitization
 * - Performance optimized for JetEngine CPTs (stored in wp_posts table)
 *
 * Note: For high-volume data (1000+ entries), consider using JetEngine CCT (Custom Content Types)
 * instead of CPT for better database performance. CCT uses separate tables vs shared wp_posts.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @see https://crocoblock.com/knowledge-base/jetengine/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * JetEngine CPT Research & Add implementation.
 *
 * Provides Research & Add interface for JetEngine custom post types.
 * Dynamically adapts to any JetEngine CPT structure and fields.
 */
class WP_MCP_AI_JetEngine_CPT_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * JetEngine CPT data.
	 *
	 * @var array
	 */
	private $jetengine_cpt_data;

	/**
	 * Constructor.
	 *
	 * @param string $cpt_slug JetEngine CPT slug.
	 */
	public function __construct( $cpt_slug ) {
		// Verify JetEngine is active.
		if ( ! $this->is_jetengine_active() ) {
			return;
		}

		// Get CPT data from JetEngine.
		$this->jetengine_cpt_data = $this->get_jetengine_cpt_data( $cpt_slug );
		if ( empty( $this->jetengine_cpt_data ) ) {
			return;
		}

		// Set properties from CPT data.
		$this->post_type   = $cpt_slug;
		$this->page_title  = sprintf(
			/* translators: %s: CPT name */
			__( '%s - Research & Add', 'mcp-ai-wpoos-pro' ),
			$this->get_cpt_name()
		);
		$this->menu_title  = __( 'Research & Add', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'wp-mcp-ai-jetengine-research-' . $cpt_slug;
		$this->capability  = 'edit_posts';

		// Initialize parent with dynamic toolkit slug.
		parent::__construct( 'jetengine_cpt_' . $cpt_slug );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
	}

	/**
	 * Check if JetEngine is active.
	 *
	 * @return bool
	 */
	private function is_jetengine_active() {
		return function_exists( 'jet_engine' ) && class_exists( 'Jet_Engine' );
	}

	/**
	 * Get JetEngine CPT data.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return array|null CPT data or null if not found.
	 */
	private function get_jetengine_cpt_data( $cpt_slug ) {
		if ( ! $this->is_jetengine_active() ) {
			return null;
		}

		$module = jet_engine()->modules->get_module( 'post-type' );
		if ( ! $module || ! $module->instance ) {
			return null;
		}

		$post_types = $module->instance->get_items();
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			return null;
		}

		foreach ( $post_types as $post_type ) {
			if ( isset( $post_type['slug'] ) && $post_type['slug'] === $cpt_slug ) {
				return $post_type;
			}
		}

		return null;
	}

	/**
	 * Get CPT name.
	 *
	 * @return string
	 */
	private function get_cpt_name() {
		if ( isset( $this->jetengine_cpt_data['name'] ) ) {
			return $this->jetengine_cpt_data['name'];
		}

		$post_type_object = get_post_type_object( $this->post_type );
		if ( $post_type_object ) {
			return $post_type_object->labels->name;
		}

		return $this->post_type;
	}

	/**
	 * Get entity types for this toolkit.
	 *
	 * For JetEngine CPTs, we use a single entity type matching the CPT.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			$this->post_type => $this->get_cpt_name(),
		);
	}

	/**
	 * Filter CPT field schema.
	 *
	 * @param array  $schema       Field schema.
	 * @param string $toolkit_slug Toolkit slug.
	 * @param string $entity_type  Entity type.
	 * @return array Filtered schema.
	 */
	public function filter_cpt_field_schema( $schema, $toolkit_slug, $entity_type ) {
		// Only filter for our toolkit and entity.
		if ( $toolkit_slug !== $this->toolkit_slug || $entity_type !== $this->post_type ) {
			return $schema;
		}

		return $this->get_jetengine_field_schema();
	}

	/**
	 * Get JetEngine field schema.
	 *
	 * Extracts field definitions from JetEngine meta fields using the correct API.
	 * Uses jet_engine()->meta_boxes->get_fields_for_context() per JetEngine best practices.
	 * Compatible with JetEngine 3.7+ and 3.8+.
	 *
	 * @return array Field definitions.
	 */
	private function get_jetengine_field_schema() {
		$schema = array();

		// Use compatibility layer for version-safe access.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Compat' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-compat.php';
		}

		// Get registered meta fields for this post type.
		$meta_fields = WP_MCP_AI_JetEngine_Compat::get_post_type_meta_fields( $this->post_type );

		if ( empty( $meta_fields ) || ! is_array( $meta_fields ) ) {
			return $schema;
		}

		foreach ( $meta_fields as $field ) {
			if ( ! isset( $field['name'] ) ) {
				continue;
			}

			$field_name  = $field['name'];
			$field_type  = isset( $field['type'] ) ? $field['type'] : 'text';
			$field_title = isset( $field['title'] ) ? $field['title'] : $field_name;
			$is_required = isset( $field['is_required'] ) ? (bool) $field['is_required'] : false;

			// Map JetEngine field types to Research & Add field types.
			$mapped_type = $this->map_jetengine_field_type( $field_type );

			$schema[ $field_name ] = array(
				'title'       => $field_title,
				'type'        => $mapped_type,
				'is_required' => $is_required,
			);

			// Add options for select/radio/checkbox fields.
			if ( in_array( $field_type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$schema[ $field_name ]['options'] = $field['options'];
				}
			}

			// Add description if available.
			if ( isset( $field['description'] ) && ! empty( $field['description'] ) ) {
				$schema[ $field_name ]['description'] = $field['description'];
			}

			// Add default value if specified.
			if ( isset( $field['default_val'] ) && ! empty( $field['default_val'] ) ) {
				$schema[ $field_name ]['default'] = $field['default_val'];
			}

			// Add field width for better UI layout.
			if ( isset( $field['width'] ) && ! empty( $field['width'] ) ) {
				$schema[ $field_name ]['width'] = $field['width'];
			}
		}

		return $schema;
	}

	/**
	 * Map JetEngine field type to Research & Add field type.
	 *
	 * @param string $jetengine_type JetEngine field type.
	 * @return string Mapped field type.
	 */
	private function map_jetengine_field_type( $jetengine_type ) {
		$type_map = array(
			'text'         => 'text',
			'textarea'     => 'textarea',
			'wysiwyg'      => 'wysiwyg',
			'number'       => 'number',
			'date'         => 'date',
			'time'         => 'time',
			'datetime'     => 'datetime-local',
			'checkbox'     => 'checkbox',
			'radio'        => 'radio',
			'select'       => 'select',
			'media'        => 'media',
			'gallery'      => 'gallery',
			'repeater'     => 'repeater',
			'iconpicker'   => 'text',
			'colorpicker'  => 'colorpicker',
			'posts'        => 'select',
			'html'         => 'wysiwyg',
		);

		return isset( $type_map[ $jetengine_type ] ) ? $type_map[ $jetengine_type ] : 'text';
	}
}
