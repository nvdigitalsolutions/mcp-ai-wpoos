<?php
/**
 * Agent Skills Metabox for Assistants.
 *
 * Allows selecting Anthropic Agent Skills for an assistant,
 * whose instructions are injected into the system prompt.
 *
 * @package WP_MCP_AI
 * @since   1.7.0
 * @see     https://agentskills.io/specification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Agent Skills metabox on the assistant editor.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Metabox_Skills extends WP_MCP_AI_Metabox_Base {

	/**
	 * Reference to the Assistant CPT class.
	 *
	 * @var WP_MCP_AI_Assistant_CPT
	 */
	protected $cpt;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 * @param WP_MCP_AI_Assistant_CPT $cpt Assistant CPT instance.
	 */
	public function __construct( $cpt ) {
		$this->cpt = $cpt;
	}

	/**
	 * Get the metabox ID.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_skills';
	}

	/**
	 * Get the metabox title.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_title() {
		return __( 'Agent Skills', 'mcp-ai-wpoos' );
	}

	/**
	 * Get documentation URL for this metabox.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_documentation_url() {
		return 'https://github.com/anthropics/skills';
	}

	/**
	 * Check if current user can view this metabox.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	protected function can_view() {
		global $post;
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Render the metabox content.
	 *
	 * @since 1.7.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'mcp-ai-wpoos' ), '', array( 'response' => 403 ) );
		}

		wp_nonce_field( 'wp_mcp_ai_skills_meta', 'wp_mcp_ai_skills_meta_nonce' );

		// Get currently assigned skills.
		$assigned_skills = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_SKILLS, true );
		if ( ! is_array( $assigned_skills ) ) {
			$assigned_skills = array();
		}

		// Get available skills from the registry.
		$registry        = WP_MCP_AI_Skill_Registry::instance();
		$available_skills = $registry->get_all_skills();

		?>
		<div class="wp-mcp-ai-skills">
			<p class="description">
				<?php
				printf(
					/* translators: %s: URL to the Agent Skills specification */
					esc_html__( 'Select Agent Skills to enhance this assistant with specialized instructions. Skills follow the %s specification and inject task-specific guidelines into the system prompt.', 'mcp-ai-wpoos' ),
					'<a href="https://agentskills.io/specification" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Agent Skills', 'mcp-ai-wpoos' ) . '</a>'
				);
				?>
			</p>

			<?php if ( empty( $available_skills ) ) : ?>
				<div class="wp-mcp-ai-skills-empty" style="padding: 20px; text-align: center; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px; margin: 15px 0;">
					<span class="dashicons dashicons-welcome-learn-more" style="font-size: 32px; color: #999; display: block; margin-bottom: 10px;"></span>
					<p><?php esc_html_e( 'No skills are installed yet.', 'mcp-ai-wpoos' ); ?></p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: path to the skills upload directory */
							esc_html__( 'Upload SKILL.md files to %s to add skills. Each skill must be in its own subdirectory matching the skill name.', 'mcp-ai-wpoos' ),
							'<code>' . esc_html( WP_MCP_AI_Skill_Registry::UPLOAD_DIR . '/{skill-name}/SKILL.md' ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="wp-mcp-ai-skills-search" style="margin: 15px 0;">
					<label>
						<?php esc_html_e( 'Search:', 'mcp-ai-wpoos' ); ?>
						<input type="text" id="wp-mcp-ai-skill-search" placeholder="<?php esc_attr_e( 'Search skills...', 'mcp-ai-wpoos' ); ?>" style="width: 300px; margin-left: 5px;" />
					</label>
				</div>

				<table class="widefat striped" id="wp-mcp-ai-skills-table">
					<thead>
						<tr>
							<th style="width: 40px;"></th>
							<th><?php esc_html_e( 'Skill', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $available_skills as $skill ) : ?>
							<?php $is_selected = in_array( $skill['name'], $assigned_skills, true ); ?>
							<tr class="wp-mcp-ai-skill-row"
								data-name="<?php echo esc_attr( strtolower( $skill['name'] ) ); ?>"
								data-description="<?php echo esc_attr( strtolower( $skill['description'] ) ); ?>">
								<td>
									<input
										type="checkbox"
										name="wp_mcp_ai_skills[]"
										value="<?php echo esc_attr( $skill['name'] ); ?>"
										class="wp-mcp-ai-skill-checkbox"
										<?php checked( $is_selected ); ?>
									/>
								</td>
								<td>
									<strong><?php echo esc_html( $skill['name'] ); ?></strong>
									<?php if ( ! empty( $skill['license'] ) ) : ?>
										<br />
										<small style="color: #666;">
											<?php
											/* translators: %s: skill license */
											printf( esc_html__( 'License: %s', 'mcp-ai-wpoos' ), esc_html( $skill['license'] ) );
											?>
										</small>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $skill['description'] ); ?>
									<?php if ( ! empty( $skill['compatibility'] ) ) : ?>
										<br />
										<small style="color: #666;">
											<?php echo esc_html( $skill['compatibility'] ); ?>
										</small>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description" style="margin-top: 10px;">
					<?php esc_html_e( 'Selected skills will have their instructions injected into the system prompt alongside any custom instructions and roles.', 'mcp-ai-wpoos' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<script type="text/javascript">
		( function() {
			document.addEventListener( 'DOMContentLoaded', function() {
				var searchInput = document.getElementById( 'wp-mcp-ai-skill-search' );
				var rows = document.querySelectorAll( '.wp-mcp-ai-skill-row' );

				if ( ! searchInput || ! rows.length ) {
					return;
				}

				searchInput.addEventListener( 'input', function() {
					var search = this.value.toLowerCase().trim();

					rows.forEach( function( row ) {
						var name = row.getAttribute( 'data-name' );
						var desc = row.getAttribute( 'data-description' );

						if ( ! search || name.indexOf( search ) !== -1 || desc.indexOf( search ) !== -1 ) {
							row.style.display = '';
						} else {
							row.style.display = 'none';
						}
					} );
				} );
			} );
		} )();
		</script>
		<?php
		$this->render_documentation_link();
	}
}
