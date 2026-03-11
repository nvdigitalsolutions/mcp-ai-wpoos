<?php
/**
 * Pro Skill Manager Admin Page.
 *
 * Provides a dedicated admin interface for managing Agent Skills (agentskills.io):
 *  - View all installed skills with details
 *  - Upload a SKILL.md file directly
 *  - Upload a ZIP archive containing a skill directory
 *  - Install a skill from a remote URL
 *  - Inline editor to create or update SKILL.md content
 *  - Delete/uninstall installed skills
 *
 * Registered as a submenu page under the mcp_ai_assistant CPT.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.8.0
 * @see     https://agentskills.io/specification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Skill Manager admin page for the Pro add-on.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Skill_Manager_Admin_Page {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-skill-manager';

	/**
	 * Nonce action for upload/install operations.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_skill_manager';

	/**
	 * Register hooks.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wp_mcp_ai_skill_manager_upload', array( __CLASS__, 'handle_ajax_upload' ) );
		add_action( 'wp_ajax_wp_mcp_ai_skill_manager_install_url', array( __CLASS__, 'handle_ajax_install_url' ) );
		add_action( 'wp_ajax_wp_mcp_ai_skill_manager_save', array( __CLASS__, 'handle_ajax_save' ) );
		add_action( 'wp_ajax_wp_mcp_ai_skill_manager_delete', array( __CLASS__, 'handle_ajax_delete' ) );
	}

	/**
	 * Register the admin submenu page.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_assistant',
			__( 'Skill Manager', 'mcp-ai-wpoos-pro' ),
			__( 'Skill Manager', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue page assets only on the Skill Manager screen.
	 *
	 * @since 1.8.0
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		// Code editor (CodeMirror) – bundled with WordPress core since 4.9.
		wp_enqueue_code_editor( array( 'type' => 'text/x-markdown' ) );

		wp_add_inline_style(
			'wp-admin',
			self::get_inline_styles()
		);
	}

	/**
	 * Inline CSS for the Skill Manager page.
	 *
	 * @since 1.8.0
	 * @return string CSS string.
	 */
	private static function get_inline_styles() {
		return '
			.wp-mcp-ai-skill-manager { max-width: 1200px; }
			.wp-mcp-ai-skill-manager .nav-tab-wrapper { margin-bottom: 20px; }
			.wp-mcp-ai-skill-manager .tab-content { display: none; }
			.wp-mcp-ai-skill-manager .tab-content.active { display: block; }
			.wp-mcp-ai-skill-manager .skill-badge {
				display: inline-block; padding: 2px 8px; border-radius: 3px;
				background: #e7f3ff; color: #0073aa; font-size: 11px; font-weight: 600;
			}
			.wp-mcp-ai-skill-manager .skill-actions a,
			.wp-mcp-ai-skill-manager .skill-actions button { margin-right: 6px; }
			.wp-mcp-ai-skill-manager #skill-editor-textarea {
				width: 100%; min-height: 400px; font-family: monospace; font-size: 13px;
			}
			.wp-mcp-ai-skill-manager .CodeMirror { height: 420px; border: 1px solid #ddd; }
			.wp-mcp-ai-skill-manager .skill-manager-notice {
				display: none; margin: 10px 0; padding: 10px 12px;
				border-left: 4px solid #46b450; background: #f0fff0;
			}
			.wp-mcp-ai-skill-manager .skill-manager-notice.error {
				border-color: #dc3232; background: #fff0f0;
			}
			.wp-mcp-ai-skill-manager .install-section {
				background: #f9f9f9; border: 1px solid #ddd; padding: 20px;
				margin-bottom: 20px; border-radius: 3px;
			}
			.wp-mcp-ai-skill-manager .install-section h3 { margin-top: 0; }
		';
	}

	/**
	 * Render the full admin page.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage skills.', 'mcp-ai-wpoos-pro' ) );
		}

		$registry = self::get_registry();
		$skills   = $registry->get_all_skills();

		// Determine active tab.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switching; no state change.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'list';
		$valid_tabs = array( 'list', 'install', 'editor' );
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'list';
		}

		// If ?edit=<name> is present, pre-load into editor.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pre-fill; no state change.
		$edit_name    = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
		$edit_content = '';
		if ( $edit_name && 'editor' === $active_tab ) {
			$skill_file = trailingslashit( $registry->get_skills_dir() ) . $edit_name . '/SKILL.md';
			if ( file_exists( $skill_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local uploaded skill file.
				$edit_content = file_get_contents( $skill_file );
			}
		}

		$nonce = wp_create_nonce( self::NONCE_ACTION );

		?>
		<div class="wrap wp-mcp-ai-skill-manager">
			<h1>
				<span class="dashicons dashicons-superhero" style="font-size:28px;line-height:1;vertical-align:middle;margin-right:8px;"></span>
				<?php esc_html_e( 'Agent Skill Manager', 'mcp-ai-wpoos-pro' ); ?>
			</h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to agentskills.io specification */
					esc_html__( 'Install, upload, edit, and manage Agent Skills following the %s specification.', 'mcp-ai-wpoos-pro' ),
					'<a href="https://agentskills.io/specification" target="_blank" rel="noopener noreferrer">agentskills.io</a>'
				);
				?>
			</p>

			<?php /* Tab navigation */ ?>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=list' ) ); ?>"
				   class="nav-tab <?php echo 'list' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Installed Skills', 'mcp-ai-wpoos-pro' ); ?>
					<span class="skill-badge"><?php echo esc_html( (string) count( $skills ) ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=install' ) ); ?>"
				   class="nav-tab <?php echo 'install' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Upload &amp; Install', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=editor' ) ); ?>"
				   class="nav-tab <?php echo 'editor' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Skill Editor', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</nav>

			<?php /* Shared notice area */ ?>
			<div id="skill-manager-notice" class="skill-manager-notice" role="alert"></div>

			<?php /* ── Tab: Installed Skills ── */ ?>
			<div id="tab-list" class="tab-content <?php echo 'list' === $active_tab ? 'active' : ''; ?>">
				<?php self::render_tab_list( $skills ); ?>
			</div>

			<?php /* ── Tab: Upload & Install ── */ ?>
			<div id="tab-install" class="tab-content <?php echo 'install' === $active_tab ? 'active' : ''; ?>">
				<?php self::render_tab_install( $nonce ); ?>
			</div>

			<?php /* ── Tab: Skill Editor ── */ ?>
			<div id="tab-editor" class="tab-content <?php echo 'editor' === $active_tab ? 'active' : ''; ?>">
				<?php self::render_tab_editor( $nonce, $edit_name, $edit_content ); ?>
			</div>
		</div>

		<?php self::render_inline_scripts( $nonce ); ?>
		<?php
	}

	/**
	 * Render the "Installed Skills" tab content.
	 *
	 * @since 1.8.0
	 * @param array $skills All installed skills from the registry.
	 * @return void
	 */
	private static function render_tab_list( $skills ) {
		if ( empty( $skills ) ) {
			?>
			<div style="padding:30px;text-align:center;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;margin-top:15px;">
				<span class="dashicons dashicons-warning" style="font-size:40px;color:#999;display:block;margin-bottom:10px;"></span>
				<p><?php esc_html_e( 'No skills are installed yet.', 'mcp-ai-wpoos-pro' ); ?></p>
				<p class="description">
					<?php esc_html_e( 'Use the Upload & Install tab to add skills.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		// Search box.
		?>
		<div style="margin:15px 0;">
			<label for="skill-list-search">
				<?php esc_html_e( 'Search:', 'mcp-ai-wpoos-pro' ); ?>
				<input type="text" id="skill-list-search"
				       placeholder="<?php esc_attr_e( 'Filter skills...', 'mcp-ai-wpoos-pro' ); ?>"
				       style="width:280px;margin-left:5px;" />
			</label>
		</div>

		<table class="wp-list-table widefat fixed striped" id="skill-list-table">
			<thead>
				<tr>
					<th scope="col" style="width:180px;"><?php esc_html_e( 'Skill Name', 'mcp-ai-wpoos-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
					<th scope="col" style="width:120px;"><?php esc_html_e( 'License', 'mcp-ai-wpoos-pro' ); ?></th>
					<th scope="col" style="width:200px;"><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $skills as $skill ) : ?>
					<tr data-name="<?php echo esc_attr( mb_strtolower( $skill['name'], 'UTF-8' ) ); ?>"
					    data-description="<?php echo esc_attr( mb_strtolower( $skill['description'], 'UTF-8' ) ); ?>">
						<td>
							<strong><?php echo esc_html( $skill['name'] ); ?></strong>
							<?php if ( ! empty( $skill['compatibility'] ) ) : ?>
								<br /><small style="color:#666;"><?php echo esc_html( $skill['compatibility'] ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $skill['description'] ); ?></td>
						<td><?php echo esc_html( $skill['license'] ); ?></td>
						<td class="skill-actions">
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=editor&edit=' . rawurlencode( $skill['name'] ) ) ); ?>"
							   class="button button-small">
								<?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>
							</a>
							<button type="button" class="button button-small button-link-delete skill-delete-btn"
							        data-skill="<?php echo esc_attr( $skill['name'] ); ?>">
								<?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the "Upload & Install" tab content.
	 *
	 * @since 1.8.0
	 * @param string $nonce Nonce value for AJAX requests.
	 * @return void
	 */
	private static function render_tab_install( $nonce ) {
		?>
		<div style="margin-top:15px;">

			<?php /* ── Section 1: Upload file ── */ ?>
			<div class="install-section">
				<h3>
					<span class="dashicons dashicons-upload" style="margin-right:5px;"></span>
					<?php esc_html_e( 'Upload Skill File', 'mcp-ai-wpoos-pro' ); ?>
				</h3>
				<p class="description">
					<?php esc_html_e( 'Upload a SKILL.md file or a ZIP archive containing a skill directory (the root of the ZIP must contain a single skill directory with SKILL.md inside).', 'mcp-ai-wpoos-pro' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="skill-upload-file"><?php esc_html_e( 'Skill File', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="file" id="skill-upload-file" accept=".md,.zip"
							       style="display:block;margin-bottom:8px;" />
							<p class="description">
								<?php esc_html_e( 'Accepted: .md (SKILL.md) or .zip (skill archive)', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<button type="button" id="skill-upload-btn" class="button button-primary">
					<?php esc_html_e( 'Upload & Install', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<div id="upload-notice" class="skill-manager-notice"></div>
			</div>

			<?php /* ── Section 2: Install from URL ── */ ?>
			<div class="install-section">
				<h3>
					<span class="dashicons dashicons-admin-links" style="margin-right:5px;"></span>
					<?php esc_html_e( 'Install from URL', 'mcp-ai-wpoos-pro' ); ?>
				</h3>
				<p class="description">
					<?php esc_html_e( 'Enter a direct URL pointing to a raw SKILL.md file. The file will be downloaded and installed automatically.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Example: https://raw.githubusercontent.com/example/skills/main/my-skill/SKILL.md', 'mcp-ai-wpoos-pro' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="skill-url-input"><?php esc_html_e( 'SKILL.md URL', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="url" id="skill-url-input" class="large-text"
							       placeholder="https://raw.githubusercontent.com/…/SKILL.md" />
						</td>
					</tr>
				</table>

				<button type="button" id="skill-url-install-btn" class="button button-primary">
					<?php esc_html_e( 'Fetch & Install', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<div id="url-install-notice" class="skill-manager-notice"></div>
			</div>

		</div>

		<script type="text/javascript">
		/* AJAX nonce for install operations */
		var wpMcpAiSkillNonce = <?php echo wp_json_encode( $nonce ); ?>;
		</script>
		<?php
	}

	/**
	 * Render the "Skill Editor" tab content.
	 *
	 * @since 1.8.0
	 * @param string $nonce        Nonce value for AJAX.
	 * @param string $edit_name    Pre-loaded skill name (empty for new skill).
	 * @param string $edit_content Pre-loaded SKILL.md content (empty for new).
	 * @return void
	 */
	private static function render_tab_editor( $nonce, $edit_name, $edit_content ) {
		$placeholder = "---\nname: my-skill\ndescription: \"Brief description of what this skill does and when to use it.\"\nlicense: MIT\n---\n\n# My Skill\n\nInstructions for the AI agent go here.";
		?>
		<div style="margin-top:15px;">
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to specification */
					esc_html__( 'Write or paste SKILL.md content following the %s specification. The name field must be a lowercase slug matching the skill\'s directory name.', 'mcp-ai-wpoos-pro' ),
					'<a href="https://agentskills.io/specification" target="_blank" rel="noopener noreferrer">agentskills.io</a>'
				);
				?>
			</p>

			<?php if ( $edit_name ) : ?>
				<div class="notice notice-info" style="margin:10px 0;">
					<p>
						<?php
						printf(
							/* translators: %s: skill name being edited */
							esc_html__( 'Editing skill: %s', 'mcp-ai-wpoos-pro' ),
							'<strong>' . esc_html( $edit_name ) . '</strong>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<textarea id="skill-editor-textarea" name="skill_content"
			          placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $edit_content ); ?></textarea>

			<p style="margin-top:10px;">
				<button type="button" id="skill-save-btn" class="button button-primary"
				        data-editing="<?php echo esc_attr( $edit_name ); ?>">
					<?php echo $edit_name ? esc_html__( 'Update Skill', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Install Skill', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<?php if ( $edit_name ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=editor' ) ); ?>"
					   class="button" style="margin-left:8px;">
						<?php esc_html_e( 'New Skill', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				<?php endif; ?>
			</p>

			<div id="editor-notice" class="skill-manager-notice"></div>

			<?php /* Quick reference */ ?>
			<details style="margin-top:20px;">
				<summary style="cursor:pointer;font-weight:600;">
					<?php esc_html_e( 'SKILL.md Format Reference', 'mcp-ai-wpoos-pro' ); ?>
				</summary>
				<table class="form-table" role="presentation" style="max-width:700px;">
					<tbody>
						<tr>
							<th scope="row"><code>name</code> <?php esc_html_e( '(required)', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php esc_html_e( 'Lowercase slug (letters, numbers, hyphens only; max 64 chars; must match directory name).', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><code>description</code> <?php esc_html_e( '(required)', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php esc_html_e( 'What the skill does and when to use it. Max 1024 chars.', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><code>license</code></th>
							<td><?php esc_html_e( 'License identifier (e.g. MIT, Apache-2.0, Proprietary).', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><code>compatibility</code></th>
							<td><?php esc_html_e( 'Environment or dependency notes. Max 500 chars.', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><code>metadata</code></th>
							<td><?php esc_html_e( 'Optional key/value map (author, version, etc.).', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><code>allowed-tools</code></th>
							<td><?php esc_html_e( 'Space-separated list of pre-approved tool names (experimental).', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
					</tbody>
				</table>
			</details>
		</div>
		<?php
	}

	/**
	 * Render inline JavaScript for the page.
	 *
	 * Handles:
	 *  - Tab switching (no page reload – uses history.pushState)
	 *  - Skill list search filter
	 *  - Delete confirmation + AJAX delete
	 *  - File upload AJAX
	 *  - URL install AJAX
	 *  - Skill editor save AJAX
	 *  - CodeMirror initialisation for the editor textarea
	 *
	 * @since 1.8.0
	 * @param string $nonce Nonce value.
	 * @return void
	 */
	private static function render_inline_scripts( $nonce ) {
		?>
		<script type="text/javascript">
		( function() {
			'use strict';

			var nonce       = <?php echo wp_json_encode( $nonce ); ?>;
			var ajaxUrl     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			/* ──────────────────────────────────────────────
			   Helpers
			   ────────────────────────────────────────────── */
			function showNotice( el, message, isError ) {
				el.textContent = message;
				el.classList.remove( 'error' );
				if ( isError ) {
					el.classList.add( 'error' );
				}
				el.style.display = 'block';
			}

			function hideNotice( el ) {
				el.style.display = 'none';
				el.textContent   = '';
			}

			/* ──────────────────────────────────────────────
			   Skill list search filter
			   ────────────────────────────────────────────── */
			var searchInput = document.getElementById( 'skill-list-search' );
			if ( searchInput ) {
				searchInput.addEventListener( 'input', function() {
					var q    = this.value.toLowerCase().trim();
					var rows = document.querySelectorAll( '#skill-list-table tbody tr' );
					rows.forEach( function( row ) {
						var name = row.getAttribute( 'data-name' ) || '';
						var desc = row.getAttribute( 'data-description' ) || '';
						row.style.display = ( ! q || name.indexOf( q ) !== -1 || desc.indexOf( q ) !== -1 )
							? ''
							: 'none';
					} );
				} );
			}

			/* ──────────────────────────────────────────────
			   Delete skill buttons
			   ────────────────────────────────────────────── */
			document.querySelectorAll( '.skill-delete-btn' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function() {
					var skillName = this.getAttribute( 'data-skill' );
					if ( ! skillName ) {
						return;
					}

					var notice = document.getElementById( 'skill-manager-notice' );
					/* translators: %s: skill name */
					if ( ! window.confirm( <?php echo wp_json_encode( __( 'Are you sure you want to delete this skill? This action cannot be undone.', 'mcp-ai-wpoos-pro' ) ); ?> ) ) {
						return;
					}

					var row = this.closest( 'tr' );

					var fd = new FormData();
					fd.append( 'action', 'wp_mcp_ai_skill_manager_delete' );
					fd.append( 'nonce',  nonce );
					fd.append( 'skill',  skillName );

					fetch( ajaxUrl, { method: 'POST', body: fd } )
						.then( function( r ) { return r.json(); } )
						.then( function( data ) {
							if ( data.success ) {
								if ( row ) {
									row.remove();
								}
								if ( notice ) {
									showNotice( notice, data.data || <?php echo wp_json_encode( __( 'Skill deleted.', 'mcp-ai-wpoos-pro' ) ); ?>, false );
								}
							} else {
								if ( notice ) {
									showNotice( notice, data.data || <?php echo wp_json_encode( __( 'Delete failed.', 'mcp-ai-wpoos-pro' ) ); ?>, true );
								}
							}
						} )
						.catch( function() {
							if ( notice ) {
								showNotice( notice, <?php echo wp_json_encode( __( 'Network error. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?>, true );
							}
						} );
				} );
			} );

			/* ──────────────────────────────────────────────
			   File upload (SKILL.md or ZIP)
			   ────────────────────────────────────────────── */
			var uploadBtn    = document.getElementById( 'skill-upload-btn' );
			var uploadNotice = document.getElementById( 'upload-notice' );
			if ( uploadBtn ) {
				uploadBtn.addEventListener( 'click', function() {
					var fileInput = document.getElementById( 'skill-upload-file' );
					if ( ! fileInput || ! fileInput.files.length ) {
						showNotice( uploadNotice, <?php echo wp_json_encode( __( 'Please select a file first.', 'mcp-ai-wpoos-pro' ) ); ?>, true );
						return;
					}

					var fd = new FormData();
					fd.append( 'action', 'wp_mcp_ai_skill_manager_upload' );
					fd.append( 'nonce',  nonce );
					fd.append( 'skill_file', fileInput.files[0] );

					uploadBtn.disabled = true;
					hideNotice( uploadNotice );

					fetch( ajaxUrl, { method: 'POST', body: fd } )
						.then( function( r ) { return r.json(); } )
						.then( function( data ) {
							uploadBtn.disabled = false;
							showNotice( uploadNotice, data.data || '', ! data.success );
						} )
						.catch( function() {
							uploadBtn.disabled = false;
							showNotice( uploadNotice, <?php echo wp_json_encode( __( 'Network error. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?>, true );
						} );
				} );
			}

			/* ──────────────────────────────────────────────
			   Install from URL
			   ────────────────────────────────────────────── */
			var urlBtn    = document.getElementById( 'skill-url-install-btn' );
			var urlNotice = document.getElementById( 'url-install-notice' );
			if ( urlBtn ) {
				urlBtn.addEventListener( 'click', function() {
					var urlInput = document.getElementById( 'skill-url-input' );
					if ( ! urlInput || ! urlInput.value.trim() ) {
						showNotice( urlNotice, <?php echo wp_json_encode( __( 'Please enter a URL.', 'mcp-ai-wpoos-pro' ) ); ?>, true );
						return;
					}

					var fd = new FormData();
					fd.append( 'action', 'wp_mcp_ai_skill_manager_install_url' );
					fd.append( 'nonce',  nonce );
					fd.append( 'url',    urlInput.value.trim() );

					urlBtn.disabled = true;
					hideNotice( urlNotice );

					fetch( ajaxUrl, { method: 'POST', body: fd } )
						.then( function( r ) { return r.json(); } )
						.then( function( data ) {
							urlBtn.disabled = false;
							showNotice( urlNotice, data.data || '', ! data.success );
						} )
						.catch( function() {
							urlBtn.disabled = false;
							showNotice( urlNotice, <?php echo wp_json_encode( __( 'Network error. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?>, true );
						} );
				} );
			}

			/* ──────────────────────────────────────────────
			   Skill Editor save
			   ────────────────────────────────────────────── */
			var saveBtn      = document.getElementById( 'skill-save-btn' );
			var editorNotice = document.getElementById( 'editor-notice' );
			if ( saveBtn ) {
				saveBtn.addEventListener( 'click', function() {
					var textarea = document.getElementById( 'skill-editor-textarea' );
					/* If CodeMirror is active, sync content back to textarea */
					if ( window.wp && window.wp.codeEditor && window._skillCodeMirror ) {
						textarea.value = window._skillCodeMirror.getValue();
					}

					var content = textarea ? textarea.value : '';
					if ( ! content.trim() ) {
						showNotice( editorNotice, <?php echo wp_json_encode( __( 'Please enter SKILL.md content.', 'mcp-ai-wpoos-pro' ) ); ?>, true );
						return;
					}

					var fd = new FormData();
					fd.append( 'action',  'wp_mcp_ai_skill_manager_save' );
					fd.append( 'nonce',   nonce );
					fd.append( 'content', content );

					saveBtn.disabled = true;
					hideNotice( editorNotice );

					fetch( ajaxUrl, { method: 'POST', body: fd } )
						.then( function( r ) { return r.json(); } )
						.then( function( data ) {
							saveBtn.disabled = false;
							showNotice( editorNotice, data.data || '', ! data.success );
						} )
						.catch( function() {
							saveBtn.disabled = false;
							showNotice( editorNotice, <?php echo wp_json_encode( __( 'Network error. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?>, true );
						} );
				} );
			}

			/* ──────────────────────────────────────────────
			   CodeMirror initialisation
			   ────────────────────────────────────────────── */
			if ( window.wp && window.wp.codeEditor ) {
				var ta = document.getElementById( 'skill-editor-textarea' );
				if ( ta ) {
					var editorSettings = window.wpCodeEditorL10n ? window.wpCodeEditorL10n.codemirror : {};
					var instance = wp.codeEditor.initialize( ta, editorSettings );
					if ( instance && instance.codemirror ) {
						window._skillCodeMirror = instance.codemirror;
					}
				}
			}
		} )();
		</script>
		<?php
	}

	/* ═══════════════════════════════════════════════════════
	   AJAX handlers
	   ═══════════════════════════════════════════════════════ */

	/**
	 * Handle AJAX file upload (SKILL.md or ZIP).
	 *
	 * Accepts:
	 *  - .md  → treated directly as SKILL.md content.
	 *  - .zip → extracted; the root directory is expected to contain SKILL.md.
	 *
	 * @since 1.8.0
	 * @return void Outputs JSON and dies.
	 */
	public static function handle_ajax_upload() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $_FILES['skill_file'] ) || ! isset( $_FILES['skill_file']['tmp_name'] ) ) {
			wp_send_json_error( __( 'No file was uploaded.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below after type check.
		$uploaded = $_FILES['skill_file'];

		// Basic file size guard (8 MB).
		if ( isset( $uploaded['size'] ) && $uploaded['size'] > 8 * 1024 * 1024 ) {
			wp_send_json_error( __( 'File exceeds the 8 MB size limit.', 'mcp-ai-wpoos-pro' ) );
		}

		$original_name = isset( $uploaded['name'] ) ? sanitize_file_name( $uploaded['name'] ) : '';
		$tmp_path      = isset( $uploaded['tmp_name'] ) ? $uploaded['tmp_name'] : '';

		if ( empty( $tmp_path ) || ! is_uploaded_file( $tmp_path ) ) {
			wp_send_json_error( __( 'Invalid upload.', 'mcp-ai-wpoos-pro' ) );
		}

		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

		// Allowed extensions and the MIME types that each may legitimately produce.
		// Both the extension guard and the finfo MIME check draw from this single map.
		$allowed_mime_map = array(
			'md'  => array( 'text/plain', 'text/x-markdown', 'application/octet-stream' ),
			'zip' => array( 'application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream' ),
		);

		if ( ! array_key_exists( $ext, $allowed_mime_map ) ) {
			wp_send_json_error( __( 'Unsupported file type. Please upload a .md or .zip file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Use finfo to detect the real MIME type of the uploaded file.
		if ( function_exists( 'finfo_open' ) ) {
			$finfo     = finfo_open( FILEINFO_MIME_TYPE );
			$real_mime = $finfo ? finfo_file( $finfo, $tmp_path ) : false;
			if ( $finfo ) {
				finfo_close( $finfo );
			}

			if ( $real_mime && ! in_array( $real_mime, $allowed_mime_map[ $ext ], true ) ) {
				wp_send_json_error( __( 'File content does not match the declared extension. Upload rejected.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		if ( 'md' === $ext ) {
			// Direct SKILL.md upload.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading temporary uploaded file.
			$content = file_get_contents( $tmp_path );
			if ( false === $content ) {
				wp_send_json_error( __( 'Could not read the uploaded file.', 'mcp-ai-wpoos-pro' ) );
			}

			$result = self::get_registry()->install_skill( $content );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			wp_send_json_success(
				sprintf(
					/* translators: %s: installed skill name */
					__( 'Skill "%s" installed successfully.', 'mcp-ai-wpoos-pro' ),
					$result['name']
				)
			);
		}

		if ( 'zip' === $ext ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				wp_send_json_error( __( 'ZIP extraction requires the ZipArchive PHP extension, which is not available on this server.', 'mcp-ai-wpoos-pro' ) );
			}

			$result = self::install_from_zip( $tmp_path );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			wp_send_json_success(
				sprintf(
					/* translators: %s: installed skill name */
					__( 'Skill "%s" installed from ZIP successfully.', 'mcp-ai-wpoos-pro' ),
					$result['name']
				)
			);
		}
	}

	/**
	 * Handle AJAX install-from-URL request.
	 *
	 * @since 1.8.0
	 * @return void Outputs JSON and dies.
	 */
	public static function handle_ajax_install_url() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above via check_ajax_referer.

		if ( empty( $url ) ) {
			wp_send_json_error( __( 'Please provide a URL.', 'mcp-ai-wpoos-pro' ) );
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			wp_send_json_error( __( 'Only http and https URLs are supported.', 'mcp-ai-wpoos-pro' ) );
		}

		// Block SSRF: resolve the host to an IP and reject private/reserved ranges.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			wp_send_json_error( __( 'Invalid URL: could not determine host.', 'mcp-ai-wpoos-pro' ) );
		}

		// gethostbyname() returns the input unchanged when resolution fails.
		// Treat an unresolvable hostname as a hard rejection: we cannot determine
		// whether it points to a private address, so failing closed is safer.
		// Note: when $host is already a valid IP address, gethostbyname() returns
		// it unchanged and filter_var( $host, FILTER_VALIDATE_IP ) returns a
		// non-false value, so the AND condition is FALSE and we proceed to the
		// IP-range check below — which is the correct behavior for bare IPs.
		$resolved_ip = gethostbyname( $host );
		if ( $resolved_ip === $host && false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			wp_send_json_error( __( 'URL hostname could not be resolved. Please verify the URL is correct.', 'mcp-ai-wpoos-pro' ) );
		}

		// Reject private, loopback, link-local, and reserved IP ranges.
		// FILTER_FLAG_NO_PRIV_RANGE covers RFC-1918 (10/8, 172.16/12, 192.168/16),
		// loopback (127/8), and link-local (169.254/16).
		// FILTER_FLAG_NO_RES_RANGE covers IANA-reserved blocks including 0.0.0.0/8.
		if ( false === filter_var( $resolved_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			wp_send_json_error( __( 'URL resolves to a private or reserved address and cannot be fetched.', 'mcp-ai-wpoos-pro' ) );
		}

		// Replace the hostname with its resolved IP to prevent DNS rebinding: the IP
		// is pinned here so a second DNS lookup at request time cannot return a
		// different (private) address. Mirrors the REST controller pattern.
		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$query    = wp_parse_url( $url, PHP_URL_QUERY );
		$port     = wp_parse_url( $url, PHP_URL_PORT );
		$host_str = $resolved_ip . ( $port ? ':' . (int) $port : '' );
		$safe_url = $scheme . '://' . $host_str . ( $path ? $path : '' ) . ( $query ? '?' . $query : '' );

		$response = wp_remote_get(
			$safe_url,
			array(
				'timeout'    => 15,
				'user-agent' => 'WP-MCP-AI-Skill-Manager/' . WP_MCP_AI_PRO_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
				'headers'    => array(
					'Host' => $host,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message from HTTP request */
					__( 'Fetch failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			wp_send_json_error(
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'Remote server returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$code
				)
			);
		}

		$content = wp_remote_retrieve_body( $response );
		if ( empty( $content ) ) {
			wp_send_json_error( __( 'The remote URL returned an empty response.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = self::get_registry()->install_skill( $content );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			sprintf(
				/* translators: %s: installed skill name */
				__( 'Skill "%s" installed from URL successfully.', 'mcp-ai-wpoos-pro' ),
				$result['name']
			)
		);
	}

	/**
	 * Handle AJAX save (create or update) from the inline editor.
	 *
	 * @since 1.8.0
	 * @return void Outputs JSON and dies.
	 */
	public static function handle_ajax_save() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; content is SKILL.md text validated by parser.

		if ( empty( trim( $content ) ) ) {
			wp_send_json_error( __( 'Content cannot be empty.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = self::get_registry()->install_skill( $content );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			sprintf(
				/* translators: %s: skill name */
				__( 'Skill "%s" saved successfully.', 'mcp-ai-wpoos-pro' ),
				$result['name']
			)
		);
	}

	/**
	 * Handle AJAX delete skill request.
	 *
	 * @since 1.8.0
	 * @return void Outputs JSON and dies.
	 */
	public static function handle_ajax_delete() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

		$skill_name = isset( $_POST['skill'] ) ? sanitize_key( wp_unslash( $_POST['skill'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above via check_ajax_referer.

		if ( empty( $skill_name ) ) {
			wp_send_json_error( __( 'Skill name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = self::get_registry()->uninstall_skill( $skill_name );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			sprintf(
				/* translators: %s: skill name */
				__( 'Skill "%s" deleted successfully.', 'mcp-ai-wpoos-pro' ),
				$skill_name
			)
		);
	}

	/* ═══════════════════════════════════════════════════════
	   Private helpers
	   ═══════════════════════════════════════════════════════ */

	/**
	 * Extract a skill from a ZIP archive and install it.
	 *
	 * The ZIP is expected to contain a single top-level directory whose name
	 * matches the skill slug, with a SKILL.md file inside. Extra files
	 * alongside SKILL.md are also extracted and stored.
	 *
	 * @since 1.8.0
	 * @param string $zip_path Absolute path to the uploaded ZIP file.
	 * @return array|WP_Error Parsed skill data on success, WP_Error on failure.
	 */
	private static function install_from_zip( $zip_path ) {
		$zip = new ZipArchive();
		$opened = $zip->open( $zip_path );

		if ( true !== $opened ) {
			return new WP_Error(
				'wp_mcp_ai_skill_zip_open_failed',
				__( 'Could not open the ZIP archive.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Find the SKILL.md inside the archive.
		$skill_md_entry = null;
		$root_dir       = null;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );

			// Skip macOS metadata files.
			if ( false !== strpos( $entry, '__MACOSX' ) || false !== strpos( $entry, '.DS_Store' ) ) {
				continue;
			}

			// Security: prevent directory traversal in ZIP entries.
			if ( false !== strpos( $entry, '..' ) ) {
				$zip->close();
				return new WP_Error(
					'wp_mcp_ai_skill_zip_traversal',
					__( 'The ZIP archive contains potentially unsafe paths.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			if ( preg_match( '#^([^/]+)/SKILL\.md$#', $entry, $matches ) ) {
				$skill_md_entry = $entry;
				$root_dir       = $matches[1];
				break;
			}
		}

		if ( null === $skill_md_entry ) {
			// Check for SKILL.md at root level (flat archive).
			if ( false !== $zip->locateName( 'SKILL.md' ) ) {
				$skill_md_entry = 'SKILL.md';
				$root_dir       = '';
			}
		}

		if ( null === $skill_md_entry ) {
			$zip->close();
			return new WP_Error(
				'wp_mcp_ai_skill_zip_no_skill_md',
				__( 'No SKILL.md file found in the ZIP archive. Ensure your ZIP contains a skill directory with SKILL.md inside.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Read SKILL.md content.
		$content = $zip->getFromName( $skill_md_entry );
		if ( false === $content ) {
			$zip->close();
			return new WP_Error(
				'wp_mcp_ai_skill_zip_read_failed',
				__( 'Failed to read SKILL.md from the ZIP archive.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		// Collect extra files (siblings of SKILL.md within the root dir).
		$extra_files = array();
		$prefix      = '' !== $root_dir ? $root_dir . '/' : '';

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );

			if ( false !== strpos( $entry, '__MACOSX' ) || false !== strpos( $entry, '.DS_Store' ) ) {
				continue;
			}

			// Skip SKILL.md itself and directory entries.
			if ( $entry === $skill_md_entry || '/' === substr( $entry, -1 ) ) {
				continue;
			}

			// Only include files within the root skill directory.
			if ( '' !== $prefix && 0 !== strpos( $entry, $prefix ) ) {
				continue;
			}

			$relative_path = '' !== $prefix ? substr( $entry, strlen( $prefix ) ) : $entry;

			// Safety: skip anything with traversal sequences.
			if ( false !== strpos( $relative_path, '..' ) ) {
				continue;
			}

			$file_content = $zip->getFromName( $entry );
			if ( false !== $file_content ) {
				$extra_files[ $relative_path ] = $file_content;
			}
		}

		$zip->close();

		return self::get_registry()->install_skill( $content, $extra_files );
	}

	/**
	 * Get the Skill Registry instance, loading required classes if needed.
	 *
	 * @since 1.8.0
	 * @return WP_MCP_AI_Skill_Registry
	 */
	private static function get_registry() {
		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-registry.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Skill_Parser' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-parser.php';
		}

		return WP_MCP_AI_Skill_Registry::instance();
	}
}
