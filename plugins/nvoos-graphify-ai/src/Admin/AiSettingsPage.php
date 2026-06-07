<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Admin;

use NvoosGraphify\Admin\SettingsRegistry;
use NvoosGraphify\Schema;

/**
 * Standalone admin page: NV oOS AI
 *
 * Registers a top-level menu for AI provider configuration, chat settings,
 * and future admin chat testing. Uses the same Section/Registry pattern
 * as the core Knowledge Graph page.
 *
 * @since 1.0.0
 */
final class AiSettingsPage {

	public const PAGE_SLUG = 'nvoos-graphify-ai';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenuPage' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}

	/**
	 * Add the standalone "NV oOS AI" top-level menu page.
	 *
	 * @return void
	 */
	public function addMenuPage(): void {
		add_menu_page(
			__( 'NVoOS AI', 'nvoos-graphify-ai' ),
			__( 'NVoOS AI', 'nvoos-graphify-ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'renderPage' ),
			'dashicons-superhero',
			86
		);
	}

	/**
	 * Register the settings option group and sanitisation callback.
	 *
	 * @return void
	 */
	public function registerSettings(): void {
		register_setting(
			'nvoos_graphify_ai_settings_group',
			Schema::OPTION_SETTINGS,
			array( 'sanitize_callback' => array( $this, 'sanitizeSettings' ) )
		);
	}

	/**
	 * Sanitize incoming settings, merging the submitted tab's fields
	 * with the existing stored option so other tabs are not wiped.
	 *
	 * @param mixed $raw Submitted form data.
	 * @return array<string,mixed> Sanitized settings merged with stored values.
	 */
	public function sanitizeSettings( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$existing = \NvoosGraphify\Settings::all();

		// Detect the tab from the referer so we only sanitize fields
		// belonging to the submitted tab.
		$referer = isset( $_REQUEST['_wp_http_referer'] )
			? esc_url_raw( wp_unslash( $_REQUEST['_wp_http_referer'] ) )
			: '';

		$tab = 'ai_providers';
		if ( is_string( $referer ) && '' !== $referer ) {
			$query = wp_parse_url( $referer, PHP_URL_QUERY );
			if ( is_string( $query ) && '' !== $query ) {
				$args = array();
				wp_parse_str( $query, $args );
				$tab = isset( $args['tab'] ) ? sanitize_key( $args['tab'] ) : 'ai_providers';
			}
		}

		$merged = $existing;

		$sections = SettingsRegistry::get_sections( $tab );
		foreach ( $sections as $section ) {
			$sanitized = $section->sanitize( $raw );
			$merged    = array_merge( $merged, $sanitized );
		}

		return $merged;
	}

	/**
	 * Register AI tabs and sections via the SettingsRegistry hook.
	 *
	 * @return void
	 */
	public function registerSections(): void {
		// Register our tabs.
		SettingsRegistry::register_tab( 'ai_providers', __( 'AI Providers', 'nvoos-graphify-ai' ) );
		SettingsRegistry::register_tab( 'ai_chat', __( 'Chat Settings', 'nvoos-graphify-ai' ) );

		// Register sections.
		if ( class_exists( 'NvoosGraphifyAi\Admin\Sections\ProviderSelection' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphifyAi\Admin\Sections\ProviderSelection() );
		}
		if ( class_exists( 'NvoosGraphifyAi\Admin\Sections\ApiKeys' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphifyAi\Admin\Sections\ApiKeys() );
		}
		if ( class_exists( 'NvoosGraphifyAi\Admin\Sections\ChatSettings' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphifyAi\Admin\Sections\ChatSettings() );
		}
	}

	/**
	 * Enqueue admin assets only on our page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueueAssets( $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'nvoos-graphify-ai-admin',
			NVOOS_GRAPHIFY_AI_URL . 'assets/css/graphify-ai-admin.css',
			array(),
			NVOOS_GRAPHIFY_AI_VERSION
		);
	}

	/**
	 * Render the tabbed admin page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nvoos-graphify-ai' ) );
		}

		// Ensure sections are registered before rendering.
		// This fires the action that registers sections if it hasn't been fired yet.
		if ( ! did_action( 'nvoos_graphify/admin/register_sections' ) ) {
			do_action( 'nvoos_graphify/admin/register_sections' );
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'ai_providers';
		$tabs        = SettingsRegistry::get_tabs();

		// Filter to only show AI tabs.
		$ai_tabs = array_filter(
			$tabs,
			function ( $tab ) {
				return strpos( $tab['id'], 'ai_' ) === 0;
			}
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NVoOS AI', 'nvoos-graphify-ai' ); ?></h1>
			<?php settings_errors(); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $ai_tabs as $tab_key => $tab_data ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_key ) ); ?>"
						class="nav-tab<?php echo ( $current_tab === $tab_key ) ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_data['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_graphify_ai_settings_group' );
				$sections = SettingsRegistry::get_sections( $current_tab );
				foreach ( $sections as $section ) {
					$section->render_wrapper( self::PAGE_SLUG );
				}
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
