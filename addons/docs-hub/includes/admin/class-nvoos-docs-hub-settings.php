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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_nvoos_docs_hub_import_settings', array( __CLASS__, 'ajax_import_settings' ) );
	}

	/**
	 * Enqueue admin-page-specific scripts and localize the repo-picker config.
	 *
	 * @since 0.3.8
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		// Only load on our own settings page.
		if ( false === strpos( $hook_suffix, 'nvoos-docs-hub' ) ) {
			return;
		}

		wp_enqueue_script(
			'nvoos-dh-repo-picker',
			NVOOS_DOCS_HUB_URL . 'assets/admin/repo-picker.js',
			array(),
			NVOOS_DOCS_HUB_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-dh-repo-picker',
			'NVOOS_DH_REPO_PICKER',
			array(
				'restBase'  => esc_url_raw( rest_url( NV_oOS_Docs_Hub_REST::NAMESPACE . '/remote/tree' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'enterOwnerRepo' => __( 'Enter owner and repo first.', 'nvoos-docs-hub' ),
					/* translators: Loading indicator text. */
					'loading'        => __( 'Loading…', 'nvoos-docs-hub' ),
					'filesFound'     => __( 'files found.', 'nvoos-docs-hub' ),
					'noFilesFound'   => __( 'No Markdown files found in this ref / path.', 'nvoos-docs-hub' ),
				),
			)
		);
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
			array(
				'id'          => 'enabled',
				'description' => __( 'Enable the Docs Hub documentation browser.', 'nvoos-docs-hub' ),
			)
		);

		add_settings_field(
			'public_access',
			__( 'Allow Public (Guest) Access', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array(
				'id'          => 'public_access',
				'description' => __( 'When enabled, the documentation browser is accessible to all visitors without logging in. Disable to require a WordPress account.', 'nvoos-docs-hub' ),
			)
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
			array(
				'id'          => 'search_enabled',
				'description' => __( 'Show the search box in the documentation browser.', 'nvoos-docs-hub' ),
			)
		);

		add_settings_field(
			'sidebar_enabled',
			__( 'Enable Sidebar', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array(
				'id'          => 'sidebar_enabled',
				'description' => __( 'Show the navigation sidebar.', 'nvoos-docs-hub' ),
			)
		);

		add_settings_field(
			'include_addon_readmes',
			__( 'Include per-addon README/CHANGELOG', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array(
				'id'          => 'include_addon_readmes',
				'description' => __( 'Index each addon\'s top-level README.md and CHANGELOG.md alongside its docs/ tree. Disable to surface only the plugin-root files.', 'nvoos-docs-hub' ),
			)
		);

		add_settings_field(
			'default_home',
			__( 'Home Page Slug', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_text' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array(
				'id'          => 'default_home',
				'description' => __( 'Slug of the page shown by default (e.g. "readme").', 'nvoos-docs-hub' ),
			)
		);

		add_settings_field(
			'github_repo_url',
			__( 'GitHub Repository URL', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_text' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_general',
			array(
				'id'          => 'github_repo_url',
				'description' => __( 'Base URL for "Edit on GitHub" links (e.g. https://github.com/org/repo/blob/main).', 'nvoos-docs-hub' ),
			)
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

		// Remote repositories section.
		add_settings_section(
			'nvoos_docs_hub_remote',
			__( 'Remote Repositories', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_remote_section_intro' ),
			'nvoos-docs-hub'
		);

		add_settings_field(
			'remote_repos',
			__( 'Public GitHub Repositories', 'nvoos-docs-hub' ),
			array( __CLASS__, 'render_remote_repos' ),
			'nvoos-docs-hub',
			'nvoos_docs_hub_remote',
			array()
		);
	}

	/**
	 * Render a first-run notice when the settings option has never been
	 * saved (i.e. nothing has been configured yet).
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public static function maybe_render_first_run_notice() {
		if ( false !== get_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY, false ) ) {
			return;
		}
		?>
		<div class="notice notice-info" style="margin-top:12px;">
			<p>
				<strong><?php esc_html_e( 'Welcome to NV oOS Docs Hub.', 'nvoos-docs-hub' ); ?></strong>
				<?php
				esc_html_e(
					'Configure a remote GitHub repository below to start indexing documentation. Local filesystem sources are off by default — most installs should leave them off.',
					'nvoos-docs-hub'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render a one-time, dismissible notice for installs that have all the
	 * legacy local sources enabled and zero remote repos configured —
	 * pointing them to the new tree picker.
	 *
	 * Dismissal is stored per-user in user meta.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public static function maybe_render_legacy_only_notice() {
		$user_id = get_current_user_id();
		if ( $user_id && get_user_meta( $user_id, 'nvoos_docs_hub_legacy_notice_dismissed', true ) ) {
			return;
		}

		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$sources  = isset( $settings['sources'] ) ? (array) $settings['sources'] : array();
		$repos    = isset( $settings['remote_repos'] ) ? (array) $settings['remote_repos'] : array();

		$has_all_legacy = in_array( 'base', $sources, true )
			&& in_array( 'addons', $sources, true )
			&& in_array( 'root', $sources, true );

		if ( ! $has_all_legacy || ! empty( $repos ) ) {
			return;
		}

		// Handle dismiss action.
		if ( ! empty( $_GET['nvoos_dh_dismiss_legacy'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-state-changing dismissal.
			&& isset( $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'nvoos_dh_dismiss_legacy' )
		) {
			update_user_meta( $user_id, 'nvoos_docs_hub_legacy_notice_dismissed', 1 );
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'nvoos_dh_dismiss_legacy', '1' ),
			'nvoos_dh_dismiss_legacy'
		);
		?>
		<div class="notice notice-warning" style="margin-top:12px;">
			<p>
				<strong><?php esc_html_e( 'Heads up:', 'nvoos-docs-hub' ); ?></strong>
				<?php
				esc_html_e(
					'You are indexing only local filesystem sources. Most installations should pull docs from a remote GitHub repository instead — use the "Remote Repositories" section below and the new "Browse files" picker to choose exactly what to index.',
					'nvoos-docs-hub'
				);
				?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px;">
					<?php esc_html_e( 'Dismiss', 'nvoos-docs-hub' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the rebuild action button form submission.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	/**
	 * AJAX handler: import settings from a JSON file.
	 *
	 * Accepts a `data` field containing the JSON string. Runs the
	 * same sanitize_settings pipeline as a normal save so invalid
	 * values are dropped. Tokens are stripped by the exporter, so
	 * the imported repos have blank tokens that the admin must re-enter.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function ajax_import_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nvoos-docs-hub' ) ), 403 );
		}

		check_ajax_referer( 'nvoos_docs_hub_import_settings' );

		$raw_json = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON will be validated below.
		if ( '' === $raw_json ) {
			wp_send_json_error( array( 'message' => __( 'No data received.', 'nvoos-docs-hub' ) ) );
		}

		$imported = json_decode( $raw_json, true );
		if ( ! is_array( $imported ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON structure.', 'nvoos-docs-hub' ) ) );
		}

		// Run through the same sanitization pipeline as the settings form.
		$sanitized = self::sanitize_settings( $imported );

		// Preserve existing tokens for repos that match by owner/repo.
		$existing        = NV_oOS_Docs_Hub_Plugin::get_settings();
		$existing_repos  = isset( $existing['remote_repos'] ) ? (array) $existing['remote_repos'] : array();
		$sanitized_repos = isset( $sanitized['remote_repos'] ) ? (array) $sanitized['remote_repos'] : array();
		$new_repos       = array();
		foreach ( $sanitized_repos as $sr ) {
			if ( ! is_array( $sr ) || empty( $sr['owner'] ) || empty( $sr['repo'] ) ) {
				$new_repos[] = $sr;
				continue;
			}
			// If the imported token is blank, look up existing token.
			if ( empty( $sr['token'] ) ) {
				$key = strtolower( (string) $sr['owner'] ) . '|' . strtolower( (string) $sr['repo'] );
				foreach ( $existing_repos as $er ) {
					if ( ! is_array( $er ) ) {
						continue;
					}
					$ek = strtolower( (string) ( $er['owner'] ?? '' ) ) . '|' . strtolower( (string) ( $er['repo'] ?? '' ) );
					if ( $key === $ek && ! empty( $er['token'] ) ) {
						$sr['token'] = (string) $er['token'];
						break;
					}
				}
			}
			$new_repos[] = $sr;
		}
		$sanitized['remote_repos'] = $new_repos;

		update_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY, $sanitized );

		$repo_count = count( $sanitized['remote_repos'] );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of remote repos imported */
					_n(
						'Imported %d remote repository. A rebuild will run automatically. Refresh the page to see the changes.',
						'Imported %d remote repositories. A rebuild will run automatically. Refresh the page to see the changes.',
						$repo_count,
						'nvoos-docs-hub'
					),
					$repo_count
				),
			)
		);
	}

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

		$sanitized['enabled']               = ! empty( $input['enabled'] );
		$sanitized['public_access']         = ! empty( $input['public_access'] );
		$sanitized['search_enabled']        = ! empty( $input['search_enabled'] );
		$sanitized['sidebar_enabled']       = ! empty( $input['sidebar_enabled'] );
		$sanitized['context_enabled']       = ! empty( $input['context_enabled'] );
		$sanitized['include_addon_readmes'] = ! empty( $input['include_addon_readmes'] );
		$sanitized['default_home']          = sanitize_text_field( $input['default_home'] ?? 'readme' );
		$sanitized['github_repo_url']       = esc_url_raw( $input['github_repo_url'] ?? '' );

		$allowed_themes             = array( 'auto', 'light', 'dark' );
		$raw_theme                  = sanitize_text_field( $input['default_theme'] ?? 'auto' );
		$sanitized['default_theme'] = in_array( $raw_theme, $allowed_themes, true ) ? $raw_theme : 'auto';

		$allowed_sources      = array( 'base', 'addons', 'root', 'context', 'remote' );
		$raw_sources          = isset( $input['sources'] ) && is_array( $input['sources'] ) ? $input['sources'] : array();
		$sanitized['sources'] = array_values(
			array_filter(
				$raw_sources,
				function ( $s ) use ( $allowed_sources ) {
					return in_array( $s, $allowed_sources, true );
				}
			)
		);

		// Sanitize remote repos.
		// Build a lookup map of existing tokens keyed by owner|repo so token
		// preservation survives row reordering (add/remove) that would otherwise
		// cause tokens to silently transfer between repos when matched by index.
		$existing_settings  = NV_oOS_Docs_Hub_Plugin::get_settings();
		$existing_repos     = isset( $existing_settings['remote_repos'] ) ? (array) $existing_settings['remote_repos'] : array();
		$existing_token_map = array();
		foreach ( $existing_repos as $er ) {
			if ( ! is_array( $er ) || empty( $er['owner'] ) || empty( $er['repo'] ) ) {
				continue;
			}
			$key = strtolower( trim( (string) $er['owner'] ) ) . '|' . strtolower( trim( (string) $er['repo'] ) );
			if ( ! empty( $er['token'] ) ) {
				$existing_token_map[ $key ] = (string) $er['token'];
			}
		}

		$sanitized['remote_repos']   = array();
		$raw_repos                   = isset( $input['remote_repos'] ) && is_array( $input['remote_repos'] ) ? $input['remote_repos'] : array();
		$dropped_empty               = 0;
		$dropped_invalid_chars       = 0;
		$dropped_invalid_chars_label = '';

		foreach ( $raw_repos as $i => $repo ) {
			if ( ! is_array( $repo ) ) {
				++$dropped_empty;
				continue;
			}
			$owner     = sanitize_text_field( $repo['owner'] ?? '' );
			$repo_name = sanitize_text_field( $repo['repo'] ?? '' );
			if ( '' === $owner || '' === $repo_name ) {
				++$dropped_empty;
				continue;
			}
			// Enforce safe characters: letters, digits, hyphens, underscores, dots only.
			if ( ! preg_match( '/^[a-zA-Z0-9_.\-]+$/', $owner ) || ! preg_match( '/^[a-zA-Z0-9_.\-]+$/', $repo_name ) ) {
				++$dropped_invalid_chars;
				if ( '' === $dropped_invalid_chars_label ) {
					$dropped_invalid_chars_label = $owner . '/' . $repo_name;
				}
				continue;
			}

			$new_token = sanitize_text_field( $repo['token'] ?? '' );
			// When the token field is submitted blank, preserve the existing token
			// for this specific owner/repo pair (matched by key, not array index).
			if ( '' === $new_token ) {
				$lookup_key = strtolower( $owner ) . '|' . strtolower( $repo_name );
				if ( isset( $existing_token_map[ $lookup_key ] ) ) {
					$new_token = $existing_token_map[ $lookup_key ];
				}
			}

			// Selection mode values: all (default), prefix, or selected.
			$raw_mode       = sanitize_text_field( $repo['selection_mode'] ?? 'all' );
			$selection_mode = in_array( $raw_mode, array( 'all', 'prefix', 'selected' ), true ) ? $raw_mode : 'all';

			// Selected / excluded paths arrays. Both follow the same path safety rules:
			// - Allowed chars: letters, digits, underscore, dot, slash, hyphen.
			// - No '..' segments, no leading slash.
			// - Trailing '/' = directory (recursive include/exclude).
			$selected_paths = self::sanitize_path_list( $repo['selected_paths'] ?? array() );
			$excluded_paths = self::sanitize_path_list( $repo['excluded_paths'] ?? array() );

			$sanitized['remote_repos'][] = array(
				'owner'          => $owner,
				'repo'           => $repo_name,
				'ref'            => sanitize_text_field( $repo['ref'] ?? 'HEAD' ),
				'label'          => sanitize_text_field( $repo['label'] ?? $owner . '/' . $repo_name ),
				'path'           => sanitize_text_field( $repo['path'] ?? '' ),
				'token'          => $new_token,
				'selection_mode' => $selection_mode,
				'selected_paths' => $selected_paths,
				'excluded_paths' => $excluded_paths,
			);
		}

		// Surface sanitization warnings via WordPress settings errors so the
		// admin gets feedback when repo rows were silently dropped.
		$total_dropped = $dropped_empty + $dropped_invalid_chars;
		if ( $total_dropped > 0 ) {
			$messages = array();
			if ( $dropped_empty > 0 ) {
				$messages[] = sprintf(
					/* translators: %d: number of rows dropped */
					_n(
						'%d remote repository row was dropped because owner or repo was empty.',
						'%d remote repository rows were dropped because owner or repo was empty.',
						$dropped_empty,
						'nvoos-docs-hub'
					),
					$dropped_empty
				);
			}
			if ( $dropped_invalid_chars > 0 ) {
				$messages[] = sprintf(
					/* translators: 1: number of rows, 2: example owner/repo label */
					_n(
						'%1$d remote repository row (e.g. "%2$s") was dropped because the owner or repo name contains invalid characters. Only letters, digits, hyphens, underscores, and dots are allowed.',
						'%1$d remote repository rows (e.g. "%2$s") were dropped because the owner or repo name contains invalid characters. Only letters, digits, hyphens, underscores, and dots are allowed.',
						$dropped_invalid_chars,
						'nvoos-docs-hub'
					),
					$dropped_invalid_chars,
					$dropped_invalid_chars_label
				);
			}
			foreach ( $messages as $msg ) {
				add_settings_error(
					'nvoos_docs_hub_settings_group',
					'nvoos_docs_hub_dropped_rows',
					$msg,
					'warning'
				);
			}
		}

		return $sanitized;
	}

	/**
	 * Coerce a stored path-list value into a flat array of strings.
	 *
	 * Tolerates bad input shapes that may arrive from third-party migrations:
	 * arrays of arrays, scalars, or newline-delimited strings.
	 *
	 * @since 0.3.6
	 *
	 * @param mixed $raw Stored value.
	 * @return string[] Array of single-line path strings.
	 */
	public static function coerce_path_list( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) ) {
				continue; // Nested arrays are dropped — settings save will normalise.
			}
			$line = trim( (string) $entry );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize a list of repo-relative paths (newline string or array).
	 *
	 * Allowed chars: A-Z a-z 0-9 _ . / -. Rejects entries containing '..'
	 * or leading '/'. Trailing '/' is preserved (signals "directory").
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $raw Raw input (textarea string or array).
	 * @return string[] Sanitized paths.
	 */
	public static function sanitize_path_list( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			// No leading slash.
			if ( '/' === $line[0] ) {
				continue;
			}
			// Reject '..' as a path segment (e.g. '..', 'a/..', '../b', 'a/../b'),
			// but allow filenames that merely contain consecutive dots ('..hidden.md').
			if ( preg_match( '#(^|/)\.\.(/|$)#', $line ) ) {
				continue;
			}
			// Allow common filename characters. The '..' check above prevents
			// path traversal independently of this character class, so we can
			// be permissive for legitimate repo filenames.
			if ( ! preg_match( '#^[A-Za-z0-9_./\- @(){}\[\]\'"!,;+]+/?$#', $line ) ) {
					continue;
			}
			$out[] = $line;
		}
		return array_values( array_unique( $out ) );
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

		self::maybe_render_first_run_notice();
		self::maybe_render_legacy_only_notice();

		$cache         = new NV_oOS_Docs_Hub_Cache();
		$last_built    = $cache->get_last_built();
		$manifest      = $cache->get_manifest();
		$total_pages   = is_array( $manifest ) ? ( $manifest['total_pages'] ?? 0 ) : 0;
		$broken_links  = is_array( $manifest ) ? count( $manifest['broken_links'] ?? array() ) : 0;
		$rebuild_state = NV_oOS_Docs_Hub_Rebuild_State::to_summary();
		$rest_base     = esc_url_raw( rest_url( NV_oOS_Docs_Hub_REST::NAMESPACE ) );
		$rest_nonce    = wp_create_nonce( 'wp_rest' );
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

				<div id="nvoos-docs-hub-rebuild-panel" data-rest-base="<?php echo esc_attr( $rest_base ); ?>" data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>" data-initial-state="<?php echo esc_attr( wp_json_encode( $rebuild_state ) ); ?>">
					<p class="nvoos-rebuild-status" style="margin-top: 12px;">
						<strong><?php esc_html_e( 'Rebuild status:', 'nvoos-docs-hub' ); ?></strong>
						<span class="nvoos-rebuild-phase"><?php echo esc_html( $rebuild_state['phase'] ); ?></span>
						—
						<span class="nvoos-rebuild-progress">
							<?php
							echo esc_html(
								sprintf(
								/* translators: 1: processed, 2: total, 3: percentage */
									__( '%1$d / %2$d (%3$d%%)', 'nvoos-docs-hub' ),
									(int) $rebuild_state['processed'],
									(int) $rebuild_state['total'],
									(int) $rebuild_state['percentage']
								)
							);
							?>
						</span>
					</p>
					<p class="nvoos-rebuild-error" style="display:<?php echo empty( $rebuild_state['last_error'] ) ? 'none' : 'block'; ?>; color:#a00;">
						<strong><?php esc_html_e( 'Last error:', 'nvoos-docs-hub' ); ?></strong>
						<span class="nvoos-rebuild-error-msg"><?php echo esc_html( $rebuild_state['last_error'] ); ?></span>
					</p>
					<p>
						<button type="button" class="button button-primary nvoos-rebuild-start"<?php echo $rebuild_state['is_running'] ? ' disabled' : ''; ?>>
							<?php esc_html_e( 'Rebuild Documentation Index', 'nvoos-docs-hub' ); ?>
						</button>
						<button type="button" class="button nvoos-rebuild-resume"<?php echo $rebuild_state['is_running'] ? ' disabled' : ''; ?>>
							<?php esc_html_e( 'Resume', 'nvoos-docs-hub' ); ?>
						</button>
						<button type="button" class="button nvoos-rebuild-cancel"<?php echo $rebuild_state['is_running'] ? '' : ' disabled'; ?>>
							<?php esc_html_e( 'Cancel', 'nvoos-docs-hub' ); ?>
						</button>
					</p>
				</div>

				<!-- Sync fallback for environments without JavaScript / WP-Cron. -->
				<form method="post" action="" style="margin-top: 8px;">
					<?php wp_nonce_field( 'nvoos_docs_hub_rebuild_action', 'nvoos_docs_hub_rebuild_nonce' ); ?>
					<input type="submit"
						name="nvoos_docs_hub_rebuild"
						class="button"
						value="<?php esc_attr_e( 'Rebuild now (synchronous)', 'nvoos-docs-hub' ); ?>" />
				</form>
			</div>

			<script>
			(function () {
				var panel = document.getElementById( 'nvoos-docs-hub-rebuild-panel' );
				if ( ! panel ) {
					return;
				}
				var base  = panel.getAttribute( 'data-rest-base' );
				var nonce = panel.getAttribute( 'data-rest-nonce' );
				var phaseEl    = panel.querySelector( '.nvoos-rebuild-phase' );
				var progressEl = panel.querySelector( '.nvoos-rebuild-progress' );
				var errorEl    = panel.querySelector( '.nvoos-rebuild-error' );
				var errorMsgEl = panel.querySelector( '.nvoos-rebuild-error-msg' );
				var startBtn   = panel.querySelector( '.nvoos-rebuild-start' );
				var resumeBtn  = panel.querySelector( '.nvoos-rebuild-resume' );
				var cancelBtn  = panel.querySelector( '.nvoos-rebuild-cancel' );
				var pollHandle = null;

				function applyState( state ) {
					if ( ! state ) { return; }
					phaseEl.textContent    = state.phase || '';
					progressEl.textContent = ( state.processed || 0 ) + ' / ' + ( state.total || 0 ) + ' (' + ( state.percentage || 0 ) + '%)';
					if ( state.last_error ) {
						errorEl.style.display = 'block';
						errorMsgEl.textContent = state.last_error;
					} else {
						errorEl.style.display = 'none';
					}
					var running = !! state.is_running;
					startBtn.disabled  = running;
					resumeBtn.disabled = running;
					cancelBtn.disabled = ! running;

					if ( running ) {
						if ( ! pollHandle ) {
							pollHandle = window.setInterval( pollStatus, 2000 );
						}
					} else if ( pollHandle ) {
						window.clearInterval( pollHandle );
						pollHandle = null;
					}
				}

				function call( path, method ) {
					return fetch( base + path, {
						method: method || 'GET',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
					} ).then( function ( r ) {
						if ( ! r.ok ) {
							throw new Error( 'HTTP ' + r.status );
						}
						return r.json();
					} );
				}

				function pollStatus() {
					call( '/rebuild/status', 'GET' )
						.then( applyState )
						.catch( function () {
							// Network blip — keep the polling loop alive and try again next tick.
						} );
				}

				function safeApply( promise ) {
					promise
						.then( applyState )
						.catch( function ( err ) {
							errorEl.style.display = 'block';
							errorMsgEl.textContent = ( err && err.message ) ? err.message : 'Request failed';
						} );
				}

				startBtn.addEventListener( 'click', function () {
					safeApply( call( '/rebuild', 'POST' ) );
				} );
				resumeBtn.addEventListener( 'click', function () {
					safeApply( call( '/rebuild/resume', 'POST' ) );
				} );
				cancelBtn.addEventListener( 'click', function () {
					safeApply( call( '/rebuild/cancel', 'POST' ) );
				} );

				try {
					applyState( JSON.parse( panel.getAttribute( 'data-initial-state' ) ) );
				} catch ( e ) {}
					}());

					// Dirty-state tracking: warn before leaving when the form has unsaved
					// changes (repo rows added/removed, fields edited, etc.).
					( function () {
						var form = document.querySelector( 'form[action="options.php"]' );
						if ( ! form ) { return; }
						var isDirty = false;

						function markDirty() {
							if ( ! isDirty ) {
								isDirty = true;
								window.addEventListener( 'beforeunload', warnUnsaved );
							}
						}

						function warnUnsaved( e ) {
							e.preventDefault();
							e.returnValue = '';
							return '';
						}

						// Watch input/textarea/select changes.
						form.addEventListener( 'input', markDirty, { passive: true } );
						form.addEventListener( 'change', markDirty, { passive: true } );

						// The "Add Repository" button adds new rows via cloneNode() —
						// those fire DOM mutations but no input events on the form
						// itself, so we listen for click on the add button.
						var addBtn = document.getElementById( 'nvoos-dh-add-repo' );
						if ( addBtn ) { addBtn.addEventListener( 'click', markDirty ); }

						// The "Remove this repository" button is handled via delegation
						// in repo-picker.js; we listen for the click on the wrapper.
						var wrap = document.getElementById( 'nvoos-dh-remote-repos-wrap' );
						if ( wrap ) { wrap.addEventListener( 'click', markDirty ); }

						// Clear dirty flag on form submit so the warning doesn't fire
						// when the user intentionally saves.
						form.addEventListener( 'submit', function () {
							isDirty = false;
							window.removeEventListener( 'beforeunload', warnUnsaved );
						} );
					}() );
					</script>

					<form method="post" action="options.php">
				<?php
					settings_fields( 'nvoos_docs_hub_settings_group' );
				try {
					do_settings_sections( 'nvoos-docs-hub' );
				} catch ( \Throwable $e ) {
					echo '<div class="notice notice-error"><p>';
					echo esc_html(
						sprintf(
							/* translators: %s: error message */
							__( 'Error rendering settings sections: %s', 'nvoos-docs-hub' ),
							$e->getMessage()
						)
					);
					echo '</p></div>';
					error_log(
						sprintf(
							'[NV oOS Docs Hub] do_settings_sections fatal: %s in %s:%d',
							$e->getMessage(),
							$e->getFile(),
							$e->getLine()
						)
					);
				}
					submit_button();
				?>
				</form>

				<hr />

				<h2><?php esc_html_e( 'Export / Import Settings', 'nvoos-docs-hub' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Download your Docs Hub configuration as JSON or restore a previously exported file. Importing overwrites the current settings (a rebuild will be triggered automatically).', 'nvoos-docs-hub' ); ?>
				</p>

				<p>
					<button type="button" id="nvoos-dh-export-settings" class="button">
						<?php esc_html_e( 'Export Settings (JSON)', 'nvoos-docs-hub' ); ?>
					</button>
				</p>

				<p>
					<label for="nvoos-dh-import-file" class="button" style="cursor:pointer;">
						<?php esc_html_e( 'Import Settings (JSON)', 'nvoos-docs-hub' ); ?>
					</label>
					<input type="file"
						id="nvoos-dh-import-file"
						accept=".json,application/json"
						style="display:none;" />
					<span id="nvoos-dh-import-status" style="margin-left:8px; color:#646970; font-style:italic;"></span>
				</p>

				<script>
				( function () {
					// --- Export ---
					var exportBtn = document.getElementById( 'nvoos-dh-export-settings' );
					if ( exportBtn ) {
						exportBtn.addEventListener( 'click', function () {
							var settings = <?php echo wp_json_encode( NV_oOS_Docs_Hub_Plugin::get_settings() ); ?>;
							// Strip sensitive token values from the export.
							if ( settings.remote_repos && Array.isArray( settings.remote_repos ) ) {
								settings.remote_repos = settings.remote_repos.map( function ( r ) {
									var copy = Object.assign( {}, r );
									delete copy.token;
									return copy;
								} );
							}
							var blob = new Blob(
								[ JSON.stringify( settings, null, 2 ) ],
								{ type: 'application/json' }
							);
							var a = document.createElement( 'a' );
							a.href = URL.createObjectURL( blob );
							a.download = 'nvoos-docs-hub-settings-' + new Date().toISOString().slice( 0, 10 ) + '.json';
							document.body.appendChild( a );
							a.click();
							document.body.removeChild( a );
							URL.revokeObjectURL( a.href );
						} );
					}

					// --- Import ---
					var importFile = document.getElementById( 'nvoos-dh-import-file' );
					var importLabel = document.querySelector( 'label[for="nvoos-dh-import-file"]' );
					var importStatus = document.getElementById( 'nvoos-dh-import-status' );

					if ( importLabel && importFile ) {
						importLabel.addEventListener( 'click', function () {
							importFile.click();
						} );
					}

					if ( importFile && importStatus ) {
						importFile.addEventListener( 'change', function () {
							var file = importFile.files && importFile.files[ 0 ];
							if ( ! file ) { return; }
							importStatus.textContent = '<?php echo esc_js( __( 'Importing…', 'nvoos-docs-hub' ) ); ?>';
							var reader = new FileReader();
							reader.onload = function ( e ) {
								try {
									var imported = JSON.parse( e.target.result );
									if ( ! imported || typeof imported !== 'object' || Array.isArray( imported ) ) {
										throw new Error( 'Invalid JSON structure' );
									}
									// POST via hidden form to trigger settings save.
									var formData = new FormData();
									formData.append( 'action', 'nvoos_docs_hub_import_settings' );
									formData.append( 'data', JSON.stringify( imported ) );
									formData.append( '_wpnonce', '<?php echo esc_js( wp_create_nonce( 'nvoos_docs_hub_import_settings' ) ); ?>' );
									fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: formData } )
										.then( function ( r ) { return r.json(); } )
										.then( function ( data ) {
											if ( data.success ) {
												importStatus.textContent = '\u2705 ' + ( data.data && data.data.message ? data.data.message : '<?php echo esc_js( __( 'Imported. A rebuild will run automatically.', 'nvoos-docs-hub' ) ); ?>' );
												setTimeout( function () { location.reload(); }, 1500 );
											} else {
												importStatus.textContent = '\u274c ' + ( ( data.data && data.data.message ) ? data.data.message : '<?php echo esc_js( __( 'Import failed.', 'nvoos-docs-hub' ) ); ?>' );
											}
										} )
										.catch( function () {
											importStatus.textContent = '\u274c <?php echo esc_js( __( 'Import request failed.', 'nvoos-docs-hub' ) ); ?>';
										} );
								} catch ( err ) {
									importStatus.textContent = '\u274c ' + ( err.message || '<?php echo esc_js( __( 'Invalid JSON file.', 'nvoos-docs-hub' ) ); ?>' );
								}
							};
							reader.readAsText( file );
						} );
					}
				}() );
				</script>

				<p class="description">
					<?php
					esc_html_e( 'Note: Exported files do NOT include Personal Access Tokens. After importing, re-enter your tokens and save. If your server runs Nginx, add a location rule to deny direct access to the cache directory:', 'nvoos-docs-hub' );
					?>
					<br />
					<code style="display:inline-block; margin-top:4px; background:#f0f0f1; padding:4px 8px;">
						location /wp-content/uploads/nvoos-docs-hub/ { deny all; return 403; }
					</code>
				</p>

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
		$enabled  = isset( $settings['sources'] ) ? (array) $settings['sources'] : array( 'remote' );

		// Primary (recommended) source.
		$primary = array(
			'remote' => __( 'Remote GitHub repositories <em>(recommended — configure below)</em>', 'nvoos-docs-hub' ),
		);

		// Legacy local-filesystem sources. Functional, but most users
		// should leave these off — see the "Advanced" notice below.
		$legacy = array(
			'base'    => __( 'Base Plugin (<code>mcp-ai-wpoos/docs/</code>)', 'nvoos-docs-hub' ),
			'addons'  => __( 'Addons (<code>addons/*/docs/</code> and <code>README.md</code>)', 'nvoos-docs-hub' ),
			'root'    => __( 'Repository root files (<code>README.md</code>, <code>CHANGELOG.md</code>, etc.)', 'nvoos-docs-hub' ),
			'context' => __( 'Context files (<code>.context/*.md</code>) — only visible to manage_options users', 'nvoos-docs-hub' ),
		);

		$kses_allowed = array(
			'code' => array(),
			'em'   => array(),
		);

		foreach ( $primary as $key => $label ) :
			?>
			<label style="display: block; margin-bottom: 6px;">
				<input type="checkbox"
					name="<?php echo esc_attr( NV_oOS_Docs_Hub_Plugin::OPTION_KEY . '[sources][]' ); ?>"
					value="<?php echo esc_attr( $key ); ?>"
					<?php checked( in_array( $key, $enabled, true ) ); ?> />
				<?php echo wp_kses( $label, $kses_allowed ); ?>
			</label>
			<?php
		endforeach;
		?>

		<details style="margin-top:10px; padding:10px; border:1px solid #ccd0d4; border-radius:4px; background:#f6f7f7;">
			<summary style="cursor:pointer; font-weight:600;">
				<?php esc_html_e( 'Advanced — local filesystem sources (legacy)', 'nvoos-docs-hub' ); ?>
			</summary>
			<p class="description" style="margin-top:8px;">
				<?php
				esc_html_e(
					'These sources index Markdown from the local plugin install. Most installations should leave them OFF and configure a Remote Repository above. They are kept available for local development and monorepo setups.',
					'nvoos-docs-hub'
				);
				?>
			</p>
			<?php foreach ( $legacy as $key => $label ) : ?>
				<label style="display: block; margin-bottom: 6px;">
					<input type="checkbox"
						name="<?php echo esc_attr( NV_oOS_Docs_Hub_Plugin::OPTION_KEY . '[sources][]' ); ?>"
						value="<?php echo esc_attr( $key ); ?>"
						<?php checked( in_array( $key, $enabled, true ) ); ?> />
					<?php echo wp_kses( $label, $kses_allowed ); ?>
				</label>
			<?php endforeach; ?>
		</details>
		<?php
	}

	/**
	 * Render introductory text for the Remote Repositories section.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function render_remote_section_intro() {
		echo '<p>';
		esc_html_e(
			'Add public GitHub repositories whose Markdown documentation you want to include in the browser. Files are fetched from the GitHub API over HTTPS and cached locally for 24 hours. Only public repos (or private repos accessible with a Personal Access Token) are supported.',
			'nvoos-docs-hub'
		);
		echo '</p>';
		echo '<p style="background:#fff8e5; border-left:4px solid #f0b849; padding:8px 12px; margin:8px 0;">';
		echo '<strong>' . esc_html__( 'Pick exactly which files to index:', 'nvoos-docs-hub' ) . '</strong> ';
		esc_html_e(
			'After entering an Owner and Repository, click "Browse files in repo…" to load the file tree, then check the files or folders you want indexed. Switch "File selection" to "Selected files / folders only" to limit indexing to just those entries.',
			'nvoos-docs-hub'
		);
		echo '</p>';
	}

	/**
	 * Render the remote repositories repeatable list UI.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function render_remote_repos() {
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$repos    = isset( $settings['remote_repos'] ) && is_array( $settings['remote_repos'] ) ? $settings['remote_repos'] : array();

		// Always render at least one (empty) row so the UI is usable.
		if ( empty( $repos ) ) {
			$repos = array(
				array(
					'owner'          => '',
					'repo'           => '',
					'ref'            => 'HEAD',
					'label'          => '',
					'path'           => '',
					'token'          => '',
					'selection_mode' => 'all',
					'selected_paths' => array(),
					'excluded_paths' => array(),
				),
			);
		}

		$option_key = NV_oOS_Docs_Hub_Plugin::OPTION_KEY;

		echo '<div id="nvoos-dh-remote-repos-wrap">';

		try {
			foreach ( $repos as $i => $r ) :
				// Defensive: a malformed (string/null/scalar) row from a partial migration must
				// not fatal the settings page. Coerce to an array and surface an inline notice.
				if ( ! is_array( $r ) ) {
					$r = array();
					echo '<div class="notice notice-warning inline" style="margin:0 0 10px 0;"><p>';
					printf(
					/* translators: %d: 1-based row index */
						esc_html__( 'NV oOS Docs Hub: remote repository row #%d was stored in an unexpected shape and has been reset to defaults. Please re-enter the values and save.', 'nvoos-docs-hub' ),
						(int) ( $i + 1 )
					);
					echo '</p></div>';
				}
				$owner = esc_attr( is_string( $r['owner'] ?? '' ) ? $r['owner'] : '' );
				$repo  = esc_attr( is_string( $r['repo'] ?? '' ) ? $r['repo'] : '' );
				$ref   = esc_attr( is_string( $r['ref'] ?? 'HEAD' ) ? $r['ref'] : 'HEAD' );
				$label = esc_attr( is_string( $r['label'] ?? '' ) ? $r['label'] : '' );
				$path  = esc_attr( is_string( $r['path'] ?? '' ) ? $r['path'] : '' );
				// Token: never echo saved token back for security — show placeholder.
				$has_token      = ! empty( $r['token'] );
				$selection_mode = isset( $r['selection_mode'] ) && in_array( $r['selection_mode'], array( 'all', 'prefix', 'selected' ), true )
				? $r['selection_mode']
				: 'all';
				// Coerce path lists defensively. A flat string (from older migrations) is
				// split on newlines so the textarea round-trips correctly.
				$selected_paths = self::coerce_path_list( $r['selected_paths'] ?? array() );
				$excluded_paths = self::coerce_path_list( $r['excluded_paths'] ?? array() );
				$selected_text  = esc_textarea( implode( "\n", $selected_paths ) );
				$excluded_text  = esc_textarea( implode( "\n", $excluded_paths ) );
				?>
			<div class="nvoos-dh-remote-repo-row" style="border:1px solid #ccd0d4; border-radius:4px; padding:12px; margin-bottom:10px; background:#fafafa;">
				<table class="widefat" style="background:transparent; border:none;">
					<tr>
						<td style="width:110px; font-weight:600; padding:4px 8px 4px 0;"><?php esc_html_e( 'Owner *', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<input type="text"
								name="<?php echo esc_attr( "{$option_key}[remote_repos][{$i}][owner]" ); ?>"
								value="<?php echo $owner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr'd ?>"
								placeholder="e.g. nvdigitalsolutions"
								class="regular-text" required />
						</td>
					</tr>
					<tr>
						<td style="font-weight:600; padding:4px 8px 4px 0;"><?php esc_html_e( 'Repository *', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<input type="text"
								name="<?php echo esc_attr( "{$option_key}[remote_repos][{$i}][repo]" ); ?>"
								value="<?php echo $repo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr'd ?>"
								placeholder="e.g. mcp-ai-wpoos"
								class="regular-text" required />
						</td>
					</tr>
					<tr>
						<td style="padding:4px 8px 4px 0;"><?php esc_html_e( 'Branch / Tag', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<input type="text"
								name="<?php echo esc_attr( "{$option_key}[remote_repos][{$i}][ref]" ); ?>"
								value="<?php echo $ref; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr'd ?>"
								placeholder="HEAD"
								class="regular-text" />
							<p class="description"><?php esc_html_e( 'Branch name, tag, or commit SHA. Default: HEAD (latest commit on default branch).', 'nvoos-docs-hub' ); ?></p>
						</td>
					</tr>
					<tr>
						<td style="padding:4px 8px 4px 0;"><?php esc_html_e( 'Label', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<input type="text"
								name="<?php echo esc_attr( "{$option_key}[remote_repos][{$i}][label]" ); ?>"
								value="<?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr'd ?>"
								placeholder="<?php esc_attr_e( 'e.g. My Plugin Docs', 'nvoos-docs-hub' ); ?>"
								class="regular-text" />
							<p class="description"><?php esc_html_e( 'Human-readable name shown in the sidebar.', 'nvoos-docs-hub' ); ?></p>
						</td>
					</tr>
					<tr>
						<td style="padding:4px 8px 4px 0;"><?php esc_html_e( 'Path prefix', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<input type="text"
								name="<?php echo esc_attr( "{$option_key}[remote_repos][{$i}][path]" ); ?>"
								value="<?php echo $path; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr'd ?>"
								placeholder="e.g. docs"
								class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional: restrict to a subdirectory (e.g. "docs"). Leave blank to index the whole repo.', 'nvoos-docs-hub' ); ?></p>
						</td>
					</tr>
					<tr>
						<td style="padding:4px 8px 4px 0;"><?php esc_html_e( 'Access Token', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<input type="password"
								name="<?php echo esc_attr( "{$option_key}[remote_repos][{$i}][token]" ); ?>"
								value=""
								placeholder="<?php echo $has_token ? esc_attr__( '(saved — enter new value to change)', 'nvoos-docs-hub' ) : esc_attr__( 'Optional GitHub PAT', 'nvoos-docs-hub' ); ?>"
								class="regular-text"
								autocomplete="new-password" />
							<p class="description">
								<?php esc_html_e( 'Optional GitHub Personal Access Token. Raises the rate limit from 60 to 5000 req/hr. Required for private repositories.', 'nvoos-docs-hub' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<td style="padding:4px 8px 4px 0; vertical-align:top;"><?php esc_html_e( 'File selection', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<?php $name_mode = "{$option_key}[remote_repos][{$i}][selection_mode]"; ?>
							<label style="display:block; margin-bottom:4px;">
								<input type="radio"
									name="<?php echo esc_attr( $name_mode ); ?>"
									value="all"
									<?php checked( $selection_mode, 'all' ); ?> />
								<?php esc_html_e( 'All Markdown / .txt files', 'nvoos-docs-hub' ); ?>
								<span class="description"><?php esc_html_e( '— index everything (with optional excludes below).', 'nvoos-docs-hub' ); ?></span>
							</label>
							<label style="display:block; margin-bottom:4px;">
								<input type="radio"
									name="<?php echo esc_attr( $name_mode ); ?>"
									value="prefix"
									<?php checked( $selection_mode, 'prefix' ); ?> />
								<?php esc_html_e( 'Path prefix only', 'nvoos-docs-hub' ); ?>
								<span class="description"><?php esc_html_e( '— restrict to the "Path prefix" field above.', 'nvoos-docs-hub' ); ?></span>
							</label>
							<label style="display:block; margin-bottom:4px;">
								<input type="radio"
									name="<?php echo esc_attr( $name_mode ); ?>"
									value="selected"
									<?php checked( $selection_mode, 'selected' ); ?> />
								<?php esc_html_e( 'Selected files / folders only', 'nvoos-docs-hub' ); ?>
								<span class="description"><?php esc_html_e( '— pick exactly which files to index.', 'nvoos-docs-hub' ); ?></span>
							</label>
						</td>
					</tr>
					<tr class="nvoos-dh-picker-row">
						<td style="padding:4px 8px 4px 0; vertical-align:top;"><?php esc_html_e( 'Browse files', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<button type="button"
								class="button nvoos-dh-browse-btn"
								data-row-index="<?php echo esc_attr( (string) $i ); ?>">
								<?php esc_html_e( 'Browse files in repo…', 'nvoos-docs-hub' ); ?>
							</button>
							<button type="button"
								class="button nvoos-dh-refresh-btn"
								data-row-index="<?php echo esc_attr( (string) $i ); ?>"
								title="<?php esc_attr_e( 'Bypass the 10-minute cache and re-fetch from GitHub', 'nvoos-docs-hub' ); ?>">
								<?php esc_html_e( 'Refresh', 'nvoos-docs-hub' ); ?>
							</button>
							<button type="button"
								class="button nvoos-dh-test-btn"
								data-row-index="<?php echo esc_attr( (string) $i ); ?>"
								title="<?php esc_attr_e( 'Verify the owner, repo, and ref are reachable without saving', 'nvoos-docs-hub' ); ?>">
								<?php esc_html_e( 'Test', 'nvoos-docs-hub' ); ?>
							</button>
							<span class="nvoos-dh-picker-status" style="margin-left:8px; color:#646970; font-style:italic;"></span>
							<div class="nvoos-dh-picker-tree"
								style="display:none; margin-top:8px; max-height:340px; overflow:auto; padding:8px; border:1px solid #ccd0d4; border-radius:4px; background:#fff;">
							</div>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'Tree results are cached for 10 minutes. Click "Refresh" to bypass the cache.', 'nvoos-docs-hub' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<td style="padding:4px 8px 4px 0; vertical-align:top;"><?php esc_html_e( 'Selected paths', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<textarea
								name="<?php echo esc_attr( "{$option_key}[remote_repos][{$i}][selected_paths]" ); ?>"
								class="large-text code nvoos-dh-selected-paths"
								rows="4"
								placeholder="docs/intro.md&#10;guides/&#10;README.md"><?php echo $selected_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_textarea'd ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One repo-relative path per line. Trailing "/" includes everything beneath that directory. Used only when "Selected files / folders only" is chosen above.', 'nvoos-docs-hub' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<td style="padding:4px 8px 4px 0; vertical-align:top;"><?php esc_html_e( 'Excluded paths', 'nvoos-docs-hub' ); ?></td>
						<td style="padding:4px 0;">
							<textarea
								name="<?php echo esc_attr( "{$option_key}[remote_repos][{$i}][excluded_paths]" ); ?>"
								class="large-text code"
								rows="3"
								placeholder="docs/internal/&#10;CHANGELOG.md"><?php echo $excluded_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_textarea'd ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Optional. One repo-relative path per line. Always applied — useful with "All Markdown / .txt files" mode.', 'nvoos-docs-hub' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<td></td>
						<td style="padding:4px 0;">
							<button type="button" class="button nvoos-dh-remove-repo" style="color:#a00;">
								<?php esc_html_e( '✕ Remove this repository', 'nvoos-docs-hub' ); ?>
							</button>
						</td>
					</tr>
				</table>
			</div>
				<?php
		endforeach;
		} catch ( \Throwable $e ) {
			echo '<div class="notice notice-error inline" style="margin:10px 0;"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: error message */
					__( 'Error rendering remote repository settings: %s', 'nvoos-docs-hub' ),
					$e->getMessage()
				)
			);
			echo '</p></div>';
			error_log(
				sprintf(
					'[NV oOS Docs Hub] render_remote_repos fatal: %s in %s:%d',
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
		}

		echo '</div><!-- #nvoos-dh-remote-repos-wrap -->';
		echo '<p>';
		echo '<button type="button" id="nvoos-dh-add-repo" class="button">' . esc_html__( '+ Add Repository', 'nvoos-docs-hub' ) . '</button>';
		echo '</p>';
		echo '<p class="description">';
		esc_html_e(
			'Fetched files are cached for 24 hours. Click "Rebuild Documentation Index" after adding or removing repos to refresh immediately.',
			'nvoos-docs-hub'
		);
		echo '</p>';

		// The repo-picker script is enqueued and localized in enqueue_admin_assets().
	}
}

// Initialize.
NV_oOS_Docs_Hub_Settings::init();
