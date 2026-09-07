<?php
/**
 * Skill Settings Admin Page (ecosystem port — Wave F1, sub-cluster 3b-3).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/admin/class-wp-mcp-ai-skill-settings-admin-page.php`
 * for the standalone `nvoos-content-graph-pro` addon. The global class name
 * is kept byte-identical; in monolith installs the base Pro addon owns the
 * class (this file is only loaded standalone).
 *
 * Provides a dedicated settings page for Agent Skills configuration.
 * Surfaces the global skill options and management tools for administrators.
 *
 * Documented deviations from the monolith copy:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. Text domain `mcp-ai-wpoos-pro` → `nvoos-content-graph-pro`.
 * 3. Per-mode seams: `registry_instance()` resolves the base plugin's
 *    `WP_MCP_AI_Skill_Registry` monolith / the Platform addon's
 *    `Skills\SkillRegistry` standalone; `menu_parent()` resolves the base
 *    assistant-CPT menu monolith / the Platform addon's NV Platform menu
 *    standalone (no dead menus).
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 * @see     https://agentskills.io/specification
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Skill Settings admin page handler.
 *
 * @since 1.10.0
 */
class WP_MCP_AI_Skill_Settings_Admin_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-skill-settings';

	/**
	 * Settings group.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'wp_mcp_ai_skill_settings_group';

	/**
	 * Option: maximum skills per assistant.
	 *
	 * @var string
	 */
	const OPT_MAX_SKILLS = 'wp_mcp_ai_skill_max_per_assistant';

	/**
	 * Option: whether to auto-inject skill instructions into the system prompt.
	 *
	 * @var string
	 */
	const OPT_AUTO_INJECT = 'wp_mcp_ai_skill_auto_inject';

	/**
	 * Option: skills enabled globally.
	 *
	 * @var string
	 */
	const OPT_SKILLS_ENABLED = 'wp_mcp_ai_skills_enabled';

	/**
	 * Constructor: register hooks.
	 *
	 * @since 1.10.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 35 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Initialize the page by instantiating the class and wiring hooks.
	 *
	 * @since 1.10.0
	 * @return self
	 */
	public static function init() {
		return new self();
	}

	/**
	 * Register the submenu page under the Assistants CPT menu.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	public function register_submenu_page() {
		add_submenu_page(
			$this->menu_parent(),
			__( 'Skill Settings', 'nvoos-content-graph-pro' ),
			__( 'Skill Settings', 'nvoos-content-graph-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Resolve the menu parent per install mode (documented deviation).
	 *
	 * The base plugin owns the assistant-CPT menu monolith; the Platform
	 * addon owns the NV Platform menu standalone.
	 *
	 * @return string
	 */
	protected function menu_parent() {
		return defined( 'WP_MCP_AI_PATH' ) ? 'edit.php?post_type=mcp_ai_assistant' : \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG;
	}

	/**
	 * Resolve the skill-registry instance per install mode (documented
	 * deviation — per-mode seam, prior-wave pattern).
	 *
	 * @return WP_MCP_AI_Skill_Registry|\NvoosContentGraphAiPlatform\Skills\SkillRegistry|null
	 */
	protected function registry_instance() {
		$class = defined( 'WP_MCP_AI_PATH' ) ? 'WP_MCP_AI_Skill_Registry' : \NvoosContentGraphAiPlatform\Skills\SkillRegistry::class;
		return class_exists( $class ) ? $class::instance() : null;
	}

	/**
	 * Register settings for the skill options.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_SKILLS_ENABLED,
			array( 'sanitize_callback' => 'absint' )
		);
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_AUTO_INJECT,
			array( 'sanitize_callback' => 'absint' )
		);
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_MAX_SKILLS,
			array( 'sanitize_callback' => 'absint' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-content-graph-pro' ) );
		}

		// Handle form submission.
		if ( isset( $_POST['wp_mcp_ai_skill_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_skill_settings_nonce'] ) ), 'wp_mcp_ai_skill_settings' ) ) {
			$this->save_settings();
		}

		// Handle catalogue source updates (separate nonce).
		if ( isset( $_POST['wp_mcp_ai_skill_catalogues_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_skill_catalogues_nonce'] ) ), 'wp_mcp_ai_skill_catalogues' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			if ( isset( $_POST['restore_defaults'] ) && '1' === $_POST['restore_defaults'] ) {
				$this->restore_default_catalogues();
			} else {
				$this->save_catalogues();
			}
		}

		// Get active tab.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation parameter; no state change.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';

		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-superhero" style="font-size: 32px;"></span>
				<?php esc_html_e( 'Skill Settings', 'nvoos-content-graph-pro' ); ?>
			</h1>

			<?php $this->render_tabs( $active_tab ); ?>

			<div class="skill-settings-content">
				<?php
				switch ( $active_tab ) {
					case 'overview':
						$this->render_overview_tab();
						break;
					case 'configuration':
						$this->render_configuration_tab();
						break;
					case 'installed':
						$this->render_installed_tab();
						break;
					case 'catalogues':
						$this->render_catalogues_tab();
						break;
					case 'help':
						$this->render_help_tab();
						break;
					default:
						$this->render_overview_tab();
				}
				?>
			</div>
		</div>

		<style>
			.skill-settings-nav {
				border-bottom: 1px solid #ccd0d4;
				margin: 20px 0;
			}
			.skill-settings-nav a {
				display: inline-block;
				padding: 10px 15px;
				text-decoration: none;
				border-bottom: 2px solid transparent;
				margin-bottom: -1px;
			}
			.skill-settings-nav a.nav-tab-active {
				border-bottom-color: #2271b1;
				font-weight: 600;
			}
			.skill-settings-content {
				margin-top: 20px;
			}
			.skill-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin-bottom: 20px;
			}
			.skill-card h2 {
				margin-top: 0;
			}
			.skill-item {
				padding: 10px;
				border-bottom: 1px solid #f0f0f1;
			}
			.skill-item:last-child {
				border-bottom: none;
			}
			.skill-item strong {
				display: inline-block;
				min-width: 200px;
			}
			.skill-dir-path {
				font-family: monospace;
				background: #f6f7f7;
				padding: 4px 8px;
				border-radius: 3px;
				border: 1px solid #ddd;
				word-break: break-all;
			}
		</style>
		<?php
	}

	/**
	 * Render tab navigation.
	 *
	 * @since 1.10.0
	 * @param string $active_tab Active tab slug.
	 * @return void
	 */
	protected function render_tabs( $active_tab ) {
		$tabs = array(
			'overview'      => __( 'Overview', 'nvoos-content-graph-pro' ),
			'configuration' => __( 'Configuration', 'nvoos-content-graph-pro' ),
			'installed'     => __( 'Installed Skills', 'nvoos-content-graph-pro' ),
			'catalogues'    => __( 'Catalogues', 'nvoos-content-graph-pro' ),
			'help'          => __( 'Help & Documentation', 'nvoos-content-graph-pro' ),
		);

		?>
		<nav class="skill-settings-nav nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG ) ) ); ?>"
					class="nav-tab <?php echo esc_attr( $active_tab === $tab_slug ? 'nav-tab-active' : '' ); ?>"
				>
					<?php echo esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render the overview tab.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	protected function render_overview_tab() {
		?>
		<div class="skill-card">
			<h2><?php esc_html_e( 'Agent Skills Overview', 'nvoos-content-graph-pro' ); ?></h2>

			<div class="skill-description">
				<p><?php esc_html_e( 'Agent Skills are reusable, version-controlled procedures that AI agents can invoke by name. Each skill is defined in a SKILL.md file following the agentskills.io specification and provides structured instructions for handling specific tasks.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'File-Based Storage: Skills are stored as SKILL.md files in the uploads directory', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Version Control: Each skill has a semantic version for reproducibility', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'YAML Frontmatter: Name, description, license, and metadata in structured format', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Markdown Instructions: Clear, readable procedures the AI agent follows', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Per-Assistant Assignment: Each assistant can be assigned a specific set of skills', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'ZIP Bundles: Full skill bundles with scripts/, references/, and assets/ support', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Use Cases', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Document Processing:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'PDF extraction, OCR, document summarisation', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Data Analysis:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Structured data parsing, spreadsheet workflows', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Web Research:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Search, scrape, summarise, and cite web content', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Content Generation:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Blog writing, SEO, social media, email', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Code Assistance:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Code review, refactoring, documentation generation', 'nvoos-content-graph-pro' ); ?></li>
			</ul>
		</div>

		<?php
		// Show skill directory info.
		$registry   = $this->registry_instance();
		$skills_dir = $registry ? $registry->get_skills_dir() : '';
		$skills     = $registry ? $registry->get_all_skills() : array();
		?>
		<div class="skill-card">
			<h2><?php esc_html_e( 'Skills Directory', 'nvoos-content-graph-pro' ); ?></h2>
			<p>
				<?php esc_html_e( 'Skills are stored in the following directory within your WordPress uploads folder:', 'nvoos-content-graph-pro' ); ?>
			</p>
			<p>
				<code class="skill-dir-path"><?php echo esc_html( $skills_dir ); ?></code>
			</p>
			<p>
				<?php
				printf(
					/* translators: %d: Number of installed skills */
					esc_html( _n( '%d skill currently installed.', '%d skills currently installed.', count( $skills ), 'nvoos-content-graph-pro' ) ),
					absint( count( $skills ) )
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager' ) ); ?>">
					<?php esc_html_e( 'Manage Skills →', 'nvoos-content-graph-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the configuration tab.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	protected function render_configuration_tab() {
		$skills_enabled = get_option( self::OPT_SKILLS_ENABLED, 1 );
		$auto_inject    = get_option( self::OPT_AUTO_INJECT, 1 );
		$max_skills     = get_option( self::OPT_MAX_SKILLS, 10 );

		?>
		<div class="skill-card">
			<h2><?php esc_html_e( 'Configuration', 'nvoos-content-graph-pro' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Configure global settings for Agent Skills. These settings apply to all assistants unless overridden at the individual skill assignment level.', 'nvoos-content-graph-pro' ); ?>
			</p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query param set after save redirect; no state change occurs here. ?>
			<?php if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) : ?>
				<div class="notice notice-success inline">
					<p><?php esc_html_e( 'Settings saved successfully.', 'nvoos-content-graph-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'wp_mcp_ai_skill_settings', 'wp_mcp_ai_skill_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_skills_enabled">
									<?php esc_html_e( 'Enable Skills', 'nvoos-content-graph-pro' ); ?>
								</label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="wp_mcp_ai_skills_enabled" id="wp_mcp_ai_skills_enabled" value="1" <?php checked( 1, (int) $skills_enabled ); ?>>
									<?php esc_html_e( 'Enable the Agent Skills system site-wide', 'nvoos-content-graph-pro' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When disabled, no skill instructions will be injected into assistant system prompts, even if skills are assigned to assistants.', 'nvoos-content-graph-pro' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_skill_auto_inject">
									<?php esc_html_e( 'Auto-Inject Instructions', 'nvoos-content-graph-pro' ); ?>
								</label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="wp_mcp_ai_skill_auto_inject" id="wp_mcp_ai_skill_auto_inject" value="1" <?php checked( 1, (int) $auto_inject ); ?>>
									<?php esc_html_e( 'Automatically inject skill instructions into the assistant system prompt', 'nvoos-content-graph-pro' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, the instructions from each assigned skill are appended to the assistant\'s system prompt so the AI is aware of available procedures. Recommended for most use cases.', 'nvoos-content-graph-pro' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_skill_max_per_assistant">
									<?php esc_html_e( 'Maximum Skills per Assistant', 'nvoos-content-graph-pro' ); ?>
								</label>
							</th>
							<td>
								<input type="number" name="wp_mcp_ai_skill_max_per_assistant" id="wp_mcp_ai_skill_max_per_assistant" value="<?php echo esc_attr( absint( $max_skills ) ); ?>" class="small-text" min="1" max="100" step="1">
								<p class="description">
									<?php esc_html_e( 'Maximum number of skills that can be assigned to a single assistant. Limit to keep system prompts concise.', 'nvoos-content-graph-pro' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'nvoos-content-graph-pro' ); ?>">
				</p>
			</form>
		</div>

		<div class="skill-card">
			<h2><?php esc_html_e( 'How Skills Are Applied', 'nvoos-content-graph-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Skills are applied in the following order:', 'nvoos-content-graph-pro' ); ?>
			</p>
			<ol>
				<li><strong><?php esc_html_e( 'Global Enable Check', 'nvoos-content-graph-pro' ); ?></strong> — <?php esc_html_e( 'Skills must be enabled globally (this page)', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Assistant Assignment', 'nvoos-content-graph-pro' ); ?></strong> — <?php esc_html_e( 'Skills are assigned per assistant via the Skills metabox on each assistant edit screen', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'System Prompt Injection', 'nvoos-content-graph-pro' ); ?></strong> — <?php esc_html_e( 'If Auto-Inject is on, skill instructions are appended to the system prompt at chat time', 'nvoos-content-graph-pro' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render the installed skills tab.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	protected function render_installed_tab() {
		$registry = $this->registry_instance();
		$skills   = $registry ? $registry->get_all_skills() : array();

		?>
		<div class="skill-card">
			<h2><?php esc_html_e( 'Installed Skills', 'nvoos-content-graph-pro' ); ?></h2>

			<?php if ( empty( $skills ) ) : ?>
				<p><?php esc_html_e( 'No skills are currently installed.', 'nvoos-content-graph-pro' ); ?></p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager&tab=install' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Upload & Install a Skill', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=research-skill' ) ); ?>" class="button">
						<?php esc_html_e( 'Research & Add a Skill', 'nvoos-content-graph-pro' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %d: Number of installed skills */
						esc_html( _n( '%d skill installed.', '%d skills installed.', count( $skills ), 'nvoos-content-graph-pro' ) ),
						absint( count( $skills ) )
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager' ) ); ?>" style="margin-left:10px;">
						<?php esc_html_e( 'Manage in Skill Manager →', 'nvoos-content-graph-pro' ); ?>
					</a>
				</p>

				<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
					<thead>
						<tr>
							<th scope="col" style="width: 200px;"><?php esc_html_e( 'Skill Name', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Description', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width: 80px;"><?php esc_html_e( 'Version', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width: 120px;"><?php esc_html_e( 'License', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width: 160px;"><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $skills as $skill ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $skill['name'] ); ?></strong>
									<?php if ( ! empty( $skill['compatibility'] ) ) : ?>
										<br><small style="color: #666;"><?php echo esc_html( $skill['compatibility'] ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $skill['description'] ); ?></td>
								<td><?php echo esc_html( ! empty( $skill['version'] ) ? $skill['version'] : '—' ); ?></td>
								<td><?php echo esc_html( ! empty( $skill['license'] ) ? $skill['license'] : '—' ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager&tab=editor&edit=' . rawurlencode( $skill['name'] ) ) ); ?>" class="button button-small" title="<?php esc_attr_e( 'Edit', 'nvoos-content-graph-pro' ); ?>">
										<span class="dashicons dashicons-edit" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></span>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the help and documentation tab.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	protected function render_help_tab() {
		?>
		<div class="skill-card">
			<h2><?php esc_html_e( 'Quick Start Guide', 'nvoos-content-graph-pro' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( 'Research a Skill:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Use the Research & Add page to explore skill ideas with AI assistance', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Build the SKILL.md:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Use the Builder tab in the Skill Manager to create or edit your SKILL.md file', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Install the Skill:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Upload and install the skill via the Upload & Install tab in the Skill Manager', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Assign to Assistant:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Open an assistant\'s edit screen and select the skill in the Skills metabox', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Test:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Chat with your assistant and invoke the skill by name to verify it works', 'nvoos-content-graph-pro' ); ?></li>
			</ol>
		</div>

		<div class="skill-card">
			<h2><?php esc_html_e( 'Support & Documentation', 'nvoos-content-graph-pro' ); ?></h2>
			<p><?php esc_html_e( 'For more information and detailed documentation:', 'nvoos-content-graph-pro' ); ?></p>
			<ul>
				<li><a href="https://agentskills.io/specification" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'agentskills.io Specification', 'nvoos-content-graph-pro' ); ?></a></li>
				<li><a href="https://developers.openai.com/cookbook/examples/skills_in_api" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'OpenAI Cookbook — Skills in API', 'nvoos-content-graph-pro' ); ?></a></li>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/tool-reference.md" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Tool Reference Documentation', 'nvoos-content-graph-pro' ); ?></a></li>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Report Issues or Request Features', 'nvoos-content-graph-pro' ); ?></a></li>
			</ul>
		</div>

		<div class="skill-card">
			<h2><?php esc_html_e( 'Quick Actions', 'nvoos-content-graph-pro' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager' ) ); ?>" class="button">
					<?php esc_html_e( 'Skill Manager', 'nvoos-content-graph-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=research-skill' ) ); ?>" class="button">
					<?php esc_html_e( 'Research & Add Skill', 'nvoos-content-graph-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager&tab=research-skill' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open Builder', 'nvoos-content-graph-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 *
	 * Note: Nonce verification is done in render_page() before calling this method.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	private function save_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		$skills_enabled = isset( $_POST['wp_mcp_ai_skills_enabled'] ) ? 1 : 0;
		update_option( self::OPT_SKILLS_ENABLED, $skills_enabled );

		$auto_inject = isset( $_POST['wp_mcp_ai_skill_auto_inject'] ) ? 1 : 0;
		update_option( self::OPT_AUTO_INJECT, $auto_inject );

		if ( isset( $_POST['wp_mcp_ai_skill_max_per_assistant'] ) ) {
			$max_skills = absint( wp_unslash( $_POST['wp_mcp_ai_skill_max_per_assistant'] ) );
			$max_skills = max( 1, min( 100, $max_skills ) ); // Clamp between 1 and 100.
			update_option( self::OPT_MAX_SKILLS, $max_skills );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=configuration' ) ) );
		exit;
	}

	/**
	 * Render the Catalogues tab — registered remote skill catalogue sources.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	protected function render_catalogues_tab() {
		if ( ! class_exists( 'WP_MCP_AI_Skill_Catalogue_Service' ) ) {
			?>
			<div class="skill-card">
				<p><?php esc_html_e( 'Skill catalogue service is not available.', 'nvoos-content-graph-pro' ); ?></p>
			</div>
			<?php
			return;
		}

		$service = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$sources = $service->get_sources();
		?>
		<div class="skill-card">
			<h2><?php esc_html_e( 'Skill Catalogues', 'nvoos-content-graph-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Register one or more remote catalogues of SKILL.md files. The Skill Manager Browse tab pulls its list from these sources, and a daily background refresh keeps "update available" badges accurate.', 'nvoos-content-graph-pro' ); ?>
			</p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag, no state change. ?>
			<?php if ( isset( $_GET['catalogues-updated'] ) && 'true' === $_GET['catalogues-updated'] ) : ?>
				<div class="notice notice-success inline">
					<p><?php esc_html_e( 'Catalogue sources saved.', 'nvoos-content-graph-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'wp_mcp_ai_skill_catalogues', 'wp_mcp_ai_skill_catalogues_nonce' ); ?>

				<table class="wp-list-table widefat fixed striped" id="wp-mcp-ai-skill-catalogues-table">
					<thead>
						<tr>
							<th scope="col" style="width:18%;"><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Label', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width:14%;"><?php esc_html_e( 'Owner', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width:14%;"><?php esc_html_e( 'Repo', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width:10%;"><?php esc_html_e( 'Ref', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width:14%;"><?php esc_html_e( 'Last refreshed', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width:5%;"></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Always render at least one empty row so admins can add a new entry.
						$rows   = $sources;
						$rows[] = array(
							'id'             => '',
							'label'          => '',
							'type'           => 'github',
							'owner'          => '',
							'repo'           => '',
							'ref'            => 'main',
							'manifest_path'  => '',
							'last_refreshed' => 0,
						);
						foreach ( $rows as $idx => $src ) :
							$last = ! empty( $src['last_refreshed'] ) ? human_time_diff( (int) $src['last_refreshed'], time() ) . ' ' . esc_html__( 'ago', 'nvoos-content-graph-pro' ) : esc_html__( 'never', 'nvoos-content-graph-pro' );
							?>
						<tr>
							<td>
								<input type="text" name="catalogues[<?php echo (int) $idx; ?>][id]" value="<?php echo esc_attr( $src['id'] ); ?>" class="regular-text" pattern="[a-z0-9][a-z0-9_-]*" placeholder="my-catalogue">
							</td>
							<td>
								<input type="text" name="catalogues[<?php echo (int) $idx; ?>][label]" value="<?php echo esc_attr( $src['label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Human-friendly label', 'nvoos-content-graph-pro' ); ?>">
							</td>
							<td>
								<input type="text" name="catalogues[<?php echo (int) $idx; ?>][owner]" value="<?php echo esc_attr( $src['owner'] ); ?>" class="regular-text" placeholder="github-org">
							</td>
							<td>
								<input type="text" name="catalogues[<?php echo (int) $idx; ?>][repo]" value="<?php echo esc_attr( $src['repo'] ); ?>" class="regular-text" placeholder="repo-name">
							</td>
							<td>
								<input type="text" name="catalogues[<?php echo (int) $idx; ?>][ref]" value="<?php echo esc_attr( $src['ref'] ); ?>" class="regular-text" placeholder="main">
							</td>
							<td><?php echo esc_html( $last ); ?></td>
							<td>
								<label>
									<input type="checkbox" name="catalogues[<?php echo (int) $idx; ?>][delete]" value="1">
									<?php esc_html_e( 'Remove', 'nvoos-content-graph-pro' ); ?>
								</label>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description" style="margin-top:10px;">
					<?php esc_html_e( 'Only public GitHub repositories are supported. Refs may be a branch, tag, or full commit SHA. Pin to a SHA for reproducibility.', 'nvoos-content-graph-pro' ); ?>
				</p>

				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save catalogues', 'nvoos-content-graph-pro' ); ?>">
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager&tab=browse' ) ); ?>" class="button">
						<?php esc_html_e( 'Browse skills →', 'nvoos-content-graph-pro' ); ?>
					</a>
					<button type="submit" name="restore_defaults" value="1" class="button" style="margin-left:10px;" onclick="return confirm('<?php echo esc_js( __( 'This will replace all current catalogue sources with the default set. Any custom sources you added will be lost. Continue?', 'nvoos-content-graph-pro' ) ); ?>');">
						<span class="dashicons dashicons-backup" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Restore Default Catalogues', 'nvoos-content-graph-pro' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Restore catalogue sources to the built-in defaults.
	 *
	 * Nonce verification is performed in render_page() before this method runs.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	private function restore_default_catalogues() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'WP_MCP_AI_Skill_Catalogue_Service' ) ) {
			return;
		}

		$defaults = WP_MCP_AI_Skill_Catalogue_Service::get_default_sources();
		WP_MCP_AI_Skill_Catalogue_Service::instance()->save_sources( $defaults );

		wp_safe_redirect( add_query_arg( 'catalogues-updated', 'true', admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=catalogues' ) ) );
		exit;
	}

	/**
	 * Persist catalogue source updates posted from the Catalogues tab.
	 *
	 * Nonce verification is performed in render_page() before this method runs.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	private function save_catalogues() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'WP_MCP_AI_Skill_Catalogue_Service' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		// phpcs:disable WordPressVIPMinimum.Security.ProperEscapingFunction.hrefSrcEscUrl, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Individual string values sanitised in the loop below.
		$raw = isset( $_POST['catalogues'] ) && is_array( $_POST['catalogues'] ) ? wp_unslash( $_POST['catalogues'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPressVIPMinimum.Security.ProperEscapingFunction.hrefSrcEscUrl, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$cleaned = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( ! empty( $entry['delete'] ) ) {
				continue;
			}
			// Skip rows that are completely empty (e.g. the trailing add-row left blank).
			$id    = isset( $entry['id'] ) ? sanitize_text_field( (string) $entry['id'] ) : '';
			$owner = isset( $entry['owner'] ) ? sanitize_text_field( (string) $entry['owner'] ) : '';
			$repo  = isset( $entry['repo'] ) ? sanitize_text_field( (string) $entry['repo'] ) : '';
			if ( '' === trim( $id ) && '' === trim( $owner ) && '' === trim( $repo ) ) {
				continue;
			}
			$cleaned[] = array(
				'id'    => $id,
				'label' => isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '',
				'type'  => 'github',
				'owner' => $owner,
				'repo'  => $repo,
				'ref'   => isset( $entry['ref'] ) ? sanitize_text_field( (string) $entry['ref'] ) : 'main',
			);
		}

		WP_MCP_AI_Skill_Catalogue_Service::instance()->save_sources( $cleaned );

		wp_safe_redirect( add_query_arg( 'catalogues-updated', 'true', admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG . '&tab=catalogues' ) ) );
		exit;
	}
}
