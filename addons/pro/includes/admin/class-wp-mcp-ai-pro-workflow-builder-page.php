<?php
/**
 * Pro Workflow Builder Admin Page
 *
 * Advanced visual workflow builder with React-based UI.
 * Implements 2026 industry standards for AI workflow tools.
 *
 * @package WP_MCP_AI
 * @subpackage Admin
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Workflow Builder Admin Page Class
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Pro_Workflow_Builder_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-pro-workflow-builder';

	/**
	 * Actual WordPress hook name returned by add_submenu_page().
	 *
	 * Stored during register_page() so enqueue_assets() can compare against the
	 * real hook (which uses sanitize_title(menu_title) as prefix, not the raw
	 * parent slug).
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Cached templates class instance.
	 *
	 * @var WP_MCP_AI_Pattern_Workflow_Templates|null
	 */
	private $templates_instance = null;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		// Register admin menu with priority 26 to ensure parent menu (nvoos-pro-dashboard at priority 25) exists.
		add_action( 'admin_menu', array( $this, 'register_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_save_pro_workflow', array( $this, 'ajax_save_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_load_pro_workflow', array( $this, 'ajax_load_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_delete_pro_workflow', array( $this, 'ajax_delete_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_workflow_templates', array( $this, 'ajax_get_templates' ) );
		add_action( 'wp_ajax_wp_mcp_ai_execute_workflow_node', array( $this, 'ajax_execute_workflow_node' ) );
		add_action( 'wp_ajax_wp_mcp_ai_save_workflow_execution', array( $this, 'ajax_save_workflow_execution' ) );
		add_action( 'wp_ajax_wp_mcp_ai_list_pro_workflows', array( $this, 'ajax_list_workflows' ) );
		add_action( 'wp_ajax_wp_mcp_ai_export_pro_workflow', array( $this, 'ajax_export_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_duplicate_pro_workflow', array( $this, 'ajax_duplicate_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_rename_pro_workflow', array( $this, 'ajax_rename_workflow' ) );

		// Workflow presets AJAX handlers.
		add_action( 'wp_ajax_wp_mcp_ai_get_workflow_presets', array( $this, 'ajax_get_workflow_presets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_install_workflow_preset', array( $this, 'ajax_install_workflow_preset' ) );
	}

	/**
	 * Register admin page.
	 *
	 * @since 2.0.0
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Pro Workflow Builder', 'mcp-ai-wpoos' ),
			__( 'Pro Workflows', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True if debug logging is enabled, false otherwise.
	 */
	private function is_debug_logging_enabled() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Use the actual hook stored when add_submenu_page() was called so that
		// we match the real WordPress-generated hook suffix (which is derived from
		// sanitize_title(menu_title), not from the raw parent slug).
		if ( empty( $this->page_hook ) || $hook !== $this->page_hook ) {
			return;
		}

		// Enqueue the React-based workflow builder.
		$asset_file = WP_MCP_AI_PRO_PATH . 'build/workflow-builder/workflow-builder.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			// Built assets are missing. Show an admin notice via render_page() — do NOT
			// attempt to load the raw src/workflow-builder/index.jsx source file, as
			// browsers cannot execute JSX and web servers typically return a 404 for it.
			if ( $this->is_debug_logging_enabled() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled.
				error_log( sprintf( 'Workflow Builder: Built asset file not found: %s', $asset_file ) );
			}
			return;
		}

		$asset = require $asset_file;

		// Debug logging.
		if ( $this->is_debug_logging_enabled() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled.
			error_log( sprintf( 'Workflow Builder: Enqueuing built assets from %s', $asset_file ) );
		}

		wp_enqueue_script(
			'mcp-ai-pro-workflow-builder',
			WP_MCP_AI_PRO_URL . 'build/workflow-builder/workflow-builder.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'mcp-ai-pro-workflow-builder',
			WP_MCP_AI_PRO_URL . 'build/workflow-builder/workflow-builder.css',
			array(),
			$asset['version']
		);

		// Enqueue ReactFlow's CSS (compiled from `import 'reactflow/dist/style.css'`)
		// by wp-scripts into a separate style-*.css file. Without this, the canvas
		// area has no layout and nodes/edges do not render correctly.
		$style_file = WP_MCP_AI_PRO_PATH . 'build/workflow-builder/style-workflow-builder.css';
		if ( file_exists( $style_file ) ) {
			wp_enqueue_style(
				'mcp-ai-pro-workflow-builder-reactflow',
				WP_MCP_AI_PRO_URL . 'build/workflow-builder/style-workflow-builder.css',
				array(),
				$asset['version']
			);
		} elseif ( $this->is_debug_logging_enabled() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled.
			error_log( sprintf( 'Workflow Builder: ReactFlow style file not found: %s', $style_file ) );
		}

		// Localize script with data.
		wp_localize_script(
			'mcp-ai-pro-workflow-builder',
			'mcpAiWorkflowBuilder',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'mcp_ai_pro_workflow_builder' ),
				'workflows'       => $this->get_all_workflows(),
				'templates'       => $this->get_workflow_templates(),
				'workflowPresets' => $this->get_all_workflow_presets(),
				'availableTools'  => $this->get_available_tools(),
				'assistants'      => $this->get_available_assistants(),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @since 2.0.0
	 */
	public function render_page() {
		$asset_file = WP_MCP_AI_PRO_PATH . 'build/workflow-builder/workflow-builder.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Pro Workflow Builder', 'mcp-ai-wpoos' ); ?></h1>
				<div class="notice notice-error">
					<p>
						<?php
						esc_html_e(
							'The Workflow Builder compiled assets are missing. Please run `npm install && npm run build` in the plugin directory to generate the required files, then reload this page.',
							'mcp-ai-wpoos'
						);
						?>
					</p>
				</div>
			</div>
			<?php
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pro Workflow Builder', 'mcp-ai-wpoos' ); ?></h1>
			<div id="mcp-ai-pro-workflow-builder-root"></div>

			<?php $this->render_workflow_presets(); ?>
			<?php $this->render_workflow_listing(); ?>
		</div>
		<?php
	}

	/**
	 * Render the saved workflows CRUD listing table below the builder.
	 *
	 * Provides a WP-admin-styled table with Name, Description, Nodes, Edges,
	 * Created/Updated timestamps, and row actions (Edit, Duplicate, Export, Delete).
	 * The table is AJAX-powered so it refreshes without a full page reload.
	 *
	 * @since 2.1.0
	 */
	protected function render_workflow_listing() {
		$workflows = $this->get_all_workflows();
		?>
		<div id="mcp-ai-pro-workflow-listing" class="mcp-ai-pro-workflow-listing">
			<div class="mcp-ai-pro-workflow-listing-header">
				<h2>
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'Saved Workflows', 'mcp-ai-wpoos' ); ?>
					<span class="mcp-ai-pro-workflow-count">(<?php echo count( $workflows ); ?>)</span>
				</h2>
				<div class="mcp-ai-pro-workflow-listing-actions">
					<button type="button" class="button" id="mcp-ai-wf-refresh" title="<?php esc_attr_e( 'Refresh', 'mcp-ai-wpoos' ); ?>">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos' ); ?>
					</button>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped" id="mcp-ai-wf-table">
				<thead>
					<tr>
						<th class="column-name" scope="col"><?php esc_html_e( 'Name', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-description" scope="col"><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-nodes" scope="col"><?php esc_html_e( 'Nodes', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-edges" scope="col"><?php esc_html_e( 'Edges', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-created" scope="col"><?php esc_html_e( 'Created', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-updated" scope="col"><?php esc_html_e( 'Last Modified', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody id="mcp-ai-wf-table-body">
					<?php if ( empty( $workflows ) ) : ?>
						<tr class="mcp-ai-wf-empty-row">
							<td colspan="6"><?php esc_html_e( 'No saved workflows yet. Use the builder above to create one.', 'mcp-ai-wpoos' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $workflows as $id => $wf ) : ?>
							<?php $this->render_workflow_row( $id, $wf ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
				<tfoot>
					<tr>
						<th class="column-name" scope="col"><?php esc_html_e( 'Name', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-description" scope="col"><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-nodes" scope="col"><?php esc_html_e( 'Nodes', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-edges" scope="col"><?php esc_html_e( 'Edges', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-created" scope="col"><?php esc_html_e( 'Created', 'mcp-ai-wpoos' ); ?></th>
						<th class="column-updated" scope="col"><?php esc_html_e( 'Last Modified', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</tfoot>
			</table>
		</div>

		<style>
			.mcp-ai-pro-workflow-listing {
				margin-top: 30px;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 0;
			}
			.mcp-ai-pro-workflow-listing-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 12px 16px;
				border-bottom: 1px solid #c3c4c7;
				background: #f6f7f7;
			}
			.mcp-ai-pro-workflow-listing-header h2 {
				margin: 0;
				font-size: 14px;
				font-weight: 600;
				display: flex;
				align-items: center;
				gap: 6px;
			}
			.mcp-ai-pro-workflow-listing-header h2 .dashicons {
				color: #2271b1;
			}
			.mcp-ai-pro-workflow-count {
				color: #787c82;
				font-weight: 400;
			}
			#mcp-ai-wf-table {
				border: none;
				border-radius: 0;
				margin: 0;
			}
			#mcp-ai-wf-table .column-name { width: 22%; }
			#mcp-ai-wf-table .column-description { width: 30%; }
			#mcp-ai-wf-table .column-nodes,
			#mcp-ai-wf-table .column-edges { width: 8%; text-align: center; }
			#mcp-ai-wf-table .column-created,
			#mcp-ai-wf-table .column-updated { width: 16%; }
			#mcp-ai-wf-table .row-actions {
				visibility: hidden;
				padding: 2px 0 0;
			}
			#mcp-ai-wf-table tr:hover .row-actions {
				visibility: visible;
			}
			#mcp-ai-wf-table .row-actions a {
				cursor: pointer;
			}
			#mcp-ai-wf-table .row-actions .delete a {
				color: #b32d2e;
			}
			#mcp-ai-wf-table .row-actions .delete a:hover {
				color: #a00;
			}
			.mcp-ai-wf-empty-row td {
				text-align: center;
				color: #787c82;
				padding: 20px !important;
			}
			.mcp-ai-pro-wf-node-count {
				display: inline-block;
				min-width: 24px;
				text-align: center;
				background: #f0f0f1;
				border-radius: 10px;
				padding: 2px 8px;
				font-size: 12px;
			}
			.mcp-ai-wf-rename-input {
				width: 100%;
				max-width: 200px;
			}
		</style>

		<script>
		( function( $ ) {
			'use strict';

			var WF_NONCE = ( typeof mcpAiWorkflowBuilder !== 'undefined' ) ? mcpAiWorkflowBuilder.nonce : '';
			var AJAX_URL = ( typeof mcpAiWorkflowBuilder !== 'undefined' ) ? mcpAiWorkflowBuilder.ajaxUrl : ajaxurl;

			function formatDate( ts ) {
				if ( ! ts ) { return '—'; }
				var d = new Date( ts * 1000 );
				return d.toLocaleDateString() + ' ' + d.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
			}

			function escHtml( str ) {
				var div = document.createElement( 'div' );
				div.appendChild( document.createTextNode( str || '' ) );
				return div.innerHTML;
			}

			function buildRow( id, wf ) {
				var nodes = ( wf.nodes && Array.isArray( wf.nodes ) ) ? wf.nodes.length : 0;
				var edges = ( wf.edges && Array.isArray( wf.edges ) ) ? wf.edges.length : 0;
				var html = '<tr data-workflow-id="' + escHtml( id ) + '">';
				html += '<td class="column-name"><strong>' + escHtml( wf.name || id ) + '</strong>';
				html += '<div class="row-actions">';
				html += '<span class="edit"><a data-action="edit" data-id="' + escHtml( id ) + '">' + '<?php echo esc_js( __( 'Edit in Builder', 'mcp-ai-wpoos' ) ); ?>' + '</a> | </span>';
				html += '<span class="duplicate"><a data-action="duplicate" data-id="' + escHtml( id ) + '">' + '<?php echo esc_js( __( 'Duplicate', 'mcp-ai-wpoos' ) ); ?>' + '</a> | </span>';
				html += '<span class="export"><a data-action="export" data-id="' + escHtml( id ) + '">' + '<?php echo esc_js( __( 'Export JSON', 'mcp-ai-wpoos' ) ); ?>' + '</a> | </span>';
				html += '<span class="rename"><a data-action="rename" data-id="' + escHtml( id ) + '">' + '<?php echo esc_js( __( 'Rename', 'mcp-ai-wpoos' ) ); ?>' + '</a> | </span>';
				html += '<span class="delete"><a data-action="delete" data-id="' + escHtml( id ) + '">' + '<?php echo esc_js( __( 'Delete', 'mcp-ai-wpoos' ) ); ?>' + '</a></span>';
				html += '</div></td>';
				html += '<td class="column-description">' + escHtml( wf.description || '' ) + '</td>';
				html += '<td class="column-nodes"><span class="mcp-ai-pro-wf-node-count">' + nodes + '</span></td>';
				html += '<td class="column-edges"><span class="mcp-ai-pro-wf-node-count">' + edges + '</span></td>';
				html += '<td class="column-created">' + formatDate( wf.created_at ) + '</td>';
				html += '<td class="column-updated">' + formatDate( wf.updated_at ) + '</td>';
				html += '</tr>';
				return html;
			}

			function refreshTable() {
				$.post( AJAX_URL, {
					action: 'wp_mcp_ai_list_pro_workflows',
					nonce:  WF_NONCE
				}, function( res ) {
					if ( ! res.success ) { return; }
					var workflows = res.data.workflows || [];
					var $body = $( '#mcp-ai-wf-table-body' );
					$body.empty();
					$( '.mcp-ai-pro-workflow-count' ).text( '(' + workflows.length + ')' );
					if ( ! workflows.length ) {
						$body.html( '<tr class="mcp-ai-wf-empty-row"><td colspan="6">' +
							'<?php echo esc_js( __( 'No saved workflows yet. Use the builder above to create one.', 'mcp-ai-wpoos' ) ); ?>' +
							'</td></tr>' );
						return;
					}
					$.each( workflows, function( _, wf ) {
						$body.append( buildRow( wf.id, wf ) );
					} );
				} );
			}

			$( document ).on( 'click', '#mcp-ai-wf-refresh', refreshTable );

			// Listen for React builder save events (custom event dispatched by the React app).
			window.addEventListener( 'mcpAiWorkflowSaved', refreshTable );

			// Row actions.
			$( document ).on( 'click', '#mcp-ai-wf-table [data-action]', function( e ) {
				e.preventDefault();
				var $el    = $( this );
				var action = $el.data( 'action' );
				var id     = $el.data( 'id' );

				switch ( action ) {
					case 'edit':
						// Dispatch custom event for the React builder to load this workflow.
						window.dispatchEvent( new CustomEvent( 'mcpAiLoadWorkflow', { detail: { workflowId: id } } ) );
						// Scroll to builder.
						$( 'html, body' ).animate( { scrollTop: $( '#mcp-ai-pro-workflow-builder-root' ).offset().top - 40 }, 300 );
						break;

					case 'duplicate':
						if ( ! window.confirm( '<?php echo esc_js( __( 'Duplicate this workflow?', 'mcp-ai-wpoos' ) ); ?>' ) ) { return; }
						$.post( AJAX_URL, {
							action:      'wp_mcp_ai_duplicate_pro_workflow',
							nonce:       WF_NONCE,
							workflow_id: id
						}, function( res ) {
							if ( res.success ) {
								refreshTable();
							} else {
								alert( res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Failed to duplicate workflow.', 'mcp-ai-wpoos' ) ); ?>' );
							}
						} );
						break;

					case 'export':
						$.post( AJAX_URL, {
							action:      'wp_mcp_ai_export_pro_workflow',
							nonce:       WF_NONCE,
							workflow_id: id
						}, function( res ) {
							if ( res.success && res.data.workflow ) {
								var blob = new Blob( [ JSON.stringify( res.data.workflow, null, 2 ) ], { type: 'application/json' } );
								var url  = URL.createObjectURL( blob );
								var a    = document.createElement( 'a' );
								a.href     = url;
								a.download = ( res.data.workflow.name || id ) + '.json';
								document.body.appendChild( a );
								a.click();
								document.body.removeChild( a );
								URL.revokeObjectURL( url );
							} else {
								alert( res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Failed to export workflow.', 'mcp-ai-wpoos' ) ); ?>' );
							}
						} );
						break;

					case 'rename':
						var $td   = $el.closest( 'td' );
						var $strong = $td.find( 'strong' );
						var current = $strong.text();
						var $input = $( '<input>' ).attr( { type: 'text', 'class': 'mcp-ai-wf-rename-input' } ).val( current );
						$strong.empty().append( $input );
						$input.focus().select();
						$input.on( 'blur keydown', function( ev ) {
							if ( ev.type === 'keydown' && ev.which !== 13 && ev.which !== 27 ) { return; }
							if ( ev.type === 'keydown' && ev.which === 27 ) {
								$strong.text( current );
								return;
							}
							var newName = $input.val().trim();
							if ( ! newName || newName === current ) {
								$strong.text( current );
								return;
							}
							$.post( AJAX_URL, {
								action:      'wp_mcp_ai_rename_pro_workflow',
								nonce:       WF_NONCE,
								workflow_id: id,
								new_name:    newName
							}, function( res ) {
								if ( res.success ) {
									refreshTable();
								} else {
									$strong.text( current );
									alert( res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Failed to rename workflow.', 'mcp-ai-wpoos' ) ); ?>' );
								}
							} );
						} );
						break;

					case 'delete':
						if ( ! window.confirm( '<?php echo esc_js( __( 'Are you sure you want to delete this workflow? This cannot be undone.', 'mcp-ai-wpoos' ) ); ?>' ) ) { return; }
						$.post( AJAX_URL, {
							action:      'wp_mcp_ai_delete_pro_workflow',
							nonce:       WF_NONCE,
							workflow_id: id
						}, function( res ) {
							if ( res.success ) {
								refreshTable();
							} else {
								alert( res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Failed to delete workflow.', 'mcp-ai-wpoos' ) ); ?>' );
							}
						} );
						break;
				}
			} );
		} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Render a single workflow table row.
	 *
	 * @since 2.1.0
	 *
	 * @param string $id Workflow ID (key).
	 * @param array  $wf Workflow data.
	 */
	protected function render_workflow_row( $id, $wf ) {
		$nodes = isset( $wf['nodes'] ) && is_array( $wf['nodes'] ) ? count( $wf['nodes'] ) : 0;
		$edges = isset( $wf['edges'] ) && is_array( $wf['edges'] ) ? count( $wf['edges'] ) : 0;
		?>
		<tr data-workflow-id="<?php echo esc_attr( $id ); ?>">
			<td class="column-name">
				<strong><?php echo esc_html( ! empty( $wf['name'] ) ? $wf['name'] : $id ); ?></strong>
				<div class="row-actions">
					<span class="edit">
						<a data-action="edit" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Edit in Builder', 'mcp-ai-wpoos' ); ?></a> |
					</span>
					<span class="duplicate">
						<a data-action="duplicate" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Duplicate', 'mcp-ai-wpoos' ); ?></a> |
					</span>
					<span class="export">
						<a data-action="export" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Export JSON', 'mcp-ai-wpoos' ); ?></a> |
					</span>
					<span class="rename">
						<a data-action="rename" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Rename', 'mcp-ai-wpoos' ); ?></a> |
					</span>
					<span class="delete">
						<a data-action="delete" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos' ); ?></a>
					</span>
				</div>
			</td>
			<td class="column-description"><?php echo esc_html( $wf['description'] ?? '' ); ?></td>
			<td class="column-nodes"><span class="mcp-ai-pro-wf-node-count"><?php echo (int) $nodes; ?></span></td>
			<td class="column-edges"><span class="mcp-ai-pro-wf-node-count"><?php echo (int) $edges; ?></span></td>
			<td class="column-created"><?php echo esc_html( ! empty( $wf['created_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $wf['created_at'] ) : '—' ); ?></td>
			<td class="column-updated"><?php echo esc_html( ! empty( $wf['updated_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $wf['updated_at'] ) : '—' ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Get all workflows.
	 *
	 * @since 2.0.0
	 *
	 * @return array Workflows.
	 */
	protected function get_all_workflows() {
		$workflows = get_option( 'wp_mcp_ai_pro_workflows', array() );
		return is_array( $workflows ) ? $workflows : array();
	}

	/**
	 * Get workflow templates.
	 *
	 * @since 2.0.0
	 *
	 * @return array Templates.
	 */
	protected function get_workflow_templates() {
		// Cache the templates class instance to avoid repeated instantiation.
		// Check for both the class and the constants it depends on.
		if ( null === $this->templates_instance &&
			class_exists( 'WP_MCP_AI_Pattern_Workflow_Templates' ) &&
			class_exists( 'WP_MCP_AI_Pattern_Constants' ) ) {
			try {
				$this->templates_instance = new WP_MCP_AI_Pattern_Workflow_Templates();
			} catch ( Exception $e ) {
				// Log error if debugging is enabled.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WP_MCP_AI: Failed to instantiate workflow templates: ' . $e->getMessage() );
				}
				return array();
			}
		}

		if ( ! $this->templates_instance ) {
			return array();
		}

		try {
			return $this->templates_instance->get_all_templates();
		} catch ( Exception $e ) {
			// Log error if debugging is enabled.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP_MCP_AI: Failed to get workflow templates: ' . $e->getMessage() );
			}
			return array();
		}
	}

	/**
	 * AJAX handler for saving workflows.
	 *
	 * @since 2.0.0
	 */
	public function ajax_save_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_json = isset( $_POST['workflow'] ) ? wp_unslash( $_POST['workflow'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload decoded with json_decode() and validated downstream.

		if ( empty( $workflow_json ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow data required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow = json_decode( $workflow_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( array( 'message' => __( 'Invalid workflow data.', 'mcp-ai-wpoos' ) ) );
		}

		// Validate workflow structure.
		if ( empty( $workflow['name'] ) || empty( $workflow['nodes'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow must have a name and nodes.', 'mcp-ai-wpoos' ) ) );
		}

		// Sanitize workflow data.
		$workflow['name']        = sanitize_text_field( $workflow['name'] );
		$workflow['description'] = isset( $workflow['description'] ) ? sanitize_textarea_field( $workflow['description'] ) : '';

		// Generate workflow ID from name.
		$workflow_id = sanitize_key( $workflow['name'] );

		// Get existing workflows.
		$workflows = $this->get_all_workflows();

		// Add/update workflow.
		$workflows[ $workflow_id ] = array(
			'id'          => $workflow_id,
			'name'        => $workflow['name'],
			'description' => $workflow['description'],
			'nodes'       => $workflow['nodes'],
			'edges'       => $workflow['edges'],
			'created_at'  => isset( $workflows[ $workflow_id ]['created_at'] ) ? $workflows[ $workflow_id ]['created_at'] : time(),
			'updated_at'  => time(),
		);

		// Save workflows.
		$result = update_option( 'wp_mcp_ai_pro_workflows', $workflows );

		if ( $result ) {
			wp_send_json_success(
				array(
					'message'  => __( 'Workflow saved successfully.', 'mcp-ai-wpoos' ),
					'workflow' => $workflows[ $workflow_id ],
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save workflow.', 'mcp-ai-wpoos' ) ) );
		}
	}

	/**
	 * AJAX handler for loading workflow.
	 *
	 * @since 2.0.0
	 */
	public function ajax_load_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_key( $_POST['workflow_id'] ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

		wp_send_json_success(
			array(
				'workflow' => $workflows[ $workflow_id ],
			)
		);
	}

	/**
	 * AJAX handler for deleting workflows.
	 *
	 * @since 2.0.0
	 */
	public function ajax_delete_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_key( $_POST['workflow_id'] ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

		unset( $workflows[ $workflow_id ] );

		$result = update_option( 'wp_mcp_ai_pro_workflows', $workflows );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Workflow deleted successfully.', 'mcp-ai-wpoos' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to delete workflow.', 'mcp-ai-wpoos' ) ) );
		}
	}

	/**
	 * AJAX handler for getting workflow templates.
	 *
	 * @since 2.0.0
	 */
	public function ajax_get_templates() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$templates = $this->get_workflow_templates();

		wp_send_json_success(
			array(
				'templates' => $templates,
			)
		);
	}

	/**
	 * AJAX handler for listing all workflows.
	 *
	 * @since 2.0.0
	 */
	public function ajax_list_workflows() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		wp_send_json_success(
			array(
				'workflows' => array_values( $workflows ),
			)
		);
	}

	/**
	 * AJAX handler for exporting a workflow as JSON.
	 *
	 * @since 2.0.0
	 */
	public function ajax_export_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_key( $_POST['workflow_id'] ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

		wp_send_json_success(
			array(
				'workflow' => $workflows[ $workflow_id ],
			)
		);
	}

	/**
	 * AJAX handler for duplicating a workflow.
	 *
	 * Creates a copy of the specified workflow with a " (Copy)" suffix.
	 *
	 * @since 2.1.0
	 */
	public function ajax_duplicate_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_key( $_POST['workflow_id'] ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

		$original  = $workflows[ $workflow_id ];
		$copy_name = sanitize_text_field( $original['name'] . ' (Copy)' );
		$copy_id   = sanitize_key( $copy_name );

		// Ensure uniqueness.
		$suffix = 2;
		while ( isset( $workflows[ $copy_id ] ) ) {
			$copy_name = sanitize_text_field( $original['name'] . ' (Copy ' . $suffix . ')' );
			$copy_id   = sanitize_key( $copy_name );
			++$suffix;
		}

		$workflows[ $copy_id ] = array(
			'id'          => $copy_id,
			'name'        => $copy_name,
			'description' => $original['description'] ?? '',
			'nodes'       => $original['nodes'] ?? array(),
			'edges'       => $original['edges'] ?? array(),
			'created_at'  => time(),
			'updated_at'  => time(),
		);

		$result = update_option( 'wp_mcp_ai_pro_workflows', $workflows );

		// update_option returns false both on failure and when the value is unchanged.
		// Since we always add a new key, verify success by checking the stored data.
		if ( $result || isset( get_option( 'wp_mcp_ai_pro_workflows', array() )[ $copy_id ] ) ) {
			wp_send_json_success(
				array(
					'message'  => __( 'Workflow duplicated successfully.', 'mcp-ai-wpoos' ),
					'workflow' => $workflows[ $copy_id ],
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to duplicate workflow.', 'mcp-ai-wpoos' ) ) );
		}
	}

	/**
	 * AJAX handler for renaming a workflow.
	 *
	 * Updates the workflow name and re-keys the workflow in storage.
	 *
	 * @since 2.1.0
	 */
	public function ajax_rename_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_key( $_POST['workflow_id'] ) : '';
		$new_name    = isset( $_POST['new_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_name'] ) ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		if ( empty( $new_name ) ) {
			wp_send_json_error( array( 'message' => __( 'New name is required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

		$new_id = sanitize_key( $new_name );

		// If the ID changed, re-key the workflow.
		if ( $new_id !== $workflow_id ) {
			if ( isset( $workflows[ $new_id ] ) ) {
				wp_send_json_error( array( 'message' => __( 'A workflow with that name already exists.', 'mcp-ai-wpoos' ) ) );
			}
			$workflows[ $new_id ] = $workflows[ $workflow_id ];
			unset( $workflows[ $workflow_id ] );
		}

		$workflows[ $new_id ]['id']         = $new_id;
		$workflows[ $new_id ]['name']       = $new_name;
		$workflows[ $new_id ]['updated_at'] = time();

		$result = update_option( 'wp_mcp_ai_pro_workflows', $workflows );

		// update_option returns false both on failure and when the value is unchanged.
		// Verify success by checking the stored data contains the new key.
		if ( $result || isset( get_option( 'wp_mcp_ai_pro_workflows', array() )[ $new_id ] ) ) {
			wp_send_json_success(
				array(
					'message'  => __( 'Workflow renamed successfully.', 'mcp-ai-wpoos' ),
					'workflow' => $workflows[ $new_id ],
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to rename workflow.', 'mcp-ai-wpoos' ) ) );
		}
	}

	/**
	 * AJAX handler for executing a workflow node.
	 *
	 * Dispatches to the appropriate execution method based on node type.
	 *
	 * @since 2.0.0
	 */
	public function ajax_execute_workflow_node() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$node_type = isset( $_POST['node_type'] ) ? sanitize_key( $_POST['node_type'] ) : '';

		if ( empty( $node_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Node type required.', 'mcp-ai-wpoos' ) ) );
		}

		// Parse the execution context (results from previous nodes).
		$context_json = isset( $_POST['context'] ) ? wp_unslash( $_POST['context'] ) : '{}'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload decoded with json_decode() and validated downstream.
		$context      = json_decode( $context_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$context = array();
		}

		switch ( $node_type ) {
			case 'action':
				$result = $this->execute_action_node( $context );
				break;

			case 'tool':
				$result = $this->execute_tool_node( $context );
				break;

			case 'agent':
				$result = $this->execute_agent_node( $context );
				break;

			default:
				wp_send_json_error( array( 'message' => sprintf( __( 'Unsupported node type: %s', 'mcp-ai-wpoos' ), $node_type ) ) );
				return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_key( wp_unslash( $_POST['workflow_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$node_id = isset( $_POST['node_id'] ) ? sanitize_text_field( wp_unslash( $_POST['node_id'] ) ) : '';

		/**
		 * Fires after a Pro workflow node has been executed.
		 *
		 * Listeners (e.g. WP_MCP_AI_Pro_Workflow_Bridge) use this to mirror
		 * Pro execution into the base Workflow Run CPT for observability.
		 *
		 * @since 1.6.0
		 *
		 * @param string         $node_type   Node type: action|tool|agent.
		 * @param string         $node_id     Workflow-scoped node identifier.
		 * @param string         $workflow_id Pro workflow ID (sanitize_key form).
		 * @param array|WP_Error $result      Execution result or error.
		 * @param array          $context     Execution context from previous nodes.
		 */
		do_action( 'wp_mcp_ai_pro_workflow_node_executed', $node_type, $node_id, $workflow_id, $result, $context );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * Execute an action (slash command) node.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Execution context from previous nodes.
	 * @return array|WP_Error Execution result.
	 */
	private function execute_action_node( $context ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() in ajax_execute_workflow_node().
		$command = isset( $_POST['command'] ) ? sanitize_text_field( wp_unslash( $_POST['command'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in caller; JSON payload decoded with json_decode().
		$params = isset( $_POST['params'] ) ? wp_unslash( $_POST['params'] ) : '{}';

		if ( empty( $command ) ) {
			return new WP_Error( 'missing_command', __( 'Action node missing command.', 'mcp-ai-wpoos' ) );
		}

		$params_array = json_decode( $params, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$params_array = array();
		}

		// Apply context variable substitution.
		$params_array = $this->apply_context_to_params( $params_array, $context );

		/**
		 * Filter to allow action node execution via third-party hooks.
		 *
		 * @since 2.0.0
		 *
		 * @param array|WP_Error|null $result  Execution result, or null to use default.
		 * @param string              $command Slash command.
		 * @param array               $params  Command parameters.
		 * @param array               $context Execution context.
		 */
		$result = apply_filters( 'wp_mcp_ai_workflow_execute_action', null, $command, $params_array, $context );

		if ( null !== $result ) {
			return $result;
		}

		return array(
			'type'    => 'action',
			'command' => $command,
			'params'  => $params_array,
			'status'  => 'completed',
			'message' => sprintf( __( 'Command "%s" queued for execution.', 'mcp-ai-wpoos' ), $command ),
		);
	}

	/**
	 * Execute a tool node.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Execution context from previous nodes.
	 * @return array|WP_Error Execution result.
	 */
	private function execute_tool_node( $context ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() in ajax_execute_workflow_node().
		$tool_name = isset( $_POST['tool_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tool_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in caller; JSON payload decoded with json_decode().
		$args_json = isset( $_POST['tool_arguments'] ) ? wp_unslash( $_POST['tool_arguments'] ) : '{}';

		if ( empty( $tool_name ) ) {
			return new WP_Error( 'missing_tool', __( 'Tool node missing tool_name.', 'mcp-ai-wpoos' ) );
		}

		$arguments = json_decode( $args_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$arguments = array();
		}

		// Apply context variable substitution.
		$arguments = $this->apply_context_to_params( $arguments, $context );

		/**
		 * Pre-execute filter to allow short-circuiting tool execution.
		 *
		 * Used by the Pro Workflow Bridge to enforce HITL approvals and the
		 * prompt-injection guardrail before the tool registry is invoked.
		 * Returning a non-null value (array or WP_Error) skips registry
		 * execution entirely.
		 *
		 * @since 1.6.0
		 *
		 * @param array|WP_Error|null $short_circuit Pre-execute result, or null to proceed normally.
		 * @param string              $tool_name     Tool name.
		 * @param array               $arguments     Tool arguments after context substitution.
		 * @param array               $context       Execution context.
		 */
		$short_circuit = apply_filters( 'wp_mcp_ai_pro_workflow_pre_execute_tool', null, $tool_name, $arguments, $context );

		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		// Try to execute via the tool registry.
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$tool     = $registry->get_tool( $tool_name );

			if ( $tool ) {
				try {
					$tool_result = $tool->execute( $arguments, array( 'context' => $context ) );
					return array(
						'type'      => 'tool',
						'tool_name' => $tool_name,
						'arguments' => $arguments,
						'status'    => 'completed',
						'result'    => $tool_result,
					);
				} catch ( Exception $e ) {
					return new WP_Error( 'tool_execution_failed', $e->getMessage() );
				}
			}
		}

		/**
		 * Filter to allow tool node execution via third-party hooks.
		 *
		 * @since 2.0.0
		 *
		 * @param array|WP_Error|null $result    Execution result, or null to use default.
		 * @param string              $tool_name Tool name.
		 * @param array               $arguments Tool arguments.
		 * @param array               $context   Execution context.
		 */
		$result = apply_filters( 'wp_mcp_ai_workflow_execute_tool', null, $tool_name, $arguments, $context );

		if ( null !== $result ) {
			return $result;
		}

		return new WP_Error( 'tool_not_found', sprintf( __( 'Tool "%s" not found.', 'mcp-ai-wpoos' ), $tool_name ) );
	}

	/**
	 * Execute an agent node.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Execution context from previous nodes.
	 * @return array|WP_Error Execution result.
	 */
	private function execute_agent_node( $context ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() in ajax_execute_workflow_node().
		$agent_id = isset( $_POST['agent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_id'] ) ) : 'default';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() in ajax_execute_workflow_node().
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';

		if ( empty( $prompt ) ) {
			return new WP_Error( 'missing_prompt', __( 'Agent node missing prompt.', 'mcp-ai-wpoos' ) );
		}

		// Substitute context variables in prompt.
		$prompt = $this->apply_context_to_string( $prompt, $context );

		/**
		 * Filter to allow agent node execution via third-party hooks.
		 *
		 * @since 2.0.0
		 *
		 * @param array|WP_Error|null $result   Execution result, or null to use default.
		 * @param string              $agent_id Agent identifier.
		 * @param string              $prompt   Prompt text.
		 * @param array               $context  Execution context.
		 */
		$result = apply_filters( 'wp_mcp_ai_workflow_execute_agent', null, $agent_id, $prompt, $context );

		if ( null !== $result ) {
			return $result;
		}

		return array(
			'type'     => 'agent',
			'agent_id' => $agent_id,
			'prompt'   => $prompt,
			'status'   => 'completed',
			'message'  => __( 'Agent execution queued.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Substitute context variables in a parameter array.
	 *
	 * Replaces {{key}} or {{nodeId.field}} placeholders with values from context.
	 *
	 * @since 2.0.0
	 *
	 * @param array $params  Parameters array to process.
	 * @param array $context Execution context.
	 * @return array Processed parameters.
	 */
	private function apply_context_to_params( $params, $context ) {
		if ( ! is_array( $params ) || empty( $context ) ) {
			return $params;
		}

		array_walk_recursive(
			$params,
			function ( &$value ) use ( $context ) {
				if ( is_string( $value ) ) {
					$value = $this->apply_context_to_string( $value, $context );
				}
			}
		);

		return $params;
	}

	/**
	 * Substitute context variables in a string.
	 *
	 * @since 2.0.0
	 *
	 * @param string $text    Text containing placeholders.
	 * @param array  $context Execution context.
	 * @return string Processed text.
	 */
	private function apply_context_to_string( $text, $context ) {
		if ( ! is_string( $text ) || empty( $context ) ) {
			return $text;
		}

		// Replace {{nodeId.field}} and {{key}} patterns.
		$text = preg_replace_callback(
			'/\{\{([^}]+)\}\}/',
			function ( $matches ) use ( $context ) {
				$path  = explode( '.', trim( $matches[1] ) );
				$value = $context;
				foreach ( $path as $key ) {
					if ( is_array( $value ) && isset( $value[ $key ] ) ) {
						$value = $value[ $key ];
					} else {
						return $matches[0]; // Return original placeholder if not found.
					}
				}
				return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			},
			$text
		);

		return $text;
	}

	/**
	 * AJAX handler for saving workflow execution records.
	 *
	 * @since 2.0.0
	 */
	public function ajax_save_workflow_execution() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$execution_json = isset( $_POST['execution'] ) ? wp_unslash( $_POST['execution'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload decoded with json_decode() and validated downstream.

		if ( empty( $execution_json ) ) {
			wp_send_json_error( array( 'message' => __( 'Execution data required.', 'mcp-ai-wpoos' ) ) );
		}

		$execution = json_decode( $execution_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( array( 'message' => __( 'Invalid execution data.', 'mcp-ai-wpoos' ) ) );
		}

		// Sanitize execution record fields.
		$sanitized = array(
			'id'              => sanitize_text_field( $execution['id'] ?? '' ),
			'workflow_id'     => sanitize_key( $execution['workflowId'] ?? '' ),
			'timestamp'       => absint( $execution['timestamp'] ?? time() ),
			'duration'        => absint( $execution['duration'] ?? 0 ),
			'status'          => sanitize_text_field( $execution['status'] ?? 'unknown' ),
			'node_count'      => absint( $execution['nodeCount'] ?? 0 ),
			'completed_nodes' => absint( $execution['completedNodes'] ?? 0 ),
			'failed_nodes'    => absint( $execution['failedNodes'] ?? 0 ),
		);

		if ( empty( $sanitized['workflow_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		// Load existing execution logs.
		$log_key = 'wp_mcp_ai_workflow_executions_' . $sanitized['workflow_id'];
		$log     = get_option( $log_key, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// Prepend the new record.
		array_unshift( $log, $sanitized );

		// Keep only the last 100 executions per workflow.
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, 0, 100 );
		}

		update_option( $log_key, $log );

		/**
		 * Fires after a Pro workflow execution record has been persisted.
		 *
		 * Listeners (e.g. WP_MCP_AI_Pro_Workflow_Bridge) use this to finalize
		 * the corresponding base Workflow Run CPT record (set status,
		 * record terminal cost/token totals).
		 *
		 * @since 1.6.0
		 *
		 * @param array $sanitized Sanitized execution record (id, workflow_id, status, etc.).
		 */
		do_action( 'wp_mcp_ai_pro_workflow_execution_saved', $sanitized );

		wp_send_json_success( array( 'message' => __( 'Execution saved.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * Get the list of available MCP tools for the workflow builder.
	 *
	 * @since 2.0.0
	 *
	 * @return array List of tools with name and description.
	 */
	protected function get_available_tools() {
		$tools = array();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return $tools;
		}

		try {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$all      = $registry->get_all_tools();

			foreach ( $all as $slug => $tool ) {
				$definition = method_exists( $tool, 'get_definition' ) ? $tool->get_definition() : array();
				$tools[]    = array(
					'name'        => sanitize_text_field( $slug ),
					'label'       => sanitize_text_field( $definition['name'] ?? $slug ),
					'description' => sanitize_text_field( $definition['description'] ?? '' ),
				);
			}
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP_MCP_AI: Failed to get available tools: ' . $e->getMessage() );
			}
		}

		return $tools;
	}

	/**
	 * Get the list of available AI assistants for the workflow builder.
	 *
	 * @since 2.0.0
	 *
	 * @return array List of assistants with id and name.
	 */
	protected function get_available_assistants() {
		$assistants = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $query->posts as $post ) {
			$assistants[] = array(
				'id'   => $post->ID,
				'name' => get_the_title( $post ),
				'slug' => $post->post_name,
			);
		}

		wp_reset_postdata();

		return $assistants;
	}

	// -------------------------------------------------------------------------
	// Workflow Presets
	// -------------------------------------------------------------------------

	/**
	 * Retrieve all workflow presets.
	 *
	 * @since  2.1.0
	 * @return array Presets array suitable for JS localization.
	 */
	protected function get_all_workflow_presets() {
		$presets_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-workflow-presets.php';
		if ( ! file_exists( $presets_file ) ) {
			return array();
		}
		require_once $presets_file;

		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			return array();
		}

		$raw    = WP_MCP_AI_Pro_Workflow_Presets::get_presets();
		$output = array();

		foreach ( $raw as $id => $preset ) {
			$output[] = array(
				'id'          => $id,
				'name'        => isset( $preset['name'] ) ? $preset['name'] : '',
				'description' => isset( $preset['description'] ) ? $preset['description'] : '',
				'category'    => isset( $preset['category'] ) ? $preset['category'] : '',
				'icon'        => isset( $preset['icon'] ) ? $preset['icon'] : 'dashicons-randomize',
				'tags'        => isset( $preset['tags'] ) ? $preset['tags'] : array(),
				'nodes'       => isset( $preset['nodes'] ) ? $preset['nodes'] : array(),
				'edges'       => isset( $preset['edges'] ) ? $preset['edges'] : array(),
			);
		}

		return $output;
	}

	/**
	 * Render the workflow presets browser below the builder canvas.
	 *
	 * @since 2.1.0
	 */
	protected function render_workflow_presets() {
		$presets_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-workflow-presets.php';
		if ( ! file_exists( $presets_file ) ) {
			return;
		}
		require_once $presets_file;

		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			return;
		}

		$categories = WP_MCP_AI_Pro_Workflow_Presets::get_categories();
		$presets    = WP_MCP_AI_Pro_Workflow_Presets::get_presets();
		?>
		<div id="mcp-ai-pro-workflow-presets" class="mcp-ai-pro-workflow-presets" style="margin-top:24px;">
			<div class="mcp-ai-pro-workflow-presets-header">
				<h2>
					<span class="dashicons dashicons-welcome-widgets-menus"></span>
					<?php esc_html_e( 'Workflow Presets', 'mcp-ai-wpoos-pro' ); ?>
					<span class="mcp-ai-pro-workflow-preset-count">(<?php echo count( $presets ); ?>)</span>
				</h2>
				<div class="mcp-ai-pro-workflow-presets-filters">
					<select id="mcp-ai-wf-preset-category" class="mcp-ai-pro-wf-filter">
						<option value=""><?php esc_html_e( 'All Categories', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $categories as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="search" id="mcp-ai-wf-preset-search" class="mcp-ai-pro-wf-filter" placeholder="<?php esc_attr_e( 'Search presets…', 'mcp-ai-wpoos-pro' ); ?>">
				</div>
			</div>

			<div id="mcp-ai-wf-presets-grid" class="mcp-ai-pro-workflow-presets-grid">
				<?php foreach ( $presets as $id => $preset ) : ?>
					<div class="mcp-ai-pro-workflow-preset-card" data-category="<?php echo esc_attr( $preset['category'] ); ?>">
						<div class="mcp-ai-pro-workflow-preset-card-header">
							<span class="dashicons <?php echo esc_attr( $preset['icon'] ); ?>"></span>
							<strong><?php echo esc_html( $preset['name'] ); ?></strong>
						</div>
						<p class="mcp-ai-pro-workflow-preset-desc"><?php echo esc_html( $preset['description'] ); ?></p>
						<div class="mcp-ai-pro-workflow-preset-meta">
							<span class="mcp-ai-pro-workflow-preset-category"><?php echo esc_html( $preset['category'] ); ?></span>
							<span class="mcp-ai-pro-workflow-preset-nodes"><?php echo esc_html( count( $preset['nodes'] ) ); ?> <?php esc_html_e( 'nodes', 'mcp-ai-wpoos-pro' ); ?></span>
						</div>
						<?php if ( ! empty( $preset['tags'] ) ) : ?>
							<div class="mcp-ai-pro-workflow-preset-tags">
								<?php foreach ( $preset['tags'] as $tag ) : ?>
									<span class="mcp-ai-pro-workflow-preset-tag"><?php echo esc_html( $tag ); ?></span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<button type="button" class="button button-primary button-small" data-wf-preset-install="<?php echo esc_attr( $id ); ?>">
							<?php esc_html_e( 'Load into Builder', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<style>
			.mcp-ai-pro-workflow-presets-header {
				display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 12px;
			}
			.mcp-ai-pro-workflow-presets-header h2 {
				display: flex; align-items: center; gap: 6px; margin: 0; font-size: 1.1em;
			}
			.mcp-ai-pro-workflow-presets-filters {
				display: flex; gap: 8px; align-items: center; margin-left: auto;
			}
			.mcp-ai-pro-wf-filter { min-width: 150px; }
			.mcp-ai-pro-workflow-presets-grid {
				display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;
			}
			.mcp-ai-pro-workflow-preset-card {
				background: #f9f9f9; border: 1px solid #dcdcde; border-radius: 6px;
				padding: 16px; display: flex; flex-direction: column; gap: 8px;
				transition: box-shadow 0.15s, border-color 0.15s;
			}
			.mcp-ai-pro-workflow-preset-card:hover {
				border-color: #2271b1; box-shadow: 0 1px 4px rgba(0,0,0,.08);
			}
			.mcp-ai-pro-workflow-preset-card-header {
				display: flex; align-items: center; gap: 8px;
			}
			.mcp-ai-pro-workflow-preset-card-header .dashicons {
				color: #2271b1; font-size: 20px; width: 20px; height: 20px;
			}
			.mcp-ai-pro-workflow-preset-desc {
				color: #50575e; font-size: 0.85em; line-height: 1.5; margin: 0; flex: 1;
			}
			.mcp-ai-pro-workflow-preset-meta {
				display: flex; gap: 8px; flex-wrap: wrap;
			}
			.mcp-ai-pro-workflow-preset-category,
			.mcp-ai-pro-workflow-preset-nodes {
				display: inline-block; padding: 2px 7px; border-radius: 3px;
				font-size: 0.75em; font-weight: 600; white-space: nowrap;
				background: #e0f0ff; color: #135e96;
			}
			.mcp-ai-pro-workflow-preset-nodes {
				background: #f0f0f1; color: #50575e;
			}
			.mcp-ai-pro-workflow-preset-tags {
				display: flex; gap: 4px; flex-wrap: wrap;
			}
			.mcp-ai-pro-workflow-preset-tag {
				display: inline-block; padding: 1px 6px; border-radius: 2px;
				font-size: 0.7em; background: #e8e8e8; color: #646970;
			}
			.mcp-ai-pro-workflow-preset-card .button { align-self: flex-start; margin-top: 4px; }
			.mcp-ai-pro-workflow-preset-card[style*="display: none"] { display: none !important; }
		</style>
		<script>
		( function( $ ) {
			'use strict';

			function filterWfPresets() {
				var category = $( '#mcp-ai-wf-preset-category' ).val(),
					search   = ( $( '#mcp-ai-wf-preset-search' ).val() || '' ).toLowerCase();

				$( '#mcp-ai-wf-presets-grid .mcp-ai-pro-workflow-preset-card' ).each( function() {
					var $card = $( this ),
						cat   = $card.data( 'category' ) || '',
						text  = $card.text().toLowerCase(),
						show  = true;

					if ( category && cat !== category ) {
						show = false;
					}
					if ( search && text.indexOf( search ) === -1 ) {
						show = false;
					}
					$card.toggle( show );
				} );
			}

			$( document ).on( 'change', '#mcp-ai-wf-preset-category', filterWfPresets );
			$( document ).on( 'input', '#mcp-ai-wf-preset-search', filterWfPresets );

			$( document ).on( 'click', '[data-wf-preset-install]', function( e ) {
				e.preventDefault();
				var $btn     = $( this ),
					presetId = $btn.data( 'wf-preset-install' ),
					nonce    = window.mcpAiWorkflowBuilder ? mcpAiWorkflowBuilder.nonce : '',
					ajaxUrl  = window.mcpAiWorkflowBuilder ? mcpAiWorkflowBuilder.ajaxUrl : '';

				if ( ! ajaxUrl || ! nonce ) {
					return;
				}

				// eslint-disable-next-line no-alert
				if ( ! window.confirm( 'Load this workflow preset into the builder?' ) ) {
					return;
				}

				$btn.prop( 'disabled', true ).text( 'Loading…' );

				$.post( ajaxUrl, {
					action:    'wp_mcp_ai_install_workflow_preset',
					nonce:     nonce,
					preset_id: presetId,
				} ).done( function( resp ) {
					if ( resp.success && resp.data && resp.data.workflow ) {
						// Dispatch custom event that the React workflow builder can listen for.
						var evt = new CustomEvent( 'mcpAiLoadWorkflowPreset', {
							detail: resp.data.workflow,
						} );
						document.dispatchEvent( evt );
						$btn.text( '✓ Loaded' );
					} else {
						$btn.text( 'Error' );
					}
				} ).fail( function() {
					$btn.text( 'Error' );
				} ).always( function() {
					setTimeout( function() {
						$btn.prop( 'disabled', false ).text( 'Load into Builder' );
					}, 2000 );
				} );
			} );
		} )( jQuery );
		</script>
		<?php
	}

	/**
	 * AJAX: Return workflow presets.
	 *
	 * @since 2.1.0
	 */
	public function ajax_get_workflow_presets() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		wp_send_json_success( array( 'presets' => $this->get_all_workflow_presets() ) );
	}

	/**
	 * AJAX: Install (load) a workflow preset.
	 *
	 * Returns the workflow data (nodes + edges) for the React builder to load.
	 *
	 * @since 2.1.0
	 */
	public function ajax_install_workflow_preset() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$presets_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-workflow-presets.php';
		if ( ! file_exists( $presets_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow presets not available.', 'mcp-ai-wpoos-pro' ) ) );
		}
		require_once $presets_file;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$preset_id = isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '';

		if ( '' === $preset_id ) {
			wp_send_json_error( array( 'message' => __( 'No preset ID provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$workflow = WP_MCP_AI_Pro_Workflow_Presets::install_preset( $preset_id );

		if ( null === $workflow || is_wp_error( $workflow ) ) {
			$msg = is_wp_error( $workflow ) ? esc_html( $workflow->get_error_message() ) : __( 'Workflow preset not found.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error( array( 'message' => $msg ) );
		}

		wp_send_json_success(
			array(
				'workflow' => $workflow,
				'message'  => __( 'Workflow preset loaded.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}
}

/**
 * Initialize the pro workflow builder page.
 *
 * Instantiated immediately when file is loaded (during plugins_loaded) to ensure
 * the admin_menu hook registration in the constructor happens before WordPress
 * processes the admin_menu action.
 *
 * Correct WordPress Hook Order:
 * 1. plugins_loaded - Pro plugin loads, includes this file, instantiates class
 * 2. admin_menu (priority 25) - Parent menu 'nvoos-pro-dashboard' registers
 * 3. admin_menu (priority 26) - This class registers its submenu page
 * 4. admin_init - Other initialization (too late for menu registration)
 *
 * @since 2.0.0
 */
if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
	new WP_MCP_AI_Pro_Workflow_Builder_Page();
}
