<?php
/**
 * Build Assistant admin page for the Content Graph AI addon (Wave D-UI-4).
 *
 * Aligned port of the base plugin's Build Assistant page
 * (`includes/admin/class-wp-mcp-ai-build-assistant-page.php`): the same
 * four-tab structure (Manual / Prompt / Configuration / Advanced), the
 * same form vocabulary (title, professions, regions, industry focus,
 * knowledge files, provider, model, temperature, background create),
 * the same profession/region option lists, the same configuration and
 * advanced card links, and the same assistant statistics. Class names
 * use the ecosystem's `nvoos-cg-*` prefix and the page slug is
 * `nvoos-cg-build-assistant` so monolith installs can run both pages
 * side by side without collisions (documented deviation).
 *
 * Documented deviations (additive decoupling):
 * - The Prompt tab's Tools Grid + Knowledge Base block components are
 *   deferred until the ecosystem block set grows counterparts; the tab
 *   keeps the builder-assistant chat modal surface.
 * - The background-create flag is accepted but answers `async_unavailable`
 *   until the queue wave (E2) ports the async creator.
 * - `create_from_form()` builds a simple structured system prompt from
 *   the chosen professions/regions/industry (the base's full prompt
 *   construction stays with the base until the profession runtime
 *   ports); meta keys written are byte-identical.
 *
 * @package NvoosContentGraphAi\Admin\AssistantPages
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\AssistantPages;

use NvoosContentGraphAi\Admin\AssistantPostType;
use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Build Assistant page in admin.
 *
 * @since 1.1.0
 */
class BuildAssistantPage {

	/**
	 * Page slug (ecosystem-specific; never collides with the base).
	 */
	const PAGE_SLUG = 'nvoos-cg-build-assistant';

	/**
	 * AJAX action for the manual create form (ecosystem-specific).
	 */
	const CREATE_ACTION = 'nvoos_cg_ai_create_assistant';

	/**
	 * Page hook suffix.
	 *
	 * @var string|false
	 */
	protected $page_hook;

	/**
	 * Register the admin page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . AssistantPostType::POST_TYPE,
			__( 'Build Assistant', 'nvoos-content-graph-ai' ),
			__( 'Build Assistant', 'nvoos-content-graph-ai' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts and styles for this page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ): void {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'nvoos-cg-build-assistant',
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/css/admin-build-assistant.css',
			array(),
			NVOOS_CONTENT_GRAPH_AI_VERSION
		);

		wp_enqueue_script(
			'nvoos-cg-build-assistant-js',
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/admin-build-assistant.js',
			array(),
			NVOOS_CONTENT_GRAPH_AI_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-cg-build-assistant-js',
			'nvoosCgCreateAssistant',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => self::CREATE_ACTION,
				'nonce'    => wp_create_nonce( self::CREATE_ACTION ),
				'redirect' => admin_url( 'edit.php?post_type=' . AssistantPostType::POST_TYPE ),
				'strings'  => array(
					'creating' => __( 'Creating assistant...', 'nvoos-content-graph-ai' ),
					'success'  => __( 'Assistant created successfully!', 'nvoos-content-graph-ai' ),
					'error'    => __( 'Error creating assistant. Please try again.', 'nvoos-content-graph-ai' ),
					'required' => __( 'This field is required.', 'nvoos-content-graph-ai' ),
				),
				'professions' => $this->get_professions(),
				'regions'     => $this->get_regions(),
			)
		);
	}

	/**
	 * Get the currently active tab.
	 *
	 * @return string Active tab ID.
	 */
	public function get_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'manual';

		$valid_tabs = array( 'manual', 'prompt', 'configuration', 'advanced' );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'manual';
		}

		return $tab;
	}

	/**
	 * Get tab definitions.
	 *
	 * @return array
	 */
	public function get_tabs(): array {
		return array(
			'manual'        => array(
				'title' => __( 'Manual', 'nvoos-content-graph-ai' ),
				'icon'  => 'dashicons-edit',
			),
			'prompt'        => array(
				'title' => __( 'Prompt', 'nvoos-content-graph-ai' ),
				'icon'  => 'dashicons-format-chat',
			),
			'configuration' => array(
				'title' => __( 'Configuration', 'nvoos-content-graph-ai' ),
				'icon'  => 'dashicons-admin-settings',
			),
			'advanced'      => array(
				'title' => __( 'Advanced', 'nvoos-content-graph-01' === 'x' ? 'nvoos-content-graph-ai' : 'nvoos-content-graph-ai' ),
				'icon'  => 'dashicons-admin-generic',
			),
		);
	}

	/**
	 * Render the page content.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$active_tab = $this->get_active_tab();
		$tabs       = $this->get_tabs();

		?>
		<div class="wrap nvoos-cg-build-assistant-page">
			<h1><?php esc_html_e( 'Build Assistant', 'nvoos-content-graph-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure and build custom AI assistants with advanced settings and options.', 'nvoos-content-graph-ai' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Build Assistant tabs', 'nvoos-content-graph-ai' ); ?>">
				<?php foreach ( $tabs as $tab_id => $tab ) : ?>
					<?php
					$tab_url = add_query_arg(
						array(
							'post_type' => AssistantPostType::POST_TYPE,
							'page'      => self::PAGE_SLUG,
							'tab'       => $tab_id,
						),
						admin_url( 'edit.php' )
					);
					$active  = ( $tab_id === $active_tab ) ? 'nav-tab-active' : '';
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo esc_attr( $active ); ?>">
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
						<?php echo esc_html( $tab['title'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tab-content">
				<?php
				if ( 'manual' === $active_tab ) {
					$this->render_manual_tab();
				} elseif ( 'prompt' === $active_tab ) {
					$this->render_prompt_tab();
				} elseif ( 'configuration' === $active_tab ) {
					$this->render_configuration_tab();
				} elseif ( 'advanced' === $active_tab ) {
					$this->render_advanced_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Manual tab content.
	 *
	 * @return void
	 */
	public function render_manual_tab(): void {
		$providers = $this->get_available_providers();
		$first     = true;
		?>
		<div class="nvoos-cg-tab-content nvoos-cg-manual-tab">
			<div class="nvoos-cg-section">
				<h2><?php esc_html_e( 'Create Assistant Manually', 'nvoos-content-graph-ai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Fill in the form below to create a new AI assistant with custom settings.', 'nvoos-content-graph-01' === 'x' ? 'nvoos-content-graph-ai' : 'nvoos-content-graph-ai' ); ?></p>

				<form id="nvoos-cg-create-assistant-form" class="nvoos-cg-assistant-form">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="assistant-title">
										<?php esc_html_e( 'Assistant Title', 'nvoos-content-graph-ai' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-title" name="title" class="regular-text" required>
									<p class="description">
										<?php esc_html_e( 'E.g., "Jamaica Tax Assistant", "Sri Lanka Customs Broker - Perfumes"', 'nvoos-content-graph-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-professions">
										<?php esc_html_e( 'Professions', 'nvoos-content-graph-ai' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<select id="assistant-professions" name="professions[]" multiple class="regular-text" required style="height: 150px;">
										<?php foreach ( $this->get_professions() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Select up to 3 professions. Hold Ctrl/Cmd to select multiple.', 'nvoos-content-graph-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-regions">
										<?php esc_html_e( 'Regions', 'nvoos-content-graph-ai' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<select id="assistant-regions" name="regions[]" multiple class="regular-text" required style="height: 150px;">
										<?php foreach ( $this->get_regions() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Select up to 2 regions. Hold Ctrl/Cmd to select multiple.', 'nvoos-content-graph-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-industry">
										<?php esc_html_e( 'Industry Focus', 'nvoos-content-graph-ai' ); ?>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-industry" name="industry_focus" class="regular-text">
									<p class="description">
										<?php esc_html_e( 'Optional: E.g., "perfumes", "technology", "restaurants"', 'nvoos-content-graph-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-attachments">
										<?php esc_html_e( 'Knowledge Files', 'nvoos-content-graph-ai' ); ?>
									</label>
								</th>
								<td>
									<input type="file" id="assistant-attachments" name="attachments[]" multiple accept=".txt,.md,.pdf,.doc,.docx">
									<p class="description">
										<?php esc_html_e( 'Optional: Upload files to include in the assistant\'s knowledge base (.txt, .md, .pdf, .doc, .docx)', 'nvoos-content-graph-ai' ); ?>
									</p>
									<ul id="assistant-attachments-list" class="nvoos-cg-attachments-list"></ul>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-provider">
										<?php esc_html_e( 'AI Provider', 'nvoos-content-graph-ai' ); ?>
									</label>
								</th>
								<td>
									<select id="assistant-provider" name="provider" class="regular-text">
										<?php foreach ( $providers as $provider_slug => $provider_label ) : ?>
											<option value="<?php echo esc_attr( $provider_slug ); ?>"<?php echo $first ? ' selected' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML attribute. ?>><?php echo esc_html( $provider_label ); ?></option>
											<?php
											$first = false;
										endforeach;
										?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-model">
										<?php esc_html_e( 'Model', 'nvoos-content-graph-ai' ); ?>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-model" name="model" class="regular-text" value="gpt-4">
									<p class="description">
										<?php esc_html_e( 'E.g., "gpt-4", "gpt-4-turbo", "gemini-pro"', 'nvoos-content-graph-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-temperature">
										<?php esc_html_e( 'Temperature', 'nvoos-content-graph-ai' ); ?>
									</label>
								</th>
								<td>
									<input type="number" id="assistant-temperature" name="temperature" class="small-text" min="0" max="2" step="0.1" value="0.7">
									<p class="description">
										<?php esc_html_e( '0-2. Lower is more deterministic, higher is more creative.', 'nvoos-content-graph-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-async">
										<input type="checkbox" id="assistant-async" name="async" value="1">
										<?php esc_html_e( 'Create in Background', 'nvoos-content-graph-ai' ); ?>
									</label>
								</th>
								<td>
									<p class="description">
										<?php esc_html_e( 'For complex assistants, create asynchronously via cron. You will be notified when complete.', 'nvoos-content-graph-ai' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary" id="nvoos-cg-submit-create">
							<?php esc_html_e( 'Create Assistant', 'nvoos-content-graph-ai' ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Prompt tab content.
	 *
	 * @return void
	 */
	public function render_prompt_tab(): void {
		$builder_assistant_id = $this->get_builder_assistant_id();
		?>
		<div class="nvoos-cg-tab-content nvoos-cg-prompt-tab">
			<div class="nvoos-cg-section">
				<h2><?php esc_html_e( 'Build with AI Prompt', 'nvoos-content-graph-ai' ); ?></h2>
				<div class="nvoos-cg-prompt-intro">
					<strong><?php esc_html_e( 'Describe your assistant', 'nvoos-content-graph-ai' ); ?></strong>
					<p><?php esc_html_e( 'Tell the AI what kind of assistant you want to create. Describe its purpose, expertise, target audience, and any specific capabilities. When ready, click the "Build" button to create your assistant.', 'nvoos-content-graph-ai' ); ?></p>
				</div>

				<div class="nvoos-cg-prompt-action">
					<?php if ( $builder_assistant_id ) : ?>
						<button
							type="button"
							class="button button-primary button-hero nvoos-cg-build-with-ai-btn"
							data-assistant-id="<?php echo esc_attr( (string) $builder_assistant_id ); ?>"
							data-assistant-title="<?php esc_attr_e( 'AI Assistant Builder', 'nvoos-content-graph-ai' ); ?>"
						>
							<span class="dashicons dashicons-format-chat"></span>
							<?php esc_html_e( 'Build with AI', 'nvoos-content-graph-ai' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Click to open the AI chat interface and describe your assistant.', 'nvoos-content-graph-ai' ); ?></p>
					<?php else : ?>
						<div class="nvoos-cg-no-builder">
							<p><?php esc_html_e( 'The Assistant Builder is not configured. Please create an assistant with the slug "assistant-builder" or set one in the plugin settings.', 'nvoos-content-graph-ai' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Modal container for Build with AI chat interface -->
		<div id="nvoos-cg-build-assistant-modal" class="nvoos-cg-test-modal" style="display: none;">
			<div class="nvoos-cg-test-modal__backdrop"></div>
			<div class="nvoos-cg-test-modal__panel">
				<div class="nvoos-cg-test-modal__header">
					<h2 id="nvoos-cg-build-assistant-modal__title"><?php esc_html_e( 'Build with AI', 'nvoos-content-graph-ai' ); ?></h2>
					<button type="button" class="nvoos-cg-test-modal__close" aria-label="<?php esc_attr_e( 'Close', 'nvoos-content-graph-ai' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="nvoos-cg-test-modal__body">
					<!-- Chat interface will be initialized here -->
					<div id="nvoos-cg-build-assistant-chat-container"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the builder assistant ID for the Prompt tab.
	 *
	 * Looks for an assistant with the slug "assistant-builder" or uses a
	 * configured default.
	 *
	 * @return int Builder assistant ID or 0 if not found.
	 */
	public function get_builder_assistant_id(): int {
		$builder_assistant = get_page_by_path( 'assistant-builder', OBJECT, AssistantPostType::POST_TYPE );

		if ( $builder_assistant && 'publish' === $builder_assistant->post_status ) {
			return (int) $builder_assistant->ID;
		}

		$settings = $this->get_settings();
		if ( ! empty( $settings['builder_assistant'] ) ) {
			$builder_id = absint( $settings['builder_assistant'] );
			$post       = get_post( $builder_id );
			if ( $post && AssistantPostType::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
				return $builder_id;
			}
		}

		if ( ! empty( $settings['default_assistant'] ) ) {
			return absint( $settings['default_assistant'] );
		}

		return 0;
	}

	/**
	 * Get profession options.
	 *
	 * Monolith installs read the base profession CPT system; standalone
	 * falls back to the same hardcoded list the base ships.
	 *
	 * @return array Profession key => label pairs.
	 */
	public function get_professions(): array {
		if ( defined( 'WP_MCP_AI_PATH' ) && function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$professions = \wp_mcp_ai_get_profession_service()->get_professions_for_dropdown();
			if ( ! empty( $professions ) && is_array( $professions ) ) {
				return $professions;
			}
		}

		return array(
			'tax_advisor'              => __( 'Tax Advisor', 'nvoos-content-graph-ai' ),
			'accountant'               => __( 'Accountant', 'nvoos-content-graph-ai' ),
			'bookkeeper'               => __( 'Bookkeeper', 'nvoos-content-graph-ai' ),
			'lawyer'                   => __( 'Lawyer', 'nvoos-content-graph-ai' ),
			'legal_advisor'            => __( 'Legal Advisor', 'nvoos-content-graph-ai' ),
			'customs_broker'           => __( 'Customs Broker', 'nvoos-content-graph-ai' ),
			'import_export_specialist' => __( 'Import/Export Specialist', 'nvoos-content-graph-ai' ),
			'financial_advisor'        => __( 'Financial Advisor', 'nvoos-content-graph-ai' ),
			'business_consultant'      => __( 'Business Consultant', 'nvoos-content-graph-ai' ),
			'real_estate_agent'        => __( 'Real Estate Agent', 'nvoos-content-graph-ai' ),
			'healthcare_advisor'       => __( 'Healthcare Advisor', 'nvoos-content-graph-ai' ),
			'marketing_consultant'     => __( 'Marketing Consultant', 'nvoos-content-graph-ai' ),
			'hr_consultant'            => __( 'HR Consultant', 'nvoos-content-graph-ai' ),
			'it_consultant'            => __( 'IT Consultant', 'nvoos-content-graph-ai' ),
			'restaurant_consultant'    => __( 'Restaurant Consultant', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Get region options (byte-identical to the base list).
	 *
	 * @return array Region key => label pairs.
	 */
	public function get_regions(): array {
		return array(
			'united_states'        => __( 'United States', 'nvoos-content-graph-ai' ),
			'canada'               => __( 'Canada', 'nvoos-content-graph-ai' ),
			'united_kingdom'       => __( 'United Kingdom', 'nvoos-content-graph-ai' ),
			'australia'            => __( 'Australia', 'nvoos-content-graph-ai' ),
			'jamaica'              => __( 'Jamaica', 'nvoos-content-graph-ai' ),
			'sri_lanka'            => __( 'Sri Lanka', 'nvoos-content-graph-ai' ),
			'india'                => __( 'India', 'nvoos-content-graph-ai' ),
			'singapore'            => __( 'Singapore', 'nvoos-content-graph-ai' ),
			'united_arab_emirates' => __( 'United Arab Emirates', 'nvoos-content-graph-ai' ),
			'germany'              => __( 'Germany', 'nvoos-content-graph-ai' ),
			'france'               => __( 'France', 'nvoos-content-graph-ai' ),
			'spain'                => __( 'Spain', 'nvoos-content-graph-ai' ),
			'italy'                => __( 'Italy', 'nvoos-content-graph-ai' ),
			'netherlands'          => __( 'Netherlands', 'nvoos-content-graph-ai' ),
			'brazil'               => __( 'Brazil', 'nvoos-content-graph-ai' ),
			'mexico'               => __( 'Mexico', 'nvoos-content-graph-ai' ),
			'south_africa'         => __( 'South Africa', 'nvoos-content-graph-ai' ),
			'new_zealand'          => __( 'New Zealand', 'nvoos-content-graph-ai' ),
			'ireland'              => __( 'Ireland', 'nvoos-content-graph-ai' ),
			'japan'                => __( 'Japan', 'nvoos-content-graph-ai' ),
			'china'                => __( 'China', 'nvoos-content-graph-ai' ),
			'global'               => __( 'Global', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Render the Configuration tab content.
	 *
	 * @return void
	 */
	public function render_configuration_tab(): void {
		?>
		<div class="nvoos-cg-tab-content nvoos-cg-configuration-tab">
			<div class="nvoos-cg-section">
				<h2><?php esc_html_e( 'Assistant Configuration', 'nvoos-content-graph-ai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure the basic settings for your AI assistant.', 'nvoos-content-graph-ai' ); ?></p>

				<div class="nvoos-cg-config-grid">
					<div class="nvoos-cg-config-card">
						<span class="dashicons dashicons-format-chat"></span>
						<h3><?php esc_html_e( 'Create from Template', 'nvoos-content-graph-ai' ); ?></h3>
						<p><?php esc_html_e( 'Create a new assistant using a professional template with pre-configured settings.', 'nvoos-content-graph-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . AssistantPostType::POST_TYPE . '&page=' . AddAssistantPage::PAGE_SLUG ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Use Template', 'nvoos-content-graph-ai' ); ?>
						</a>
					</div>

					<div class="nvoos-cg-config-card">
						<span class="dashicons dashicons-plus-alt"></span>
						<h3><?php esc_html_e( 'Create Custom', 'nvoos-content-graph-ai' ); ?></h3>
						<p><?php esc_html_e( 'Create a new custom assistant from scratch with your own configuration.', 'nvoos-content-graph-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . AssistantPostType::POST_TYPE ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Add New', 'nvoos-content-graph-ai' ); ?>
						</a>
					</div>

					<div class="nvoos-cg-config-card">
						<span class="dashicons dashicons-list-view"></span>
						<h3><?php esc_html_e( 'Manage Assistants', 'nvoos-content-graph-ai' ); ?></h3>
						<p><?php esc_html_e( 'View and manage all existing AI assistants in your system.', 'nvoos-content-graph-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . AssistantPostType::POST_TYPE ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'View All', 'nvoos-content-graph-ai' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="nvoos-cg-section">
				<h2><?php esc_html_e( 'Quick Statistics', 'nvoos-content-graph-ai' ); ?></h2>
				<?php $this->render_assistant_stats(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Advanced tab content.
	 *
	 * @return void
	 */
	public function render_advanced_tab(): void {
		$settings_url = admin_url( 'options-general.php' );
		?>
		<div class="nvoos-cg-tab-content nvoos-cg-advanced-tab">
			<div class="nvoos-cg-section">
				<h2><?php esc_html_e( 'Advanced Settings', 'nvoos-content-graph-ai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Advanced configuration options for power users.', 'nvoos-content-graph-ai' ); ?></p>

				<div class="nvoos-cg-config-grid">
					<div class="nvoos-cg-config-card">
						<span class="dashicons dashicons-admin-users"></span>
						<h3><?php esc_html_e( 'Professional Templates', 'nvoos-content-graph-ai' ); ?></h3>
						<p><?php esc_html_e( 'Manage professional templates that define roles, tools, and knowledge bases for assistants.', 'nvoos-content-graph-01' === 'x' ? 'nvoos-content-graph-ai' : 'nvoos-content-graph-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Templates', 'nvoos-content-graph-ai' ); ?>
						</a>
					</div>

					<div class="nvoos-cg-config-card">
						<span class="dashicons dashicons-groups"></span>
						<h3><?php esc_html_e( 'Teams', 'nvoos-content-graph-ai' ); ?></h3>
						<p><?php esc_html_e( 'Create teams of assistants that can work together on complex tasks.', 'nvoos-content-graph-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_team' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Teams', 'nvoos-content-graph-ai' ); ?>
						</a>
					</div>

					<div class="nvoos-cg-config-card">
						<span class="dashicons dashicons-admin-tools"></span>
						<h3><?php esc_html_e( 'Tools & Features', 'nvoos-content-graph-ai' ); ?></h3>
						<p><?php esc_html_e( 'Configure available tools and features that assistants can use.', 'nvoos-content-graph-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-content-graph' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Tools', 'nvoos-content-graph-ai' ); ?>
						</a>
					</div>

					<div class="nvoos-cg-config-card">
						<span class="dashicons dashicons-admin-generic"></span>
						<h3><?php esc_html_e( 'AI Providers', 'nvoos-content-graph-ai' ); ?></h3>
						<p><?php esc_html_e( 'Configure API keys and settings for AI providers (OpenAI, Anthropic, Gemini, etc.).', 'nvoos-content-graph-ai' ); ?></p>
						<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Providers', 'nvoos-content-graph-ai' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render assistant statistics.
	 *
	 * @return void
	 */
	public function render_assistant_stats(): void {
		$assistants_count = wp_count_posts( AssistantPostType::POST_TYPE );

		$published_assistants = isset( $assistants_count->publish ) ? $assistants_count->publish : 0;
		?>
		<div class="nvoos-cg-stats-grid">
			<div class="nvoos-cg-stat-card">
				<span class="nvoos-cg-stat-number"><?php echo esc_html( (string) $published_assistants ); ?></span>
				<span class="nvoos-cg-stat-label"><?php esc_html_e( 'Active Assistants', 'nvoos-content-graph-ai' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the available AI providers (per-install-mode seam).
	 *
	 * @return array Provider slug => label pairs.
	 */
	public function get_available_providers(): array {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$providers = \WP_MCP_AI_Admin_Settings::get_available_providers();
			return is_array( $providers ) ? $providers : array();
		}

		return array(
			'openai'       => 'OpenAI',
			'anthropic'    => 'Anthropic',
			'gemini'       => 'Google Gemini',
			'ollama'       => 'Ollama (Local)',
			'deepseek'     => 'DeepSeek',
			'openrouter'   => 'OpenRouter',
			'huggingface'  => 'Hugging Face',
			'cloudflare'   => 'Cloudflare Workers AI',
			'lm_studio'    => 'LM Studio',
			'nvidia'       => 'NVIDIA NIM',
			'digitalocean' => 'DigitalOcean GenAI',
			'kimi'         => 'Kimi (Moonshot)',
			'baseten'      => 'Baseten',
			'zai'          => 'Zhipu AI (Z.ai)',
		);
	}

	/**
	 * Read the active settings map (per-install-mode seam).
	 *
	 * @return array
	 */
	protected function get_settings(): array {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			return is_array( $settings ) ? $settings : array();
		}

		$all = CoreBridge::instance()->settings->all();
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Handle the AJAX create request.
	 *
	 * @return void
	 */
	public function handle_ajax_create(): void {
		if ( ! check_ajax_referer( self::CREATE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'nvoos-content-graph-ai' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai' ) ), 403 );
		}

		$input = array(
			'title'        => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'professions'  => isset( $_POST['professions'] ) ? (array) wp_unslash( $_POST['professions'] ) : array(),
			'regions'      => isset( $_POST['regions'] ) ? (array) wp_unslash( $_POST['regions'] ) : array(),
			'industry'     => isset( $_POST['industry_focus'] ) ? sanitize_text_field( wp_unslash( $_POST['industry_focus'] ) ) : '',
			'provider'     => isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '',
			'model'        => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '',
			'temperature'  => isset( $_POST['temperature'] ) ? (string) sanitize_text_field( wp_unslash( $_POST['temperature'] ) ) : '',
			'async'        => ! empty( wp_unslash( $_POST['async'] ) ),
			'memory_files' => isset( $_POST['memory_files'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['memory_files'] ) ) : array(),
		);

		$result = self::create_from_form( $input );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Create an assistant from the manual form input.
	 *
	 * @param array $input Sanitized form input.
	 * @return array|WP_Error Assistant payload or error.
	 */
	public static function create_from_form( array $input ) {
		$title = isset( $input['title'] ) ? trim( sanitize_text_field( $input['title'] ) ) : '';
		if ( '' === $title ) {
			return new \WP_Error( 'wp_mcp_ai_missing_title', __( 'Assistant title is required.', 'nvoos-content-graph-ai' ) );
		}

		$professions = array();
		foreach ( (array) ( isset( $input['professions'] ) ? $input['professions'] : array() ) as $profession ) {
			$profession = sanitize_key( (string) $profession );
			if ( '' !== $profession ) {
				$professions[] = $profession;
			}
		}
		$professions = array_slice( array_values( array_unique( $professions ) ), 0, 3 );

		$regions = array();
		foreach ( (array) ( isset( $input['regions'] ) ? $input['regions'] : array() ) as $region ) {
			$region = sanitize_key( (string) $region );
			if ( '' !== $region ) {
				$regions[] = $region;
			}
		}
		$regions = array_slice( array_values( array_unique( $regions ) ), 0, 2 );

		$industry = isset( $input['industry'] ) ? sanitize_text_field( $input['industry'] ) : '';

		$provider = isset( $input['provider'] ) ? sanitize_key( $input['provider'] ) : 'openai';
		$allowed  = apply_filters(
			'wp_mcp_ai_allowed_providers',
			array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'ollama', 'lm_studio', 'cloudflare', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'embedded', 'zai' )
		);
		if ( ! is_array( $allowed ) ) {
			$allowed = array();
		}
		if ( ! in_array( $provider, $allowed, true ) ) {
			return new \WP_Error( 'wp_mcp_ai_invalid_provider', __( 'Unknown AI provider.', 'nvoos-content-graph-ai' ) );
		}

		$model = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : '';

		$temperature = isset( $input['temperature'] ) && '' !== (string) $input['temperature']
			? max( 0.0, min( 2.0, floatval( $input['temperature'] ) ) )
			: 0.7;

		if ( ! empty( $input['async'] ) ) {
			// The base queues complex creations via cron; the async queue
			// ports with Wave E2. Until then the flag is answered honestly.
			return new \WP_Error( 'wp_mcp_ai_async_unavailable', __( 'Background creation is not available in the ecosystem plugin yet.', 'nvoos-content-graph-ai' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => AssistantPostType::POST_TYPE,
				'post_title'   => $title,
				'post_content' => '',
				'post_status'  => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, AssistantPostType::META_PROVIDER, $provider );
		update_post_meta( $post_id, AssistantPostType::META_MODEL, $model );
		update_post_meta( $post_id, AssistantPostType::META_TEMPERATURE, $temperature );
		update_post_meta( $post_id, AssistantPostType::META_SYSTEM_PROMPT, self::build_system_prompt( $professions, $regions, $industry ) );
		update_post_meta( $post_id, AssistantPostType::META_TOOLS, array() );

		$memory_files = array_filter( array_map( 'absint', (array) ( isset( $input['memory_files'] ) ? $input['memory_files'] : array() ) ) );
		if ( ! empty( $memory_files ) ) {
			update_post_meta( $post_id, AssistantPostType::META_MEMORY_FILES, array_values( $memory_files ) );
		}

		return array(
			'assistant_id' => $post_id,
			'edit_url'     => get_edit_post_link( $post_id, 'raw' ),
			'message'      => __( 'Assistant created successfully!', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Build the structured system prompt from the form selections.
	 *
	 * @param array  $professions Selected profession keys.
	 * @param array  $regions     Selected region keys.
	 * @param string $industry    Industry focus string.
	 * @return string
	 */
	protected static function build_system_prompt( array $professions, array $regions, string $industry ): string {
		$page        = new self();
		$all_profs   = $page->get_professions();
		$all_regions = $page->get_regions();

		$prof_labels = array();
		foreach ( $professions as $key ) {
			if ( isset( $all_profs[ $key ] ) ) {
				$prof_labels[] = $all_profs[ $key ];
			}
		}

		$region_labels = array();
		foreach ( $regions as $key ) {
			if ( isset( $all_regions[ $key ] ) ) {
				$region_labels[] = $all_regions[ $key ];
			}
		}

		$lines   = array();
		$lines[] = __( 'You are a professional AI assistant.', 'nvoos-content-graph-ai' );

		if ( ! empty( $prof_labels ) ) {
			/* translators: %s: comma-separated profession list */
			$lines[] = sprintf( __( 'Areas of expertise: %s.', 'nvoos-content-graph-ai' ), implode( ', ', $prof_labels ) );
		}

		if ( ! empty( $region_labels ) ) {
			/* translators: %s: comma-separated region list */
			$lines[] = sprintf( __( 'Focus regions: %s.', 'nvoos-content-graph-ai' ), implode( ', ', $region_labels ) );
		}

		if ( '' !== $industry ) {
			/* translators: %s: industry focus */
			$lines[] = sprintf( __( 'Industry focus: %s.', 'nvoos-content-graph-ai' ), $industry );
		}

		$lines[] = __( 'Answer accurately and concisely, and say plainly when you do not know something.', 'nvoos-content-graph-ai' );

		return implode( ' ', $lines );
	}
}
