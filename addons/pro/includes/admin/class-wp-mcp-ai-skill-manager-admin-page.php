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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
		add_action( 'wp_ajax_wp_mcp_ai_skill_manager_generate_skill', array( __CLASS__, 'handle_ajax_generate_skill' ) );
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

		// Enqueue the Skill Manager stylesheet (external file; replaces former inline styles).
		wp_enqueue_style(
			'wp-mcp-ai-skill-manager',
			WP_MCP_AI_PRO_URL . 'assets/css/skill-manager-admin.css',
			array( 'wp-admin' ),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue the Skill Manager script.
		wp_enqueue_script(
			'wp-mcp-ai-skill-manager',
			WP_MCP_AI_PRO_URL . 'assets/js/skill-manager-admin.js',
			array(),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Pass all PHP-side data to JavaScript via wp_localize_script().
		wp_localize_script(
			'wp-mcp-ai-skill-manager',
			'wpMcpAiSkillManager',
			array(
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'confirmDelete'        => __( 'Are you sure you want to delete this skill? This action cannot be undone.', 'mcp-ai-wpoos-pro' ),
					'deleted'              => __( 'Skill deleted.', 'mcp-ai-wpoos-pro' ),
					'deleteFailed'         => __( 'Delete failed.', 'mcp-ai-wpoos-pro' ),
					'networkError'         => __( 'Network error. Please try again.', 'mcp-ai-wpoos-pro' ),
					'selectFile'           => __( 'Please select a file first.', 'mcp-ai-wpoos-pro' ),
					'enterUrl'             => __( 'Please enter a URL.', 'mcp-ai-wpoos-pro' ),
					'enterContent'         => __( 'Please enter SKILL.md content.', 'mcp-ai-wpoos-pro' ),
					'titleRequired'        => __( 'Please enter a Skill Title before continuing.', 'mcp-ai-wpoos-pro' ),
					'nameRequired'         => __( 'Skill Name (slug) is required.', 'mcp-ai-wpoos-pro' ),
					'nameInvalid'          => __( 'Skill name must be lowercase letters, numbers, and hyphens only (e.g. my-skill).', 'mcp-ai-wpoos-pro' ),
					'descRequired'         => __( 'Description is required.', 'mcp-ai-wpoos-pro' ),
					'instructionsRequired' => __( 'Please write skill instructions before continuing.', 'mcp-ai-wpoos-pro' ),
					'copied'               => __( 'Copied!', 'mcp-ai-wpoos-pro' ),
					'installed'            => __( 'Installed!', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
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
		$valid_tabs = array( 'list', 'install', 'editor', 'research-skill', 'browse' );
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
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=browse' ) ); ?>"
					class="nav-tab <?php echo 'browse' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-cloud" style="font-size:16px;vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Browse Catalogues', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=editor' ) ); ?>"
					class="nav-tab <?php echo 'editor' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Skill Editor', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=research-skill' ) ); ?>"
					class="nav-tab wp-mcp-ai-skill-manager-research-tab <?php echo 'research-skill' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-hammer" style="font-size:16px;vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Builder', 'mcp-ai-wpoos-pro' ); ?>
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
				<?php self::render_tab_install(); ?>
			</div>

			<?php /* ── Tab: Skill Editor ── */ ?>
			<div id="tab-editor" class="tab-content <?php echo 'editor' === $active_tab ? 'active' : ''; ?>">
				<?php self::render_tab_editor( $edit_name, $edit_content ); ?>
			</div>

			<?php /* ── Tab: Builder ── */ ?>
			<div id="tab-research-skill" class="tab-content <?php echo 'research-skill' === $active_tab ? 'active' : ''; ?>">
				<?php self::render_tab_research(); ?>
			</div>

			<?php /* ── Tab: Browse Catalogues ── */ ?>
			<div id="tab-browse" class="tab-content <?php echo 'browse' === $active_tab ? 'active' : ''; ?>">
				<?php self::render_tab_browse(); ?>
			</div>
		</div>


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
								class="button button-small" title="<?php esc_attr_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></span>
							</a>
							<button type="button" class="button button-small button-link-delete skill-delete-btn"
									data-skill="<?php echo esc_attr( $skill['name'] ); ?>"
									title="<?php esc_attr_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?>">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></span>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the "Builder" tab content.
	 *
	 * Provides a guided 4-step wizard for creating a new SKILL.md bundle,
	 * informed by the agentskills.io specification and the OpenAI Cookbook
	 * "Skills in API" patterns:
	 *  1. Research     – define topic, purpose, and trigger scenarios.
	 *  2. Configure    – set the name slug, description, license, and metadata.
	 *  3. Instructions – write the Markdown body the AI agent will follow.
	 *  4. Review & Install – preview the assembled SKILL.md and install it.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	private static function render_tab_research() {
		$licenses = array(
			'MIT'          => 'MIT',
			'Apache-2.0'   => 'Apache 2.0',
			'GPL-2.0'      => 'GPL 2.0',
			'GPL-3.0'      => 'GPL 3.0',
			'BSD-2-Clause' => 'BSD 2-Clause',
			'BSD-3-Clause' => 'BSD 3-Clause',
			'ISC'          => 'ISC',
			'Proprietary'  => 'Proprietary',
			'CC0-1.0'      => 'CC0 (Public Domain)',
		);
		?>
<div class="research-wizard" style="margin-top:15px;">

		<?php /* ── OpenAI cookbook guidance card ── */ ?>
<div style="background:#f0f6fc;border:1px solid #c3d9ee;border-radius:4px;padding:14px 18px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;">
<span class="dashicons dashicons-info-outline" style="color:#0073aa;font-size:22px;flex-shrink:0;margin-top:2px;"></span>
<div style="font-size:12px;color:#333;line-height:1.6;">
<strong style="font-size:13px;"><?php esc_html_e( 'Skills vs. Tools vs. System Prompts', 'mcp-ai-wpoos-pro' ); ?></strong><br />
		<?php esc_html_e( 'Use a Skill for reusable, version-controlled procedures an agent can invoke by name. Use a Tool for live API or database connections. Use a System Prompt for global tone and guardrails.', 'mcp-ai-wpoos-pro' ); ?>
&mdash; <a href="https://developers.openai.com/cookbook/examples/skills_in_api" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'OpenAI Cookbook', 'mcp-ai-wpoos-pro' ); ?></a>
</div>
</div>

		<?php /* ── Progress bar ── */ ?>
<div class="wizard-progress" role="navigation" aria-label="<?php esc_attr_e( 'Skill builder steps', 'mcp-ai-wpoos-pro' ); ?>">
<div class="wizard-step-indicator active" data-step="1" id="wizard-step-ind-1">
<span class="wizard-step-num" aria-hidden="true">1</span>
<span class="wizard-step-label"><?php esc_html_e( 'Research', 'mcp-ai-wpoos-pro' ); ?></span>
</div>
<div class="wizard-step-connector" id="wizard-conn-1"></div>
<div class="wizard-step-indicator" data-step="2" id="wizard-step-ind-2">
<span class="wizard-step-num" aria-hidden="true">2</span>
<span class="wizard-step-label"><?php esc_html_e( 'Configure', 'mcp-ai-wpoos-pro' ); ?></span>
</div>
<div class="wizard-step-connector" id="wizard-conn-2"></div>
<div class="wizard-step-indicator" data-step="3" id="wizard-step-ind-3">
<span class="wizard-step-num" aria-hidden="true">3</span>
<span class="wizard-step-label"><?php esc_html_e( 'Instructions', 'mcp-ai-wpoos-pro' ); ?></span>
</div>
<div class="wizard-step-connector" id="wizard-conn-3"></div>
<div class="wizard-step-indicator" data-step="4" id="wizard-step-ind-4">
<span class="wizard-step-num" aria-hidden="true">4</span>
<span class="wizard-step-label"><?php esc_html_e( 'Review &amp; Install', 'mcp-ai-wpoos-pro' ); ?></span>
</div>
</div>

		<?php /* ═══ STEP 1: Research ═══ */ ?>
<div class="wizard-step-panel active" id="research-panel-1">
<div class="wizard-panel-header">
<h3><?php esc_html_e( 'Step 1 of 4 &#8212; Research Your Skill', 'mcp-ai-wpoos-pro' ); ?></h3>
<p><?php esc_html_e( 'Think through what your skill needs to accomplish. Your answers here automatically populate the configuration in Step 2.', 'mcp-ai-wpoos-pro' ); ?></p>
</div>

<table class="form-table" role="presentation">
<tr>
<th scope="row">
<label for="research-title"><?php esc_html_e( 'Skill Title', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<input type="text" id="research-title" class="regular-text"
		placeholder="<?php esc_attr_e( 'e.g. PDF Text Extractor', 'mcp-ai-wpoos-pro' ); ?>" />
<p class="description">
		<?php esc_html_e( 'A human-friendly title. Auto-generates the skill name slug in Step 2.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</td>
</tr>
<tr>
<th scope="row">
<label for="research-purpose"><?php esc_html_e( 'Purpose', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<textarea id="research-purpose" class="large-text" rows="4"
			placeholder="<?php esc_attr_e( 'Describe what this skill does and why it exists&#8230;', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
<p class="description">
		<?php esc_html_e( 'A clear, specific explanation of the skill\'s function. Pre-fills the description in Step 2.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</td>
</tr>
<tr>
<th scope="row">
<label for="research-triggers"><?php esc_html_e( 'When to Use', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<textarea id="research-triggers" class="large-text" rows="3"
			placeholder="<?php esc_attr_e( 'Describe specific scenarios where an AI agent should invoke this skill&#8230;', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
<div class="research-field-tip">
		<?php esc_html_e( 'Tip: Precise trigger conditions lead to better agent behaviour. Per the OpenAI Cookbook, skills should be invoked by name only when the specific procedure applies &#8212; not as a catch-all.', 'mcp-ai-wpoos-pro' ); ?>
</div>
</td>
</tr>
<tr>
<th scope="row">
<label for="research-domain"><?php esc_html_e( 'Domain / Category', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<select id="research-domain" class="regular-text">
<option value=""><?php esc_html_e( '&#8212; Select a domain &#8212;', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="document-processing"><?php esc_html_e( 'Document Processing', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="data-analysis"><?php esc_html_e( 'Data Analysis', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="content-generation"><?php esc_html_e( 'Content Generation', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="code-assistance"><?php esc_html_e( 'Code Assistance', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="web-research"><?php esc_html_e( 'Web Research', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="customer-support"><?php esc_html_e( 'Customer Support', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="workflow-automation"><?php esc_html_e( 'Workflow Automation', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="e-commerce"><?php esc_html_e( 'E-Commerce', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="seo-marketing"><?php esc_html_e( 'SEO &amp; Marketing', 'mcp-ai-wpoos-pro' ); ?></option>
<option value="other"><?php esc_html_e( 'Other', 'mcp-ai-wpoos-pro' ); ?></option>
</select>
<p class="description">
		<?php esc_html_e( 'Optional. Stored as metadata.category to help organise and discover skills.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</td>
</tr>
<tr>
<th scope="row">
<label for="research-has-scripts"><?php esc_html_e( 'Bundle Type', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<fieldset>
<label style="display:block;margin-bottom:5px;">
<input type="radio" name="research_bundle_type" id="research-bundle-md" value="md" checked />
		<?php esc_html_e( 'SKILL.md only &#8212; instructions are self-contained in the Markdown file', 'mcp-ai-wpoos-pro' ); ?>
</label>
<label style="display:block;">
<input type="radio" name="research_bundle_type" id="research-bundle-zip" value="zip" />
		<?php esc_html_e( 'Full bundle (ZIP) &#8212; SKILL.md + scripts/, references/, or assets/ sub-directories', 'mcp-ai-wpoos-pro' ); ?>
</label>
</fieldset>
<div id="research-bundle-zip-note" style="display:none;margin-top:8px;" class="research-field-tip">
		<?php esc_html_e( 'After installing the SKILL.md you can upload the full ZIP via the Upload &amp; Install tab. The wizard assembles the SKILL.md manifest; you add the supporting files separately.', 'mcp-ai-wpoos-pro' ); ?>
</div>
</td>
</tr>
</table>

<div class="wizard-step-nav">
<div class="spacer"></div>
<button type="button" class="button button-primary" id="research-next-1">
		<?php esc_html_e( 'Next: Configure', 'mcp-ai-wpoos-pro' ); ?> &rarr;
</button>
</div>
</div>

		<?php /* ═══ STEP 2: Configure ═══ */ ?>
<div class="wizard-step-panel" id="research-panel-2">
<div class="wizard-panel-header">
<h3><?php esc_html_e( 'Step 2 of 4 &#8212; Configure Skill Identity', 'mcp-ai-wpoos-pro' ); ?></h3>
<p><?php esc_html_e( 'Set the SKILL.md frontmatter fields. Fields are pre-populated from Step 1 &#8212; edit as needed.', 'mcp-ai-wpoos-pro' ); ?></p>
</div>

<table class="form-table" role="presentation">
<tr>
<th scope="row">
<label for="research-name">
		<?php esc_html_e( 'Skill Name (slug)', 'mcp-ai-wpoos-pro' ); ?>
<span style="color:#dc3232;" aria-label="<?php esc_attr_e( 'Required', 'mcp-ai-wpoos-pro' ); ?>">*</span>
</label>
</th>
<td>
<input type="text" id="research-name" class="regular-text"
		placeholder="<?php esc_attr_e( 'my-skill', 'mcp-ai-wpoos-pro' ); ?>"
		maxlength="64" />
<p class="description">
		<?php esc_html_e( 'Lowercase letters, numbers, and hyphens only. Max 64 chars. Must match the skill directory name.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</td>
</tr>
<tr>
<th scope="row">
<label for="research-version"><?php esc_html_e( 'Version', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<input type="text" id="research-version" class="small-text"
		placeholder="1.0.0" value="1.0.0" />
<p class="description">
		<?php esc_html_e( 'Semantic version (e.g. 1.0.0). The OpenAI Cookbook recommends explicit versioning for reproducibility across agents.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</td>
</tr>
<tr>
<th scope="row">
<label for="research-description">
		<?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?>
<span style="color:#dc3232;" aria-label="<?php esc_attr_e( 'Required', 'mcp-ai-wpoos-pro' ); ?>">*</span>
</label>
</th>
<td>
<textarea id="research-description" class="large-text" rows="3" maxlength="1024"></textarea>
<span class="char-counter" id="desc-counter">0 / 1024</span>
<p class="description">
		<?php esc_html_e( 'What the skill does and precisely when to invoke it. Max 1024 characters. This is the text the AI model reads to decide whether to use this skill.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</td>
</tr>
<tr>
<th scope="row">
		<?php esc_html_e( 'License', 'mcp-ai-wpoos-pro' ); ?>
</th>
<td>
<div class="license-grid" id="research-license-grid">
		<?php foreach ( $licenses as $value => $label ) : ?>
<label class="license-option <?php echo 'MIT' === $value ? 'selected' : ''; ?>"
		data-value="<?php echo esc_attr( $value ); ?>">
<input type="radio" name="research_license" value="<?php echo esc_attr( $value ); ?>"
		style="display:none;"
			<?php echo 'MIT' === $value ? 'checked' : ''; ?> />
			<?php echo esc_html( $label ); ?>
</label>
<?php endforeach; ?>
</div>
<input type="hidden" id="research-license" value="MIT" />
</td>
</tr>
<tr>
<th scope="row">
<label for="research-compatibility"><?php esc_html_e( 'Compatibility', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<input type="text" id="research-compatibility" class="large-text" maxlength="500"
		placeholder="<?php esc_attr_e( 'e.g. Requires Python 3.8+, poppler-utils', 'mcp-ai-wpoos-pro' ); ?>" />
<span class="char-counter" id="compat-counter">0 / 500</span>
<p class="description">
		<?php esc_html_e( 'Optional runtime or dependency notes. Max 500 characters.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</td>
</tr>
<tr>
<th scope="row">
		<?php esc_html_e( 'Author &amp; Homepage', 'mcp-ai-wpoos-pro' ); ?>
</th>
<td>
<table style="border-collapse:collapse;width:100%;max-width:520px;">
<tr>
<td style="width:110px;padding:4px 8px 4px 0;">
<label for="research-author" style="font-size:12px;font-weight:600;"><?php esc_html_e( 'Author', 'mcp-ai-wpoos-pro' ); ?></label>
</td>
<td style="padding:4px 0;">
<input type="text" id="research-author" class="regular-text"
		placeholder="<?php esc_attr_e( 'Your name or organisation', 'mcp-ai-wpoos-pro' ); ?>" />
</td>
</tr>
<tr>
<td style="padding:4px 8px 4px 0;">
<label for="research-homepage" style="font-size:12px;font-weight:600;"><?php esc_html_e( 'Homepage', 'mcp-ai-wpoos-pro' ); ?></label>
</td>
<td style="padding:4px 0;">
<input type="url" id="research-homepage" class="large-text"
		placeholder="https://example.com/my-skill" />
</td>
</tr>
</table>
</td>
</tr>
<tr>
<th scope="row">
<label for="research-allowed-tools"><?php esc_html_e( 'Allowed Tools', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<input type="text" id="research-allowed-tools" class="large-text"
		placeholder="<?php esc_attr_e( 'e.g. Bash WebSearch ReadFiles', 'mcp-ai-wpoos-pro' ); ?>" />
<p class="description">
		<?php esc_html_e( 'Optional. Space-separated list of pre-approved tool names (agentskills.io spec, experimental). These map to function-calling tool names in the OpenAI API.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</td>
</tr>
</table>

<div class="wizard-step-nav">
<button type="button" class="button" id="research-back-2">
&larr; <?php esc_html_e( 'Back', 'mcp-ai-wpoos-pro' ); ?>
</button>
<div class="spacer"></div>
<button type="button" class="button button-primary" id="research-next-2">
		<?php esc_html_e( 'Next: Instructions', 'mcp-ai-wpoos-pro' ); ?> &rarr;
</button>
</div>
</div>

		<?php /* ═══ STEP 3: Instructions ═══ */ ?>
<div class="wizard-step-panel" id="research-panel-3">
<div class="wizard-panel-header">
<h3><?php esc_html_e( 'Step 3 of 4 &#8212; Write Skill Instructions', 'mcp-ai-wpoos-pro' ); ?></h3>
<p>
		<?php
		printf(
		/* translators: 1: agentskills.io link, 2: OpenAI cookbook link */
			esc_html__( 'Write the Markdown body your AI agent will follow. See the %1$s specification and the %2$s for best-practice patterns.', 'mcp-ai-wpoos-pro' ),
			'<a href="https://agentskills.io/specification" target="_blank" rel="noopener noreferrer">agentskills.io</a>',
			'<a href="https://developers.openai.com/cookbook/examples/skills_in_api" target="_blank" rel="noopener noreferrer">' . esc_html__( 'OpenAI Cookbook', 'mcp-ai-wpoos-pro' ) . '</a>'
		);
		?>
</p>
</div>

<p style="margin-bottom:8px;">
<button type="button" class="button" id="research-gen-template">
<span class="dashicons dashicons-editor-insertmore" style="vertical-align:middle;font-size:16px;"></span>
		<?php esc_html_e( 'Insert Starter Template', 'mcp-ai-wpoos-pro' ); ?>
</button>
<span style="margin-left:8px;font-size:12px;color:#666;">
		<?php esc_html_e( 'Builds a structured template using your research from Steps 1 &amp; 2.', 'mcp-ai-wpoos-pro' ); ?>
</span>
</p>

<textarea id="research-instructions" class="large-text" rows="20"
			style="font-family:monospace;font-size:13px;line-height:1.55;"></textarea>

<details style="margin-top:16px;">
<summary style="cursor:pointer;font-weight:600;font-size:12px;color:#555;">
		<?php esc_html_e( 'Tips for effective skill instructions (OpenAI Cookbook &amp; agentskills.io)', 'mcp-ai-wpoos-pro' ); ?>
</summary>
<table class="form-table" role="presentation" style="max-width:700px;margin-top:8px;">
<tr>
<th scope="row" style="font-size:12px;width:180px;"><?php esc_html_e( 'Numbered steps', 'mcp-ai-wpoos-pro' ); ?></th>
<td style="font-size:12px;"><?php esc_html_e( 'Give the agent a deterministic path it can follow and retry on failure.', 'mcp-ai-wpoos-pro' ); ?></td>
</tr>
<tr>
<th scope="row" style="font-size:12px;"><?php esc_html_e( 'Concrete examples', 'mcp-ai-wpoos-pro' ); ?></th>
<td style="font-size:12px;"><?php esc_html_e( 'Include sample inputs and expected outputs to reduce hallucination.', 'mcp-ai-wpoos-pro' ); ?></td>
</tr>
<tr>
<th scope="row" style="font-size:12px;"><?php esc_html_e( 'Branching &amp; validation', 'mcp-ai-wpoos-pro' ); ?></th>
<td style="font-size:12px;"><?php esc_html_e( 'Add conditional logic (If X, do Y; else do Z) and explicit validation checks per the OpenAI Cookbook.', 'mcp-ai-wpoos-pro' ); ?></td>
</tr>
<tr>
<th scope="row" style="font-size:12px;"><?php esc_html_e( 'Reference files', 'mcp-ai-wpoos-pro' ); ?></th>
<td style="font-size:12px;"><?php esc_html_e( 'Mention scripts/normalize.py or references/schema.json when those sub-directory files are bundled in a ZIP.', 'mcp-ai-wpoos-pro' ); ?></td>
</tr>
<tr>
<th scope="row" style="font-size:12px;"><?php esc_html_e( 'Token budget', 'mcp-ai-wpoos-pro' ); ?></th>
<td style="font-size:12px;"><?php esc_html_e( 'Keep the body under ~5\xc2\xa0000 tokens for best performance across all supported models.', 'mcp-ai-wpoos-pro' ); ?></td>
</tr>
</table>
</details>

<div class="wizard-step-nav">
<button type="button" class="button" id="research-back-3">
&larr; <?php esc_html_e( 'Back', 'mcp-ai-wpoos-pro' ); ?>
</button>
<div class="spacer"></div>
<button type="button" class="button button-primary" id="research-next-3">
		<?php esc_html_e( 'Next: Review &amp; Install', 'mcp-ai-wpoos-pro' ); ?> &rarr;
</button>
</div>
</div>

		<?php /* ═══ STEP 4: Review & Install ═══ */ ?>
<div class="wizard-step-panel" id="research-panel-4">
<div class="wizard-panel-header">
<h3><?php esc_html_e( 'Step 4 of 4 &#8212; Review &amp; Install', 'mcp-ai-wpoos-pro' ); ?></h3>
<p><?php esc_html_e( 'Review the assembled SKILL.md below. Install it directly or open it in the Skill Editor for further edits.', 'mcp-ai-wpoos-pro' ); ?></p>
</div>

<div style="overflow:hidden;margin-bottom:8px;">
<button type="button" class="button button-small" id="research-copy-btn" style="float:right;">
<span class="dashicons dashicons-clipboard" style="font-size:14px;vertical-align:middle;"></span>
		<?php esc_html_e( 'Copy to Clipboard', 'mcp-ai-wpoos-pro' ); ?>
</button>
<strong style="font-size:13px;line-height:28px;"><?php esc_html_e( 'Generated SKILL.md', 'mcp-ai-wpoos-pro' ); ?></strong>
</div>
<pre class="skill-preview-block" id="research-preview" aria-live="polite"></pre>

		<?php /* OpenAI tool schema panel */ ?>
<details style="margin-top:16px;" id="research-schema-details">
<summary style="cursor:pointer;font-weight:600;font-size:12px;color:#555;">
<span class="dashicons dashicons-rest-api" style="font-size:14px;vertical-align:middle;"></span>
		<?php esc_html_e( 'OpenAI API Tool Schema (JSON) &#8212; register this skill as a function-calling tool', 'mcp-ai-wpoos-pro' ); ?>
</summary>
<div style="margin-top:8px;">
<p class="description">
		<?php
		printf(
		/* translators: %s: OpenAI cookbook link */
			esc_html__( 'Copy this JSON object into your %s tools array to make this skill invocable via function calling.', 'mcp-ai-wpoos-pro' ),
			'<a href="https://platform.openai.com/docs/guides/function-calling" target="_blank" rel="noopener noreferrer">OpenAI API</a>'
		);
		?>
</p>
<div style="overflow:hidden;margin-bottom:6px;">
<button type="button" class="button button-small" id="research-copy-schema-btn" style="float:right;">
<span class="dashicons dashicons-clipboard" style="font-size:14px;vertical-align:middle;"></span>
		<?php esc_html_e( 'Copy Schema', 'mcp-ai-wpoos-pro' ); ?>
</button>
</div>
<pre class="skill-preview-block" id="research-schema-preview" style="max-height:260px;"></pre>
</div>
</details>

		<?php /* Directory structure preview */ ?>
<details style="margin-top:12px;" id="research-dir-details">
<summary style="cursor:pointer;font-weight:600;font-size:12px;color:#555;">
<span class="dashicons dashicons-category" style="font-size:14px;vertical-align:middle;"></span>
		<?php esc_html_e( 'Skill Directory Structure', 'mcp-ai-wpoos-pro' ); ?>
</summary>
<pre class="skill-preview-block" id="research-dir-preview" style="max-height:180px;margin-top:8px;"></pre>
<p class="description" style="margin-top:6px;">
		<?php esc_html_e( 'For a full bundle (scripts, references, assets), upload a ZIP via the Upload &amp; Install tab after installing the SKILL.md here.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</details>

<div id="research-install-notice" class="skill-manager-notice" style="margin-top:14px;"></div>

<div class="wizard-step-nav">
<button type="button" class="button" id="research-back-4">
&larr; <?php esc_html_e( 'Back', 'mcp-ai-wpoos-pro' ); ?>
</button>
<div class="spacer"></div>
<button type="button" class="button" id="research-open-editor-btn">
<span class="dashicons dashicons-edit" style="font-size:14px;vertical-align:middle;"></span>
		<?php esc_html_e( 'Open in Skill Editor', 'mcp-ai-wpoos-pro' ); ?>
</button>
<button type="button" class="button button-primary" id="research-install-btn">
<span class="dashicons dashicons-yes" style="font-size:14px;vertical-align:middle;"></span>
		<?php esc_html_e( 'Install Skill', 'mcp-ai-wpoos-pro' ); ?>
</button>
</div>
</div>

</div><!-- /.research-wizard -->
		<?php
	}

	/**
	 * Handle AJAX request to generate a validated SKILL.md from structured wizard inputs.
	 *
	 * Assembles the YAML frontmatter and Markdown body server-side, runs it
	 * through the Skill Parser for validation, and returns the result. Acts as
	 * an extension point for future AI-powered generation.
	 *
	 * @since 1.9.0
	 * @return void Outputs JSON and dies.
	 */
	public static function handle_ajax_generate_skill() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$name          = isset( $_POST['name'] ) ? sanitize_key( wp_unslash( $_POST['name'] ) ) : '';
		$version       = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '1.0.0';
		$description   = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
		$license       = isset( $_POST['license'] ) ? sanitize_text_field( wp_unslash( $_POST['license'] ) ) : 'MIT';
		$compatibility = isset( $_POST['compatibility'] ) ? sanitize_text_field( wp_unslash( $_POST['compatibility'] ) ) : '';
		$author        = isset( $_POST['author'] ) ? sanitize_text_field( wp_unslash( $_POST['author'] ) ) : '';
		$homepage      = isset( $_POST['homepage'] ) ? esc_url_raw( wp_unslash( $_POST['homepage'] ) ) : '';
		$category      = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';
		$allowed_tools = isset( $_POST['allowed_tools'] ) ? sanitize_text_field( wp_unslash( $_POST['allowed_tools'] ) ) : '';
		$instructions  = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Skill name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $description ) ) {
			wp_send_json_error( __( 'Description is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Assemble YAML frontmatter.
		$yaml  = "---\n";
		$yaml .= 'name: ' . $name . "\n";
		$yaml .= 'description: "' . str_replace( '"', '\\"', $description ) . "\"\n";

		if ( ! empty( $license ) ) {
			$yaml .= 'license: ' . $license . "\n";
		}

		if ( ! empty( $compatibility ) ) {
			$yaml .= 'compatibility: "' . str_replace( '"', '\\"', $compatibility ) . "\"\n";
		}

		if ( ! empty( $allowed_tools ) ) {
			$yaml .= 'allowed-tools: ' . $allowed_tools . "\n";
		}

		// Metadata block.
		$meta_lines = array();
		if ( ! empty( $version ) ) {
			$meta_lines[] = '  version: "' . $version . '"';
		}
		if ( ! empty( $author ) ) {
			$meta_lines[] = '  author: "' . str_replace( '"', '\\"', $author ) . '"';
		}
		if ( ! empty( $homepage ) ) {
			$meta_lines[] = '  homepage: "' . $homepage . '"';
		}
		if ( ! empty( $category ) ) {
			$meta_lines[] = '  category: "' . str_replace( '"', '\\"', $category ) . '"';
		}
		if ( ! empty( $meta_lines ) ) {
			$yaml .= "metadata:\n" . implode( "\n", $meta_lines ) . "\n";
		}

		$yaml .= "---\n\n";

		$body    = ! empty( trim( $instructions ) ) ? $instructions : '# ' . $name . "\n\n" . __( 'Describe the skill instructions here.', 'mcp-ai-wpoos-pro' );
		$content = $yaml . $body;

		// Validate through the parser.
		$registry = self::get_registry();

		if ( class_exists( 'WP_MCP_AI_Skill_Parser' ) ) {
			$parser = new WP_MCP_AI_Skill_Parser();
			$parsed = $parser->parse( $content );

			if ( is_wp_error( $parsed ) ) {
				wp_send_json_error( $parsed->get_error_message() );
			}
		}

		wp_send_json_success(
			array(
				'content' => $content,
				'name'    => $name,
			)
		);
	}

	/**
	 * Render the "Upload & Install" tab content.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	private static function render_tab_install() {
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

		<?php
	}

	/**
	 * Render the "Skill Editor" tab content.
	 *
	 * @since 1.8.0
	 * @param string $edit_name    Pre-loaded skill name (empty for new skill).
	 * @param string $edit_content Pre-loaded SKILL.md content (empty for new).
	 * @return void
	 */
	private static function render_tab_editor( $edit_name, $edit_content ) {
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

	/*
	═══════════════════════════════════════════════════════
		AJAX handlers
		═══════════════════════════════════════════════════════
	 */

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
		// Reject the upload entirely if MIME detection is unavailable (fail-closed).
		if ( ! function_exists( 'finfo_open' ) ) {
			wp_send_json_error( __( 'The fileinfo PHP extension is required to validate uploads securely. Please contact your host to enable it.', 'mcp-ai-wpoos-pro' ) );
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( ! $finfo ) {
			wp_send_json_error( __( 'Could not open the fileinfo resource for MIME detection. Upload rejected.', 'mcp-ai-wpoos-pro' ) );
		}

		$real_mime = finfo_file( $finfo, $tmp_path );
		finfo_close( $finfo );

		if ( false === $real_mime ) {
			wp_send_json_error( __( 'Could not detect the MIME type of the uploaded file. Upload rejected.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! in_array( $real_mime, $allowed_mime_map[ $ext ], true ) ) {
			wp_send_json_error( __( 'File content does not match the declared extension. Upload rejected.', 'mcp-ai-wpoos-pro' ) );
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
		if ( 'https' !== $scheme ) {
			wp_send_json_error( __( 'Only HTTPS URLs are supported for skill installation.', 'mcp-ai-wpoos-pro' ) );
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

	/*
	═══════════════════════════════════════════════════════
		Private helpers
		═══════════════════════════════════════════════════════
	 */

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
		$zip    = new ZipArchive();
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

		// `num_files` is not a ZipArchive property on PHP 8.x; count the archive.
		$entry_count = count( $zip );
		for ( $i = 0; $i < $entry_count; $i++ ) {
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

		for ( $i = 0; $i < $entry_count; $i++ ) {
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

	/**
	 * Render the "Browse Catalogues" tab.
	 *
	 * The tab is a thin client over the Catalogue REST API: it lists registered
	 * sources, fetches their manifests, and lets admins one-click-install each
	 * skill. The actual install funnels through the registry's hardened pipeline
	 * (extension allowlist + decompression cap), so this UI carries no extra
	 * security surface beyond a nonce-gated REST call.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	private static function render_tab_browse() {
		if ( ! class_exists( 'WP_MCP_AI_Skill_Catalogue_Service' ) ) {
			?>
			<p><?php esc_html_e( 'Skill Catalogue service is not available on this site.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php
			return;
		}

		$service = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$sources = $service->get_sources();

		// Inline a small JS payload that the browse-tab script will read for
		// REST URL + nonce. We reuse the existing wp-mcp-ai-skill-manager script
		// handle so we do not need a separate enqueue.
		$rest_root  = esc_url_raw( rest_url( 'mcp-ai-pro/v1/catalogues' ) );
		$rest_nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wp-mcp-ai-skill-browse">
			<h2><?php esc_html_e( 'Browse Catalogue', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php
				$settings_url = esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-settings&tab=catalogues' ) );
				printf(
					/* translators: %s: link to the Catalogues settings tab */
					wp_kses_post( __( 'Install skills from registered remote catalogues. Add, remove, or pin sources on the <a href="%s">Catalogues settings tab</a>.', 'mcp-ai-wpoos-pro' ) ),
					$settings_url // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url'd above.
				);
				?>
			</p>

			<?php if ( empty( $sources ) ) : ?>
				<p><?php esc_html_e( 'No catalogue sources are registered yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<p>
					<label for="wp-mcp-ai-skill-browse-source"><strong><?php esc_html_e( 'Source:', 'mcp-ai-wpoos-pro' ); ?></strong></label>
					<select id="wp-mcp-ai-skill-browse-source">
						<?php foreach ( $sources as $src ) : ?>
							<option value="<?php echo esc_attr( $src['id'] ); ?>"><?php echo esc_html( $src['label'] . ' (' . $src['owner'] . '/' . $src['repo'] . '@' . $src['ref'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" id="wp-mcp-ai-skill-browse-refresh">
						<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</p>

				<div id="wp-mcp-ai-skill-browse-status" class="notice notice-info inline" style="display:none;"></div>

				<table class="wp-list-table widefat fixed striped" id="wp-mcp-ai-skill-browse-table" style="margin-top:12px;">
					<thead>
						<tr>
							<th scope="col" style="width:22%;"><?php esc_html_e( 'Skill', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col" style="width:12%;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col" style="width:14%;"><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td colspan="4"><em><?php esc_html_e( 'Select a source above to load its skill list.', 'mcp-ai-wpoos-pro' ); ?></em></td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<script>
		(function () {
			var root = <?php echo wp_json_encode( $rest_root ); ?>;
			var nonce = <?php echo wp_json_encode( $rest_nonce ); ?>;
			var sel = document.getElementById( 'wp-mcp-ai-skill-browse-source' );
			var btn = document.getElementById( 'wp-mcp-ai-skill-browse-refresh' );
			var table = document.getElementById( 'wp-mcp-ai-skill-browse-table' );
			var status = document.getElementById( 'wp-mcp-ai-skill-browse-status' );
			if ( ! sel || ! table ) {
				return;
			}
			var tbody = table.querySelector( 'tbody' );

			function setStatus( msg, isError ) {
				if ( ! status ) { return; }
				status.style.display = msg ? 'block' : 'none';
				status.className = 'notice ' + ( isError ? 'notice-error' : 'notice-info' ) + ' inline';
				status.textContent = msg || '';
			}

			function escapeHtml( s ) {
				return String( s == null ? '' : s )
					.replace( /&/g, '&amp;' )
					.replace( /</g, '&lt;' )
					.replace( />/g, '&gt;' )
					.replace( /"/g, '&quot;' )
					.replace( /'/g, '&#39;' );
			}

			function loadSkills( force ) {
				var id = sel.value;
				if ( ! id ) { return; }
				setStatus( <?php echo wp_json_encode( __( 'Loading catalogue…', 'mcp-ai-wpoos-pro' ) ); ?> );
				tbody.innerHTML = '<tr><td colspan="4"><em>' + escapeHtml( <?php echo wp_json_encode( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ); ?> ) + '</em></td></tr>';
				var url = root + '/' + encodeURIComponent( id ) + '/skills' + ( force ? '?force=1' : '' );
				fetch( url, { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, data: j }; } ); } )
					.then( function ( res ) {
						if ( ! res.ok ) {
							var msg = res.data && res.data.message ? res.data.message : <?php echo wp_json_encode( __( 'Failed to load catalogue.', 'mcp-ai-wpoos-pro' ) ); ?>;
							setStatus( msg, true );
							tbody.innerHTML = '<tr><td colspan="4"><em>' + escapeHtml( msg ) + '</em></td></tr>';
							return;
						}
						setStatus( '' );
						var skills = ( res.data && res.data.skills ) || [];
						if ( ! skills.length ) {
							tbody.innerHTML = '<tr><td colspan="4"><em>' + escapeHtml( <?php echo wp_json_encode( __( 'No skills found in this catalogue.', 'mcp-ai-wpoos-pro' ) ); ?> ) + '</em></td></tr>';
							return;
						}
						tbody.innerHTML = '';
						skills.forEach( function ( sk ) {
							var tr = document.createElement( 'tr' );
							var statusLabel = sk.installed
								? ( sk.update_available
									? '<span class="skill-badge" style="background:#e7a900;color:#fff;">' + escapeHtml( <?php echo wp_json_encode( __( 'Update', 'mcp-ai-wpoos-pro' ) ); ?> ) + '</span>'
									: '<span class="skill-badge" style="background:#46b450;color:#fff;">' + escapeHtml( <?php echo wp_json_encode( __( 'Installed', 'mcp-ai-wpoos-pro' ) ); ?> ) + '</span>' )
								: '<span class="skill-badge">' + escapeHtml( <?php echo wp_json_encode( __( 'Available', 'mcp-ai-wpoos-pro' ) ); ?> ) + '</span>';
							var actionLabel = sk.installed && ! sk.update_available
								? <?php echo wp_json_encode( __( 'Reinstall', 'mcp-ai-wpoos-pro' ) ); ?>
								: ( sk.update_available
									? <?php echo wp_json_encode( __( 'Update', 'mcp-ai-wpoos-pro' ) ); ?>
									: <?php echo wp_json_encode( __( 'Install', 'mcp-ai-wpoos-pro' ) ); ?> );
							tr.innerHTML =
								'<td><strong>' + escapeHtml( sk.name ) + '</strong><br><code>' + escapeHtml( sk.path ) + '</code></td>' +
								'<td>' + escapeHtml( sk.description || '' ) + '</td>' +
								'<td>' + statusLabel + '</td>' +
								'<td><button type="button" class="button button-small wp-mcp-ai-skill-browse-install" data-path="' + escapeHtml( sk.path ) + '">' + escapeHtml( actionLabel ) + '</button></td>';
							tbody.appendChild( tr );
						} );
					} )
					.catch( function ( e ) {
						setStatus( ( e && e.message ) || 'Network error.', true );
					} );
			}

			tbody.addEventListener( 'click', function ( ev ) {
				var btn = ev.target.closest && ev.target.closest( '.wp-mcp-ai-skill-browse-install' );
				if ( ! btn ) { return; }
				var id = sel.value;
				var path = btn.getAttribute( 'data-path' );
				if ( ! id || ! path ) { return; }
				btn.disabled = true;
				var origText = btn.textContent;
				btn.textContent = <?php echo wp_json_encode( __( 'Installing…', 'mcp-ai-wpoos-pro' ) ); ?>;
				fetch( root + '/' + encodeURIComponent( id ) + '/install', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( { path: path } )
				} )
					.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, data: j }; } ); } )
					.then( function ( res ) {
						if ( ! res.ok ) {
							btn.disabled = false;
							btn.textContent = origText;
							setStatus( ( res.data && res.data.message ) || <?php echo wp_json_encode( __( 'Install failed.', 'mcp-ai-wpoos-pro' ) ); ?>, true );
							return;
						}
						btn.textContent = <?php echo wp_json_encode( __( 'Installed', 'mcp-ai-wpoos-pro' ) ); ?>;
						setStatus( <?php echo wp_json_encode( __( 'Skill installed successfully.', 'mcp-ai-wpoos-pro' ) ); ?>, false );
						// Reload the table to reflect installed state.
						loadSkills( false );
					} )
					.catch( function ( e ) {
						btn.disabled = false;
						btn.textContent = origText;
						setStatus( ( e && e.message ) || 'Network error.', true );
					} );
			} );

			sel.addEventListener( 'change', function () { loadSkills( false ); } );
			if ( btn ) {
				btn.addEventListener( 'click', function () { loadSkills( true ); } );
			}
			// Auto-load the first source on tab open.
			loadSkills( false );
		})();
		</script>
		<?php
	}
}
