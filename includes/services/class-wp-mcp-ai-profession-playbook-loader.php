<?php
/**
 * Profession Playbook Loader Service.
 *
 * Service layer for loading and assembling profession playbooks from txt files.
 * Playbooks are authorable documents assembled from:
 * - Global behavioral guidelines (global.txt)
 * - Category-specific workflows (categories/{category}.txt)
 * - Profession-specific content (professions/{slug}.txt)
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and assembles profession playbooks from txt files.
 */
class WP_MCP_AI_Profession_Playbook_Loader {
	/**
	 * Path to playbook base directory.
	 *
	 * @var string
	 */
	protected $playbook_base_path;

	/**
	 * Tool recommender instance.
	 *
	 * @var WP_MCP_AI_Profession_Tool_Recommender
	 */
	protected $tool_recommender;

	/**
	 * Constructor.
	 *
	 * @param string                                     $playbook_base_path Optional path to playbook directory.
	 * @param WP_MCP_AI_Profession_Tool_Recommender|null $tool_recommender Optional tool recommender instance.
	 *                                                                     If null, a new instance will be created automatically.
	 *                                                                     This parameter is backward compatible - existing code
	 *                                                                     that doesn't pass it will continue to work.
	 */
	public function __construct( $playbook_base_path = null, $tool_recommender = null ) {
		if ( null === $playbook_base_path ) {
			$this->playbook_base_path = WP_MCP_AI_PATH . 'includes/knowledge-base/profession-playbooks/';
		} else {
			$this->playbook_base_path = trailingslashit( $playbook_base_path );
		}

		if ( null === $tool_recommender ) {
			// Load tool recommender if not provided.
			if ( ! class_exists( 'WP_MCP_AI_Profession_Tool_Recommender' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-profession-tool-recommender.php';
			}
			$tool_registry          = WP_MCP_AI_Tool_Registry::get_instance();
			$this->tool_recommender = new WP_MCP_AI_Profession_Tool_Recommender( $tool_registry );
		} else {
			$this->tool_recommender = $tool_recommender;
		}
	}

	/**
	 * Get global playbook text.
	 *
	 * Contains general assistant behavior and safety guardrails.
	 *
	 * @return string Global playbook content, empty string if file not found.
	 */
	public function get_global_text() {
		$file_path = $this->playbook_base_path . 'global.txt';

		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file_path );

		return false !== $content ? $content : '';
	}

	/**
	 * Get category-specific playbook text.
	 *
	 * Contains category-wide workflows and quality rubrics.
	 *
	 * @param string $category Category slug (e.g., 'advisory', 'creative', 'technical').
	 * @return string Category playbook content, empty string if file not found.
	 */
	public function get_category_text( $category ) {
		if ( empty( $category ) ) {
			return '';
		}

		$file_path = $this->playbook_base_path . 'categories/' . sanitize_file_name( $category ) . '.txt';

		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file_path );

		return false !== $content ? $content : '';
	}

	/**
	 * Get profession-specific playbook text.
	 *
	 * Contains profession-specific guidelines and best practices.
	 *
	 * @param string $slug Profession slug.
	 * @return string Profession playbook content, empty string if file not found.
	 */
	public function get_profession_text( $slug ) {
		if ( empty( $slug ) ) {
			return '';
		}

		$file_path = $this->playbook_base_path . 'professions/' . sanitize_file_name( $slug ) . '.txt';

		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file_path );

		return false !== $content ? $content : '';
	}

	/**
	 * Build complete playbook for a profession.
	 *
	 * Assembles playbook from global, category, and profession-specific sections.
	 * Reads profession slug, category, and region from post meta.
	 *
	 * @param int $profession_post_id Profession post ID.
	 * @return string Complete playbook content in plain text (UTF-8).
	 */
	public function build_playbook( $profession_post_id ) {
		// Get profession data.
		$profession = get_post( $profession_post_id );

		if ( ! $profession || WP_MCP_AI_Profession_CPT::POST_TYPE !== $profession->post_type ) {
			return '';
		}

		$slug     = $profession->post_name;
		$title    = $profession->post_title;
		$category = get_post_meta( $profession_post_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
		$region   = get_post_meta( $profession_post_id, WP_MCP_AI_Profession_CPT::META_REGION, true );

		// Build playbook sections.
		$sections = array();

		// Add header.
		$sections[] = "# {$title} - Professional Playbook\n";
		$sections[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";

		// Add region context if specified.
		if ( ! empty( $region ) ) {
			$region_label = $this->get_region_label( $region );
			$sections[]   = "Primary Region/Jurisdiction: {$region_label}\n";
		}

		$sections[] = "---\n";

		// Global section.
		$global_text = $this->get_global_text();
		if ( ! empty( $global_text ) ) {
			$sections[] = "## Global Guidelines\n";
			$sections[] = trim( $global_text ) . "\n";
			$sections[] = "---\n";
		}

		// Category section.
		$category_text = $this->get_category_text( $category );
		if ( ! empty( $category_text ) ) {
			$category_label = $this->get_category_label( $category );
			$sections[]     = "## {$category_label} Category Guidelines\n";
			$sections[]     = trim( $category_text ) . "\n";
			$sections[]     = "---\n";
		}

		// Profession section.
		$profession_text = $this->get_profession_text( $slug );
		if ( ! empty( $profession_text ) ) {
			$sections[] = "## {$title} Specific Guidelines\n";
			$sections[] = trim( $profession_text ) . "\n";
			$sections[] = "---\n";
		}

		// Add region context note if specified.
		if ( ! empty( $region ) ) {
			$region_label = $this->get_region_label( $region );
			$sections[]   = "## Region-Specific Context\n";
			$sections[]   = "This playbook is optimized for: **{$region_label}**\n\n";
			$sections[]   = "When providing guidance:\n";
			$sections[]   = "- Prioritize standards, regulations, and practices relevant to {$region_label}\n";
			$sections[]   = "- Reference region-appropriate frameworks and authorities\n";
			$sections[]   = "- Note when practices differ significantly in other regions\n";
			$sections[]   = "- Always ask about the user's specific location if it materially affects the answer\n";
			$sections[]   = "---\n";
		}

		// Tool recommendations section.
		$tool_reference = $this->tool_recommender->get_tool_reference_section( $slug, $category );
		if ( ! empty( $tool_reference ) ) {
			$sections[] = trim( $tool_reference ) . "\n";
			$sections[] = "---\n";
		}

		// Add footer.
		$sections[] = "\nThis playbook is assembled from authorable text files in the NV oOS plugin.\n";
		$sections[] = "To update this content, edit the relevant txt files in includes/knowledge-base/profession-playbooks/\n";

		// Concatenate all sections.
		return implode( "\n", $sections );
	}

	/**
	 * Get human-readable category label.
	 *
	 * @param string $category Category slug.
	 * @return string Category label.
	 */
	protected function get_category_label( $category ) {
		$labels = array(
			'advisory'   => 'Advisory/Consulting',
			'creative'   => 'Creative Services',
			'technical'  => 'Technical',
			'healthcare' => 'Healthcare',
			'legal'      => 'Legal',
			'financial'  => 'Financial',
			'other'      => 'Other',
		);

		return isset( $labels[ $category ] ) ? $labels[ $category ] : ucfirst( $category );
	}

	/**
	 * Get human-readable region label.
	 *
	 * @param string $region Region slug.
	 * @return string Region label.
	 */
	protected function get_region_label( $region ) {
		$labels = array(
			'north_america'           => 'North America',
			'united_states'           => 'United States',
			'canada'                  => 'Canada',
			'europe'                  => 'Europe',
			'european_union'          => 'European Union',
			'united_kingdom'          => 'United Kingdom',
			'asia_pacific'            => 'Asia-Pacific',
			'latin_america_caribbean' => 'Latin America & Caribbean',
			'caribbean'               => 'Caribbean (CARICOM)',
			'middle_east_africa'      => 'Middle East & Africa',
			'africa'                  => 'Africa',
		);

		return isset( $labels[ $region ] ) ? $labels[ $region ] : ucwords( str_replace( '_', ' ', $region ) );
	}
}
