<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Graph\Exporter;
use NvoosContentGraph\Schema;
use function esc_attr__;
use function esc_url;
use function wp_enqueue_script;

/**
 * Graph Explorer admin page.
 *
 * Provides an interactive Cytoscape.js visualization of the knowledge graph
 * in the WordPress admin, with search, node detail, and export controls.
 *
 * @since 1.0.0
 */
class GraphExplorer {

	/** @var string Page slug. */
	private const PAGE_SLUG = 'nvoos-content-graph-explorer';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPage' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	/**
	 * Add the Graph Explorer submenu page.
	 *
	 * @return void
	 */
	public function addPage(): void {
		add_submenu_page(
			'nvoos-content-graph',
			esc_attr__( 'Graph Explorer', 'nvoos-content-graph' ),
			esc_attr__( 'Graph Explorer', 'nvoos-content-graph' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Enqueue Cytoscape.js and admin JavaScript.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueAssets( string $hook ): void {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		$vendorUrl = NVOOS_CONTENT_GRAPH_URL . 'assets/vendor/';

		// Cytoscape.js and layout extensions (vendored).
		wp_enqueue_script( 'cytoscape-layout-base', $vendorUrl . 'layout-base/layout-base.js', array(), NVOOS_CONTENT_GRAPH_VERSION, true );
		wp_enqueue_script( 'cytoscape-cose-base', $vendorUrl . 'cose-base/cose-base.js', array( 'cytoscape-layout-base' ), NVOOS_CONTENT_GRAPH_VERSION, true );
		wp_enqueue_script( 'cytoscape', $vendorUrl . 'cytoscape/cytoscape.min.js', array(), NVOOS_CONTENT_GRAPH_VERSION, true );
		wp_enqueue_script( 'cytoscape-fcose', $vendorUrl . 'cytoscape-fcose/cytoscape-fcose.js', array( 'cytoscape', 'cytoscape-cose-base' ), NVOOS_CONTENT_GRAPH_VERSION, true );

		// Graph explorer JavaScript.
		wp_enqueue_script(
			'nvoos-content-graph-admin',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-admin.js',
			array( 'cytoscape', 'cytoscape-fcose' ),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);

		// Pass REST config to JS.
		wp_localize_script(
			'nvoos-content-graph-admin',
			'nvoosContentGraphAdmin',
			array(
				'rest_url'  => esc_url_raw( rest_url( Schema::REST_NAMESPACE ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'max_nodes' => 300,
				'height'    => '600px',
			)
		);

		// Admin styles.
		wp_enqueue_style(
			'nvoos-content-graph-admin',
			NVOOS_CONTENT_GRAPH_URL . 'assets/css/content-graph-admin.css',
			array(),
			NVOOS_CONTENT_GRAPH_VERSION
		);
	}

	/**
	 * Render the Graph Explorer page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		$stats = Db::getStats();
		?>
		<div class="wrap nvoos-content-graph-explorer">
			<h1><?php echo esc_html__( 'Knowledge Graph Explorer', 'nvoos-content-graph' ); ?></h1>

			<div class="content-graph-stats-bar">
				<div class="stat-card">
					<span class="stat-value"><?php echo esc_html( number_format_i18n( $stats['node_count'] ) ); ?></span>
					<span class="stat-label"><?php echo esc_html__( 'Nodes', 'nvoos-content-graph' ); ?></span>
				</div>
				<div class="stat-card">
					<span class="stat-value"><?php echo esc_html( number_format_i18n( $stats['edge_count'] ) ); ?></span>
					<span class="stat-label"><?php echo esc_html__( 'Edges', 'nvoos-content-graph' ); ?></span>
				</div>
				<div class="stat-card">
					<span class="stat-value"><?php echo esc_html( number_format_i18n( $stats['community_count'] ) ); ?></span>
					<span class="stat-label"><?php echo esc_html__( 'Communities', 'nvoos-content-graph' ); ?></span>
				</div>
			</div>

			<div class="content-graph-toolbar">
				<input type="text" id="content-graph-search" placeholder="<?php echo esc_attr__( 'Search nodes...', 'nvoos-content-graph' ); ?>">
				<button type="button" id="content-graph-refresh" class="button">
					<?php echo esc_html__( 'Refresh Graph', 'nvoos-content-graph' ); ?>
				</button>
				<button type="button" id="content-graph-fit" class="button">
					<?php echo esc_html__( 'Fit to Screen', 'nvoos-content-graph' ); ?>
				</button>
				<span class="content-graph-legend">
					<span class="legend-dot" style="background:#e74c3c"></span> <?php esc_html_e( 'Post', 'nvoos-content-graph' ); ?>
					<span class="legend-dot" style="background:#3498db"></span> <?php esc_html_e( 'Page', 'nvoos-content-graph' ); ?>
					<span class="legend-dot" style="background:#2ecc71"></span> <?php esc_html_e( 'Term', 'nvoos-content-graph' ); ?>
					<span class="legend-dot" style="background:#f39c12"></span> <?php esc_html_e( 'User', 'nvoos-content-graph' ); ?>
				</span>
			</div>

			<div id="content-graph-container" style="width:100%; height:600px; border:1px solid #ccd0d4; background:#f9f9fb;"></div>

			<div id="content-graph-node-detail" class="content-graph-detail-panel" style="display:none;">
				<h3 id="content-graph-detail-label"></h3>
				<p id="content-graph-detail-type"></p>
				<p id="content-graph-detail-degree"></p>
				<p id="content-graph-detail-url"></p>
			</div>
		</div>
		<?php
	}
}
