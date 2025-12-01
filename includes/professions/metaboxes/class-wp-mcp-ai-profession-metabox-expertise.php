<?php
/**
 * Profession Expertise Metabox.
 *
 * Handles the expertise areas, default tools, and knowledge base fields.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profession expertise metabox.
 */
class WP_MCP_AI_Profession_Metabox_Expertise extends WP_MCP_AI_Profession_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_profession_expertise';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Expertise & Knowledge', 'wp-mcp-ai' );
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		$expertise      = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
		$default_tools  = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );
		$knowledge_base = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true );

		if ( ! is_array( $expertise ) ) {
			$expertise = array();
		}

		if ( ! is_array( $default_tools ) ) {
			$default_tools = array();
		}

		// Get available tools from registry.
		$available_tools = $this->get_available_tools();

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="profession_expertise">
							<?php esc_html_e( 'Expertise Areas', 'wp-mcp-ai' ); ?>
						</label>
					</th>
					<td>
						<div id="profession-expertise-list">
							<?php foreach ( $expertise as $index => $area ) : ?>
								<div class="profession-expertise-item" style="margin-bottom: 10px;">
									<input type="text" name="profession_expertise[]" value="<?php echo esc_attr( $area ); ?>" class="large-text" />
									<button type="button" class="button button-small remove-expertise"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" id="add-profession-expertise" class="button button-secondary">
							<?php esc_html_e( 'Add Expertise Area', 'wp-mcp-ai' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'List specific areas of expertise for this profession.', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="profession_default_tools">
							<?php esc_html_e( 'Default Tools', 'wp-mcp-ai' ); ?>
						</label>
					</th>
					<td>
						<?php if ( ! empty( $available_tools ) ) : ?>
							<div id="profession-default-tools-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
								<?php foreach ( $available_tools as $tool ) : ?>
									<?php
									$tool_slug  = method_exists( $tool, 'get_slug' ) ? $tool->get_slug() : '';
									$tool_name  = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $tool_slug;
									$tool_desc  = method_exists( $tool, 'get_description' ) ? $tool->get_description() : '';
									$is_checked = in_array( $tool_slug, $default_tools, true );
									?>
									<div style="margin-bottom: 8px;">
										<label style="display: inline-flex; align-items: flex-start; cursor: pointer;">
											<input type="checkbox" name="profession_default_tools[]" value="<?php echo esc_attr( $tool_slug ); ?>" <?php checked( $is_checked ); ?> style="margin-right: 8px; margin-top: 2px;" />
											<span>
												<strong><?php echo esc_html( $tool_name ); ?></strong>
												<?php if ( $tool_desc ) : ?>
													<br><small style="color: #666;"><?php echo esc_html( wp_trim_words( $tool_desc, 15 ) ); ?></small>
												<?php endif; ?>
											</span>
										</label>
									</div>
								<?php endforeach; ?>
							</div>
							<p class="description">
								<?php esc_html_e( 'Select the default tools that should be pre-selected when creating assistants with this profession. Choose 4-8 essential tools that align with the profession\'s expertise.', 'wp-mcp-ai' ); ?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php esc_html_e( 'No tools available. Tools will be loaded after the tool registry is initialized.', 'wp-mcp-ai' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="profession_knowledge_base">
							<?php esc_html_e( 'Knowledge Base Content', 'wp-mcp-ai' ); ?>
						</label>
					</th>
					<td>
						<?php
						wp_editor(
							$knowledge_base,
							'profession_knowledge_base',
							array(
								'textarea_name' => 'profession_knowledge_base',
								'textarea_rows' => 15,
								'media_buttons' => false,
								'teeny'         => false,
								'quicktags'     => true,
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Knowledge base content that will be included in AI assistant instructions. Use markdown formatting.', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#add-profession-expertise').on('click', function() {
				var expertiseHtml = '<div class="profession-expertise-item" style="margin-bottom: 10px;">' +
					'<input type="text" name="profession_expertise[]" value="" class="large-text" />' +
					'<button type="button" class="button button-small remove-expertise"><?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?></button>' +
					'</div>';
				$('#profession-expertise-list').append(expertiseHtml);
			});

			$(document).on('click', '.remove-expertise', function() {
				$(this).closest('.profession-expertise-item').remove();
			});
		});
		</script>
		<?php
	}

	/**
	 * Save metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		// Save expertise.
		if ( isset( $_POST['profession_expertise'] ) && is_array( $_POST['profession_expertise'] ) ) {
			$expertise = array_map( 'sanitize_text_field', wp_unslash( $_POST['profession_expertise'] ) );
			$expertise = array_filter( $expertise );
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, array_values( $expertise ) );
		} else {
			delete_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE );
		}

		// Save default tools.
		if ( isset( $_POST['profession_default_tools'] ) && is_array( $_POST['profession_default_tools'] ) ) {
			$default_tools = array_map( 'sanitize_key', wp_unslash( $_POST['profession_default_tools'] ) );
			$default_tools = array_filter( $default_tools );
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, array_values( $default_tools ) );
		} else {
			delete_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS );
		}

		// Save knowledge base.
		if ( isset( $_POST['profession_knowledge_base'] ) ) {
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, wp_kses_post( wp_unslash( $_POST['profession_knowledge_base'] ) ) );
		}
	}

	/**
	 * Get available tools from registry.
	 *
	 * @return array Array of tool instances.
	 */
	protected function get_available_tools() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array();
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		return $registry->get_tools();
	}
}
