<?php
/**
 * Elementor widget for displaying a matrix of available MCP AI tools.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
    return;
}

/**
 * Elementor widget definition for the assistant tool matrix.
 */
class WP_MCP_AI_Elementor_Dashboard_Tool_Matrix_Widget extends \Elementor\Widget_Base {
    use WP_MCP_AI_Elementor_Text_Formatting;
    /**
     * Widget slug.
     */
    public function get_name() {
        return 'wp_mcp_ai_tool_matrix';
    }

    /**
     * Widget title shown in the Elementor editor.
     */
    public function get_title() {
        return __( 'MCP AI Tool Matrix', 'wp-mcp-ai' );
    }

    /**
     * Widget icon for Elementor panel.
     */
    public function get_icon() {
        return 'eicon-table';
    }

    /**
     * Widget categories.
     */
    public function get_categories() {
        return array( 'general' );
    }

    /**
     * Keywords to help search for the widget.
     */
    public function get_keywords() {
        return array( 'mcp', 'tool', 'assistant', 'matrix', 'dashboard' );
    }

    /**
     * Register controls for the widget settings.
     */
    protected function register_controls() {
        $this->start_controls_section(
            'section_content',
            array(
                'label' => __( 'Matrix Content', 'wp-mcp-ai' ),
            )
        );

        $this->add_control(
            'title',
            array(
                'label'       => __( 'Title', 'wp-mcp-ai' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __( 'Assistant tool matrix', 'wp-mcp-ai' ),
                'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
                'label_block' => true,
            )
        );

        $this->add_control(
            'description',
            array(
                'label'       => __( 'Description', 'wp-mcp-ai' ),
                'type'        => \Elementor\Controls_Manager::TEXTAREA,
                'placeholder' => __( 'Provide context for the tool list.', 'wp-mcp-ai' ),
                'rows'        => 4,
                'default'     => __( 'Review each bundled MCP tool, its slug, and the capability required before enabling it for assistants.', 'wp-mcp-ai' ),
            )
        );

        $this->add_control(
            'show_capability_notes',
            array(
                'label'        => __( 'Show capability notes', 'wp-mcp-ai' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
                'label_off'    => __( 'No', 'wp-mcp-ai' ),
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->end_controls_section();
    }

    /**
     * Render the widget on the front-end.
     */
    protected function render() {
        $settings = $this->get_settings_for_display();

        $title             = isset( $settings['title'] ) ? $settings['title'] : '';
        $description       = isset( $settings['description'] ) ? $settings['description'] : '';
        $show_capabilities = ! empty( $settings['show_capability_notes'] ) && 'yes' === $settings['show_capability_notes'];

        if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
            echo '<div class="wp-mcp-ai-tool-matrix">';
            if ( ! empty( $title ) ) {
                echo '<h3 class="wp-mcp-ai-tool-matrix__title">' . esc_html( $title ) . '</h3>';
            }
            echo '<p class="wp-mcp-ai-tool-matrix__notice">' . esc_html__( 'The tool registry is unavailable.', 'wp-mcp-ai' ) . '</p>';
            echo '</div>';
            return;
        }

        $registry = WP_MCP_AI_Tool_Registry::get_instance();

        if ( method_exists( $registry, 'init' ) ) {
            $registry->init();
        }

        $tools = $registry->get_tools();

        $group_map    = $this->get_tool_group_map();
        $group_labels = $this->get_group_labels();
        $capabilities = $this->get_capability_notes();

        $grouped = array();

        foreach ( $tools as $tool ) {
            if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
                continue;
            }

            $slug     = sanitize_key( $tool->get_slug() );
            $group_id = isset( $group_map[ $slug ] ) ? $group_map[ $slug ] : 'other';
            $group    = isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : $group_labels['other'];

            if ( ! isset( $grouped[ $group ] ) ) {
                $grouped[ $group ] = array();
            }

            $grouped[ $group ][] = array(
                'name'        => $tool->get_name(),
                'slug'        => $slug,
                'capability'  => isset( $capabilities[ $slug ] ) ? $capabilities[ $slug ] : $capabilities['default'],
                'description' => $tool->get_description(),
            );
        }

        if ( empty( $grouped ) ) {
            echo '<div class="wp-mcp-ai-tool-matrix">';
            if ( ! empty( $title ) ) {
                echo '<h3 class="wp-mcp-ai-tool-matrix__title">' . esc_html( $title ) . '</h3>';
            }
            echo '<p class="wp-mcp-ai-tool-matrix__notice">' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="wp-mcp-ai-tool-matrix">';

        if ( ! empty( $title ) ) {
            echo '<h3 class="wp-mcp-ai-tool-matrix__title">' . esc_html( $title ) . '</h3>';
        }

        if ( ! empty( $description ) ) {
            $description_output = $this->format_text_block( $description );

            if ( '' !== $description_output ) {
                echo '<div class="wp-mcp-ai-tool-matrix__description">' . $description_output . '</div>';
            }
        }

        foreach ( $grouped as $group_label => $entries ) {
            $has_descriptions     = false;
            $formatted_entries    = array();
            $has_capability_notes = false;

            foreach ( $entries as $entry ) {
                $formatted_entry = array(
                    'name'        => $entry['name'],
                    'slug'        => $entry['slug'],
                    'capability'  => '',
                    'description' => '',
                );

                if ( $show_capabilities ) {
                    $capability_output = $this->format_text_inline( $entry['capability'] );

                    if ( '' !== $capability_output ) {
                        $formatted_entry['capability'] = $capability_output;
                        $has_capability_notes           = true;
                    }
                }

                if ( ! empty( $entry['description'] ) ) {
                    $description_output = $this->format_text_inline( $entry['description'] );

                    if ( '' !== $description_output ) {
                        $formatted_entry['description'] = $description_output;
                        $has_descriptions               = true;
                    }
                }

                $formatted_entries[] = $formatted_entry;
            }

            echo '<div class="wp-mcp-ai-tool-matrix__group">';
            echo '<h4 class="wp-mcp-ai-tool-matrix__group-title">' . esc_html( $group_label ) . '</h4>';
            echo '<div class="wp-mcp-ai-tool-matrix__table">';
            echo '<table class="wp-mcp-ai-tool-matrix__table-grid">';
            echo '<thead>';
            echo '<tr class="wp-mcp-ai-tool-matrix__table-row wp-mcp-ai-tool-matrix__table-row--head">';
            echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--name">' . esc_html__( 'Tool', 'wp-mcp-ai' ) . '</th>';
            echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--slug">' . esc_html__( 'Slug', 'wp-mcp-ai' ) . '</th>';

            if ( $has_capability_notes ) {
                echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--capability">' . esc_html__( 'Required capability', 'wp-mcp-ai' ) . '</th>';
            }

            if ( $has_descriptions ) {
                echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--description">' . esc_html__( 'Description', 'wp-mcp-ai' ) . '</th>';
            }

            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ( $formatted_entries as $formatted_entry ) {
                echo '<tr class="wp-mcp-ai-tool-matrix__table-row">';
                echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--name">' . esc_html( $formatted_entry['name'] ) . '</td>';
                echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--slug"><code>' . esc_html( $formatted_entry['slug'] ) . '</code></td>';

                if ( $has_capability_notes ) {
                    echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--capability">' . $formatted_entry['capability'] . '</td>';
                }

                if ( $has_descriptions ) {
                    echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--description">' . $formatted_entry['description'] . '</td>';
                }

                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Mapping of tool slugs to group identifiers.
     *
     * @return array
     */
    protected function get_tool_group_map() {
        return array(
            'submit_document_prompt'        => 'content',
            'get_recent_posts'              => 'content',
            'get_user_info'                 => 'content',
            'get_site_summary'              => 'content',
            'get_jetengine_items'           => 'content',
            'get_woo_recent_orders'         => 'content',
            'get_woo_products'              => 'content',
            'generate_openai_image'         => 'media',
            'generate_openai_speech'        => 'media',
            'transcribe_openai_audio'       => 'media',
            'run_openai_external_action'    => 'automation',
            'run_crawl4ai_job'              => 'automation',
            'web_search'                    => 'automation',
            'list_jetengine_rest_routes'    => 'jetengine',
            'invoke_jetengine_route'        => 'jetengine',
            'create_cron_job'               => 'operations',
            'send_group_email'              => 'operations',
            'open_openai_usage'             => 'operations',
            'open_openai_logs'              => 'operations',
        );
    }

    /**
     * Human readable labels for tool groups.
     *
     * @return array
     */
    protected function get_group_labels() {
        return array(
            'content'    => __( 'Content ingestion & retrieval', 'wp-mcp-ai' ),
            'media'      => __( 'Media generation & transcription', 'wp-mcp-ai' ),
            'automation' => __( 'External data & automations', 'wp-mcp-ai' ),
            'jetengine'  => __( 'JetEngine REST utilities', 'wp-mcp-ai' ),
            'operations' => __( 'Operational helpers', 'wp-mcp-ai' ),
            'other'      => __( 'Other tools', 'wp-mcp-ai' ),
        );
    }

    /**
     * Capability notes for each tool slug.
     *
     * @return array
     */
    protected function get_capability_notes() {
        return array(
            'default'                         => __( 'Requires authenticated access.', 'wp-mcp-ai' ),
            'submit_document_prompt'          => __( 'Requires upload permissions matching attachment handling.', 'wp-mcp-ai' ),
            'get_recent_posts'                => __( 'Requires the "read" capability.', 'wp-mcp-ai' ),
            'get_user_info'                   => __( 'Requires login; "list_users" or "manage_options" to inspect other profiles.', 'wp-mcp-ai' ),
            'get_site_summary'                => __( 'Requires the "manage_options" capability.', 'wp-mcp-ai' ),
            'get_jetengine_items'             => __( 'Requires access to the JetEngine post type (typically "edit_posts").', 'wp-mcp-ai' ),
            'get_woo_recent_orders'           => __( 'Requires "manage_woocommerce" or "view_woocommerce_reports".', 'wp-mcp-ai' ),
            'get_woo_products'                => __( 'Requires "manage_woocommerce" or "view_woocommerce_reports".', 'wp-mcp-ai' ),
            'generate_openai_image'           => __( 'Requires the "upload_files" capability for media storage.', 'wp-mcp-ai' ),
            'generate_openai_speech'          => __( 'Requires the "upload_files" capability for media storage.', 'wp-mcp-ai' ),
            'transcribe_openai_audio'         => __( 'Requires the "upload_files" capability for media storage.', 'wp-mcp-ai' ),
            'run_openai_external_action'      => __( 'Requires the "manage_options" capability.', 'wp-mcp-ai' ),
            'run_crawl4ai_job'                => __( 'Requires the "manage_options" capability.', 'wp-mcp-ai' ),
            'web_search'                      => __( 'Requires the "read" capability.', 'wp-mcp-ai' ),
            'list_jetengine_rest_routes'      => __( 'Requires the "manage_options" capability and JetEngine.', 'wp-mcp-ai' ),
            'invoke_jetengine_route'          => __( 'Requires JetEngine access for the requested operation.', 'wp-mcp-ai' ),
            'create_cron_job'                 => __( 'Requires the "manage_options" capability.', 'wp-mcp-ai' ),
            'send_group_email'                => __( 'Requires the configured group email capability (defaults to "publish_posts").', 'wp-mcp-ai' ),
            'open_openai_usage'               => __( 'Requires the "manage_options" capability.', 'wp-mcp-ai' ),
            'open_openai_logs'                => __( 'Requires the "manage_options" capability.', 'wp-mcp-ai' ),
        );
    }
}
