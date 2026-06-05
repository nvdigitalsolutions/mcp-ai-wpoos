<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin;

use NvoosGraphify\Schema;
use NvoosGraphify\Settings;
use NvoosGraphify\Graph\Db;
use NvoosGraphify\Graph\Builder;

use function __;
use function absint;
use function add_action;
use function add_menu_page;
use function add_query_arg;
use function add_settings_field;
use function add_settings_section;
use function admin_url;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function call_user_func;
use function check_ajax_referer;
use function checked;
use function class_exists;
use function current_user_can;
use function do_settings_fields;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_js;
use function esc_url;
use function esc_url_raw;
use function get_post_types;
use function in_array;
use function is_array;
use function max;
use function method_exists;
use function min;
use function number_format_i18n;
use function register_setting;
use function rest_url;
use function sanitize_key;
use function sanitize_text_field;
use function selected;
use function settings_errors;
use function submit_button;
use function wp_create_nonce;
use function wp_die;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_localize_script;
use function wp_parse_str;
use function wp_parse_url;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Admin settings page for the NV oOS Graphify plugin.
 *
 * Registers a standalone top-level "Knowledge Graph" menu page with
 * tabbed settings (General, Sources, Remote, Embeddings), a graph
 * overview stats card with rebuild button, and the Cytoscape.js
 * graph explorer.
 *
 * @since 1.0.0
 */
class SettingsPage
{
    /**
     * Settings page slug.
     *
     * @since 1.0.0
     * @var string
     */
    public const PAGE_SLUG = 'nvoos-graphify';

    /**
     * Register admin hooks.
     *
     * Called by {@see \NvoosGraphify\Plugin::registerAdmin()}.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_init', [ $this, 'registerSettings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
        add_action( 'wp_ajax_nvoos_graphify_build', [ $this, 'handleAjaxBuild' ] );
    }

    /**
     * Add the standalone "Knowledge Graph" top-level menu page.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function addMenuPage(): void
    {
        add_menu_page(
            __( 'Knowledge Graph', 'nvoos-graphify' ),
            __( 'Knowledge Graph', 'nvoos-graphify' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'renderPage' ],
            'dashicons-networking',
            85
        );
    }

    /**
     * Register settings, sections, and fields.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function registerSettings(): void
    {
        register_setting(
            'nvoos_graphify_settings_group',
            Schema::OPTION_SETTINGS,
            [ 'sanitize_callback' => [ $this, 'sanitizeSettings' ] ]
        );

        // --- General section ---
        add_settings_section( 'nvoos_graphify_general', __( 'General', 'nvoos-graphify' ), '__return_false', self::PAGE_SLUG );
        add_settings_field( 'enabled', __( 'Enable Graphify', 'nvoos-graphify' ), [ $this, 'fieldEnabled' ], self::PAGE_SLUG, 'nvoos_graphify_general' );

        // --- Build section ---
        add_settings_section( 'nvoos_graphify_build', __( 'Build Settings', 'nvoos-graphify' ), '__return_false', self::PAGE_SLUG );
        add_settings_field( 'semantic_extraction', __( 'Semantic Extraction', 'nvoos-graphify' ), [ $this, 'fieldSemantic' ], self::PAGE_SLUG, 'nvoos_graphify_build' );
        add_settings_field( 'incremental_builds', __( 'Incremental Builds', 'nvoos-graphify' ), [ $this, 'fieldIncremental' ], self::PAGE_SLUG, 'nvoos_graphify_build' );
        add_settings_field( 'auto_rebuild', __( 'Auto-Rebuild on Save', 'nvoos-graphify' ), [ $this, 'fieldAutoRebuild' ], self::PAGE_SLUG, 'nvoos_graphify_build' );
        add_settings_field( 'rebuild_schedule', __( 'Scheduled Rebuild', 'nvoos-graphify' ), [ $this, 'fieldRebuildSchedule' ], self::PAGE_SLUG, 'nvoos_graphify_build' );
        add_settings_field( 'openai_api_key', __( 'OpenAI API Key (optional)', 'nvoos-graphify' ), [ $this, 'fieldOpenaiKey' ], self::PAGE_SLUG, 'nvoos_graphify_build' );

        // --- Remote sources section ---
        add_settings_section( 'nvoos_graphify_remote', __( 'Remote Enrichment', 'nvoos-graphify' ), '__return_false', self::PAGE_SLUG );
        add_settings_field( 'remote_enrich_enabled', __( 'Enable Remote Enrichment', 'nvoos-graphify' ), [ $this, 'fieldRemoteEnrichEnabled' ], self::PAGE_SLUG, 'nvoos_graphify_remote' );
        add_settings_field( 'remote_enrich_budget', __( 'Enrichment Budget (nodes/run)', 'nvoos-graphify' ), [ $this, 'fieldRemoteEnrichBudget' ], self::PAGE_SLUG, 'nvoos_graphify_remote' );
        add_settings_field( 'remote_enrich_async', __( 'Async Enrichment', 'nvoos-graphify' ), [ $this, 'fieldRemoteEnrichAsync' ], self::PAGE_SLUG, 'nvoos_graphify_remote' );

        // --- Embeddings section ---
        add_settings_section( 'nvoos_graphify_embeddings', __( 'Embeddings', 'nvoos-graphify' ), '__return_false', self::PAGE_SLUG );
        add_settings_field( 'embeddings_enabled', __( 'Enable Embeddings', 'nvoos-graphify' ), [ $this, 'fieldEmbeddingsEnabled' ], self::PAGE_SLUG, 'nvoos_graphify_embeddings' );
        add_settings_field( 'embeddings_model', __( 'Embeddings Model', 'nvoos-graphify' ), [ $this, 'fieldEmbeddingsModel' ], self::PAGE_SLUG, 'nvoos_graphify_embeddings' );

        // --- Sources section (NV oOS bridge post types + external tables) ---
        add_settings_section( 'nvoos_graphify_sources_cpts', __( 'NV oOS Post Types', 'nvoos-graphify' ), [ $this, 'sectionSourcesCptsIntro' ], self::PAGE_SLUG );
        add_settings_field( 'nvoos_post_types', __( 'Indexed Post Types', 'nvoos-graphify' ), [ $this, 'fieldNvoosPostTypes' ], self::PAGE_SLUG, 'nvoos_graphify_sources_cpts' );

        add_settings_section( 'nvoos_graphify_sources_ext', __( 'NV oOS Internal Tables', 'nvoos-graphify' ), [ $this, 'sectionSourcesExtIntro' ], self::PAGE_SLUG );
        add_settings_field( 'nvoos_external_tables', __( 'Indexed Tables', 'nvoos-graphify' ), [ $this, 'fieldNvoosExternalTables' ], self::PAGE_SLUG, 'nvoos_graphify_sources_ext' );

        // --- Display section ---
        add_settings_section( 'nvoos_graphify_display', __( 'Display', 'nvoos-graphify' ), '__return_false', self::PAGE_SLUG );
        add_settings_field( 'schema_injection', __( 'Schema.org Injection', 'nvoos-graphify' ), [ $this, 'fieldSchema' ], self::PAGE_SLUG, 'nvoos_graphify_display' );
        add_settings_field( 'related_content', __( 'Related Content Widget', 'nvoos-graphify' ), [ $this, 'fieldRelated' ], self::PAGE_SLUG, 'nvoos_graphify_display' );
        add_settings_field( 'cytoscape_height', __( 'Graph Explorer Height', 'nvoos-graphify' ), [ $this, 'fieldHeight' ], self::PAGE_SLUG, 'nvoos_graphify_display' );
        add_settings_field( 'max_display_nodes', __( 'Max Explorer Nodes', 'nvoos-graphify' ), [ $this, 'fieldMaxNodes' ], self::PAGE_SLUG, 'nvoos_graphify_display' );
    }

    /**
     * Map of tab slug => list of setting keys rendered on that tab's form.
     *
     * Used by {@see sanitizeSettings()} to decide which keys may be
     * overwritten by a submission, so that saving one tab does not wipe
     * values controlled by the other tabs.
     *
     * @since 1.0.0
     *
     * @return array<string,string[]>
     */
    private function getTabFieldMap(): array
    {
        return [
            // "General" tab renders general + build + display sections.
            'general'    => [
                'enabled',
                'semantic_extraction',
                'incremental_builds',
                'auto_rebuild',
                'rebuild_schedule',
                'openai_api_key',
                'schema_injection',
                'related_content',
                'cytoscape_height',
                'max_display_nodes',
                'max_related',
            ],
            'remote'     => [
                'remote_enrich_enabled',
                'remote_enrich_budget',
                'remote_enrich_async',
            ],
            'embeddings' => [
                'embeddings_enabled',
                'embeddings_model',
            ],
            // "Sources" tab — NV oOS CPTs, CCT slugs, external tables.
            'sources'    => [
                'excluded_post_types',
                'extra_post_types',
                'external_tables',
                'disabled_external_tables',
            ],
        ];
    }

    /**
     * Sanitize a single setting key from the raw submission.
     *
     * Centralised so that {@see sanitizeSettings()} can apply per-key
     * sanitisation only to the keys belonging to the submitted tab, while
     * preserving values from other tabs untouched.
     *
     * @since 1.0.0
     *
     * @param string $key Setting key.
     * @param array  $raw Raw submitted settings array.
     * @return mixed Sanitized value, or null if the key is not recognised.
     */
    private function sanitizeField( string $key, array $raw )
    {
        switch ( $key ) {
            case 'enabled':
            case 'semantic_extraction':
            case 'incremental_builds':
            case 'auto_rebuild':
            case 'schema_injection':
            case 'related_content':
            case 'remote_enrich_enabled':
            case 'remote_enrich_async':
            case 'embeddings_enabled':
                return ! empty( $raw[ $key ] ) ? 1 : 0;

            case 'rebuild_schedule':
                $allowed = [ 'hourly', 'twicedaily', 'daily', 'weekly' ];
                $value   = isset( $raw[ $key ] ) ? $raw[ $key ] : 'daily';
                return in_array( $value, $allowed, true ) ? $value : 'daily';

            case 'openai_api_key':
                return sanitize_text_field( isset( $raw[ $key ] ) ? $raw[ $key ] : '' );

            case 'cytoscape_height':
                return sanitize_text_field( isset( $raw[ $key ] ) ? $raw[ $key ] : '600px' );

            case 'max_display_nodes':
                return max( 50, min( 2000, absint( isset( $raw[ $key ] ) ? $raw[ $key ] : 300 ) ) );

            case 'max_related':
                return max( 1, min( 10, absint( isset( $raw[ $key ] ) ? $raw[ $key ] : 5 ) ) );

            case 'remote_enrich_budget':
                return max( 1, min( 500, absint( isset( $raw[ $key ] ) ? $raw[ $key ] : 50 ) ) );

            case 'embeddings_model':
                $allowed = [ 'text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002' ];
                $value   = isset( $raw[ $key ] ) ? $raw[ $key ] : '';
                return in_array( $value, $allowed, true ) ? $value : 'text-embedding-3-small';

            case 'excluded_post_types':
            case 'extra_post_types':
            case 'external_tables':
            case 'disabled_external_tables':
                // These are arrays of sanitize_key strings.
                if ( empty( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
                    return [];
                }
                return array_values( array_filter( array_map( 'sanitize_key', $raw[ $key ] ) ) );
        }

        // Defensive default: any key not explicitly handled above is not part
        // of the tab field map (see {@see getTabFieldMap()}) and should not
        // be written. Returning null lets {@see sanitizeSettings()} skip it.
        return null;
    }

    /**
     * Detect the tab being saved from the WP referer (`_wp_http_referer`).
     *
     * The settings page renders one form per tab, so the referer tells us
     * which tab's fields were actually present in the submission.
     *
     * @since 1.0.0
     *
     * @return string One of: 'general', 'remote', 'embeddings', 'sources'.
     */
    private function detectSubmittedTab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab detection only; the option update itself is nonce-protected by options.php.
        $referer = isset( $_REQUEST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_REQUEST['_wp_http_referer'] ) ) : '';
        if ( ! is_string( $referer ) || '' === $referer ) {
            return 'general';
        }

        $query = wp_parse_url( $referer, PHP_URL_QUERY );
        if ( ! is_string( $query ) || '' === $query ) {
            return 'general';
        }

        $args = [];
        wp_parse_str( $query, $args );
        $tab = isset( $args['tab'] ) ? sanitize_key( $args['tab'] ) : 'general';

        $map = $this->getTabFieldMap();
        return isset( $map[ $tab ] ) ? $tab : 'general';
    }

    /**
     * Sanitize incoming settings array.
     *
     * The settings page splits fields across multiple tabs that each submit
     * their own form to `options.php`. We must merge the submitted tab's
     * fields into the existing stored option instead of rebuilding the
     * whole array — otherwise saving one tab silently zeros out every
     * checkbox controlled by the other tabs.
     *
     * @since 1.0.0
     *
     * @param array $raw Submitted form data.
     * @return array Sanitized settings merged with previously-stored values.
     */
    public function sanitizeSettings( $raw ): array
    {
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        // Start from the currently-stored option (with defaults filled in)
        // so any field not part of the submitted tab keeps its value.
        $existing = Settings::all();

        $tab    = $this->detectSubmittedTab();
        $map    = $this->getTabFieldMap();
        $keys   = isset( $map[ $tab ] ) ? $map[ $tab ] : $map['general'];
        $merged = $existing;

        foreach ( $keys as $key ) {
            $value = $this->sanitizeField( $key, $raw );
            if ( null === $value ) {
                continue;
            }
            $merged[ $key ] = $value;
        }

        // Handle the Sources tab CPT checkboxes, which are submitted as
        // nvoos_cpt_include[slug]=1. Translate that into excluded_post_types
        // and extra_post_types arrays.
        if ( 'sources' === $tab && class_exists( 'NvoosGraphify\Admin\Bridge' ) ) {
            $checked_slugs = [];
            if ( isset( $raw['nvoos_cpt_include'] ) && is_array( $raw['nvoos_cpt_include'] ) ) {
                $checked_slugs = array_keys( array_filter( $raw['nvoos_cpt_include'] ) );
                $checked_slugs = array_values( array_map( 'sanitize_key', $checked_slugs ) );
            }

            $registry     = \NvoosGraphify\Admin\Bridge::getCptRegistry();
            $new_excluded = [];
            $new_extra    = [];

            foreach ( $registry as $entry ) {
                $slug = sanitize_key( $entry['slug'] );
                if ( $entry['default_include'] ) {
                    // Default-on: if NOT checked, add to excluded.
                    if ( ! in_array( $slug, $checked_slugs, true ) ) {
                        $new_excluded[] = $slug;
                    }
                } elseif ( in_array( $slug, $checked_slugs, true ) ) {
                    // Default-off: if checked, add to extra.
                    $new_extra[] = $slug;
                }
            }
            $merged['excluded_post_types'] = $new_excluded;
            $merged['extra_post_types']    = $new_extra;
        }

        return $merged;
    }

    // -------------------------------------------------------------------------
    // Field renderers
    // -------------------------------------------------------------------------

    /** Render the enabled checkbox. */
    public function fieldEnabled(): void
    {
        $s = Settings::all();
        echo '<input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[enabled]" value="1" ' . checked( 1, $s['enabled'], false ) . '>';
        echo '<p class="description">' . esc_html__( 'Enable the Knowledge Graph addon.', 'nvoos-graphify' ) . '</p>';
    }

    /** Render semantic extraction field. */
    public function fieldSemantic(): void
    {
        $s = Settings::all();
        echo '<input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[semantic_extraction]" value="1" ' . checked( 1, $s['semantic_extraction'], false ) . '>';
        echo '<p class="description">' . esc_html__( 'Use AI to extract named entities and topics from content.', 'nvoos-graphify' ) . '</p>';
    }

    /** Render incremental builds field. */
    public function fieldIncremental(): void
    {
        $s = Settings::all();
        echo '<input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[incremental_builds]" value="1" ' . checked( 1, $s['incremental_builds'], false ) . '>';
        echo '<p class="description">' . esc_html__( 'Only process content modified since last build.', 'nvoos-graphify' ) . '</p>';
    }

    /** Render auto-rebuild field. */
    public function fieldAutoRebuild(): void
    {
        $s = Settings::all();
        echo '<input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[auto_rebuild]" value="1" ' . checked( 1, $s['auto_rebuild'], false ) . '>';
        echo '<p class="description">' . esc_html__( 'Trigger an incremental rebuild whenever a post is published or updated.', 'nvoos-graphify' ) . '</p>';
    }

    /** Render rebuild schedule field. */
    public function fieldRebuildSchedule(): void
    {
        $s       = Settings::all();
        $options = [
            'hourly'     => __( 'Hourly', 'nvoos-graphify' ),
            'twicedaily' => __( 'Twice Daily', 'nvoos-graphify' ),
            'daily'      => __( 'Daily', 'nvoos-graphify' ),
            'weekly'     => __( 'Weekly', 'nvoos-graphify' ),
        ];
        echo '<select name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[rebuild_schedule]">';
        foreach ( $options as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $s['rebuild_schedule'], $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    /** Render OpenAI key field. */
    public function fieldOpenaiKey(): void
    {
        $s = Settings::all();
        echo '<input type="password" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[openai_api_key]" value="' . esc_attr( $s['openai_api_key'] ) . '" class="regular-text" autocomplete="new-password">';
        echo '<p class="description">' . esc_html__( 'Used as fallback when the oOS AI provider is not available. Leave blank to use the global oOS key.', 'nvoos-graphify' ) . '</p>';
    }

    /** Render schema injection field. */
    public function fieldSchema(): void
    {
        $s = Settings::all();
        echo '<input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[schema_injection]" value="1" ' . checked( 1, $s['schema_injection'], false ) . '>';
        echo '<p class="description">' . esc_html__( 'Inject Schema.org JSON-LD (about, relatedLink) on singular views.', 'nvoos-graphify' ) . '</p>';
    }

    /** Render related content field. */
    public function fieldRelated(): void
    {
        $s = Settings::all();
        echo '<input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[related_content]" value="1" ' . checked( 1, $s['related_content'], false ) . '>';
        echo '<p class="description">' . esc_html__( 'Append a "Related Content" list from graph neighbors below singular post content.', 'nvoos-graphify' ) . '</p>';
    }

    /** Render explorer height field. */
    public function fieldHeight(): void
    {
        $s = Settings::all();
        echo '<input type="text" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[cytoscape_height]" value="' . esc_attr( $s['cytoscape_height'] ) . '" class="small-text">';
        echo '<p class="description">' . esc_html__( 'CSS height for the graph explorer (e.g. 600px, 80vh).', 'nvoos-graphify' ) . '</p>';
    }

    /** Render max display nodes field. */
    public function fieldMaxNodes(): void
    {
        $s = Settings::all();
        echo '<input type="number" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[max_display_nodes]" value="' . absint( $s['max_display_nodes'] ) . '" min="50" max="2000" class="small-text">';
        echo '<p class="description">' . esc_html__( 'Maximum nodes to render in the graph explorer. Lower values improve browser performance.', 'nvoos-graphify' ) . '</p>';
    }

    /**
     * Field: Enable remote enrichment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function fieldRemoteEnrichEnabled(): void
    {
        $s = Settings::all();
        echo '<label><input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[remote_enrich_enabled]" value="1"' . checked( 1, $s['remote_enrich_enabled'], false ) . '> ';
        echo esc_html__( 'Enrich graph nodes from configured remote sources during each build.', 'nvoos-graphify' ) . '</label>';
    }

    /**
     * Field: Remote enrichment node budget per run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function fieldRemoteEnrichBudget(): void
    {
        $s = Settings::all();
        echo '<input type="number" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[remote_enrich_budget]" value="' . absint( $s['remote_enrich_budget'] ) . '" min="1" max="500" class="small-text">';
        echo '<p class="description">' . esc_html__( 'Maximum nodes to enrich per build run (1–500). Prevents long-running builds.', 'nvoos-graphify' ) . '</p>';
    }

    /**
     * Field: Async remote enrichment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function fieldRemoteEnrichAsync(): void
    {
        $s = Settings::all();
        echo '<label><input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[remote_enrich_async]" value="1"' . checked( 1, $s['remote_enrich_async'], false ) . '> ';
        echo esc_html__( 'Run enrichment in the background via WP-Cron (recommended for large sites).', 'nvoos-graphify' ) . '</label>';
    }

    /**
     * Field: Enable embeddings.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function fieldEmbeddingsEnabled(): void
    {
        $s = Settings::all();
        echo '<label><input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[embeddings_enabled]" value="1"' . checked( 1, $s['embeddings_enabled'], false ) . '> ';
        echo esc_html__( 'Generate and store vector embeddings for nodes (requires OpenAI API key).', 'nvoos-graphify' ) . '</label>';
    }

    /**
     * Field: Embeddings model selector.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function fieldEmbeddingsModel(): void
    {
        $s       = Settings::all();
        $current = isset( $s['embeddings_model'] ) ? $s['embeddings_model'] : 'text-embedding-3-small';
        $models  = [
            'text-embedding-3-small' => __( 'text-embedding-3-small (recommended)', 'nvoos-graphify' ),
            'text-embedding-3-large' => __( 'text-embedding-3-large (higher quality, slower)', 'nvoos-graphify' ),
            'text-embedding-ada-002' => __( 'text-embedding-ada-002 (legacy)', 'nvoos-graphify' ),
        ];
        echo '<select name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[embeddings_model]">';
        foreach ( $models as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'OpenAI embedding model used when generating node vectors.', 'nvoos-graphify' ) . '</p>';
    }

    // -------------------------------------------------------------------------
    // Sources tab field renderers
    // -------------------------------------------------------------------------

    /**
     * Intro paragraph for the NV oOS Post Types section.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function sectionSourcesCptsIntro(): void
    {
        echo '<p>' . esc_html__( 'Control which NV oOS internal post types (assistants, workflow runs, approvals, audit entries, etc.) are included in the knowledge graph. Default-on types are already included; default-off types are sensitive or high-volume and require explicit opt-in.', 'nvoos-graphify' ) . '</p>';
    }

    /**
     * Intro paragraph for the NV oOS Internal Tables section.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function sectionSourcesExtIntro(): void
    {
        echo '<p>' . esc_html__( 'Control which NV oOS custom database tables (slash-command audit, metric events, compliance evidence, risks, etc.) are indexed. All tables here are opt-in by default.', 'nvoos-graphify' ) . '</p>';
    }

    /**
     * Render the NV oOS post-type inclusion checkboxes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function fieldNvoosPostTypes(): void
    {
        if ( ! class_exists( 'NvoosGraphify\Admin\Bridge' ) ) {
            echo '<p class="description">' . esc_html__( 'The NV oOS bridge is not active (NV oOS base plugin not detected).', 'nvoos-graphify' ) . '</p>';
            return;
        }

        $s        = Settings::all();
        $excluded = isset( $s['excluded_post_types'] ) && is_array( $s['excluded_post_types'] ) ? $s['excluded_post_types'] : [];
        $extra    = isset( $s['extra_post_types'] ) && is_array( $s['extra_post_types'] ) ? $s['extra_post_types'] : [];
        $registry = \NvoosGraphify\Admin\Bridge::getCptRegistry();

        echo '<table class="widefat striped" style="max-width:700px">';
        echo '<thead><tr><th>' . esc_html__( 'Post Type', 'nvoos-graphify' ) . '</th><th>' . esc_html__( 'Include', 'nvoos-graphify' ) . '</th><th>' . esc_html__( 'Notes', 'nvoos-graphify' ) . '</th></tr></thead>';
        echo '<tbody>';
        foreach ( $registry as $entry ) {
            $slug    = sanitize_key( $entry['slug'] );
            $default = $entry['default_include'];

            if ( $default ) {
                // Default-on: show as enabled unless explicitly excluded.
                $checked = ! in_array( $entry['slug'], $excluded, true );
                // For default-on, the admin sends the slug in `excluded_post_types` to opt OUT.
                echo '<tr>';
                echo '<td><strong>' . esc_html( $entry['label'] ) . '</strong> <code style="font-size:11px">' . esc_html( $slug ) . '</code></td>';
                echo '<td><input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[nvoos_cpt_include][' . esc_attr( $slug ) . ']" value="1"' . checked( $checked, true, false ) . '></td>';
                echo '<td>' . esc_html__( 'Included by default', 'nvoos-graphify' ) . '</td>';
                echo '</tr>';
            } else {
                // Default-off: show as disabled unless explicitly opted in.
                $checked = in_array( $entry['slug'], $extra, true );
                echo '<tr>';
                echo '<td><strong>' . esc_html( $entry['label'] ) . '</strong> <code style="font-size:11px">' . esc_html( $slug ) . '</code></td>';
                echo '<td><input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[nvoos_cpt_include][' . esc_attr( $slug ) . ']" value="1"' . checked( $checked, true, false ) . '></td>';
                echo '<td>' . esc_html__( 'Opt-in (sensitive / high-volume)', 'nvoos-graphify' ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__( 'Uncheck to exclude a post type; check to include it. Changes take effect on the next graph build.', 'nvoos-graphify' ) . '</p>';
    }

    /**
     * Render the NV oOS external table inclusion checkboxes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function fieldNvoosExternalTables(): void
    {
        if ( ! class_exists( 'NvoosGraphify\Admin\Bridge' ) ) {
            echo '<p class="description">' . esc_html__( 'The NV oOS bridge is not active.', 'nvoos-graphify' ) . '</p>';
            return;
        }

        $s       = Settings::all();
        $enabled = isset( $s['external_tables'] ) && is_array( $s['external_tables'] ) ? $s['external_tables'] : [];

        $all_descriptors = [
            [
                'table'           => 'mcp_ai_slash_command_audit',
                'label'           => __( 'Slash Command Audit', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => false,
            ],
            [
                'table'           => 'mcp_ai_metric_events',
                'label'           => __( 'Metric Events', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => false,
            ],
            [
                'table'           => 'mcp_ai_hourly_token_usage',
                'label'           => __( 'Hourly Token Usage', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => false,
            ],
            [
                'table'           => 'mcp_ai_job_queue',
                'label'           => __( 'Job Queue', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => false,
            ],
            [
                'table'           => 'mcp_ai_controls',
                'label'           => __( 'Compliance Controls', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => true,
            ],
            [
                'table'           => 'mcp_ai_evidence',
                'label'           => __( 'Compliance Evidence', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => true,
            ],
            [
                'table'           => 'mcp_ai_risks',
                'label'           => __( 'Risk Register', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => true,
            ],
            [
                'table'           => 'mcp_ai_audit_trail',
                'label'           => __( 'Audit Trail', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => true,
            ],
            [
                'table'           => 'mcp_ai_compliance_checks',
                'label'           => __( 'Compliance Checks', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => true,
            ],
            [
                'table'           => 'mcp_ai_custom_metrics',
                'label'           => __( 'Custom Metrics', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => false,
            ],
            [
                'table'           => 'mcp_ai_events',
                'label'           => __( 'NV oOS Events', 'nvoos-graphify' ),
                'default_include' => false,
                'sensitive'       => false,
            ],
        ];

        global $wpdb;

        echo '<table class="widefat striped" style="max-width:700px">';
        echo '<thead><tr><th>' . esc_html__( 'Table', 'nvoos-graphify' ) . '</th><th>' . esc_html__( 'Index', 'nvoos-graphify' ) . '</th><th>' . esc_html__( 'Status', 'nvoos-graphify' ) . '</th></tr></thead>';
        echo '<tbody>';
        foreach ( $all_descriptors as $desc ) {
            $table_key  = sanitize_key( $desc['table'] );
            $checked    = in_array( $table_key, $enabled, true );
            $table_full = $wpdb->prefix . $desc['table'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $table_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_full ) ) === $table_full;
            $status_text     = $table_exists
                ? esc_html__( 'Table exists', 'nvoos-graphify' )
                : '<em>' . esc_html__( 'Table not found', 'nvoos-graphify' ) . '</em>';
            $sensitive_badge = $desc['sensitive'] ? ' <span style="background:#d63638;color:#fff;padding:0 4px;border-radius:2px;font-size:10px">' . esc_html__( 'Sensitive', 'nvoos-graphify' ) . '</span>' : '';

            echo '<tr>';
            echo '<td><strong>' . esc_html( $desc['label'] ) . '</strong>' . wp_kses_post( $sensitive_badge ) . ' <code style="font-size:11px">' . esc_html( $table_full ) . '</code></td>';
            echo '<td><input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[external_tables][]" value="' . esc_attr( $table_key ) . '"' . checked( $checked, true, false ) . ( ! $table_exists ? ' disabled' : '' ) . '></td>';
            echo '<td>' . wp_kses_post( $status_text ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__( 'Check each table you want indexed. Tables marked Sensitive contain compliance/audit data. All opt-in. Changes apply on next build.', 'nvoos-graphify' ) . '</p>';
    }

    // -------------------------------------------------------------------------
    // Asset enqueuing
    // -------------------------------------------------------------------------

    /**
     * Enqueue admin assets on the Graphify settings page.
     *
     * @since 1.0.0
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueueAssets( $hook ): void
    {
        if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
            return;
        }

        // Cytoscape.js + fcose layout (bundled locally — see assets/vendor/).
        // Load order matters: layout-base → cose-base → cytoscape → cytoscape-fcose
        // (fcose auto-registers itself on the global cytoscape when it evaluates).
        \wp_enqueue_script(
            'layout-base',
            NVOOS_GRAPHIFY_URL . 'assets/vendor/layout-base/layout-base.js',
            [],
            '2.0.1',
            true
        );
        \wp_enqueue_script(
            'cose-base',
            NVOOS_GRAPHIFY_URL . 'assets/vendor/cose-base/cose-base.js',
            [ 'layout-base' ],
            '2.2.0',
            true
        );
        \wp_enqueue_script(
            'cytoscape',
            NVOOS_GRAPHIFY_URL . 'assets/vendor/cytoscape/cytoscape.min.js',
            [],
            '3.28.1',
            true
        );
        \wp_enqueue_script(
            'cytoscape-fcose',
            NVOOS_GRAPHIFY_URL . 'assets/vendor/cytoscape-fcose/cytoscape-fcose.js',
            [ 'cytoscape', 'cose-base' ],
            '2.2.0',
            true
        );

        \wp_enqueue_script(
            'nvoos-graphify-admin',
            NVOOS_GRAPHIFY_URL . 'assets/js/graphify-admin.js',
            [ 'jquery', 'cytoscape', 'cytoscape-fcose' ],
            NVOOS_GRAPHIFY_VERSION,
            true
        );

        \wp_enqueue_style(
            'nvoos-graphify-admin',
            NVOOS_GRAPHIFY_URL . 'assets/css/graphify-admin.css',
            [],
            NVOOS_GRAPHIFY_VERSION
        );

        $settings = Settings::all();

        \wp_localize_script(
            'nvoos-graphify-admin',
            'nvoosGraphifyAdmin',
            [
                'rest_url'    => esc_url_raw( rest_url( Schema::REST_NAMESPACE ) ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'ajax_url'    => admin_url( 'admin-ajax.php' ),
                'ajax_nonce'  => wp_create_nonce( 'nvoos_graphify_admin' ),
                'height'      => esc_js( $settings['cytoscape_height'] ),
                'max_nodes'   => absint( $settings['max_display_nodes'] ),
                'type_labels' => $this->getTypeLabels(),
                'i18n'        => [
                    'all_types' => __( 'All types', 'nvoos-graphify' ),
                ],
            ]
        );
    }

    /**
     * Build a slug => label map for every node type that may appear in the
     * graph. Includes the built-in semantic node types (term, topic, entity,
     * memory, agent, wing, room) plus every public custom post type / CCT
     * registered on the site, so the Graph Explorer's type filter can present
     * a friendly label for each.
     *
     * @since 1.0.0
     *
     * @return array<string,string>
     */
    private function getTypeLabels(): array
    {
        $labels = [
            'term'   => __( 'Terms', 'nvoos-graphify' ),
            'topic'  => __( 'Topics', 'nvoos-graphify' ),
            'entity' => __( 'Entities', 'nvoos-graphify' ),
            'memory' => __( 'Memories', 'nvoos-graphify' ),
            'agent'  => __( 'Agents', 'nvoos-graphify' ),
            'wing'   => __( 'Wings', 'nvoos-graphify' ),
            'room'   => __( 'Rooms', 'nvoos-graphify' ),
        ];

        // Include every public post type (built-in and custom) so CPTs and
        // JetEngine-registered content types show up in the filter when
        // nodes for them exist in the graph.
        $post_type_objects = get_post_types( [ 'public' => true ], 'objects' );
        foreach ( $post_type_objects as $slug => $object ) {
            if ( 'attachment' === $slug ) {
                continue;
            }
            $plural = isset( $object->labels->name ) && '' !== $object->labels->name
                ? $object->labels->name
                : $object->label;
            if ( '' === $plural ) {
                $plural = ucwords( str_replace( [ '_', '-' ], ' ', $slug ) );
            }
            $labels[ $slug ] = $plural;
        }

        return $labels;
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    /**
     * Render the settings page.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function renderPage(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'nvoos-graphify' ) );
        }

        $stats      = Db::getStats();
        $last_build = Db::getMeta( 'last_build_completed', __( 'Never', 'nvoos-graphify' ) );
        $status     = Db::getMeta( 'build_status', 'idle' );
        $settings   = Settings::all();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        $tabs        = [
            'general'    => __( 'General', 'nvoos-graphify' ),
            'sources'    => __( 'Sources (CPT / CCT)', 'nvoos-graphify' ),
            'remote'     => __( 'Remote Sources', 'nvoos-graphify' ),
            'embeddings' => __( 'Embeddings', 'nvoos-graphify' ),
        ];
        ?>
        <div class="wrap nvoos-graphify-admin">
            <h1><?php esc_html_e( 'Knowledge Graph', 'nvoos-graphify' ); ?></h1>

            <?php settings_errors(); ?>

            <?php /* Graph overview card */ ?>
            <div class="nvoos-graphify-stats-card">
                <h2><?php esc_html_e( 'Graph Overview', 'nvoos-graphify' ); ?></h2>
                <div class="nvoos-graphify-stats-grid">
                    <div class="nvoos-graphify-stat">
                        <span class="nvoos-graphify-stat-value"><?php echo esc_html( number_format_i18n( $stats['node_count'] ) ); ?></span>
                        <span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Nodes', 'nvoos-graphify' ); ?></span>
                    </div>
                    <div class="nvoos-graphify-stat">
                        <span class="nvoos-graphify-stat-value"><?php echo esc_html( number_format_i18n( $stats['edge_count'] ) ); ?></span>
                        <span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Edges', 'nvoos-graphify' ); ?></span>
                    </div>
                    <div class="nvoos-graphify-stat">
                        <span class="nvoos-graphify-stat-value"><?php echo esc_html( number_format_i18n( $stats['community_count'] ) ); ?></span>
                        <span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Communities', 'nvoos-graphify' ); ?></span>
                    </div>
                </div>
                <p class="nvoos-graphify-last-build">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: build status, 2: last build time */
                            __( 'Status: %1$s — Last build: %2$s', 'nvoos-graphify' ),
                            $status,
                            $last_build
                        )
                    );
                    ?>
                </p>
                <button id="nvoos-graphify-build-btn" class="button button-primary">
                    <?php esc_html_e( 'Rebuild Graph', 'nvoos-graphify' ); ?>
                </button>
                <span id="nvoos-graphify-build-status" style="margin-left:12px; display:none;"></span>
            </div>

            <?php /* Graph explorer */ ?>
            <?php if ( $stats['node_count'] > 0 ) : ?>
            <div class="nvoos-graphify-explorer-wrap">
                <h2><?php esc_html_e( 'Graph Explorer', 'nvoos-graphify' ); ?></h2>
                <div class="nvoos-graphify-explorer-toolbar">
                    <input type="text" id="nvoos-graphify-search" placeholder="<?php esc_attr_e( 'Search nodes…', 'nvoos-graphify' ); ?>">
                    <select id="nvoos-graphify-type-filter">
                        <option value=""><?php esc_html_e( 'All types', 'nvoos-graphify' ); ?></option>
                    </select>
                    <input type="text" id="nvoos-graphify-agent-filter" placeholder="<?php esc_attr_e( 'Agent ID…', 'nvoos-graphify' ); ?>" style="width:140px;">
                    <input type="text" id="nvoos-graphify-wing-filter" placeholder="<?php esc_attr_e( 'Wing…', 'nvoos-graphify' ); ?>" style="width:120px;">
                    <button id="nvoos-graphify-memory-preset-btn" class="button" title="<?php esc_attr_e( 'Show only the agent / wing combination above', 'nvoos-graphify' ); ?>">
                        <?php esc_html_e( 'Apply', 'nvoos-graphify' ); ?>
                    </button>
                    <button id="nvoos-graphify-memory-clear-btn" class="button">
                        <?php esc_html_e( 'Clear', 'nvoos-graphify' ); ?>
                    </button>
                    <button id="nvoos-graphify-fit-btn" class="button"><?php esc_html_e( 'Fit', 'nvoos-graphify' ); ?></button>
                    <button id="nvoos-graphify-relayout-btn" class="button"><?php esc_html_e( 'Relayout', 'nvoos-graphify' ); ?></button>
                    <button id="nvoos-graphify-export-png-btn" class="button"><?php esc_html_e( 'Export PNG', 'nvoos-graphify' ); ?></button>
                </div>
                <div id="nvoos-graphify-explorer" style="height:<?php echo esc_attr( $settings['cytoscape_height'] ); ?>;"></div>
                <div id="nvoos-graphify-sidebar" class="nvoos-graphify-sidebar" style="display:none;"></div>
            </div>
            <?php endif; ?>

            <?php /* Tabbed settings */ ?>
            <h2 class="nav-tab-wrapper">
                <?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', $tab_key ) ); ?>"
                        class="nav-tab<?php echo ( $current_tab === $tab_key ) ? ' nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_label ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php if ( 'sources' === $current_tab ) : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'nvoos_graphify_settings_group' );
                    $this->doSettingsSectionsFiltered(
                        self::PAGE_SLUG,
                        [ 'nvoos_graphify_sources_cpts', 'nvoos_graphify_sources_ext' ]
                    );
                    submit_button( __( 'Save Sources Settings', 'nvoos-graphify' ) );
                    ?>
                </form>
            <?php elseif ( 'remote' === $current_tab ) : ?>
                <?php if ( class_exists( 'NvoosGraphify\Admin\RemoteAdmin' ) ) : ?>
                    <?php \NvoosGraphify\Admin\RemoteAdmin::renderTab(); ?>
                <?php endif; ?>
                <form method="post" action="options.php" style="margin-top:20px;">
                    <?php
                    settings_fields( 'nvoos_graphify_settings_group' );
                    $this->doSettingsSectionsFiltered( self::PAGE_SLUG, [ 'nvoos_graphify_remote' ] );
                    submit_button( __( 'Save Remote Settings', 'nvoos-graphify' ) );
                    ?>
                </form>
            <?php elseif ( 'embeddings' === $current_tab ) : ?>
                <?php if ( class_exists( 'NvoosGraphify\Admin\RemoteAdmin' ) ) : ?>
                    <?php \NvoosGraphify\Admin\RemoteAdmin::renderEmbeddingsPanel(); ?>
                <?php endif; ?>
                <form method="post" action="options.php" style="margin-top:20px;">
                    <?php
                    settings_fields( 'nvoos_graphify_settings_group' );
                    $this->doSettingsSectionsFiltered( self::PAGE_SLUG, [ 'nvoos_graphify_embeddings' ] );
                    submit_button( __( 'Save Embeddings Settings', 'nvoos-graphify' ) );
                    ?>
                </form>
            <?php else : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'nvoos_graphify_settings_group' );
                    $this->doSettingsSectionsFiltered(
                        self::PAGE_SLUG,
                        [ 'nvoos_graphify_general', 'nvoos_graphify_build', 'nvoos_graphify_display' ]
                    );
                    submit_button();
                    ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Output settings sections for a specific subset of section IDs.
     *
     * WordPress's `do_settings_sections()` prints ALL sections for a page slug.
     * This wrapper lets us selectively print only the sections relevant to a tab.
     *
     * @since 1.0.0
     *
     * @global array $wp_settings_sections Registered settings sections.
     * @global array $wp_settings_fields   Registered settings fields.
     *
     * @param string   $page     Settings page slug.
     * @param string[] $sections Section IDs to render.
     * @return void
     */
    private function doSettingsSectionsFiltered( string $page, array $sections ): void
    {
        global $wp_settings_sections, $wp_settings_fields;

        if ( ! isset( $wp_settings_sections[ $page ] ) ) {
            return;
        }

        foreach ( (array) $wp_settings_sections[ $page ] as $section ) {
            if ( ! in_array( $section['id'], $sections, true ) ) {
                continue;
            }
            if ( $section['title'] ) {
                echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
            }
            if ( $section['callback'] ) {
                call_user_func( $section['callback'], $section );
            }
            if ( ! isset( $wp_settings_fields[ $page ][ $section['id'] ] ) ) {
                continue;
            }
            echo '<table class="form-table" role="presentation"><tbody>';
            do_settings_fields( $page, $section['id'] );
            echo '</tbody></table>';
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: trigger build from settings page
    // -------------------------------------------------------------------------

    /**
     * Handle AJAX request to trigger a graph build.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function handleAjaxBuild(): void
    {
        check_ajax_referer( 'nvoos_graphify_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'nvoos-graphify' ) ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above.
        $incremental = ! empty( $_POST['incremental'] );

        $result = Builder::build(
            [
                'incremental'    => $incremental,
                'semantic'       => true,
                'async_semantic' => true,
            ]
        );

        wp_send_json_success( $result );
    }
}
