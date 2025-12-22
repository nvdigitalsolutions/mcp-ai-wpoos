<?php
/**
 * Profession Expertise Metabox.
 *
 * Handles the expertise areas, default tools, and knowledge base fields.
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
							<!-- Search and Filter Controls -->
							<div class="profession-tools-controls" style="margin-bottom: 15px;">
								<div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 10px;">
									<input 
										type="text" 
										id="profession-tools-search" 
										class="regular-text" 
										placeholder="<?php esc_attr_e( 'Search tools...', 'wp-mcp-ai' ); ?>"
										aria-label="<?php esc_attr_e( 'Search tools', 'wp-mcp-ai' ); ?>"
										style="flex: 1; min-width: 200px;"
									/>
									<button type="button" class="button" id="clear-tools-search">
										<?php esc_html_e( 'Clear Search', 'wp-mcp-ai' ); ?>
									</button>
								</div>
								<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
									<button type="button" class="button button-secondary" id="select-all-tools">
										<?php esc_html_e( 'Select All', 'wp-mcp-ai' ); ?>
									</button>
									<button type="button" class="button button-secondary" id="deselect-all-tools">
										<?php esc_html_e( 'Deselect All', 'wp-mcp-ai' ); ?>
									</button>
									<button type="button" class="button" id="reset-tools">
										<?php esc_html_e( 'Reset to Initial', 'wp-mcp-ai' ); ?>
									</button>
									<span id="tools-selected-count" style="margin-left: 10px; color: #666;">
										<strong><?php echo is_array( $default_tools ) ? count( $default_tools ) : 0; ?></strong> <?php esc_html_e( 'selected', 'wp-mcp-ai' ); ?>
									</span>
								</div>
							</div>

							<!-- Tools List -->
							<div id="profession-default-tools-list" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
								<?php foreach ( $available_tools as $tool ) : ?>
									<?php
									$tool_slug  = method_exists( $tool, 'get_slug' ) ? $tool->get_slug() : '';
									$tool_name  = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $tool_slug;
									$tool_desc  = method_exists( $tool, 'get_description' ) ? $tool->get_description() : '';
									$is_checked = in_array( $tool_slug, $default_tools, true );
									?>
									<div class="profession-tool-item" 
										style="margin-bottom: 8px; padding: 8px; background: #f9f9f9; border-radius: 3px;"
										data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>"
										data-tool-name="<?php echo esc_attr( strtolower( $tool_name ) ); ?>"
										data-tool-description="<?php echo esc_attr( strtolower( $tool_desc ) ); ?>"
										data-initially-checked="<?php echo $is_checked ? '1' : '0'; ?>">
										<label style="display: inline-flex; align-items: flex-start; cursor: pointer; width: 100%;">
											<input 
												type="checkbox" 
												class="profession-tool-checkbox"
												name="profession_default_tools[]" 
												value="<?php echo esc_attr( $tool_slug ); ?>" 
												<?php checked( $is_checked ); ?> 
												style="margin-right: 8px; margin-top: 2px;" 
											/>
											<span style="flex: 1;">
												<strong><?php echo esc_html( $tool_name ); ?></strong>
												<?php if ( $tool_desc ) : ?>
													<br><small style="color: #666;"><?php echo esc_html( wp_trim_words( $tool_desc, 15 ) ); ?></small>
												<?php endif; ?>
											</span>
										</label>
									</div>
								<?php endforeach; ?>
							</div>

							<!-- No Results Message (Hidden by default) -->
							<div id="no-tools-found" style="display: none; padding: 20px; text-align: center; color: #666;">
								<?php esc_html_e( 'No tools found matching your search.', 'wp-mcp-ai' ); ?>
							</div>

							<p class="description" style="margin-top: 10px;">
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
			// Expertise area management
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

			// Tools management functionality
			var searchDebounceTimer = null;
			var $toolsList = $('#profession-default-tools-list');
			var $noResultsMsg = $('#no-tools-found');
			var $searchInput = $('#profession-tools-search');
			var $selectedCount = $('#tools-selected-count');

			// Update selected count
			function updateSelectedCount() {
				var count = $('.profession-tool-checkbox:checked').length;
				$selectedCount.html('<strong>' + count + '</strong> <?php echo esc_js( __( 'selected', 'wp-mcp-ai' ) ); ?>');
			}

			// Filter tools based on search term
			function filterTools() {
				var searchTerm = $searchInput.val().toLowerCase().trim();
				var $toolItems = $('.profession-tool-item');
				var visibleCount = 0;

				$toolItems.each(function() {
					var $item = $(this);
					var toolName = $item.data('tool-name') || '';
					var toolDesc = $item.data('tool-description') || '';

					var matches = searchTerm === '' ||
						toolName.indexOf(searchTerm) !== -1 ||
						toolDesc.indexOf(searchTerm) !== -1;

					if (matches) {
						$item.show();
						visibleCount++;
					} else {
						$item.hide();
					}
				});

				// Toggle no results message
				if (visibleCount === 0 && searchTerm !== '') {
					$toolsList.hide();
					$noResultsMsg.show();
				} else {
					$toolsList.show();
					$noResultsMsg.hide();
				}
			}

			// Search input handler with debounce
			$searchInput.on('input', function() {
				clearTimeout(searchDebounceTimer);
				searchDebounceTimer = setTimeout(filterTools, 300);
			});

			// Clear search button
			$('#clear-tools-search').on('click', function() {
				$searchInput.val('');
				filterTools();
				$searchInput.focus();
			});

			// Toggle all visible tools (used by Select All and Deselect All)
			function toggleAllVisibleTools(checked) {
				$('.profession-tool-item:visible .profession-tool-checkbox').prop('checked', checked);
				updateSelectedCount();
			}

			// Select all visible tools
			$('#select-all-tools').on('click', function() {
				toggleAllVisibleTools(true);
			});

			// Deselect all visible tools
			$('#deselect-all-tools').on('click', function() {
				toggleAllVisibleTools(false);
			});

			// Reset to initial state
			$('#reset-tools').on('click', function() {
				// Use native confirm as it's consistent with WordPress admin UX patterns
				if (!confirm('<?php echo esc_js( __( 'Are you sure you want to reset the tools selection to the initial state?', 'wp-mcp-ai' ) ); ?>')) {
					return;
				}

				$('.profession-tool-item').each(function() {
					var $item = $(this);
					var $checkbox = $item.find('.profession-tool-checkbox');
					var initiallyChecked = $item.data('initially-checked') === 1;
					$checkbox.prop('checked', initiallyChecked);
				});
				updateSelectedCount();
			});

			// Update count when checkboxes change
			$(document).on('change', '.profession-tool-checkbox', function() {
				updateSelectedCount();
			});

			// Initialize count on page load
			updateSelectedCount();
		});
		</script>

		<style>
			.profession-tool-item {
				transition: background-color 0.2s ease;
			}
			.profession-tool-item:hover {
				background-color: #f0f0f0 !important;
			}
			#profession-tools-search {
				padding: 6px 10px;
				font-size: 14px;
				border: 1px solid #8c8f94;
				border-radius: 4px;
				box-shadow: 0 0 0 transparent;
				transition: border-color .1s ease-in-out, box-shadow .1s linear;
			}
			#profession-tools-search:focus {
				border-color: #2271b1;
				box-shadow: 0 0 0 1px #2271b1;
				outline: 2px solid transparent;
			}
			.profession-tools-controls .button {
				height: 32px;
				line-height: 30px;
				padding: 0 12px;
			}
			#tools-selected-count {
				display: inline-block;
				padding: 4px 8px;
				background: #f0f0f0;
				border-radius: 3px;
				font-size: 13px;
			}
		</style>
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
