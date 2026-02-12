<?php
/**
 * JetEngine Taxonomy Research & Add
 *
 * Dynamic Research & Add implementation for JetEngine custom taxonomies.
 * Works with any taxonomy created through JetEngine, adapting to their meta fields dynamically.
 *
 * Best Practices (Based on JetEngine 2024 Documentation):
 * - Uses jet_engine()->meta_boxes->get_fields_for_context('taxonomy', $taxonomy) for proper field retrieval
 * - Supports all JetEngine meta field types on taxonomies
 * - Integrates with JetEngine's taxonomy meta validation and sanitization
 * - Allows filtering and ordering taxonomy terms by meta fields via Query Builder
 *
 * Use Cases:
 * - Managing locations, categories, or any hierarchical/flat taxonomy with rich meta fields
 * - Creating directories with taxonomy-based filtering
 * - Building complex classification systems with AI assistance
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @see https://crocoblock.com/knowledge-base/jetengine/creating-custom-taxonomy-with-jetengine/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * JetEngine Taxonomy Research & Add implementation.
 *
 * Provides Research & Add interface for JetEngine custom taxonomies.
 * Dynamically adapts to any JetEngine taxonomy structure and meta fields.
 */
class WP_MCP_AI_JetEngine_Taxonomy_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * JetEngine taxonomy data.
	 *
	 * @var array
	 */
	private $jetengine_taxonomy_data;

	/**
	 * Taxonomy slug.
	 *
	 * @var string
	 */
	private $taxonomy_slug;

	/**
	 * Constructor.
	 *
	 * @param string $taxonomy_slug JetEngine taxonomy slug.
	 */
	public function __construct( $taxonomy_slug ) {
		// Verify JetEngine is active.
		if ( ! $this->is_jetengine_active() ) {
			return;
		}

		// Get taxonomy data from JetEngine.
		$this->jetengine_taxonomy_data = $this->get_jetengine_taxonomy_data( $taxonomy_slug );
		if ( empty( $this->jetengine_taxonomy_data ) ) {
			return;
		}

		$this->taxonomy_slug = $taxonomy_slug;

		// For taxonomies, we use a special post type identifier.
		// The base class expects a post_type, so we'll use a convention: 'tax_' prefix.
		$this->post_type   = 'tax_' . $taxonomy_slug;
		$this->page_title  = sprintf(
			/* translators: %s: Taxonomy name */
			__( '%s - Research & Add', 'mcp-ai-wpoos-pro' ),
			$this->get_taxonomy_name()
		);
		$this->menu_title  = __( 'Research & Add', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'wp-mcp-ai-jetengine-taxonomy-research-' . $taxonomy_slug;
		$this->capability  = 'manage_categories'; // Standard taxonomy management capability.

		// Initialize parent with dynamic toolkit slug.
		parent::__construct( 'jetengine_taxonomy_' . $taxonomy_slug );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
	}

	/**
	 * Override add_research_page to use taxonomy parent menu.
	 */
	public function add_research_page() {
		// Skip if required properties are not set.
		if ( empty( $this->taxonomy_slug ) || empty( $this->page_title ) || empty( $this->menu_title ) || empty( $this->page_slug ) ) {
			return;
		}

		// For taxonomies, parent menu is 'edit-tags.php?taxonomy={taxonomy}'.
		$parent_slug = 'edit-tags.php?taxonomy=' . $this->taxonomy_slug;

		// Use 'manage_categories' as default capability if not set.
		$capability = ! empty( $this->capability ) ? $this->capability : 'manage_categories';

		add_submenu_page(
			$parent_slug,
			$this->page_title,
			$this->menu_title,
			$capability,
			$this->page_slug,
			array( $this, 'render' )
		);
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
	 * Get JetEngine taxonomy data.
	 *
	 * @param string $taxonomy_slug Taxonomy slug.
	 * @return array|null Taxonomy data or null if not found.
	 */
	private function get_jetengine_taxonomy_data( $taxonomy_slug ) {
		if ( ! $this->is_jetengine_active() ) {
			return null;
		}

		$module = jet_engine()->modules->get_module( 'taxonomy' );
		if ( ! $module || ! $module->instance ) {
			return null;
		}

		$taxonomies = $module->instance->get_items();
		if ( empty( $taxonomies ) || ! is_array( $taxonomies ) ) {
			return null;
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( isset( $taxonomy['slug'] ) && $taxonomy['slug'] === $taxonomy_slug ) {
				return $taxonomy;
			}
		}

		return null;
	}

	/**
	 * Get taxonomy name.
	 *
	 * @return string
	 */
	private function get_taxonomy_name() {
		if ( isset( $this->jetengine_taxonomy_data['name'] ) ) {
			return $this->jetengine_taxonomy_data['name'];
		}

		$taxonomy_object = get_taxonomy( $this->taxonomy_slug );
		if ( $taxonomy_object ) {
			return $taxonomy_object->labels->name;
		}

		return $this->taxonomy_slug;
	}

	/**
	 * Get entity types for this toolkit.
	 *
	 * For JetEngine taxonomies, we use a single entity type matching the taxonomy.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			$this->taxonomy_slug => $this->get_taxonomy_name(),
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
		if ( $toolkit_slug !== $this->toolkit_slug || $entity_type !== $this->taxonomy_slug ) {
			return $schema;
		}

		return $this->get_jetengine_field_schema();
	}

	/**
	 * Get JetEngine field schema for taxonomy.
	 *
	 * Extracts field definitions from JetEngine meta fields using the correct API.
	 * Uses jet_engine()->meta_boxes->get_fields_for_context('taxonomy', $taxonomy) per JetEngine best practices.
	 *
	 * @return array Field definitions.
	 */
	private function get_jetengine_field_schema() {
		$schema = array();

		// Get JetEngine meta fields for this taxonomy using the correct API.
		// Best practice: Use get_fields_for_context() method for taxonomies.
		if ( ! function_exists( 'jet_engine' ) || ! isset( jet_engine()->meta_boxes ) ) {
			return $schema;
		}

		// Get registered meta fields for this taxonomy.
		$meta_fields = jet_engine()->meta_boxes->get_fields_for_context( 'taxonomy', $this->taxonomy_slug );

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
			'switcher'     => 'checkbox', // JetEngine switcher -> checkbox.
			'posts'        => 'select',
			'html'         => 'wysiwyg',
		);

		return isset( $type_map[ $jetengine_type ] ) ? $type_map[ $jetengine_type ] : 'text';
	}
}
