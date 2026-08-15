<?php
/**
 * Healthcare Imaging Admin Page
 *
 * Provides a WordPress admin page for the Medical Imaging Viewer module.
 * The page serves two roles:
 *
 *  1. **Study browser** – a searchable list of all uploaded DICOM studies
 *     with patient ID, modality, study date, series count, and a "View" link.
 *
 *  2. **Cornerstone3D viewer** – an embedded viewer panel that loads when a
 *     study is selected.  The viewer is bootstrapped from
 *     `assets/js/imaging-viewer.js` (compiled Cornerstone3D bundle).
 *
 * HIPAA-safe notes:
 *  - No PHI is printed into the HTML source; all data comes from REST calls.
 *  - The admin page requires the `view_medical_imaging` capability.
 *  - File URLs are never exposed; Cornerstone3D fetches via signed REST tokens.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Medical Imaging Viewer admin page.
 */
class WP_MCP_AI_Imaging_Admin_Page {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'healthcare-imaging-viewer';

	/**
	 * Parent menu slug (under the member CPT).
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'edit.php?post_type=mcp_ai_member';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the submenu page under the Health & Wellness (mcp_ai_member) menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Medical Imaging Viewer', 'mcp-ai-wpoos-pro' ),
			__( 'Imaging Viewer', 'mcp-ai-wpoos-pro' ),
			'view_medical_imaging',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Valid tab slugs for the imaging viewer page.
	 *
	 * @var string[]
	 */
	private static $valid_tabs = array( 'studies', 'tools', 'audit', 'docs', 'debug' );

	/**
	 * Pinned esm.sh CDN URL for @cornerstonejs/core.
	 *
	 * Used both in the importmap bare-specifier entry and in the JS dynamic
	 * import.  Pinning to a specific patch version avoids unexpected breakage
	 * from CDN updates.
	 *
	 * @var string
	 */
	const CORNERSTONE_CORE_CDN = 'https://esm.sh/@cornerstonejs/core@1.86.1';

	/**
	 * Pinned esm.sh CDN URL for dicom-parser (peer dep of dicom-image-loader).
	 *
	 * @var string
	 */
	const DICOM_PARSER_CDN = 'https://esm.sh/dicom-parser@1.8.21';

	/**
	 * Pinned esm.sh CDN URL for @cornerstonejs/tools.
	 *
	 * @var string
	 */
	const CORNERSTONE_TOOLS_CDN = 'https://esm.sh/@cornerstonejs/tools@1.86.1';

	/**
	 * Pinned esm.sh CDN URL for @cornerstonejs/dicom-image-loader.
	 *
	 * Dcmjs (a transitive dependency of the loader) depends on xmlbuilder2.
	 * xmlbuilder2 is listed as `?external=` on the CDN import so esm.sh emits
	 * it as a bare specifier rather than bundling its es2022 build (which does
	 * not expose the `create` named export correctly).  The importmap entry
	 * XMLBUILDER2_CDN below resolves that bare specifier to a CJS-interop URL.
	 *
	 * @var string
	 */
	const CORNERSTONE_DICOM_LOADER_CDN = 'https://esm.sh/@cornerstonejs/dicom-image-loader@1.86.0';

	/**
	 * Pinned esm.sh CDN URL for xmlbuilder2 (transitive dep via dcmjs).
	 *
	 * The `?cjs-exports=create` query parameter instructs esm.sh to explicitly
	 * re-export the `create` named export from the CommonJS package, fixing the
	 * "does not provide an export named 'create'" browser error.
	 *
	 * @var string
	 */
	const XMLBUILDER2_CDN = 'https://esm.sh/xmlbuilder2@3.0.2?cjs-exports=create';

	/**
	 * Relative path from the pro addon root to vendored Cornerstone3D bundles.
	 *
	 * @var string
	 */
	const VENDOR_CORNERSTONE_DIR = 'assets/vendor/cornerstone';

	/**
	 * Check whether locally vendored Cornerstone3D ESM bundles are available.
	 *
	 * Returns true when all five ESM bundles exist on disk — either from the
	 * standalone Cornerstone3D addon (preferred) or from the Pro addon's own
	 * vendor directory.  When true, the viewer loads from local files instead
	 * of the esm.sh CDN, eliminating the runtime external dependency.
	 *
	 * Built by `bin/vendor-cornerstone.js` or provided by the nvoos-cornerstone3d addon.
	 *
	 * @return bool
	 */
	public static function has_vendored_cornerstone() {
		// Check standalone addon first (installed as a separate plugin).
		if ( function_exists( 'nvoos_cornerstone3d_is_available' ) && nvoos_cornerstone3d_is_available() ) {
			return true;
		}

		// Fall back to pro addon's own vendor directory.
		$base = WP_MCP_AI_PRO_PATH . self::VENDOR_CORNERSTONE_DIR . '/';
		return file_exists( $base . 'cornerstone-core.esm.js' )
			&& file_exists( $base . 'cornerstone-tools.esm.js' )
			&& file_exists( $base . 'cornerstone-dicom-loader.esm.js' )
			&& file_exists( $base . 'dicom-parser.esm.js' )
			&& file_exists( $base . 'xmlbuilder2.esm.js' );
	}

	/**
	 * Resolve Cornerstone3D module URLs — local vendor if available, CDN fallback.
	 *
	 * Returns an associative array with keys:
	 *   - core, tools, dicomLoader           (direct import URLs)
	 *   - importCornerstone, importDicomParser, importXmlbuilder2  (importmap entries)
	 *   - source  ('vendor' or 'cdn')
	 *
	 * When vendored bundles are present the tools and dicom-loader bundles were
	 * built with `@cornerstonejs/core`, `dicom-parser`, and `xmlbuilder2` as
	 * esbuild externals, so the importmap still resolves them — but now from
	 * local URLs instead of esm.sh.
	 *
	 * @return array
	 */
	private static function resolve_cornerstone_urls() {
		// Check standalone addon first (installed as a separate plugin).
		if ( function_exists( 'nvoos_cornerstone3d_is_available' ) && nvoos_cornerstone3d_is_available() ) {
			$base = nvoos_cornerstone3d_get_url();
			return array(
				'core'              => esc_url( $base . 'cornerstone-core.esm.js' ),
				'tools'             => esc_url( $base . 'cornerstone-tools.esm.js' ),
				'dicomLoader'       => esc_url( $base . 'cornerstone-dicom-loader.esm.js' ),
				'importCornerstone' => esc_url( $base . 'cornerstone-core.esm.js' ),
				'importDicomParser' => esc_url( $base . 'dicom-parser.esm.js' ),
				'importXmlbuilder2' => esc_url( $base . 'xmlbuilder2.esm.js' ),
				'source'            => 'addon',
			);
		}

		// Check pro addon's own vendor directory.
		$pro_base       = WP_MCP_AI_PRO_PATH . self::VENDOR_CORNERSTONE_DIR . '/';
		$has_pro_vendor = file_exists( $pro_base . 'cornerstone-core.esm.js' )
			&& file_exists( $pro_base . 'cornerstone-tools.esm.js' )
			&& file_exists( $pro_base . 'cornerstone-dicom-loader.esm.js' )
			&& file_exists( $pro_base . 'dicom-parser.esm.js' )
			&& file_exists( $pro_base . 'xmlbuilder2.esm.js' );

		if ( $has_pro_vendor ) {
			$base = WP_MCP_AI_PRO_URL . self::VENDOR_CORNERSTONE_DIR . '/';
			return array(
				'core'              => esc_url( $base . 'cornerstone-core.esm.js' ),
				'tools'             => esc_url( $base . 'cornerstone-tools.esm.js' ),
				'dicomLoader'       => esc_url( $base . 'cornerstone-dicom-loader.esm.js' ),
				'importCornerstone' => esc_url( $base . 'cornerstone-core.esm.js' ),
				'importDicomParser' => esc_url( $base . 'dicom-parser.esm.js' ),
				'importXmlbuilder2' => esc_url( $base . 'xmlbuilder2.esm.js' ),
				'source'            => 'vendor',
			);
		}

		// CDN fallback — same URLs that were hard-coded before vendoring support.
		return array(
			'core'              => self::CORNERSTONE_CORE_CDN,
			'tools'             => self::CORNERSTONE_TOOLS_CDN . '?external=@cornerstonejs/core',
			// Only @cornerstonejs/core is externalised so that tools and
			// dicom-image-loader share a single core instance via the importmap.
			// dicom-parser and xmlbuilder2 are CommonJS internally and cannot be
			// externalised in an ESM bundle without esbuild emitting a runtime
			// `require("dicom-parser")` shim that throws "Dynamic require ... is
			// not supported".  Letting esm.sh inline them keeps the bundle ESM-pure.
			'dicomLoader'       => self::CORNERSTONE_DICOM_LOADER_CDN . '?external=@cornerstonejs/core',
			'importCornerstone' => self::CORNERSTONE_CORE_CDN,
			'importDicomParser' => self::DICOM_PARSER_CDN,
			'importXmlbuilder2' => self::XMLBUILDER2_CDN,
			'source'            => 'cdn',
		);
	}

	/**
	 * Enqueue viewer assets when we are on the imaging page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'mcp_ai_member_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$cs_urls = self::resolve_cornerstone_urls();

		// Inject an ES module importmap.
		// When vendored bundles are present the tools and dicom-loader bundles
		// were built with core/dicom-parser/xmlbuilder2 as esbuild externals,
		// so the importmap resolves bare specifiers to local files.
		// When using the CDN, the `?external=` query parameters on the import()
		// URLs cause esm.sh to emit bare specifiers, and the importmap resolves
		// them back to the pinned CDN URLs — ensuring a single shared instance.
		add_action(
			'admin_head',
			function () use ( $cs_urls ) {
				$importmap = array(
					'imports' => array(
						'@cornerstonejs/core' => esc_url_raw( $cs_urls['importCornerstone'] ),
						'dicom-parser'        => esc_url_raw( $cs_urls['importDicomParser'] ),
						'xmlbuilder2'         => esc_url_raw( $cs_urls['importXmlbuilder2'] ),
					),
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<script type="importmap">' . wp_json_encode( $importmap ) . '</script>' . "\n";
			}
		);

		wp_enqueue_script(
			'wp-mcp-ai-imaging-viewer',
			esc_url( WP_MCP_AI_PRO_URL . 'assets/js/imaging-viewer.js' ),
			array(),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Load the viewer as a module script so that the importmap above applies
		// correctly to all bare specifiers inside the Cornerstone3D packages
		// (whether loaded from local vendor or CDN).
		add_filter(
			'script_loader_tag',
			static function ( $tag, $handle ) {
				if ( 'wp-mcp-ai-imaging-viewer' === $handle ) {
					$tag = str_replace( ' src=', ' type="module" src=', $tag );
				}
				return $tag;
			},
			10,
			2
		);

		// Resolve the active tab (validated against whitelist) so the JS can
		// initialise the correct panel without relying on URL parsing in the browser.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query param.
		$active_tab_for_js = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'studies';
		if ( ! in_array( $active_tab_for_js, self::$valid_tabs, true ) ) {
			$active_tab_for_js = 'studies';
		}

		wp_localize_script(
			'wp-mcp-ai-imaging-viewer',
			'wpMcpAiImaging',
			array(
				'restBase'     => esc_url_raw( rest_url( 'mcp-ai/v1/imaging' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'canUpload'    => current_user_can( 'upload_medical_imaging' ) ? 'yes' : 'no',
				'canManage'    => current_user_can( 'manage_medical_imaging' ) ? 'yes' : 'no',
				'statsUrl'     => esc_url_raw( rest_url( 'mcp-ai/v1/imaging/stats' ) ),
				'interpretUrl' => esc_url_raw( rest_url( 'mcp-ai/v1/imaging/interpret' ) ),
				'activeTab'    => $active_tab_for_js,
				'cornerstone'  => array(
					'coreUrl'        => esc_url_raw( $cs_urls['core'] ),
					'toolsUrl'       => esc_url_raw( $cs_urls['tools'] ),
					'dicomLoaderUrl' => esc_url_raw( $cs_urls['dicomLoader'] ),
					'source'         => $cs_urls['source'],
				),
				'i18n'         => array(
					'loadingStudy'             => __( 'Loading study…', 'mcp-ai-wpoos-pro' ),
					'noStudies'                => __( 'No imaging studies found. Upload a DICOM study to get started.', 'mcp-ai-wpoos-pro' ),
					'uploadSuccess'            => __( 'Study uploaded successfully.', 'mcp-ai-wpoos-pro' ),
					/* translators: 1: successful file count, 2: total file count */
						'uploadPartialSuccess' => __( 'Study uploaded, but %1$d of %2$d file(s) could not be processed.', 'mcp-ai-wpoos-pro' ),
					'uploadError'              => __( 'Upload failed. Please ensure you are uploading valid DICOM (.dcm) files.', 'mcp-ai-wpoos-pro' ),
					'viewerError'              => __( 'Unable to load imaging study.', 'mcp-ai-wpoos-pro' ),
					'noInstances'              => __( 'No instances found in this series.', 'mcp-ai-wpoos-pro' ),
					'confirmDelete'            => __( 'Are you sure you want to delete this study? This action cannot be undone.', 'mcp-ai-wpoos-pro' ),
					'interpretRun'             => __( 'Run AI Analysis', 'mcp-ai-wpoos-pro' ),
					'interpreting'             => __( 'Analysing…', 'mcp-ai-wpoos-pro' ),
					'interpretError'           => __( 'AI interpretation failed.', 'mcp-ai-wpoos-pro' ),
					'noStudySelected'          => __( 'Enter a Study UID to analyse.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		wp_enqueue_style(
			'wp-mcp-ai-imaging-viewer',
			esc_url( WP_MCP_AI_PRO_URL . 'assets/css/imaging-viewer.css' ),
			array( 'wp-admin' ),
			WP_MCP_AI_PRO_VERSION
		);
	}

	/**
	 * Return the absolute filesystem path to the DICOM storage root.
	 *
	 * Mirrors the logic used by WP_MCP_AI_Imaging_REST_Controller::get_storage_root()
	 * so that the admin UI can display the correct path without requiring the
	 * REST controller to be instantiated.
	 *
	 * Files are stored inside the WordPress uploads folder at:
	 * `{uploads}/mcp-ai-imaging/`
	 *
	 * @return string Absolute path (no trailing slash).
	 */
	private static function get_storage_root_path() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'mcp-ai-imaging';
	}

	/**
	 * Render the admin page HTML shell.
	 *
	 * The page shell intentionally contains no PHI – all data is fetched
	 * from the REST API by `imaging-viewer.js` after capability verification.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'view_medical_imaging' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$storage_path = self::get_storage_root_path();

		// Resolve the active tab from the URL (defaults to 'studies').
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'studies';
		if ( ! in_array( $active_tab, self::$valid_tabs, true ) ) {
			$active_tab = 'studies';
		}

		// Build the base page URL used in tab link hrefs.
		// Use add_query_arg() so WordPress handles the URL assembly consistently.
		$base_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( self::PARENT_SLUG ) );

		// Tab definitions: slug => label.
		$tabs = array(
			'studies' => __( 'Studies', 'mcp-ai-wpoos-pro' ),
			'tools'   => __( 'AI Tools', 'mcp-ai-wpoos-pro' ),
			'audit'   => __( 'Audit Log', 'mcp-ai-wpoos-pro' ),
			'docs'    => __( 'Documentation', 'mcp-ai-wpoos-pro' ),
			'debug'   => __( 'Debug', 'mcp-ai-wpoos-pro' ),
		);
		?>
		<div class="wrap nv-imaging-wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Medical Imaging Viewer', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<?php if ( current_user_can( 'upload_medical_imaging' ) ) : ?>
			<button type="button" class="page-title-action" id="nv-imaging-upload-btn">
				<?php esc_html_e( 'Upload Study', 'mcp-ai-wpoos-pro' ); ?>
			</button>
			<?php endif; ?>

			<hr class="wp-header-end">

			<!-- Stats bar — populated by JS -->
			<div id="nv-imaging-stats-bar" class="nv-imaging-stats-bar"></div>

			<!-- DICOM storage location info box (managers only) -->
			<?php if ( current_user_can( 'manage_medical_imaging' ) ) : ?>
			<div class="notice notice-info inline nv-imaging-storage-notice" style="margin-top:1em;">
				<p>
					<strong><?php esc_html_e( 'DICOM Storage Location', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'DICOM files are stored in a protected directory on the server that is not accessible directly over HTTP. Files are organised in a three-level hierarchy: study → series → instance, each level named after its DICOM UID.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<table class="widefat striped" style="max-width:700px;margin-bottom:.5em;">
					<tbody>
						<tr>
							<th style="width:200px;"><?php esc_html_e( 'Storage directory', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><code><?php echo esc_html( $storage_path ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Sub-folder per study', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><code><?php echo esc_html( trailingslashit( $storage_path ) ); ?>{StudyInstanceUID}/</code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Sub-folder per series', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><code><?php echo esc_html( trailingslashit( $storage_path ) ); ?>{StudyInstanceUID}/{SeriesInstanceUID}/</code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Instance file', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><code>{StudyInstanceUID}/{SeriesInstanceUID}/{SOPInstanceUID}.dcm</code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'HTTP access', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php esc_html_e( 'Blocked via .htaccess — files are served only through signed REST API tokens.', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
					</tbody>
				</table>
				<p class="description">
					<?php
					printf(
						/* translators: %s: REST API endpoint path */
						esc_html__( 'To upload new studies use the "Upload Study" button above, or POST files to the REST endpoint: %s', 'mcp-ai-wpoos-pro' ),
						'<code>' . esc_html( rest_url( 'mcp-ai/v1/imaging/upload' ) ) . '</code>'
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<!-- Upload panel (hidden by default) -->
			<?php if ( current_user_can( 'upload_medical_imaging' ) ) : ?>
			<div id="nv-imaging-upload-panel" class="nv-imaging-panel" style="display:none;">
				<h2><?php esc_html_e( 'Upload DICOM Study', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Select one or more .dcm files from the same study/series. Files are stored in the uploads directory and protected from direct HTTP access via .htaccess rules.', 'mcp-ai-wpoos-pro' ); ?>
					<br>
					<?php
					printf(
						/* translators: %s: server filesystem path */
						esc_html__( 'Server path: %s', 'mcp-ai-wpoos-pro' ),
						'<code>' . esc_html( $storage_path ) . '</code>'
					);
					?>
				</p>
				<form id="nv-imaging-upload-form" enctype="multipart/form-data">
					<input type="file" id="nv-imaging-file-input" name="dicom_files[]" accept=".dcm" multiple />
					<div id="nv-imaging-upload-status" aria-live="polite"></div>
					<p>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Upload', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<button type="button" class="button" id="nv-imaging-upload-cancel">
							<?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</p>
				</form>
			</div>
			<?php endif; ?>

			<!-- Main panel with tabs -->
			<div id="nv-imaging-main-panel" class="nv-imaging-panel">
				<nav class="nv-imaging-tab-nav nav-tab-wrapper wp-clearfix" role="tablist">
					<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, $base_url ) ); ?>"
						role="tab"
						class="nav-tab<?php echo $active_tab === $tab_slug ? ' nav-tab-active' : ''; ?>"
						data-tab="<?php echo esc_attr( $tab_slug ); ?>"
						id="<?php echo esc_attr( 'nv-tab-' . $tab_slug ); ?>"
					>
						<?php echo esc_html( $tab_label ); ?>
					</a>
					<?php endforeach; ?>
				</nav>

				<!-- Studies tab -->
				<div id="nv-imaging-tab-studies" role="tabpanel"<?php echo 'studies' !== $active_tab ? ' style="display:none;"' : ''; ?>>
					<!-- Filter bar -->
					<div class="nv-imaging-filter-bar" id="nv-imaging-filter-bar">
						<input type="search" id="nv-imaging-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search Study UID…', 'mcp-ai-wpoos-pro' ); ?>" />
						<select id="nv-imaging-filter-modality">
							<option value=""><?php esc_html_e( 'All Modalities', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="CT">CT</option>
							<option value="MR">MR</option>
							<option value="PT">PT</option>
							<option value="US">US</option>
							<option value="DX"><?php esc_html_e( 'DX (X-Ray)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="CR">CR</option>
							<option value="MG">MG</option>
							<option value="NM">NM</option>
							<option value="RF">RF</option>
							<option value="XA">XA</option>
						</select>
						<label>
							<?php esc_html_e( 'From:', 'mcp-ai-wpoos-pro' ); ?>
							<input type="date" id="nv-imaging-date-from" />
						</label>
						<label>
							<?php esc_html_e( 'To:', 'mcp-ai-wpoos-pro' ); ?>
							<input type="date" id="nv-imaging-date-to" />
						</label>
						<button type="button" class="button button-primary" id="nv-imaging-filter-apply">
							<?php esc_html_e( 'Filter', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<button type="button" class="button" id="nv-imaging-filter-clear">
							<?php esc_html_e( 'Clear', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</div>
					<!-- Study browser -->
					<div id="nv-imaging-study-browser">
						<div id="nv-imaging-loading" class="nv-imaging-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e( 'Loading studies…', 'mcp-ai-wpoos-pro' ); ?>
						</div>
						<div id="nv-imaging-study-list" style="display:none;"></div>
					</div>
				</div>

				<!-- AI Tools tab -->
				<div id="nv-imaging-tab-tools" role="tabpanel"<?php echo 'tools' !== $active_tab ? ' style="display:none;"' : ''; ?>>
					<div class="nv-imaging-tools-grid">

						<!-- AI Study Interpretation card -->
						<div class="nv-imaging-tool-card">
							<h3><?php esc_html_e( 'AI Study Interpretation', 'mcp-ai-wpoos-pro' ); ?></h3>
							<p class="description">
								<?php esc_html_e( 'Uses the configured AI provider (OpenAI / Gemini) to analyse a study\'s metadata and return clinical observations. Open a study first, or paste its Study UID below.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<table class="form-table nv-imaging-tool-form-table">
								<tr>
									<th scope="row"><label for="nv-imaging-interpret-uid"><?php esc_html_e( 'Study UID', 'mcp-ai-wpoos-pro' ); ?></label></th>
									<td><input type="text" id="nv-imaging-interpret-uid" class="large-text" placeholder="<?php esc_attr_e( '1.2.840…', 'mcp-ai-wpoos-pro' ); ?>" /></td>
								</tr>
								<tr>
									<th scope="row"><label for="nv-imaging-interpret-focus"><?php esc_html_e( 'Analysis Focus', 'mcp-ai-wpoos-pro' ); ?></label></th>
									<td>
										<select id="nv-imaging-interpret-focus">
											<option value="full"><?php esc_html_e( 'Full Analysis', 'mcp-ai-wpoos-pro' ); ?></option>
											<option value="quality"><?php esc_html_e( 'Image Quality', 'mcp-ai-wpoos-pro' ); ?></option>
											<option value="completeness"><?php esc_html_e( 'Study Completeness', 'mcp-ai-wpoos-pro' ); ?></option>
											<option value="workflow"><?php esc_html_e( 'Workflow &amp; Next Steps', 'mcp-ai-wpoos-pro' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( 'Choose what the AI should focus its analysis on.', 'mcp-ai-wpoos-pro' ); ?></p>
									</td>
								</tr>
							</table>
							<p>
								<button type="button" class="button button-primary" id="nv-imaging-interpret-run">
									<?php esc_html_e( 'Run AI Analysis', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</p>
							<div id="nv-imaging-interpret-result" class="nv-imaging-interpret-result" style="display:none;" aria-live="polite"></div>
						</div>

						<!-- Available tools reference card -->
						<div class="nv-imaging-tool-card">
							<h3><?php esc_html_e( 'Available AI Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
							<ul class="nv-imaging-tools-list">
								<li>
									<strong>interpret_imaging_study</strong>
									<p class="description"><?php esc_html_e( 'Analyses a DICOM study\'s metadata via AI. Supports focus: quality / completeness / workflow / full. Optional pixel preview sends a 512px PNG of the first frame to a vision model.', 'mcp-ai-wpoos-pro' ); ?></p>
								</li>
								<li>
									<strong>manage_imaging_studies</strong>
									<p class="description"><?php esc_html_e( 'Lists studies, retrieves details, summarises findings, and reads the audit log. Accessible from any NV oOS AI assistant that has the view_medical_imaging capability.', 'mcp-ai-wpoos-pro' ); ?></p>
								</li>
							</ul>
							<p class="description">
								<?php
								printf(
									/* translators: %s: REST API endpoint */
									esc_html__( 'Tools can also be invoked programmatically via the AI assistant REST endpoint: %s', 'mcp-ai-wpoos-pro' ),
									'<code>' . esc_html( rest_url( 'mcp-ai/v1/chat' ) ) . '</code>'
								);
								?>
							</p>
						</div>

					</div><!-- .nv-imaging-tools-grid -->
				</div><!-- #nv-imaging-tab-tools -->

				<!-- Audit log tab -->
				<div id="nv-imaging-tab-audit" role="tabpanel"<?php echo 'audit' !== $active_tab ? ' style="display:none;"' : ''; ?>>
					<div id="nv-imaging-audit-loading" class="nv-imaging-loading" style="display:none;">
						<span class="spinner is-active"></span>
						<?php esc_html_e( 'Loading audit log…', 'mcp-ai-wpoos-pro' ); ?>
					</div>
					<div id="nv-imaging-audit-list"></div>
				</div>

				<!-- Documentation tab -->
				<div id="nv-imaging-tab-docs" role="tabpanel"<?php echo 'docs' !== $active_tab ? ' style="display:none;"' : ''; ?>>
					<div class="nv-imaging-docs-grid">

						<!-- Quick Start -->
						<div class="nv-imaging-docs-card">
							<h3><?php esc_html_e( 'Quick Start', 'mcp-ai-wpoos-pro' ); ?></h3>
							<ol>
								<li><?php esc_html_e( 'Click "Upload Study" and select one or more .dcm DICOM files.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'The study appears in the Studies table. Click "View" to open the Cornerstone3D viewer.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Select a series in the left sidebar. Use the W/L toolbar to adjust brightness/contrast.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Left-click drag = Window/Level. Right-click drag = Pan. Mouse wheel = scroll slices.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Go to AI Tools tab → Run AI Analysis to get an instant AI interpretation.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
						</div>

						<!-- Keyboard shortcuts -->
						<div class="nv-imaging-docs-card">
							<h3><?php esc_html_e( 'Viewer Keyboard Shortcuts', 'mcp-ai-wpoos-pro' ); ?></h3>
							<table class="widefat striped nv-imaging-docs-table">
								<thead><tr><th><?php esc_html_e( 'Key', 'mcp-ai-wpoos-pro' ); ?></th><th><?php esc_html_e( 'Action', 'mcp-ai-wpoos-pro' ); ?></th></tr></thead>
								<tbody>
									<tr><td><kbd>↑</kbd> / <kbd>←</kbd></td><td><?php esc_html_e( 'Previous slice / frame', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><kbd>↓</kbd> / <kbd>→</kbd></td><td><?php esc_html_e( 'Next slice / frame', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><kbd>R</kbd></td><td><?php esc_html_e( 'Reset W/L and camera', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><kbd>I</kbd></td><td><?php esc_html_e( 'Invert image', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Left drag', 'mcp-ai-wpoos-pro' ); ?></td><td><?php esc_html_e( 'Adjust Window / Level', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Right drag', 'mcp-ai-wpoos-pro' ); ?></td><td><?php esc_html_e( 'Pan image', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Scroll wheel', 'mcp-ai-wpoos-pro' ); ?></td><td><?php esc_html_e( 'Scroll through slices', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Middle drag', 'mcp-ai-wpoos-pro' ); ?></td><td><?php esc_html_e( 'Zoom in / out', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								</tbody>
							</table>
						</div>

						<!-- W/L Clinical Presets -->
						<div class="nv-imaging-docs-card">
							<h3><?php esc_html_e( 'Window / Level Clinical Presets', 'mcp-ai-wpoos-pro' ); ?></h3>
							<p class="description"><?php esc_html_e( 'These industry-standard presets are available in the viewer toolbar for CT, MR, and PET.', 'mcp-ai-wpoos-pro' ); ?></p>
							<table class="widefat striped nv-imaging-docs-table">
								<thead><tr><th><?php esc_html_e( 'Modality', 'mcp-ai-wpoos-pro' ); ?></th><th><?php esc_html_e( 'Preset', 'mcp-ai-wpoos-pro' ); ?></th><th>WW</th><th>WL</th></tr></thead>
								<tbody>
									<tr><td>CT</td><td><?php esc_html_e( 'Soft Tissue', 'mcp-ai-wpoos-pro' ); ?></td><td>350</td><td>40</td></tr>
									<tr><td>CT</td><td><?php esc_html_e( 'Lung', 'mcp-ai-wpoos-pro' ); ?></td><td>1500</td><td>-600</td></tr>
									<tr><td>CT</td><td><?php esc_html_e( 'Brain', 'mcp-ai-wpoos-pro' ); ?></td><td>80</td><td>40</td></tr>
									<tr><td>CT</td><td><?php esc_html_e( 'Bone', 'mcp-ai-wpoos-pro' ); ?></td><td>2000</td><td>400</td></tr>
									<tr><td>CT</td><td><?php esc_html_e( 'Abdomen', 'mcp-ai-wpoos-pro' ); ?></td><td>400</td><td>50</td></tr>
									<tr><td>CT</td><td><?php esc_html_e( 'Liver', 'mcp-ai-wpoos-pro' ); ?></td><td>150</td><td>80</td></tr>
									<tr><td>CT</td><td><?php esc_html_e( 'Mediastinum', 'mcp-ai-wpoos-pro' ); ?></td><td>350</td><td>50</td></tr>
									<tr><td>MR</td><td><?php esc_html_e( 'Brain', 'mcp-ai-wpoos-pro' ); ?></td><td>1000</td><td>500</td></tr>
									<tr><td>MR</td><td><?php esc_html_e( 'Spine', 'mcp-ai-wpoos-pro' ); ?></td><td>1200</td><td>600</td></tr>
									<tr><td>MR</td><td><?php esc_html_e( 'Soft Tissue', 'mcp-ai-wpoos-pro' ); ?></td><td>500</td><td>250</td></tr>
									<tr><td>PT</td><td>SUV Max</td><td>5</td><td>2.5</td></tr>
								</tbody>
							</table>
						</div>

						<!-- Modality Reference -->
						<div class="nv-imaging-docs-card">
							<h3><?php esc_html_e( 'DICOM Modality Abbreviations', 'mcp-ai-wpoos-pro' ); ?></h3>
							<table class="widefat striped nv-imaging-docs-table">
								<thead><tr><th><?php esc_html_e( 'Code', 'mcp-ai-wpoos-pro' ); ?></th><th><?php esc_html_e( 'Modality', 'mcp-ai-wpoos-pro' ); ?></th></tr></thead>
								<tbody>
									<tr><td>CT</td><td><?php esc_html_e( 'Computed Tomography', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>MR</td><td><?php esc_html_e( 'Magnetic Resonance Imaging', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>PT</td><td><?php esc_html_e( 'Positron Emission Tomography (PET)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>US</td><td><?php esc_html_e( 'Ultrasound', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>DX</td><td><?php esc_html_e( 'Digital X-Ray', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>CR</td><td><?php esc_html_e( 'Computed Radiography', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>MG</td><td><?php esc_html_e( 'Mammography', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>NM</td><td><?php esc_html_e( 'Nuclear Medicine', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>RF</td><td><?php esc_html_e( 'Fluoroscopy / Radiofluoroscopy', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>XA</td><td><?php esc_html_e( 'X-Ray Angiography', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>ECG</td><td><?php esc_html_e( 'Electrocardiography', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td>OT</td><td><?php esc_html_e( 'Other (miscellaneous)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								</tbody>
							</table>
						</div>

						<!-- REST API Reference -->
						<div class="nv-imaging-docs-card nv-imaging-docs-card--wide">
							<h3><?php esc_html_e( 'REST API Reference', 'mcp-ai-wpoos-pro' ); ?></h3>
							<p class="description">
								<?php
								printf(
									/* translators: %s: REST base URL */
									esc_html__( 'All endpoints are under %1$s and require the WP REST nonce header %2$s.', 'mcp-ai-wpoos-pro' ),
									'<code>' . esc_html( rest_url( 'mcp-ai/v1/imaging' ) ) . '</code>',
									'<code>X-WP-Nonce: &lt;nonce&gt;</code>'
								);
								?>
							</p>
							<table class="widefat striped nv-imaging-docs-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Method', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Endpoint', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Capability', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr><td><code>GET</code></td><td><code>/studies</code></td><td><code>view_medical_imaging</code></td><td><?php esc_html_e( 'List all studies. Supports: per_page, page, modality, date_from, date_to, search.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><code>GET</code></td><td><code>/studies/{uid}</code></td><td><code>view_medical_imaging</code></td><td><?php esc_html_e( 'Get a single study by StudyInstanceUID.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><code>GET</code></td><td><code>/studies/{uid}/manifest</code></td><td><code>view_medical_imaging</code></td><td><?php esc_html_e( 'Get Cornerstone3D-compatible manifest with signed imageIds.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><code>DELETE</code></td><td><code>/studies/{uid}</code></td><td><code>manage_medical_imaging</code></td><td><?php esc_html_e( 'Hard-delete study post and DICOM files from disk.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><code>POST</code></td><td><code>/upload</code></td><td><code>upload_medical_imaging</code></td><td><?php esc_html_e( 'Upload one or more .dcm files (multipart/form-data, dicom_files[]).', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><code>GET</code></td><td><code>/instances/{uid}/file</code></td><td><code>view_medical_imaging</code></td><td><?php esc_html_e( 'Stream raw DICOM bytes. Requires signed ?token= query param.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><code>GET</code></td><td><code>/stats</code></td><td><code>view_medical_imaging</code></td><td><?php esc_html_e( 'Summary: total_studies, by_modality[], storage_bytes, recent_studies[].', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><code>POST</code></td><td><code>/interpret</code></td><td><code>view_medical_imaging</code></td><td><?php esc_html_e( 'AI interpretation. Body: {study_uid, focus: full|quality|completeness|workflow}.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
									<tr><td><code>GET</code></td><td><code>/audit</code></td><td><code>manage_medical_imaging</code></td><td><?php esc_html_e( 'Recent audit events. Supports: limit, study_id.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								</tbody>
							</table>
						</div>

						<!-- HIPAA / Privacy Notes -->
						<div class="nv-imaging-docs-card nv-imaging-docs-card--wide">
							<h3><?php esc_html_e( 'Privacy &amp; HIPAA Notes', 'mcp-ai-wpoos-pro' ); ?></h3>
							<ul>
								<li><?php esc_html_e( 'DICOM files are stored in a protected server directory. Direct HTTP access is blocked by an .htaccess "Deny from all" rule.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Individual files are served only through short-lived signed tokens (WP nonces) via the REST API — no file URL is ever exposed in the HTML.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'No PHI is written to the WordPress database or admin UI. Study metadata is de-identified (UIDs only); patient names and birth dates are never stored.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Every study view, upload, delete, and AI interpretation is recorded in the Audit Log with timestamp and user ID.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Study deletion is a hard-delete (no trash) — DICOM files are physically removed from disk. Ensure your backup policy covers the DICOM storage directory.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'When using AI Interpretation the study UID and metadata summary are sent to the configured AI provider (OpenAI / Gemini). Do not enable this feature for studies containing identifiable patient data unless you have a BAA with that provider.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ul>
						</div>

					</div><!-- .nv-imaging-docs-grid -->
				</div><!-- #nv-imaging-tab-docs -->

				<!-- Debug tab -->
				<div id="nv-imaging-tab-debug" role="tabpanel"<?php echo 'debug' !== $active_tab ? ' style="display:none;"' : ''; ?>>
					<?php self::render_debug_tab( $storage_path ); ?>
				</div><!-- #nv-imaging-tab-debug -->
			</div>

			<!-- Viewer panel -->
			<div id="nv-imaging-viewer-panel" class="nv-imaging-panel" style="display:none;">
				<div class="nv-imaging-viewer-toolbar">
					<button type="button" class="button" id="nv-imaging-back-btn">
						&larr; <?php esc_html_e( 'Back to Studies', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<span id="nv-imaging-study-label" class="nv-imaging-study-label"></span>
					<span class="nv-imaging-toolbar-spacer"></span>
					<div id="nv-imaging-wl-toolbar" class="nv-imaging-wl-toolbar" aria-label="<?php esc_attr_e( 'Window/Level controls', 'mcp-ai-wpoos-pro' ); ?>"></div>
					<div id="nv-imaging-tool-btns" class="nv-imaging-tool-btns">
						<button type="button" class="button nv-imaging-tool-btn" id="nv-imaging-btn-fliph" title="<?php esc_attr_e( 'Flip Horizontal', 'mcp-ai-wpoos-pro' ); ?>">&#x21D4;</button>
						<button type="button" class="button nv-imaging-tool-btn" id="nv-imaging-btn-flipv" title="<?php esc_attr_e( 'Flip Vertical', 'mcp-ai-wpoos-pro' ); ?>">&#x21D5;</button>
						<button type="button" class="button nv-imaging-tool-btn" id="nv-imaging-btn-rotate-cw" title="<?php esc_attr_e( 'Rotate 90° CW', 'mcp-ai-wpoos-pro' ); ?>">&#x21BB;</button>
						<button type="button" class="button nv-imaging-tool-btn" id="nv-imaging-btn-rotate-ccw" title="<?php esc_attr_e( 'Rotate 90° CCW', 'mcp-ai-wpoos-pro' ); ?>">&#x21BA;</button>
						<button type="button" class="button nv-imaging-tool-btn" id="nv-imaging-btn-screenshot" title="<?php esc_attr_e( 'Export Viewport as PNG', 'mcp-ai-wpoos-pro' ); ?>">&#x1F4F7;</button>
					</div>
				</div>

				<div class="nv-imaging-viewer-layout">
					<!-- Sidebar: series list + metadata -->
					<aside class="nv-imaging-sidebar">
						<h3><?php esc_html_e( 'Series', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul id="nv-imaging-series-list"></ul>

						<h3><?php esc_html_e( 'Metadata', 'mcp-ai-wpoos-pro' ); ?></h3>
						<dl id="nv-imaging-metadata-list" class="nv-imaging-meta-dl"></dl>
					</aside>

					<!-- Cornerstone3D viewport container -->
					<main class="nv-imaging-viewport-wrap">
						<div id="nv-imaging-viewport" class="nv-imaging-viewport"></div>
					</main>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Debug tab content.
	 *
	 * Shows PHP-side diagnostic information: storage path, capabilities,
	 * plugin settings, module class availability, audit log stats, and
	 * environment details.  Visible only to users with manage_medical_imaging.
	 *
	 * @param string $storage_path Absolute path to the DICOM storage root.
	 */
	private static function render_debug_tab( $storage_path ) {
		if ( ! current_user_can( 'manage_medical_imaging' ) ) {
			?>
			<p class="description">
				<?php esc_html_e( 'Debug information is only available to users with the manage_medical_imaging capability.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<?php
			return;
		}

		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$imaging_enabled  = ! empty( $settings['enable_healthcare_imaging'] );
		$storage_exists   = is_dir( $storage_path );
		$storage_writable = $storage_exists && wp_is_writable( $storage_path );

		// Fetch the full audit buffer once to derive both the recent preview and the total count.
		$all_audit_entries = class_exists( 'WP_MCP_AI_Imaging_Audit_Log' )
			? get_option( WP_MCP_AI_Imaging_Audit_Log::OPTION_KEY, array() )
			: array();
		$audit_total       = count( $all_audit_entries );
		$audit_entries     = array_slice( array_reverse( array_values( $all_audit_entries ) ), 0, 10 );

		$classes_to_check = array(
			'WP_MCP_AI_Imaging_Admin_Page'      => __( 'Admin Page', 'mcp-ai-wpoos-pro' ),
			'WP_MCP_AI_Imaging_REST_Controller' => __( 'REST Controller', 'mcp-ai-wpoos-pro' ),
			'WP_MCP_AI_Imaging_Study_CPT'       => __( 'Study CPT', 'mcp-ai-wpoos-pro' ),
			'WP_MCP_AI_Imaging_Audit_Log'       => __( 'Audit Log', 'mcp-ai-wpoos-pro' ),
			'WP_MCP_AI_Imaging_Capabilities'    => __( 'Capabilities Helper', 'mcp-ai-wpoos-pro' ),
		);

		$caps_to_check = array(
			'view_medical_imaging'   => __( 'View studies', 'mcp-ai-wpoos-pro' ),
			'upload_medical_imaging' => __( 'Upload studies', 'mcp-ai-wpoos-pro' ),
			'manage_medical_imaging' => __( 'Manage / delete studies', 'mcp-ai-wpoos-pro' ),
		);
		?>
		<div class="nv-imaging-debug-grid">

			<!-- Module & Settings -->
			<div class="nv-imaging-debug-card">
				<h3><?php esc_html_e( 'Module &amp; Settings', 'mcp-ai-wpoos-pro' ); ?></h3>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Healthcare Imaging enabled', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php if ( $imaging_enabled ) : ?>
									<span class="nv-imaging-debug-ok">&#10003; <?php esc_html_e( 'Yes', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php else : ?>
									<span class="nv-imaging-debug-warn">&#10007; <?php esc_html_e( 'No — enable in NV oOS → Settings', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'DICOM storage path', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><code><?php echo esc_html( $storage_path ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Storage directory exists', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php if ( $storage_exists ) : ?>
									<span class="nv-imaging-debug-ok">&#10003; <?php esc_html_e( 'Yes', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php else : ?>
									<span class="nv-imaging-debug-warn">&#10007; <?php esc_html_e( 'No — created on first upload', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Storage directory writable', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php if ( $storage_writable ) : ?>
									<span class="nv-imaging-debug-ok">&#10003; <?php esc_html_e( 'Yes', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php elseif ( $storage_exists ) : ?>
									<span class="nv-imaging-debug-error">&#10007; <?php esc_html_e( 'No — check server file permissions', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php else : ?>
									<span class="nv-imaging-debug-warn"><?php esc_html_e( 'N/A (directory not yet created)', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'REST base URL', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><code><?php echo esc_html( rest_url( 'mcp-ai/v1/imaging' ) ); ?></code></td>
						</tr>
					</tbody>
				</table>
			</div><!-- .nv-imaging-debug-card -->

			<!-- Current-user capabilities -->
			<div class="nv-imaging-debug-card">
				<h3><?php esc_html_e( 'Current User Capabilities', 'mcp-ai-wpoos-pro' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Capability', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Granted', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $caps_to_check as $cap => $label ) : ?>
							<tr>
								<td>
									<code><?php echo esc_html( $cap ); ?></code>
									<span class="description">(<?php echo esc_html( $label ); ?>)</span>
								</td>
								<td>
									<?php if ( current_user_can( $cap ) ) : ?>
										<span class="nv-imaging-debug-ok">&#10003; <?php esc_html_e( 'Yes', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php else : ?>
										<span class="nv-imaging-debug-warn">&#10007; <?php esc_html_e( 'No', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div><!-- .nv-imaging-debug-card -->

			<!-- PHP classes loaded -->
			<div class="nv-imaging-debug-card">
				<h3><?php esc_html_e( 'Module Classes', 'mcp-ai-wpoos-pro' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Class', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Loaded', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $classes_to_check as $class => $label ) : ?>
							<tr>
								<td>
									<code><?php echo esc_html( $class ); ?></code>
									<span class="description">(<?php echo esc_html( $label ); ?>)</span>
								</td>
								<td>
									<?php if ( class_exists( $class ) ) : ?>
										<span class="nv-imaging-debug-ok">&#10003; <?php esc_html_e( 'Yes', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php else : ?>
										<span class="nv-imaging-debug-error">&#10007; <?php esc_html_e( 'No', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div><!-- .nv-imaging-debug-card -->

			<!-- Audit log summary -->
			<div class="nv-imaging-debug-card">
				<h3><?php esc_html_e( 'Audit Log', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: %d: number of audit log entries */
						esc_html__( 'Total entries stored: %d', 'mcp-ai-wpoos-pro' ),
						absint( $audit_total )
					);
					?>
				</p>
				<?php if ( ! empty( $audit_entries ) ) : ?>
					<table class="widefat striped nv-imaging-docs-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Timestamp', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Event', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'User ID', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $audit_entries as $entry ) : ?>
								<tr>
									<td><code><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></code></td>
									<td><?php echo esc_html( $entry['event'] ?? '' ); ?></td>
									<td><?php echo esc_html( $entry['user_id'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No audit events recorded yet.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php endif; ?>
			</div><!-- .nv-imaging-debug-card -->

			<!-- Environment -->
			<div class="nv-imaging-debug-card nv-imaging-debug-card--wide">
				<h3><?php esc_html_e( 'Environment', 'mcp-ai-wpoos-pro' ); ?></h3>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'PHP version', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( PHP_VERSION ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'WordPress version', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'NV oOS Pro version', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php
								// Prefer the plugin-header version over the manually-maintained constant.
								$imaging_pro_version = method_exists( 'WP_MCP_AI_Plugin_Updater', 'get_pro_installed_version' )
									? WP_MCP_AI_Plugin_Updater::get_pro_installed_version()
									: ( defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '' );
								echo esc_html( $imaging_pro_version ? $imaging_pro_version : __( 'N/A', 'mcp-ai-wpoos-pro' ) );
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'WP_DEBUG', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo defined( 'WP_DEBUG' ) && WP_DEBUG ? esc_html__( 'Enabled', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'GD extension (pixel preview)', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php if ( extension_loaded( 'gd' ) ) : ?>
									<span class="nv-imaging-debug-ok">&#10003; <?php esc_html_e( 'Available', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php else : ?>
									<span class="nv-imaging-debug-warn">&#10007; <?php esc_html_e( 'Not available — pixel preview disabled', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'WP uploads base dir', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<?php
								$upload_dir = wp_upload_dir();
								echo esc_html( $upload_dir['basedir'] );
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</div><!-- .nv-imaging-debug-card -->

		</div><!-- .nv-imaging-debug-grid -->
		<?php
	}
}
