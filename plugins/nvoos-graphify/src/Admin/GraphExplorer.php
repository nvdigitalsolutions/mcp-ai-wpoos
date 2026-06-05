<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin;

use NvoosGraphify\Graph\Db;
use NvoosGraphify\Graph\Exporter;
use NvoosGraphify\Schema;
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
class GraphExplorer
{
    /** @var string Page slug. */
    private const PAGE_SLUG = 'nvoos-graphify-explorer';

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_action( 'admin_menu', array( $this, 'addPage' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
    }

    /**
     * Add the Graph Explorer submenu page.
     *
     * @return void
     */
    public function addPage(): void
    {
        add_submenu_page(
            'nvoos-graphify',
            esc_attr__( 'Graph Explorer', 'nvoos-graphify' ),
            esc_attr__( 'Graph Explorer', 'nvoos-graphify' ),
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
    public function enqueueAssets( string $hook ): void
    {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
            return;
        }

        $vendorUrl = NVOOS_GRAPHIFY_URL . 'assets/vendor/';

        // Cytoscape.js and layout extensions (vendored).
        wp_enqueue_script( 'cytoscape-layout-base', $vendorUrl . 'layout-base/layout-base.js', array(), NVOOS_GRAPHIFY_VERSION, true );
        wp_enqueue_script( 'cytoscape-cose-base', $vendorUrl . 'cose-base/cose-base.js', array( 'cytoscape-layout-base' ), NVOOS_GRAPHIFY_VERSION, true );
        wp_enqueue_script( 'cytoscape', $vendorUrl . 'cytoscape/cytoscape.min.js', array(), NVOOS_GRAPHIFY_VERSION, true );
        wp_enqueue_script( 'cytoscape-fcose', $vendorUrl . 'cytoscape-fcose/cytoscape-fcose.js', array( 'cytoscape', 'cytoscape-cose-base' ), NVOOS_GRAPHIFY_VERSION, true );

        // Graph explorer JavaScript.
        wp_enqueue_script(
            'nvoos-graphify-admin',
            NVOOS_GRAPHIFY_URL . 'assets/js/graphify-admin.js',
            array( 'cytoscape', 'cytoscape-fcose' ),
            NVOOS_GRAPHIFY_VERSION,
            true
        );

        // Pass REST config to JS.
        wp_add_inline_script(
            'nvoos-graphify-admin',
            'window.NvoosGraphify = ' . wp_json_encode( array(
                'restUrl'   => rest_url( Schema::REST_NAMESPACE ),
                'nonce'     => wp_create_nonce( 'wp_rest' ),
                'maxNodes'  => 300,
                'height'    => '600px',
            ) ) . ';',
            'before'
        );

        // Admin styles.
        wp_enqueue_style(
            'nvoos-graphify-admin',
            NVOOS_GRAPHIFY_URL . 'assets/css/graphify-admin.css',
            array(),
            NVOOS_GRAPHIFY_VERSION
        );
    }

    /**
     * Render the Graph Explorer page.
     *
     * @return void
     */
    public function renderPage(): void
    {
        $stats = Db::getStats();
        ?>
        <div class="wrap nvoos-graphify-explorer">
            <h1><?php echo esc_html__( 'Knowledge Graph Explorer', 'nvoos-graphify' ); ?></h1>

            <div class="graphify-stats-bar">
                <div class="stat-card">
                    <span class="stat-value"><?php echo esc_html( number_format_i18n( $stats['node_count'] ) ); ?></span>
                    <span class="stat-label"><?php echo esc_html__( 'Nodes', 'nvoos-graphify' ); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?php echo esc_html( number_format_i18n( $stats['edge_count'] ) ); ?></span>
                    <span class="stat-label"><?php echo esc_html__( 'Edges', 'nvoos-graphify' ); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?php echo esc_html( number_format_i18n( $stats['community_count'] ) ); ?></span>
                    <span class="stat-label"><?php echo esc_html__( 'Communities', 'nvoos-graphify' ); ?></span>
                </div>
            </div>

            <div class="graphify-toolbar">
                <input type="text" id="graphify-search" placeholder="<?php echo esc_attr__( 'Search nodes...', 'nvoos-graphify' ); ?>">
                <button type="button" id="graphify-refresh" class="button">
                    <?php echo esc_html__( 'Refresh Graph', 'nvoos-graphify' ); ?>
                </button>
                <button type="button" id="graphify-fit" class="button">
                    <?php echo esc_html__( 'Fit to Screen', 'nvoos-graphify' ); ?>
                </button>
                <span class="graphify-legend">
                    <span class="legend-dot" style="background:#e74c3c"></span> Post
                    <span class="legend-dot" style="background:#3498db"></span> Page
                    <span class="legend-dot" style="background:#2ecc71"></span> Term
                    <span class="legend-dot" style="background:#f39c12"></span> User
                </span>
            </div>

            <div id="graphify-container" style="width:100%; height:600px; border:1px solid #ccd0d4; background:#f9f9fb;"></div>

            <div id="graphify-node-detail" class="graphify-detail-panel" style="display:none;">
                <h3 id="graphify-detail-label"></h3>
                <p id="graphify-detail-type"></p>
                <p id="graphify-detail-degree"></p>
                <p id="graphify-detail-url"></p>
            </div>
        </div>
        <?php
    }
}
