<?php
/**
 * Profession Details Metabox.
 *
 * Handles the category, role description, and warnings fields.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Professions\Metaboxes;

use NvoosContentGraphAiPlatform\Professions\ProfessionCpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profession details metabox.
 */
class ProfessionMetaboxDetails extends ProfessionMetaboxBase {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_profession_details';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Profession Details', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * Get documentation URL for this metabox.
	 *
	 * @return string
	 */
	public function get_documentation_url() {
		return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/user-guides/professionals/PROFESSION_KNOWLEDGE_BASE_SYSTEM.md';
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( $this->get_id() . '_save', $this->get_id() . '_nonce' );

		$category         = get_post_meta( $post->ID, ProfessionCpt::META_CATEGORY, true );
		$role_description = get_post_meta( $post->ID, ProfessionCpt::META_ROLE_DESCRIPTION, true );
		$warnings         = get_post_meta( $post->ID, ProfessionCpt::META_WARNINGS, true );

		if ( ! is_array( $warnings ) ) {
			$warnings = array();
		}

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="profession_category">
							<?php esc_html_e( 'Category', 'nvoos-content-graph-ai-platform' ); ?>
						</label>
					</th>
					<td>
						<select id="profession_category" name="profession_category" class="regular-text">
							<option value=""><?php esc_html_e( 'Select Category', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="advisory" <?php selected( $category, 'advisory' ); ?>><?php esc_html_e( 'Advisory/Consulting', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="creative" <?php selected( $category, 'creative' ); ?>><?php esc_html_e( 'Creative Services', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="technical" <?php selected( $category, 'technical' ); ?>><?php esc_html_e( 'Technical', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="healthcare" <?php selected( $category, 'healthcare' ); ?>><?php esc_html_e( 'Healthcare', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="legal" <?php selected( $category, 'legal' ); ?>><?php esc_html_e( 'Legal', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="financial" <?php selected( $category, 'financial' ); ?>><?php esc_html_e( 'Financial', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="other" <?php selected( $category, 'other' ); ?>><?php esc_html_e( 'Other', 'nvoos-content-graph-ai-platform' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Categorize this profession for easier filtering.', 'nvoos-content-graph-ai-platform' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="profession_region">
							<?php esc_html_e( 'Primary Region/Jurisdiction', 'nvoos-content-graph-ai-platform' ); ?>
						</label>
					</th>
					<td>
						<?php
						$region = get_post_meta( $post->ID, ProfessionCpt::META_REGION, true );
						?>
						<select id="profession_region" name="profession_region" class="regular-text">
							<option value=""><?php esc_html_e( 'Global (All Regions)', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="north_america" <?php selected( $region, 'north_america' ); ?>><?php esc_html_e( 'North America', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="united_states" <?php selected( $region, 'united_states' ); ?>><?php esc_html_e( 'United States', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="canada" <?php selected( $region, 'canada' ); ?>><?php esc_html_e( 'Canada', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="europe" <?php selected( $region, 'europe' ); ?>><?php esc_html_e( 'Europe', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="european_union" <?php selected( $region, 'european_union' ); ?>><?php esc_html_e( 'European Union', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="united_kingdom" <?php selected( $region, 'united_kingdom' ); ?>><?php esc_html_e( 'United Kingdom', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="asia_pacific" <?php selected( $region, 'asia_pacific' ); ?>><?php esc_html_e( 'Asia-Pacific', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="latin_america_caribbean" <?php selected( $region, 'latin_america_caribbean' ); ?>><?php esc_html_e( 'Latin America & Caribbean', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="caribbean" <?php selected( $region, 'caribbean' ); ?>><?php esc_html_e( 'Caribbean (CARICOM)', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="middle_east_africa" <?php selected( $region, 'middle_east_africa' ); ?>><?php esc_html_e( 'Middle East & Africa', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="africa" <?php selected( $region, 'africa' ); ?>><?php esc_html_e( 'Africa', 'nvoos-content-graph-ai-platform' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Primary region or jurisdiction for this profession. Used to provide region-specific guidance and standards. Select "Global" if applicable worldwide or if the playbook covers multiple regions.', 'nvoos-content-graph-ai-platform' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="profession_role_description">
							<?php esc_html_e( 'Role Description', 'nvoos-content-graph-ai-platform' ); ?>
						</label>
					</th>
					<td>
						<textarea id="profession_role_description" name="profession_role_description" rows="5" class="large-text"><?php echo esc_textarea( $role_description ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Describe the primary role and responsibilities. This will be used in AI assistant instructions.', 'nvoos-content-graph-ai-platform' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="profession_warnings">
							<?php esc_html_e( 'Warnings/Disclaimers', 'nvoos-content-graph-ai-platform' ); ?>
						</label>
					</th>
					<td>
						<div id="profession-warnings-list">
							<?php foreach ( $warnings as $index => $warning ) : ?>
								<div class="profession-warning-item" style="margin-bottom: 10px;">
									<input type="text" name="profession_warnings[]" value="<?php echo esc_attr( $warning ); ?>" class="large-text" />
									<button type="button" class="button button-small remove-warning"><?php esc_html_e( 'Remove', 'nvoos-content-graph-ai-platform' ); ?></button>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" id="add-profession-warning" class="button button-secondary">
							<?php esc_html_e( 'Add Warning', 'nvoos-content-graph-ai-platform' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Disclaimers that the AI should communicate to users.', 'nvoos-content-graph-ai-platform' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php
		$remove_label = esc_js( __( 'Remove', 'nvoos-content-graph-ai-platform' ) );

		ob_start();
		?>
		jQuery(document).ready(function($) {
			$('#add-profession-warning').on('click', function() {
				var warningHtml = '<div class="profession-warning-item" style="margin-bottom: 10px;">' +
					'<input type="text" name="profession_warnings[]" value="" class="large-text" />' +
					'<button type="button" class="button button-small remove-warning"><?php echo esc_js( $remove_label ); ?></button>' +
					'</div>';
				$('#profession-warnings-list').append(warningHtml);
			});

			$(document).on('click', '.remove-warning', function() {
				$(this).closest('.profession-warning-item').remove();
			});
		});
		<?php
		$js = ob_get_clean();
		wp_print_inline_script_tag( $js );
		$this->render_documentation_link();
	}

		/**
		 * Save metabox data.
		 *
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post    Post object.
		 * @return void
		 */
	public function save( $post_id, $post ) {
		if ( ! $this->can_save( $post_id ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST['wp_mcp_ai_profession_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_profession_details_nonce'] ) ), 'wp_mcp_ai_profession_details_save' ) ) {
			return;
		}

		// Save category.
		if ( isset( $_POST['profession_category'] ) ) {
			update_post_meta( $post_id, ProfessionCpt::META_CATEGORY, sanitize_key( wp_unslash( $_POST['profession_category'] ) ) );
		}

		// Save region.
		if ( isset( $_POST['profession_region'] ) ) {
			$region = sanitize_key( wp_unslash( $_POST['profession_region'] ) );

			// Validate against allowed region values.
			$allowed_regions = array(
				'',
				'north_america',
				'united_states',
				'canada',
				'europe',
				'european_union',
				'united_kingdom',
				'asia_pacific',
				'latin_america_caribbean',
				'caribbean',
				'middle_east_africa',
				'africa',
			);

			if ( in_array( $region, $allowed_regions, true ) ) {
				update_post_meta( $post_id, ProfessionCpt::META_REGION, $region );
			}
		}

		// Save role description.
		if ( isset( $_POST['profession_role_description'] ) ) {
			update_post_meta( $post_id, ProfessionCpt::META_ROLE_DESCRIPTION, wp_kses_post( wp_unslash( $_POST['profession_role_description'] ) ) );
		}

		// Save warnings.
		if ( isset( $_POST['profession_warnings'] ) && is_array( $_POST['profession_warnings'] ) ) {
			$warnings = array_map( 'sanitize_text_field', wp_unslash( $_POST['profession_warnings'] ) );
			$warnings = array_filter( $warnings ); // Remove empty values.
			update_post_meta( $post_id, ProfessionCpt::META_WARNINGS, array_values( $warnings ) );
		} else {
			delete_post_meta( $post_id, ProfessionCpt::META_WARNINGS );
		}
	}
}
