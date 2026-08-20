<?php
/**
 * Admin page for conversation imports.
 *
 * Provides an upload + format-preview + policy flow that enqueues imports
 * onto the async job queue, polls job progress, and offers a downloadable
 * JSON report once the run completes.
 *
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversation import admin UI.
 */
class WP_MCP_AI_Conversation_Import_Admin {

	const PAGE_SLUG    = 'wp-mcp-ai-conversation-import';
	const PREVIEW_TTL  = HOUR_IN_SECONDS;
	const NONCE_ACTION = 'wp_mcp_ai_conversation_import';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 16 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wp_mcp_ai_conversation_import_upload', array( $this, 'handle_upload_request' ) );
		add_action( 'admin_post_wp_mcp_ai_conversation_import_run', array( $this, 'handle_run_request' ) );
		add_action( 'wp_ajax_wp_mcp_ai_conversation_import_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_wp_mcp_ai_conversation_import_report', array( $this, 'ajax_report' ) );
	}

	/**
	 * Whether the import tooling is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return ( function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' ) )
			&& class_exists( 'WP_MCP_AI_Conversation_Import_Manager' );
	}

	/**
	 * Register the admin page under the NV oOS menu.
	 *
	 * @return void
	 */
	public function register_page() {
		if ( ! self::is_available() ) {
			return;
		}

		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Conversation Import', 'mcp-ai-wpoos' ),
			__( 'Conversation Import', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue page assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		$inline_css = '
			.wp-mcp-ai-import__card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:1rem 1.25rem;margin:1rem 0;max-width:760px;}
			.wp-mcp-ai-import__bar{height:1.25rem;background:#f0f0f1;border:1px solid #dcdcde;border-radius:3px;overflow:hidden;margin:0.75rem 0;}
			.wp-mcp-ai-import__bar-fill{height:100%;background:#2271b1;width:0;transition:width 0.4s ease;}
			.wp-mcp-ai-import__preview{background:#f0f6fc;border-left:4px solid #2271b1;padding:0.75rem 1rem;margin:1rem 0;}
		';

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline style registered with no URL; version not applicable.
		wp_register_style( 'wp-mcp-ai-import-inline', false );
		wp_enqueue_style( 'wp-mcp-ai-import-inline' );
		wp_add_inline_style( 'wp-mcp-ai-import-inline', $inline_css );

		$inline_js = "
			( function () {
				function pollStatus( jobId ) {
					var data = new FormData();
					data.append( 'action', 'wp_mcp_ai_conversation_import_status' );
					data.append( 'nonce', '" . esc_js( wp_create_nonce( self::NONCE_ACTION ) ) . "' );
					data.append( 'job_id', jobId );

					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res.success ) { return; }
							var fill = document.getElementById( 'wp-mcp-ai-import-fill' );
							var text = document.getElementById( 'wp-mcp-ai-import-status-text' );
							if ( fill && res.data.progress ) {
								fill.style.width = res.data.progress + '%';
							}
							if ( text ) {
								text.textContent = res.data.status + ' (' + res.data.progress + '%)';
							}
							if ( res.data.status === 'completed' || res.data.status === 'failed' ) {
								var link = document.getElementById( 'wp-mcp-ai-import-report-link' );
								if ( link && res.data.status === 'completed' ) { link.style.display = ''; }
								return;
							}
							window.setTimeout( function () { pollStatus( jobId ); }, 2000 );
						} );
				}

				var jobRow = document.getElementById( 'wp-mcp-ai-import-job' );
				if ( jobRow ) { pollStatus( jobRow.getAttribute( 'data-job-id' ) ); }
			}() );
		";

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline script registered with no URL; version fixed for cache busting.
		wp_register_script( 'wp-mcp-ai-import-inline', false, array(), '1.1.60', true );
		wp_enqueue_script( 'wp-mcp-ai-import-inline' );
		wp_add_inline_script( 'wp-mcp-ai-import-inline', $inline_js );
	}

	/**
	 * Handle the upload admin-post request.
	 *
	 * @return void
	 */
	public function handle_upload_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import conversations.', 'mcp-ai-wpoos' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		if ( empty( $_FILES['conversation_export'] ) ) {
			wp_safe_redirect( $this->get_page_url( array( 'import_error' => 'missing_file' ) ) );
			exit;
		}

		$file = $this->process_upload( $_FILES['conversation_export'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES is an upload array handled by wp_handle_upload().

		if ( is_wp_error( $file ) ) {
			wp_safe_redirect( $this->get_page_url( array( 'import_error' => $file->get_error_code() ) ) );
			exit;
		}

		$manager = new WP_MCP_AI_Conversation_Import_Manager();
		$preview = $manager->inspect( $file );

		if ( is_wp_error( $preview ) ) {
			wp_delete_file( $file );
			wp_safe_redirect( $this->get_page_url( array( 'import_error' => $preview->get_error_code() ) ) );
			exit;
		}

		$token = 'preview-' . gmdate( 'YmdHis' ) . '-' . substr( wp_hash( uniqid( '', true ) ), 0, 10 );

		set_transient(
			'wp_mcp_ai_conversation_import_' . $token,
			array(
				'path'    => $file,
				'preview' => $preview,
			),
			self::PREVIEW_TTL
		);

		wp_safe_redirect( $this->get_page_url( array( 'preview_token' => $token ) ) );
		exit;
	}

	/**
	 * Move an uploaded export file into the uploads directory.
	 *
	 * @param array $file Single $_FILES-shaped entry.
	 * @return string|\WP_Error Absolute file path, or a WP_Error.
	 */
	public function process_upload( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_upload_invalid',
				__( 'No valid export file was uploaded.', 'mcp-ai-wpoos' )
			);
		}

		$extension = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'zip', 'json', 'jsonl' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_upload_type',
				__( 'Only .zip, .json, and .jsonl export files are accepted.', 'mcp-ai-wpoos' )
			);
		}

		$size     = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
		$detector = new WP_MCP_AI_Conversation_Import_Format_Detector();
		if ( $size > $detector->get_max_file_bytes() ) {
			return new WP_Error(
				'wp_mcp_ai_import_file_too_large',
				__( 'The uploaded export exceeds the configured size limit.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$mimes = array(
			'zip'   => 'application/zip',
			'json'  => 'application/json',
			'jsonl' => 'application/json',
		);

		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);

		if ( ! empty( $moved['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_upload_failed',
				$moved['error']
			);
		}

		if ( empty( $moved['file'] ) || ! file_exists( $moved['file'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_upload_failed',
				__( 'The uploaded export could not be stored.', 'mcp-ai-wpoos' )
			);
		}

		return wp_normalize_path( $moved['file'] );
	}

	/**
	 * Handle the run admin-post request.
	 *
	 * @return void
	 */
	public function handle_run_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import conversations.', 'mcp-ai-wpoos' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$preview_token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : '';
		$transient_key = 'wp_mcp_ai_conversation_import_' . $preview_token;
		$stored        = get_transient( $transient_key );

		if ( ! is_array( $stored ) || empty( $stored['path'] ) || ! file_exists( $stored['path'] ) ) {
			wp_safe_redirect( $this->get_page_url( array( 'import_error' => 'preview_expired' ) ) );
			exit;
		}

		$dry_run        = isset( $_POST['dry_run'] );
		$policy         = isset( $_POST['policy'] ) ? sanitize_key( wp_unslash( $_POST['policy'] ) ) : 'skip';
		$limit          = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
		$format         = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '';
		$sideload_media = isset( $_POST['sideload_media'] );

		$job_id = $this->enqueue_run(
			array(
				'source'         => $stored['path'],
				'dry_run'        => $dry_run,
				'policy'         => $policy,
				'limit'          => $limit,
				'format'         => $format,
				'sideload_media' => $sideload_media,
				'user_id'        => get_current_user_id(),
				'estimate'       => isset( $stored['preview']['estimated_count'] ) ? absint( $stored['preview']['estimated_count'] ) : 0,
				'cleanup_source' => true,
			)
		);

		delete_transient( $transient_key );

		if ( is_wp_error( $job_id ) ) {
			wp_safe_redirect( $this->get_page_url( array( 'import_error' => $job_id->get_error_code() ) ) );
			exit;
		}

		wp_safe_redirect( $this->get_page_url( array( 'job_id' => $job_id ) ) );
		exit;
	}

	/**
	 * Enqueue an import run (factored for tests).
	 *
	 * @param array $args Queue arguments.
	 * @return int|\WP_Error Job ID, or a WP_Error.
	 */
	public function enqueue_run( array $args ) {
		if ( ! class_exists( 'WP_MCP_AI_Conversation_Import_Queue' ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_queue_missing',
				__( 'The async job queue is unavailable.', 'mcp-ai-wpoos' )
			);
		}

		return WP_MCP_AI_Conversation_Import_Queue::enqueue( $args );
	}

	/**
	 * AJAX: current import job status.
	 *
	 * @return void
	 */
	public function ajax_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		if ( $job_id <= 0 || ! class_exists( 'WP_MCP_AI_Conversation_Import_Queue' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import job.', 'mcp-ai-wpoos' ) ) );
		}

		$status = WP_MCP_AI_Conversation_Import_Queue::get_status( $job_id );
		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message() ) );
		}

		wp_send_json_success( $status );
	}

	/**
	 * AJAX: downloadable JSON report for a completed job.
	 *
	 * @return void
	 */
	public function ajax_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		if ( $job_id <= 0 || ! class_exists( 'WP_MCP_AI_Conversation_Import_Queue' ) ) {
			wp_die( esc_html__( 'Invalid import job.', 'mcp-ai-wpoos' ) );
		}

		$status = WP_MCP_AI_Conversation_Import_Queue::get_status( $job_id );
		if ( is_wp_error( $status ) || empty( $status['result'] ) ) {
			wp_die( esc_html__( 'The import report is not available.', 'mcp-ai-wpoos' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="conversation-import-' . $job_id . '.json"' );

		echo wp_json_encode( $status['result'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw JSON download; encoded via wp_json_encode().
		exit;
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$preview_token = isset( $_GET['preview_token'] ) ? sanitize_text_field( wp_unslash( $_GET['preview_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display; state-changing flows use admin-post + nonce.
		$job_id        = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display.
		$error_code    = isset( $_GET['import_error'] ) ? sanitize_key( wp_unslash( $_GET['import_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display.

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Conversation Import', 'mcp-ai-wpoos' ) . '</h1>';

		if ( '' !== $error_code ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html( $this->describe_error( $error_code ) );
			echo '</p></div>';
		}

		if ( '' !== $preview_token ) {
			$this->render_preview( $preview_token );
		} elseif ( $job_id > 0 ) {
			$this->render_job_status( $job_id );
		} else {
			$this->render_upload_form();
		}

		echo '</div>';
	}

	/**
	 * Render the upload form.
	 *
	 * @return void
	 */
	protected function render_upload_form() {
		echo '<div class="wp-mcp-ai-import__card">';
		echo '<h2>' . esc_html__( 'Import an external conversation export', 'mcp-ai-wpoos' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upload a ChatGPT conversations.json export, a Google Takeout Gemini activity export, or a ZIP archive containing either. The file is inspected first; nothing is imported until you confirm on the next screen.', 'mcp-ai-wpoos' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wp_mcp_ai_conversation_import_upload" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<p><input type="file" name="conversation_export" accept=".zip,.json,.jsonl" required /></p>';
		submit_button( __( 'Upload and inspect', 'mcp-ai-wpoos' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the preview and run confirmation form.
	 *
	 * @param string $preview_token Preview transient token.
	 * @return void
	 */
	protected function render_preview( $preview_token ) {
		$stored = get_transient( 'wp_mcp_ai_conversation_import_' . $preview_token );

		if ( ! is_array( $stored ) || empty( $stored['preview'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The preview has expired. Please upload the export again.', 'mcp-ai-wpoos' ) . '</p></div>';
			$this->render_upload_form();
			return;
		}

		$preview = $stored['preview'];

		echo '<div class="wp-mcp-ai-import__card">';
		echo '<div class="wp-mcp-ai-import__preview">';
		echo '<p><strong>' . esc_html__( 'Detected format:', 'mcp-ai-wpoos' ) . '</strong> ' . esc_html( $preview['platform'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Estimated conversations:', 'mcp-ai-wpoos' ) . '</strong> ' . esc_html( (string) $preview['estimated_count'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'File size:', 'mcp-ai-wpoos' ) . '</strong> ' . esc_html( size_format( $preview['bytes'] ) ) . '</p>';
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wp_mcp_ai_conversation_import_run" />';
		echo '<input type="hidden" name="preview_token" value="' . esc_attr( $preview_token ) . '" />';
		echo '<input type="hidden" name="format" value="' . esc_attr( $preview['platform'] ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );

		echo '<p><label><input type="checkbox" name="dry_run" value="1" /> ' . esc_html__( 'Dry run (preview only, no writes)', 'mcp-ai-wpoos' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="sideload_media" value="1" /> ' . esc_html__( 'Sideload referenced export images into the media library', 'mcp-ai-wpoos' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Existing conversations:', 'mcp-ai-wpoos' ) . ' ';
		echo '<select name="policy">';
		echo '<option value="skip">' . esc_html__( 'Skip existing (recommended)', 'mcp-ai-wpoos' ) . '</option>';
		echo '<option value="refresh">' . esc_html__( 'Refresh existing rows', 'mcp-ai-wpoos' ) . '</option>';
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Limit (0 = all):', 'mcp-ai-wpoos' ) . ' ';
		echo '<input type="number" name="limit" min="0" value="0" step="1" style="width:90px;" /></label></p>';

		submit_button( __( 'Import conversations', 'mcp-ai-wpoos' ), 'primary' );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render job progress UI.
	 *
	 * @param int $job_id Async job ID.
	 * @return void
	 */
	protected function render_job_status( $job_id ) {
		$status = class_exists( 'WP_MCP_AI_Conversation_Import_Queue' )
			? WP_MCP_AI_Conversation_Import_Queue::get_status( $job_id )
			: new WP_Error( 'wp_mcp_ai_import_queue_missing', __( 'Queue unavailable.', 'mcp-ai-wpoos' ) );

		$progress = is_wp_error( $status ) ? 0 : $status['progress'];

		$report_url = add_query_arg(
			array(
				'action' => 'wp_mcp_ai_conversation_import_report',
				'nonce'  => wp_create_nonce( self::NONCE_ACTION ),
				'job_id' => $job_id,
			),
			admin_url( 'admin-ajax.php' )
		);

		echo '<div class="wp-mcp-ai-import__card" id="wp-mcp-ai-import-job" data-job-id="' . esc_attr( (string) $job_id ) . '">';
		echo '<h2>' . esc_html__( 'Import in progress', 'mcp-ai-wpoos' ) . '</h2>';
		echo '<p id="wp-mcp-ai-import-status-text">';
		echo esc_html( is_wp_error( $status ) ? $status->get_error_message() : $status['status'] . ' (' . $progress . '%)' );
		echo '</p>';
		echo '<div class="wp-mcp-ai-import__bar"><div class="wp-mcp-ai-import__bar-fill" id="wp-mcp-ai-import-fill" style="width:' . esc_attr( (string) $progress ) . '%;"></div></div>';
		echo '<p id="wp-mcp-ai-import-report-link" style="display:none;"><a class="button" href="' . esc_url( $report_url ) . '">' . esc_html__( 'Download import report (JSON)', 'mcp-ai-wpoos' ) . '</a></p>';
		echo '<p><a href="' . esc_url( $this->get_page_url() ) . '">' . esc_html__( 'Start another import', 'mcp-ai-wpoos' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Build the page URL.
	 *
	 * @param array $args Optional query args.
	 * @return string
	 */
	protected function get_page_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
	}

	/**
	 * Describe a known import error code.
	 *
	 * @param string $error_code Error code.
	 * @return string
	 */
	protected function describe_error( $error_code ) {
		$map = array(
			'missing_file'                    => __( 'No export file was uploaded.', 'mcp-ai-wpoos' ),
			'wp_mcp_ai_import_upload_type'    => __( 'Only .zip, .json, and .jsonl export files are accepted.', 'mcp-ai-wpoos' ),
			'wp_mcp_ai_import_upload_invalid' => __( 'No valid export file was uploaded.', 'mcp-ai-wpoos' ),
			'wp_mcp_ai_import_upload_failed'  => __( 'The uploaded export could not be stored.', 'mcp-ai-wpoos' ),
			'wp_mcp_ai_import_file_too_large' => __( 'The uploaded export exceeds the configured size limit.', 'mcp-ai-wpoos' ),
			'wp_mcp_ai_import_unknown_format' => __( 'The file does not match any supported conversation export format.', 'mcp-ai-wpoos' ),
			'preview_expired'                 => __( 'The upload preview expired. Please upload the export again.', 'mcp-ai-wpoos' ),
			'wp_mcp_ai_import_queue_missing'  => __( 'The async job queue is unavailable.', 'mcp-ai-wpoos' ),
		);

		return isset( $map[ $error_code ] ) ? $map[ $error_code ] : __( 'The import could not be started.', 'mcp-ai-wpoos' );
	}
}
