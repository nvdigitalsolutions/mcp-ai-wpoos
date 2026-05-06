<?php
/**
 * NV oOS Docs Hub — Admin Settings Page
 *
 * Provides the WordPress admin settings page for the Docs Hub addon,
 * including a "Rebuild Documentation Index" action button.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page handler for the Docs Hub addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_Settings {

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_rebuild_action' ) );
	}

	/**
	 * Add the settings page under Settings menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'options-general.php',
			__( 'NV oOS Docs Hub', 'nvoos-docs-hub' ),
			__( 'NV oOS Docs Hub', 'nvoos-docs-hub' ),
			'manage_options',
			'nvoos-docs-hub',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings fields.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'nvoos_docs_hub_settings_group',
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		// General section.
		add_settings_section(
			'nvoos_docs_hub_general',
			__( 'General Settings', 'nvoos-docs-hub' ),
			'__return_false',
			'nvoos-docs-hub'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Addon', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array( 'id' => 'enabled', 'description' => __( 'Enable the Docs Hub documentation browser.', 'nvoos-docs-hub' ) )
		);

		add_settings_field(
			'default_theme',
			__( 'Default Theme', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_select' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array(
				'id'      => 'default_theme',
				'options' => array(
					'auto'  => __( 'Auto (system preference)', 'nvoos-docs-hub' ),
					'light' => __( 'Light', 'nvoos-docs-hub' ),
					'dark'  => __( 'Dark', 'nvoos-docs-hub' ),
				),
			)
		);

		add_settings_field(
			'search_enabled',
			__( 'Enable Search', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array( 'id' => 'search_enabled', 'description' => __( 'Show the search box in the documentation browser.', 'nvoos-docs-hub' ) )
		);

		add_settings_field(
			'sidebar_enabled',
			__( 'Enable Sidebar', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array( 'id' => 'sidebar_enabled', 'description' => __( 'Show the navigation sidebar.', 'nvoos-docs-hub' ) )
		);

		add_settings_field(
			'default_home',
			__( 'Home Page Slug', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_text' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array( 'id' => 'default_home', 'description' => __( 'Slug of the page shown by default (e.g. "readme").', 'nvoos-docs-hub' ) )
		);

		add_settings_field(
			'github_repo_url',
			__( 'GitHub Repository URL', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_text' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array( 'id' => 'github_repo_url', 'description' => __( 'Base URL for "Edit on GitHub" links (e.g. https://github.com/org/repo/blob/main).', 'nvoos-docs-hub' ) )
		);

		// Sources section.
		add_settings_section(
			'nvoos_docs_hub_sources',
			__( 'Documentation Sources', 'nvoos-docs-hub' ),
			'__return_false',
			'nvoos-docs-hub'
		);

		add_settings_field(
			'sources',
			__( 'Enabled Sources', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_sources_checkboxes' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_sources',
			array()
		);

		add_settings_field(
			'context_enabled',
			__( 'Include .context/ Files', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_sources',
			array(
				'id'          => 'context_enabled',
				'description' => __( 'Include .context/*.md files. Warning: these are only visible to users with manage_options capability.', 'nvoos-docs-hub' ),
			)
		);
	}

	/**
	 * Handle the rebuild action button form submission.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function handle_rebuild_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_POST['nvoos_docs_hub_rebuild'] ) ) {  // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( 'nvoos_docs_hub_rebuild_action', 'nvoos_docs_hub_rebuild_nonce' );

		$result = NV_oOS_Docs_Hub_Rebuild_Job::run();

		if ( $result['success'] ) {
			add_action(
				'admin_notices',
				function () use ( $result ) {
					echo '<div class="notice notice-success is-dismissible"><p>';
					echo esc_html(
						sprintf(
							/* translators: 1: page count, 2: broken link count */
							__( 'Documentation rebuilt successfully. %1$d pages indexed, %2$d broken links found.', 'nvoos-docs-hub' ),
							$result['pages'],
							$result['broken_links']
						)
					);
					echo '</p></div>';
				}
			);
		} else {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error is-dismissible"><p>';
					esc_html_e( 'Documentation rebuild failed. Please check PHP error logs.', 'nvoos-docs-hub' );
					echo '</p></div>';
				}
			);
		}
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public static function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['enabled']         = ! empty( $input['enabled'] );
		$sanitized['search_enabled']  = ! empty( $input['search_enabled'] );
		$sanitized['sidebar_enabled'] = ! empty( $input['sidebar_enabled'] );
		$sanitized['context_enabled'] = ! empty( $input['context_enabled'] );
		$sanitized['default_home']    = sanitize_text_field( $input['default_home'] ?? 'readme' );
		$sanitized['github_repo_url'] = esc_url_raw( $input['github_repo_url'] ?? '' );

		$allowed_themes             = array( 'auto', 'light', 'dark' );
		$raw_theme                  = sanitize_text_field( $input['default_theme'] ?? 'auto' );
		$sanitized['default_theme'] = in_array( $raw_theme, $allowed_themes, true ) ? $raw_theme : 'auto';

		$allowed_sources   = array( 'base', 'addons', 'root', 'context' );
		$raw_sources       = isset( $input['sources'] ) && is_array( $input['sources'] ) ? $input['sources'] : array();
		$sanitized['sources'] = array_values(
			array_filter(
				$raw_sources,
				function ( $s ) use ( $allowed_sources ) {
					return in_array( $s, $allowed_sources, true );
				}
			)
		);

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cache     = new NV_oOS_Docs_Hub_Cache();
		$last_built = $cache->get_last_built();
		$manifest   = $cache->get_manifest();
		$total_pages  = is_array( $manifest ) ? ( $manifest['total_pages'] ?? 0 ) : 0;
		$broken_links = is_array( $manifest ) ? count( $manifest['broken_links'] ?? array() ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NV oOS Docs Hub Settings', 'nvoos-docs-hub' ); ?></h1>

			<div class="card" style="max-width: 600px; margin-bottom: 20px; padding: 15px;">
				<h2 style="margin-top: 0;"><?php esc_html_e( 'Documentation Index Status', 'nvoos-docs-hub' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Last Built:', 'nvoos-docs-hub' ); ?></strong>
					<?php
					if ( $last_built > 0 ) {
						echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_built ) );
					} else {
						esc_html_e( 'Never', 'nvoos-docs-hub' );
					}
					?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Total Pages:', 'nvoos-docs-hub' ); ?></strong>
					<?php echo esc_html( $total_pages ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Broken Links:', 'nvoos-docs-hub' ); ?></strong>
					<?php echo esc_html( $broken_links ); ?>
				</p>

				<form method="post" action="">
					<?php wp_nonce_field( 'nvoos_docs_hub_rebuild_action', 'nvoos_docs_hub_rebuild_nonce' ); ?>
					<input type="submit"
						name="nvoos_docs_hub_rebuild"
						class="button button-primary"
						value="<?php esc_attr_e( 'Rebuild Documentation Index', 'nvoos-docs-hub' ); ?>" />
				</form>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_docs_hub_settings_group' );
				do_settings_sections( 'nvoos-docs-hub' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a checkbox field.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_checkbox( $args ) {
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$value    = ! empty( $settings[ $args['id'] ] );
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( NV_oOS_Docs_Hub_Plugin::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
				value="1"
				<?php checked( $value ); ?> />
			<?php echo esc_html( $args['description'] ?? '' ); ?>
		</label>
		<?php
	}

	/**
	 * Render a text input field.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_text( $args ) {
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<input type="text"
			name="<?php echo esc_attr( NV_oOS_Docs_Hub_Plugin::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a select dropdown field.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_select( $args ) {
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<select name="<?php echo esc_attr( NV_oOS_Docs_Hub_Plugin::OPTION_KEY . '[' . $args['id'] . ']' ); ?>">
			<?php foreach ( $args['options'] as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the sources multi-checkbox field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_sources_checkboxes() {
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$enabled  = isset( $settings['sources'] ) ? (array) $settings['sources'] : array( 'base', 'addons', 'root' );

		$sources = array(
			'base'    => __( 'Base Plugin (<code>mcp-ai-wpoos/docs/</code>)', 'nvoos-docs-hub' ),
			'addons'  => __( 'Addons (<code>addons/*/docs/</code> and <code>README.md</code>)', 'nvoos-docs-hub' ),
			'root'    => __( 'Repository root files (<code>README.md</code>, <code>CHANGELOG.md</code>, etc.) — only when WP_DEBUG is on', 'nvoos-docs-hub' ),
			'context' => __( 'Context files (<code>.context/*.md</code>) — only visible to manage_options users', 'nvoos-docs-hub' ),
		);

		foreach ( $sources as $key => $label ) :
			?>
			<label style="display: block; margin-bottom: 6px;">
				<input type="checkbox"
					name="<?php echo esc_attr( NV_oOS_Docs_Hub_Plugin::OPTION_KEY . '[sources][]' ); ?>"
					value="<?php echo esc_attr( $key ); ?>"
					<?php checked( in_array( $key, $enabled, true ) ); ?> />
				<?php echo wp_kses( $label, array( 'code' => array() ) ); ?>
			</label>
			<?php
		endforeach;
	}
}

// Initialize.
NV_oOS_Docs_Hub_Settings::init();
