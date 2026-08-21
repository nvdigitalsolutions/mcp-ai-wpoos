<?php
/**
 * Admin page for the OKF Bundle Manager.
 *
 * Provides a Skill-Manager-style screen for OKF knowledge bundles:
 * a bundles overview (create/rename/archive/delete/export/validate),
 * a per-bundle concept browser with trust badges, a CodeMirror concept
 * editor (save/soft-delete), ZIP import, and an on-demand conformance
 * report. All state-changing requests are nonce + manage_options gated.
 *
 * @package WP_MCP_AI
 * @since   1.1.62
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF Bundle Manager admin UI.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_OKF_Bundle_Manager_Admin_Page {

	const PAGE_SLUG    = 'wp-mcp-ai-okf-bundle-manager';
	const NONCE_ACTION = 'wp_mcp_ai_okf_bundle_manager';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers (all nonce + manage_options gated).
		add_action( 'wp_ajax_wp_mcp_ai_okf_bundle_create', array( $this, 'ajax_create_bundle' ) );
		add_action( 'wp_ajax_wp_mcp_ai_okf_bundle_rename', array( $this, 'ajax_rename_bundle' ) );
		add_action( 'wp_ajax_wp_mcp_ai_okf_bundle_archive', array( $this, 'ajax_archive_bundle' ) );
		add_action( 'wp_ajax_wp_mcp_ai_okf_bundle_delete', array( $this, 'ajax_delete_bundle' ) );
		add_action( 'wp_ajax_wp_mcp_ai_okf_bundle_import', array( $this, 'ajax_import_bundle' ) );
		add_action( 'wp_ajax_wp_mcp_ai_okf_bundle_save_concept', array( $this, 'ajax_save_concept' ) );
		add_action( 'wp_ajax_wp_mcp_ai_okf_bundle_delete_concept', array( $this, 'ajax_delete_concept' ) );

		// Export streams the generated ZIP (admin-post, nonce + manage_options).
		add_action( 'admin_post_wp_mcp_ai_okf_bundle_export', array( $this, 'handle_export_request' ) );
	}

	/**
	 * Register the admin submenu page under the assistant CPT.
	 *
	 * @return void
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=mcp_ai_assistant',
			__( 'OKF Bundle Manager', 'mcp-ai-wpoos' ),
			__( 'OKF Bundles', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue page assets (CodeMirror + scoped inline CSS/JS).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		// Code editor (CodeMirror) — bundled with WordPress core since 4.9.
		wp_enqueue_code_editor( array( 'type' => 'text/x-markdown' ) );

		$inline_css = '
			.wp-mcp-ai-okf-bundle-manager { max-width: 1200px; }
			.wp-mcp-ai-okf-bundle-manager .okf-badge { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: #e7f3ff; color: #0073aa; }
			.wp-mcp-ai-okf-bundle-manager .okf-badge.protected { background: #fcf0f1; color: #d63638; }
			.wp-mcp-ai-okf-bundle-manager .okf-badge.stale { background: #fef7e0; color: #8b6c00; }
			.wp-mcp-ai-okf-bundle-manager .okf-badge.deprecated { background: #f0f0f1; color: #646970; }
			.wp-mcp-ai-okf-bundle-manager .okf-badge.human-reviewed { background: #d5f0db; color: #0a5f1a; }
			.wp-mcp-ai-okf-bundle-manager .okf-badge.machine-confirmed { background: #e7f3ff; color: #0073aa; }
			.wp-mcp-ai-okf-bundle-manager .okf-create-form { background: #f9f9f9; border: 1px solid #dcdcde; padding: 16px; margin: 12px 0 20px; border-radius: 3px; }
			.wp-mcp-ai-okf-bundle-manager .okf-create-form label { display: block; font-weight: 600; margin-bottom: 6px; }
			.wp-mcp-ai-okf-bundle-manager .okf-notice { margin: 10px 0; padding: 8px 12px; border-left: 4px solid #46b450; background: #f0fff0; }
			.wp-mcp-ai-okf-bundle-manager .okf-notice.error { border-color: #dc3232; background: #fff0f0; }
			.wp-mcp-ai-okf-bundle-manager .okf-breadcrumb { margin: 12px 0; }
			.wp-mcp-ai-okf-bundle-manager .okf-breadcrumb a { text-decoration: none; }
			.wp-mcp-ai-okf-bundle-manager #okf-concept-editor { width: 100%; min-height: 420px; font-family: monospace; font-size: 13px; }
			.wp-mcp-ai-okf-bundle-manager .okf-editor-actions { margin-top: 12px; }
			.wp-mcp-ai-okf-bundle-manager .okf-trust-list { margin: 0; }
			.wp-mcp-ai-okf-bundle-manager .okf-trust-list li { margin: 4px 0; }
		';

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline style registered with no URL; version not applicable.
		wp_register_style( 'wp-mcp-ai-okf-bundle-manager', false );
		wp_enqueue_style( 'wp-mcp-ai-okf-bundle-manager' );
		wp_add_inline_style( 'wp-mcp-ai-okf-bundle-manager', $inline_css );

		$inline_js = $this->build_inline_js();

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline script registered with no URL; version fixed for cache busting.
		wp_register_script( 'wp-mcp-ai-okf-bundle-manager', false, array(), '1.1.62', true );
		wp_enqueue_script( 'wp-mcp-ai-okf-bundle-manager' );
		wp_add_inline_script( 'wp-mcp-ai-okf-bundle-manager', $inline_js );
	}

	/**
	 * Build the inline JS payload for the page.
	 *
	 * @return string
	 */
	private function build_inline_js() {
		return "
			( function () {
				var config = {
					nonce: '" . esc_js( wp_create_nonce( self::NONCE_ACTION ) ) . "',
					ajaxUrl: '" . esc_js( admin_url( 'admin-ajax.php' ) ) . "',
					pageUrl: '" . esc_js( $this->get_page_url() ) . "'
				};

				function okfPost( action, data ) {
					var body = new FormData();
					body.append( 'action', action );
					body.append( 'nonce', config.nonce );
					Object.keys( data ).forEach( function ( key ) { body.append( key, data[ key ] ); } );
					return fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } );
				}

				function okfReload() {
					window.location.href = config.pageUrl;
				}

				function okfFail( res ) {
					var message = res && res.data && res.data.message ? res.data.message : 'Request failed.';
					window.alert( message );
				}

				// Bundles tab: create form.
				var createForm = document.getElementById( 'okf-create-bundle-form' );
				if ( createForm ) {
					createForm.addEventListener( 'submit', function ( event ) {
						event.preventDefault();
						var name = document.getElementById( 'okf-new-bundle-name' ).value;
						okfPost( 'wp_mcp_ai_okf_bundle_create', { bundle: name } )
							.then( function ( res ) { res.success ? okfReload() : okfFail( res ); } );
					} );
				}

				// Bundles tab: rename / archive / delete buttons.
				document.querySelectorAll( '[data-okf-action]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function ( event ) {
						event.preventDefault();
						var action = button.getAttribute( 'data-okf-action' );
						var bundle = button.getAttribute( 'data-okf-bundle' );

						if ( 'rename' === action ) {
							var to = window.prompt( button.getAttribute( 'data-okf-prompt' ) || '', bundle );
							if ( ! to ) { return; }
							okfPost( 'wp_mcp_ai_okf_bundle_rename', { from: bundle, to: to } )
								.then( function ( res ) { res.success ? okfReload() : okfFail( res ); } );
							return;
						}

						var confirmText = 'archive' === action
							? button.getAttribute( 'data-okf-prompt' ) || 'Archive this bundle?'
							: button.getAttribute( 'data-okf-prompt' ) || 'Delete this bundle permanently? This cannot be undone.';
						if ( ! window.confirm( confirmText ) ) { return; }

						var ajaxAction = 'archive' === action ? 'wp_mcp_ai_okf_bundle_archive' : 'wp_mcp_ai_okf_bundle_delete';
						okfPost( ajaxAction, { bundle: bundle } )
							.then( function ( res ) { res.success ? okfReload() : okfFail( res ); } );
					} );
				} );

				// Import tab: ZIP upload form.
				var importForm = document.getElementById( 'okf-import-bundle-form' );
				if ( importForm ) {
					importForm.addEventListener( 'submit', function ( event ) {
						event.preventDefault();
						var body = new FormData( importForm );
						body.append( 'action', 'wp_mcp_ai_okf_bundle_import' );
						body.append( 'nonce', config.nonce );
						fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( res ) { res.success ? okfReload() : okfFail( res ); } );
					} );
				}

				// Import tab: enrichment form (Pro — rendered only when the agent exists).
				var enrichForm = document.getElementById( 'okf-enrich-form' );
				if ( enrichForm ) {
					enrichForm.addEventListener( 'submit', function ( event ) {
						event.preventDefault();
						var body = new FormData( enrichForm );
						body.append( 'action', 'wp_mcp_ai_okf_bundle_enrich' );
						body.append( 'nonce', config.nonce );
						fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( res ) { res.success ? okfReload() : okfFail( res ); } );
					} );
				}

				// Editor tab: CodeMirror + save/delete.
				var editorArea = document.getElementById( 'okf-concept-editor' );
				if ( editorArea && window.wp && window.wp.codeEditor ) {
					var cm = window.wp.codeEditor.initialize( editorArea, {} ).codemirror;

					var saveButton = document.getElementById( 'okf-concept-save' );
					if ( saveButton ) {
						saveButton.addEventListener( 'click', function () {
							okfPost( 'wp_mcp_ai_okf_bundle_save_concept', {
								bundle: saveButton.getAttribute( 'data-okf-bundle' ),
								concept_id: saveButton.getAttribute( 'data-okf-concept' ),
								content: cm.getValue()
							} ).then( function ( res ) { res.success ? okfReload() : okfFail( res ); } );
						} );
					}

					var deleteButton = document.getElementById( 'okf-concept-delete' );
					if ( deleteButton ) {
						deleteButton.addEventListener( 'click', function () {
							if ( ! window.confirm( deleteButton.getAttribute( 'data-okf-prompt' ) ) ) { return; }
							okfPost( 'wp_mcp_ai_okf_bundle_delete_concept', {
								bundle: deleteButton.getAttribute( 'data-okf-bundle' ),
								concept_id: deleteButton.getAttribute( 'data-okf-concept' )
							} ).then( function ( res ) { res.success ? okfReload() : okfFail( res ); } );
						} );
					}
				}

				// Browser tab: new concept form.
				var newConceptForm = document.getElementById( 'okf-new-concept-form' );
				if ( newConceptForm ) {
					newConceptForm.addEventListener( 'submit', function ( event ) {
						event.preventDefault();
						var bundle = newConceptForm.getAttribute( 'data-okf-bundle' );
						var conceptId = document.getElementById( 'okf-new-concept-id' ).value;
						var type = document.getElementById( 'okf-new-concept-type' ).value;
						var title = document.getElementById( 'okf-new-concept-title' ).value;
						var content = '---\\ntype: ' + type + ( title ? '\\ntitle: ' + title : '' ) + '\\n---\\n\\n';
						okfPost( 'wp_mcp_ai_okf_bundle_save_concept', { bundle: bundle, concept_id: conceptId, content: content } )
							.then( function ( res ) {
								if ( ! res.success ) { okfFail( res ); return; }
								window.location.href = config.pageUrl + '&tab=editor&bundle=' + encodeURIComponent( bundle ) + '&concept=' + encodeURIComponent( conceptId );
							} );
					} );
				}
			}() );
		";
	}

	/**
	 * Build the base admin URL for this page.
	 *
	 * @return string
	 */
	private function get_page_url() {
		return admin_url( 'edit.php?post_type=mcp_ai_assistant&page=' . self::PAGE_SLUG );
	}

	/**
	 * Build a page URL with query arguments.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private function get_page_url_with( array $args ) {
		return add_query_arg( $args, $this->get_page_url() );
	}

	/**
	 * Render the full admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage OKF bundles.', 'mcp-ai-wpoos' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab/bundle routing; no state change.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'bundles';
		$valid_tabs = array( 'bundles', 'browser', 'editor', 'import-export', 'validate' );
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'bundles';
		}

		echo '<div class="wrap wp-mcp-ai-okf-bundle-manager">';
		echo '<h1>' . esc_html__( 'OKF Bundle Manager', 'mcp-ai-wpoos' ) . '</h1>';

		$tabs = array(
			'bundles'       => __( 'Bundles', 'mcp-ai-wpoos' ),
			'browser'       => __( 'Browser', 'mcp-ai-wpoos' ),
			'editor'        => __( 'Editor', 'mcp-ai-wpoos' ),
			'import-export' => __( 'Import / Export', 'mcp-ai-wpoos' ),
			'validate'      => __( 'Validate', 'mcp-ai-wpoos' ),
		);

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $tab_key => $tab_label ) {
			$url = $this->get_page_url_with( array( 'tab' => $tab_key ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
			if ( isset( $_GET['bundle'] ) && in_array( $tab_key, array( 'browser', 'editor', 'validate' ), true ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
				$url = add_query_arg( 'bundle', sanitize_text_field( wp_unslash( $_GET['bundle'] ) ), $url );
			}
			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url( $url ),
				$active_tab === $tab_key ? 'nav-tab-active' : '',
				esc_html( $tab_label )
			);
		}
		echo '</nav>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice passthrough.
		if ( isset( $_GET['okf_error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="okf-notice error">' . esc_html( sanitize_text_field( wp_unslash( $_GET['okf_error'] ) ) ) . '</div>';
		}

		switch ( $active_tab ) {
			case 'browser':
				$this->render_tab_browser();
				break;
			case 'editor':
				$this->render_tab_editor();
				break;
			case 'import-export':
				$this->render_tab_import_export();
				break;
			case 'validate':
				$this->render_tab_validate();
				break;
			default:
				$this->render_tab_bundles();
		}

		echo '</div>';
	}

	/**
	 * Render the Bundles overview tab.
	 *
	 * @return void
	 */
	private function render_tab_bundles() {
		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundles = $manager->list_bundles();

		echo '<div class="okf-create-form">';
		echo '<form id="okf-create-bundle-form" method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<label for="okf-new-bundle-name">' . esc_html__( 'Create a new bundle', 'mcp-ai-wpoos' ) . '</label>';
		echo '<input type="text" id="okf-new-bundle-name" name="bundle" class="regular-text" pattern="[a-z0-9][a-z0-9_-]{0,99}" required /> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Create bundle', 'mcp-ai-wpoos' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Lowercase letters, numbers, hyphens, and underscores. A root index.md (okf_version 0.2) and log.md are created automatically.', 'mcp-ai-wpoos' ) . '</p>';
		echo '</form>';
		echo '</div>';

		if ( is_wp_error( $bundles ) ) {
			echo '<div class="okf-notice error">' . esc_html( $bundles->get_error_message() ) . '</div>';
			return;
		}

		if ( empty( $bundles ) ) {
			echo '<p>' . esc_html__( 'No OKF bundles exist yet. Create one above, or import a ZIP in the Import / Export tab.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Bundle', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th>' . esc_html__( 'Concepts', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th>' . esc_html__( 'Stale / Deprecated', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th>' . esc_html__( 'Trust', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th>' . esc_html__( 'Conformant', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mcp-ai-wpoos' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $bundles as $bundle ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $bundle['name'] ) . '</strong>';
			if ( $bundle['protected'] ) {
				echo ' <span class="okf-badge protected">' . esc_html__( 'auto-generated', 'mcp-ai-wpoos' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( (string) $bundle['concept_count'] ) . '</td>';
			echo '<td>';
			if ( $bundle['stale_count'] > 0 ) {
				echo '<span class="okf-badge stale">' . esc_html( (string) $bundle['stale_count'] ) . ' ' . esc_html__( 'stale', 'mcp-ai-wpoos' ) . '</span> ';
			}
			if ( $bundle['deprecated_count'] > 0 ) {
				echo '<span class="okf-badge deprecated">' . esc_html( (string) $bundle['deprecated_count'] ) . ' ' . esc_html__( 'deprecated', 'mcp-ai-wpoos' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $this->format_trust_tiers( $bundle['trust_tiers'] ) ) . '</td>';
			echo '<td>' . ( $bundle['conformant'] ? '✅' : '⚠️' ) . '</td>';
			echo '<td class="okf-actions">';

			echo '<a class="button button-small" href="' . esc_url(
				$this->get_page_url_with(
					array(
						'tab'    => 'browser',
						'bundle' => $bundle['name'],
					)
				)
			) . '">' . esc_html__( 'Browse', 'mcp-ai-wpoos' ) . '</a> ';
			echo '<a class="button button-small" href="' . esc_url(
				$this->get_page_url_with(
					array(
						'tab'    => 'validate',
						'bundle' => $bundle['name'],
					)
				)
			) . '">' . esc_html__( 'Validate', 'mcp-ai-wpoos' ) . '</a> ';

			// Export (admin-post form streams the ZIP).
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="wp_mcp_ai_okf_bundle_export" />';
			echo '<input type="hidden" name="bundle" value="' . esc_attr( $bundle['name'] ) . '" />';
			wp_nonce_field( self::NONCE_ACTION );
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Export ZIP', 'mcp-ai-wpoos' ) . '</button>';
			echo '</form> ';

			if ( ! $bundle['protected'] ) {
				echo '<button type="button" class="button button-small" data-okf-action="rename" data-okf-bundle="' . esc_attr( $bundle['name'] ) . '" data-okf-prompt="' . esc_attr__( 'New bundle name:', 'mcp-ai-wpoos' ) . '">' . esc_html__( 'Rename', 'mcp-ai-wpoos' ) . '</button> ';
				echo '<button type="button" class="button button-small" data-okf-action="archive" data-okf-bundle="' . esc_attr( $bundle['name'] ) . '" data-okf-prompt="' . esc_attr__( 'Archive this bundle? It moves to .trash and can be restored manually.', 'mcp-ai-wpoos' ) . '">' . esc_html__( 'Archive', 'mcp-ai-wpoos' ) . '</button> ';
				echo '<button type="button" class="button button-small button-link-delete" data-okf-action="delete" data-okf-bundle="' . esc_attr( $bundle['name'] ) . '" data-okf-prompt="' . esc_attr__( 'Delete this bundle permanently? This cannot be undone.', 'mcp-ai-wpoos' ) . '">' . esc_html__( 'Delete', 'mcp-ai-wpoos' ) . '</button>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Format a trust-tier histogram for display.
	 *
	 * @param array $trust_tiers Trust-tier counts.
	 * @return string
	 */
	private function format_trust_tiers( array $trust_tiers ) {
		$parts = array();

		$labels = array(
			'human-reviewed'    => __( 'Human-reviewed', 'mcp-ai-wpoos' ),
			'machine-confirmed' => __( 'Machine-confirmed', 'mcp-ai-wpoos' ),
			'unverified'        => __( 'Unverified', 'mcp-ai-wpoos' ),
		);

		foreach ( $labels as $tier => $label ) {
			$count = isset( $trust_tiers[ $tier ] ) ? (int) $trust_tiers[ $tier ] : 0;
			if ( $count > 0 ) {
				$parts[] = $count . ' ' . $label;
			}
		}

		return $parts ? implode( ' · ', $parts ) : '—';
	}

	/**
	 * Resolve the current bundle/path from GET params for the browser tab.
	 *
	 * @return array{0: string, 1: string} Bundle name and path.
	 */
	private function get_browser_params() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$bundle = isset( $_GET['bundle'] ) ? sanitize_text_field( wp_unslash( $_GET['bundle'] ) ) : '';
		$path   = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		// phpcs:enable

		return array( $bundle, $path );
	}

	/**
	 * Render the Browser tab (concept tree of one bundle).
	 *
	 * @return void
	 */
	private function render_tab_browser() {
		list( $bundle, $path ) = $this->get_browser_params();

		if ( '' === $bundle ) {
			echo '<p>' . esc_html__( 'Choose a bundle from the Bundles tab to browse its concepts.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$root    = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $root ) ) {
			echo '<div class="okf-notice error">' . esc_html( $root->get_error_message() ) . '</div>';
			return;
		}

		// Breadcrumb.
		echo '<p class="okf-breadcrumb">';
		echo '<a href="' . esc_url(
			$this->get_page_url_with(
				array(
					'tab'    => 'browser',
					'bundle' => $bundle,
				)
			)
		) . '">' . esc_html( $bundle ) . '</a>';
		$segments = array_filter( explode( '/', trim( $path, '/' ) ) );
		$acc      = '';
		foreach ( $segments as $segment ) {
			$acc = '' === $acc ? $segment : $acc . '/' . $segment;
			echo ' / <a href="' . esc_url(
				$this->get_page_url_with(
					array(
						'tab'    => 'browser',
						'bundle' => $bundle,
						'path'   => $acc,
					)
				)
			) . '">' . esc_html( $segment ) . '</a>';
		}
		echo '</p>';

		$reader  = new WP_MCP_AI_OKF_Reader( $root );
		$entries = $reader->browse( $path );
		if ( is_wp_error( $entries ) ) {
			echo '<div class="okf-notice error">' . esc_html( $entries->get_error_message() ) . '</div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr><th>' . esc_html__( 'Entry', 'mcp-ai-wpoos' ) . '</th><th>' . esc_html__( 'Description', 'mcp-ai-wpoos' ) . '</th><th>' . esc_html__( 'Trust', 'mcp-ai-wpoos' ) . '</th></tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$is_dir     = '/' === substr( $entry['path'], -1 );
			$concept_id = $is_dir ? '' : preg_replace( '/\.md$/', '', $entry['path'] );

			echo '<tr>';
			if ( $is_dir ) {
				$sub_path = trim( $entry['path'], '/' );
				echo '<td><a href="' . esc_url(
					$this->get_page_url_with(
						array(
							'tab'    => 'browser',
							'bundle' => $bundle,
							'path'   => $sub_path,
						)
					)
				) . '">📁 ' . esc_html( $entry['title'] ) . '</a></td>';
			} else {
				echo '<td><a href="' . esc_url(
					$this->get_page_url_with(
						array(
							'tab'     => 'editor',
							'bundle'  => $bundle,
							'concept' => $concept_id,
						)
					)
				) . '">📄 ' . esc_html( $entry['title'] ) . '</a></td>';
			}
			echo '<td>' . esc_html( $entry['description'] ) . '</td>';
			echo '<td>';

			if ( ! $is_dir ) {
				$concept = $reader->get_concept( $concept_id );
				if ( ! is_wp_error( $concept ) ) {
					$fm   = $concept['frontmatter'];
					$tier = $reader->get_trust_tier( $fm );
					echo '<span class="okf-badge ' . esc_attr( $tier ) . '">' . esc_html( $tier ) . '</span> ';
					if ( $reader->is_stale( $fm ) ) {
						echo '<span class="okf-badge stale">' . esc_html__( 'stale', 'mcp-ai-wpoos' ) . '</span> ';
					}
					if ( isset( $fm['status'] ) && 'deprecated' === strtolower( (string) $fm['status'] ) ) {
						echo '<span class="okf-badge deprecated">' . esc_html__( 'deprecated', 'mcp-ai-wpoos' ) . '</span>';
					}
				}
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $manager->is_protected_bundle( $bundle ) ) {
			echo '<p class="description">' . esc_html__( 'This bundle is auto-generated and read-only here.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		echo '<div class="okf-create-form">';
		echo '<form id="okf-new-concept-form" method="post" data-okf-bundle="' . esc_attr( $bundle ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<strong>' . esc_html__( 'New concept', 'mcp-ai-wpoos' ) . '</strong><br />';
		echo '<input type="text" id="okf-new-concept-id" placeholder="' . esc_attr__( 'concept-id (e.g. policies/refunds)', 'mcp-ai-wpoos' ) . '" class="regular-text" required /> ';
		echo '<input type="text" id="okf-new-concept-type" placeholder="' . esc_attr__( 'type (e.g. Policy)', 'mcp-ai-wpoos' ) . '" required /> ';
		echo '<input type="text" id="okf-new-concept-title" placeholder="' . esc_attr__( 'title (optional)', 'mcp-ai-wpoos' ) . '" /> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Create & edit', 'mcp-ai-wpoos' ) . '</button>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the Editor tab (CodeMirror over a concept file).
	 *
	 * @return void
	 */
	private function render_tab_editor() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$bundle  = isset( $_GET['bundle'] ) ? sanitize_text_field( wp_unslash( $_GET['bundle'] ) ) : '';
		$concept = isset( $_GET['concept'] ) ? sanitize_text_field( wp_unslash( $_GET['concept'] ) ) : '';
		// phpcs:enable

		if ( '' === $bundle || '' === $concept ) {
			echo '<p>' . esc_html__( 'Choose a concept from the Browser tab to edit it.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$root    = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $root ) ) {
			echo '<div class="okf-notice error">' . esc_html( $root->get_error_message() ) . '</div>';
			return;
		}

		$reserved = in_array( basename( $concept ), array( 'index', 'log' ), true );
		$file     = wp_normalize_path( $root . '/' . ltrim( $concept, '/' ) . '.md' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local OKF bundle file read.
		$content = file_exists( $file ) ? file_get_contents( $file ) : false;
		if ( false === $content ) {
			echo '<div class="okf-notice error">' . esc_html__( 'Concept not found.', 'mcp-ai-wpoos' ) . '</div>';
			return;
		}

		$back_url = $this->get_page_url_with(
			array(
				'tab'    => 'browser',
				'bundle' => $bundle,
			)
		);
		echo '<p><a href="' . esc_url( $back_url ) . '">← ' . esc_html__( 'Back to browser', 'mcp-ai-wpoos' ) . '</a></p>';
		echo '<h2>' . esc_html( $bundle . ' / ' . $concept . '.md' ) . '</h2>';

		if ( $reserved || $manager->is_protected_bundle( $bundle ) ) {
			$read_only_note = $reserved
				? __( 'index.md and log.md are reserved files (OKF v0.2 §3.1, §8–§9) and are read-only here.', 'mcp-ai-wpoos' )
				: __( 'This bundle is auto-generated and read-only here.', 'mcp-ai-wpoos' );
			echo '<div class="okf-notice">' . esc_html( $read_only_note ) . '</div>';
		}

		echo '<textarea id="okf-concept-editor" name="content" ' . ( ( $reserved || $manager->is_protected_bundle( $bundle ) ) ? 'readonly' : '' ) . '>' . esc_textarea( $content ) . '</textarea>';

		if ( ! $reserved && ! $manager->is_protected_bundle( $bundle ) ) {
			echo '<p class="okf-editor-actions">';
			echo '<button type="button" id="okf-concept-save" class="button button-primary" data-okf-bundle="' . esc_attr( $bundle ) . '" data-okf-concept="' . esc_attr( $concept ) . '">' . esc_html__( 'Save concept', 'mcp-ai-wpoos' ) . '</button> ';
			echo '<button type="button" id="okf-concept-delete" class="button button-link-delete" data-okf-bundle="' . esc_attr( $bundle ) . '" data-okf-concept="' . esc_attr( $concept ) . '" data-okf-prompt="' . esc_attr__( 'Archive this concept? It is renamed with a .deleted timestamp and can be restored manually.', 'mcp-ai-wpoos' ) . '">' . esc_html__( 'Delete concept', 'mcp-ai-wpoos' ) . '</button>';
			echo '</p>';
		}
	}

	/**
	 * Render the Import / Export tab.
	 *
	 * @return void
	 */
	private function render_tab_import_export() {
		$manager = new WP_MCP_AI_OKF_Bundle_Manager();

		echo '<h2>' . esc_html__( 'Import a bundle (ZIP)', 'mcp-ai-wpoos' ) . '</h2>';
		echo '<div class="okf-create-form">';
		echo '<form id="okf-import-bundle-form" method="post" enctype="multipart/form-data">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<label for="okf-import-zip">' . esc_html__( 'OKF bundle archive (.zip)', 'mcp-ai-wpoos' ) . '</label>';
		echo '<input type="file" id="okf-import-zip" name="zip_file" accept=".zip" required /> ';
		echo '<input type="text" name="bundle" placeholder="' . esc_attr__( 'new-bundle-name', 'mcp-ai-wpoos' ) . '" pattern="[a-z0-9][a-z0-9_-]{0,99}" required /> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Import', 'mcp-ai-wpoos' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'The archive must contain at least one concept document and may not contain symbolic links or unsafe paths. The target bundle must not already exist.', 'mcp-ai-wpoos' ) . '</p>';
		echo '</form>';
		echo '</div>';

		$bundles = $manager->list_bundles();
		if ( is_wp_error( $bundles ) ) {
			echo '<div class="okf-notice error">' . esc_html( $bundles->get_error_message() ) . '</div>';
			return;
		}

		// Pro enrichment section — rendered only when the Pro agent exists.
		if ( class_exists( 'WP_MCP_AI_OKF_Enrichment_Agent' ) ) {
			echo '<h2>' . esc_html__( 'Enrich from site content', 'mcp-ai-wpoos' ) . '</h2>';
			echo '<div class="okf-create-form">';
			echo '<form id="okf-enrich-form" method="post">';
			wp_nonce_field( self::NONCE_ACTION );
			echo '<label for="okf-enrich-bundle">' . esc_html__( 'Target bundle', 'mcp-ai-wpoos' ) . '</label>';
			echo '<input type="text" id="okf-enrich-bundle" name="bundle" value="site-content" pattern="[a-z0-9][a-z0-9_-]{0,99}" required /> ';
			echo '<label for="okf-enrich-limit">' . esc_html__( 'Limit', 'mcp-ai-wpoos' ) . '</label>';
			echo '<input type="number" id="okf-enrich-limit" name="limit" value="50" min="1" max="200" /> ';
			echo '<label><input type="checkbox" name="post_types[]" value="post" checked /> ' . esc_html__( 'Posts', 'mcp-ai-wpoos' ) . '</label> ';
			echo '<label><input type="checkbox" name="post_types[]" value="page" checked /> ' . esc_html__( 'Pages', 'mcp-ai-wpoos' ) . '</label> ';
			echo '<label><input type="checkbox" name="include_terms" value="1" /> ' . esc_html__( 'Taxonomy terms', 'mcp-ai-wpoos' ) . '</label> ';
			echo '<label><input type="checkbox" name="omit_content" value="1" /> ' . esc_html__( 'Omit content body', 'mcp-ai-wpoos' ) . '</label> ';
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Run enrichment', 'mcp-ai-wpoos' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'Generates OKF concepts (with cross-links) from published content into the target bundle. Deterministic and idempotent — re-running refreshes the same concepts.', 'mcp-ai-wpoos' ) . '</p>';
			echo '</form>';
			echo '</div>';
		}

		echo '<h2>' . esc_html__( 'Export a bundle', 'mcp-ai-wpoos' ) . '</h2>';

		echo '<table class="widefat striped">';
		echo '<thead><tr><th>' . esc_html__( 'Bundle', 'mcp-ai-wpoos' ) . '</th><th>' . esc_html__( 'Download', 'mcp-ai-wpoos' ) . '</th></tr></thead><tbody>';
		foreach ( $bundles as $bundle ) {
			echo '<tr><td>' . esc_html( $bundle['name'] ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="wp_mcp_ai_okf_bundle_export" />';
			echo '<input type="hidden" name="bundle" value="' . esc_attr( $bundle['name'] ) . '" />';
			wp_nonce_field( self::NONCE_ACTION );
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Download ZIP', 'mcp-ai-wpoos' ) . '</button>';
			echo '</form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the Validate tab.
	 *
	 * @return void
	 */
	private function render_tab_validate() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$bundle = isset( $_GET['bundle'] ) ? sanitize_text_field( wp_unslash( $_GET['bundle'] ) ) : '';

		if ( '' === $bundle ) {
			echo '<p>' . esc_html__( 'Choose a bundle from the Bundles tab to validate it.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$root    = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $root ) ) {
			echo '<div class="okf-notice error">' . esc_html( $root->get_error_message() ) . '</div>';
			return;
		}

		$writer = new WP_MCP_AI_OKF_Writer( $root );
		$report = $writer->validate_bundle();

		echo '<h2>' . esc_html( $bundle ) . '</h2>';
		echo '<ul class="okf-trust-list">';
		echo '<li>' . esc_html__( 'Conformant:', 'mcp-ai-wpoos' ) . ' <strong>' . ( $report['conformant'] ? esc_html__( 'yes', 'mcp-ai-wpoos' ) : esc_html__( 'no', 'mcp-ai-wpoos' ) ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Concepts:', 'mcp-ai-wpoos' ) . ' <strong>' . esc_html( (string) $report['concept_count'] ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Stale:', 'mcp-ai-wpoos' ) . ' <strong>' . esc_html( (string) $report['stale_count'] ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Deprecated:', 'mcp-ai-wpoos' ) . ' <strong>' . esc_html( (string) $report['deprecated_count'] ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Broken links:', 'mcp-ai-wpoos' ) . ' <strong>' . esc_html( (string) count( $report['broken_links'] ) ) . '</strong></li>';
		echo '</ul>';

		// Trust-tier histogram (OKF v0.2 §5.3).
		$reader      = new WP_MCP_AI_OKF_Reader( $root );
		$trust_tiers = array(
			'human-reviewed'    => 0,
			'machine-confirmed' => 0,
			'unverified'        => 0,
		);
		foreach ( $reader->search( array() ) as $concept ) {
			$tier = isset( $concept['trust_tier'] ) ? $concept['trust_tier'] : 'unverified';
			if ( isset( $trust_tiers[ $tier ] ) ) {
				++$trust_tiers[ $tier ];
			}
		}
		echo '<h3>' . esc_html__( 'Trust tiers', 'mcp-ai-wpoos' ) . '</h3>';
		echo '<ul class="okf-trust-list">';
		$tier_labels = array(
			'human-reviewed'    => __( 'Human-reviewed', 'mcp-ai-wpoos' ),
			'machine-confirmed' => __( 'Machine-confirmed', 'mcp-ai-wpoos' ),
			'unverified'        => __( 'Unverified', 'mcp-ai-wpoos' ),
		);
		foreach ( $tier_labels as $tier => $label ) {
			echo '<li>' . esc_html( $label ) . ': <strong>' . esc_html( (string) $trust_tiers[ $tier ] ) . '</strong></li>';
		}
		echo '</ul>';

		if ( ! empty( $report['broken_links'] ) ) {
			echo '<h3>' . esc_html__( 'Broken cross-links (advisory — OKF v0.2 §6.1)', 'mcp-ai-wpoos' ) . '</h3>';
			echo '<ul>';
			foreach ( $report['broken_links'] as $broken ) {
				echo '<li>';
				printf(
					/* translators: 1: concept ID, 2: link target */
					esc_html__( 'Concept "%1$s" links to missing concept "%2$s".', 'mcp-ai-wpoos' ),
					esc_html( $broken['concept_id'] ),
					esc_html( $broken['target'] )
				);
				echo '</li>';
			}
			echo '</ul>';
		}

		if ( empty( $report['issues'] ) ) {
			echo '<div class="okf-notice">' . esc_html__( 'No issues found. Per the OKF spec, issues are advisory and never block reading.', 'mcp-ai-wpoos' ) . '</div>';
			return;
		}

		echo '<h3>' . esc_html__( 'Advisory issues', 'mcp-ai-wpoos' ) . '</h3>';
		echo '<ul>';
		foreach ( $report['issues'] as $issue ) {
			echo '<li>' . esc_html( $issue ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Verify a state-changing request: nonce + manage_options.
	 *
	 * @return bool True when the request is authorized.
	 */
	private function verify_request() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'mcp-ai-wpoos' ) ), 403 );
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ), 403 );
			return false;
		}

		return true;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every handler below verifies the nonce first (verify_request() / check_admin_referer()).

	/**
	 * AJAX: create a bundle.
	 *
	 * @return void
	 */
	public function ajax_create_bundle() {
		if ( ! $this->verify_request() ) {
			return;
		}

		$bundle = isset( $_POST['bundle'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle'] ) ) : '';

		$result = ( new WP_MCP_AI_OKF_Bundle_Manager() )->create_bundle( $bundle );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Bundle created.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * AJAX: rename a bundle.
	 *
	 * @return void
	 */
	public function ajax_rename_bundle() {
		if ( ! $this->verify_request() ) {
			return;
		}

		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';

		$result = ( new WP_MCP_AI_OKF_Bundle_Manager() )->rename_bundle( $from, $to );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Bundle renamed.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * AJAX: archive a bundle.
	 *
	 * @return void
	 */
	public function ajax_archive_bundle() {
		if ( ! $this->verify_request() ) {
			return;
		}

		$bundle = isset( $_POST['bundle'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle'] ) ) : '';

		$result = ( new WP_MCP_AI_OKF_Bundle_Manager() )->archive_bundle( $bundle );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Bundle archived.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * AJAX: delete a bundle.
	 *
	 * @return void
	 */
	public function ajax_delete_bundle() {
		if ( ! $this->verify_request() ) {
			return;
		}

		$bundle = isset( $_POST['bundle'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle'] ) ) : '';

		$result = ( new WP_MCP_AI_OKF_Bundle_Manager() )->delete_bundle( $bundle );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Bundle deleted.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * AJAX: import a bundle from an uploaded ZIP.
	 *
	 * @return void
	 */
	public function ajax_import_bundle() {
		if ( ! $this->verify_request() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES entry; validated below.
		$upload = isset( $_FILES['zip_file'] ) ? $_FILES['zip_file'] : null;
		$bundle = isset( $_POST['bundle'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle'] ) ) : '';

		if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== (int) $upload['error'] ) {
			wp_send_json_error( array( 'message' => __( 'File upload failed.', 'mcp-ai-wpoos' ) ) );
		}

		if ( empty( $upload['tmp_name'] ) || ! file_exists( $upload['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing uploaded file.', 'mcp-ai-wpoos' ) ) );
		}

		if ( $upload['size'] > WP_MCP_AI_OKF_Bundle_Manager::MAX_ZIP_TOTAL_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'The ZIP archive is too large.', 'mcp-ai-wpoos' ) ) );
		}

		if ( '.zip' !== strtolower( substr( (string) $upload['name'], -4 ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Only .zip archives are accepted.', 'mcp-ai-wpoos' ) ) );
		}

		$result = ( new WP_MCP_AI_OKF_Bundle_Manager() )->import_bundle_zip( (string) $upload['tmp_name'], $bundle );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_delete -- Uploaded temp file cleanup.
		wp_delete_file( (string) $upload['tmp_name'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Bundle imported.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * AJAX: save a concept's raw content (editor).
	 *
	 * @return void
	 */
	public function ajax_save_concept() {
		if ( ! $this->verify_request() ) {
			return;
		}

		$bundle  = isset( $_POST['bundle'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle'] ) ) : '';
		$concept = isset( $_POST['concept_id'] ) ? sanitize_text_field( wp_unslash( $_POST['concept_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw markdown file content; parsed and validated by save_concept_raw() before writing.
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';

		$result = ( new WP_MCP_AI_OKF_Bundle_Manager() )->save_concept_raw( $bundle, $concept, $content );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Concept saved.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * AJAX: soft-delete a concept.
	 *
	 * @return void
	 */
	public function ajax_delete_concept() {
		if ( ! $this->verify_request() ) {
			return;
		}

		$bundle  = isset( $_POST['bundle'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle'] ) ) : '';
		$concept = isset( $_POST['concept_id'] ) ? sanitize_text_field( wp_unslash( $_POST['concept_id'] ) ) : '';

		$manager     = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundle_root = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $bundle_root ) ) {
			wp_send_json_error( array( 'message' => $bundle_root->get_error_message() ) );
		}

		$writable = $manager->assert_bundle_writable( $bundle );
		if ( is_wp_error( $writable ) ) {
			wp_send_json_error( array( 'message' => $writable->get_error_message() ) );
		}

		$writer = new WP_MCP_AI_OKF_Writer( $bundle_root );
		$result = $writer->delete_concept( $concept );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Concept archived.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * Handle the export admin-post request (streams the ZIP).
	 *
	 * @return void
	 */
	public function handle_export_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export OKF bundles.', 'mcp-ai-wpoos' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$bundle = isset( $_POST['bundle'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle'] ) ) : '';

		$result = ( new WP_MCP_AI_OKF_Bundle_Manager() )->export_bundle_zip( $bundle );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				$this->get_page_url_with(
					array(
						'tab'       => 'import-export',
						'okf_error' => $result->get_error_message(),
					)
				)
			);
			exit;
		}

		$zip_path = $result['path'];
		$filename = $bundle . '.zip';

		// Clean up the generated ZIP after streaming (best-effort).
		register_shutdown_function(
			static function () use ( $zip_path ) {
				if ( file_exists( $zip_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_delete -- Generated export artifact cleanup.
					wp_delete_file( $zip_path );
				}
			}
		);

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $zip_path ) );

		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a generated ZIP to the browser.
		exit;
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
