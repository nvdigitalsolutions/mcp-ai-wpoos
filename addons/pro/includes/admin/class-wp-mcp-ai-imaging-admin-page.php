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
	 * Enqueue viewer assets when we are on the imaging page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'mcp_ai_member_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue the Cornerstone3D imaging viewer bundle.
		// The bundle is loaded from a CDN; see imaging-viewer.js for the
		// importmap / CDN URL strategy.  A local build path is preferred
		// when the npm package has been compiled into the pro build directory.
		$viewer_js = WP_MCP_AI_PRO_URL . 'assets/js/imaging-viewer.js';

		wp_enqueue_script(
			'wp-mcp-ai-imaging-viewer',
			$viewer_js,
			array(),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-imaging-viewer',
			'wpMcpAiImaging',
			array(
				'restBase'       => esc_url_raw( rest_url( 'mcp-ai/v1/imaging' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'canUpload'      => current_user_can( 'upload_medical_imaging' ) ? 'yes' : 'no',
				'canManage'      => current_user_can( 'manage_medical_imaging' ) ? 'yes' : 'no',
				'i18n'           => array(
					'loadingStudy'    => __( 'Loading study…', 'mcp-ai-wpoos-pro' ),
					'noStudies'       => __( 'No imaging studies found. Upload a DICOM study to get started.', 'mcp-ai-wpoos-pro' ),
					'uploadSuccess'   => __( 'Study uploaded successfully.', 'mcp-ai-wpoos-pro' ),
					'uploadError'     => __( 'Upload failed. Please ensure you are uploading valid DICOM (.dcm) files.', 'mcp-ai-wpoos-pro' ),
					'viewerError'     => __( 'Unable to load imaging study.', 'mcp-ai-wpoos-pro' ),
					'noInstances'     => __( 'No instances found in this series.', 'mcp-ai-wpoos-pro' ),
					'confirmDelete'   => __( 'Are you sure you want to delete this study? This action cannot be undone.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		// Enqueue viewer stylesheet.
		$viewer_css = WP_MCP_AI_PRO_URL . 'assets/css/imaging-viewer.css';
		wp_enqueue_style(
			'wp-mcp-ai-imaging-viewer',
			$viewer_css,
			array( 'wp-admin' ),
			WP_MCP_AI_PRO_VERSION
		);
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

			<!-- Upload panel (hidden by default) -->
			<?php if ( current_user_can( 'upload_medical_imaging' ) ) : ?>
			<div id="nv-imaging-upload-panel" class="nv-imaging-panel" style="display:none;">
				<h2><?php esc_html_e( 'Upload DICOM Study', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Select one or more .dcm files from the same study/series. Files are stored in a protected directory outside the public web root.', 'mcp-ai-wpoos-pro' ); ?>
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

			<!-- Study browser -->
			<div id="nv-imaging-study-browser" class="nv-imaging-panel">
				<div id="nv-imaging-loading" class="nv-imaging-loading">
					<span class="spinner is-active"></span>
					<?php esc_html_e( 'Loading studies…', 'mcp-ai-wpoos-pro' ); ?>
				</div>
				<div id="nv-imaging-study-list" style="display:none;"></div>
			</div>

			<!-- Viewer panel -->
			<div id="nv-imaging-viewer-panel" class="nv-imaging-panel" style="display:none;">
				<div class="nv-imaging-viewer-toolbar">
					<button type="button" class="button" id="nv-imaging-back-btn">
						&larr; <?php esc_html_e( 'Back to Studies', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<span id="nv-imaging-study-label" class="nv-imaging-study-label"></span>
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
}
