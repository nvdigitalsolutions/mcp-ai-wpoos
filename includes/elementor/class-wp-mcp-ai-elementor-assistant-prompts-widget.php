<?php
/**
 * Elementor widget for listing an assistant's configured prompts.
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
 * Elementor widget definition for assistant prompts.
 */
class WP_MCP_AI_Elementor_Assistant_Prompts_Widget extends \Elementor\Widget_Base {
    use WP_MCP_AI_Elementor_Text_Formatting;

    /**
     * Widget slug.
     */
    public function get_name() {
        return 'wp_mcp_ai_assistant_prompts';
    }

    /**
     * Widget title shown in the Elementor editor.
     */
    public function get_title() {
        return __( 'MCP AI Assistant Prompts', 'wp-mcp-ai' );
    }

    /**
     * Widget icon for Elementor panel.
     */
    public function get_icon() {
        return 'eicon-bullet-list';
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
        return array( 'assistant', 'prompt', 'prompts', 'tasks', 'mcp', 'ai' );
    }

    /**
     * Register controls for the widget settings.
     */
    protected function register_controls() {
        $this->start_controls_section(
            'section_content',
            array(
                'label' => __( 'Content', 'wp-mcp-ai' ),
            )
        );

        $this->add_control(
            'title',
            array(
                'label'       => __( 'Title', 'wp-mcp-ai' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __( 'Prompts', 'wp-mcp-ai' ),
                'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
                'label_block' => true,
            )
        );

        $this->add_control(
            'assistant_id',
            array(
                'label'       => __( 'Assistant', 'wp-mcp-ai' ),
                'type'        => \Elementor\Controls_Manager::SELECT,
                'options'     => $this->get_assistant_options(),
                'default'     => '',
                'label_block' => true,
                'description' => __( 'Choose which assistant to display prompts for. Only published assistants appear in this list.', 'wp-mcp-ai' ),
            )
        );

        $this->add_control(
            'show_descriptions',
            array(
                'label'        => __( 'Show descriptions', 'wp-mcp-ai' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
                'label_off'    => __( 'No', 'wp-mcp-ai' ),
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'show_prompt_text',
            array(
                'label'        => __( 'Show prompt text', 'wp-mcp-ai' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
                'label_off'    => __( 'No', 'wp-mcp-ai' ),
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'show_tool_context',
            array(
                'label'        => __( 'Show associated tool', 'wp-mcp-ai' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
                'label_off'    => __( 'No', 'wp-mcp-ai' ),
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'empty_message',
            array(
                'label'       => __( 'Empty state message', 'wp-mcp-ai' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __( 'Select an assistant in the widget settings to view its prompts.', 'wp-mcp-ai' ),
                'placeholder' => __( 'Add guidance for when no assistant is selected…', 'wp-mcp-ai' ),
                'label_block' => true,
            )
        );

        $this->add_control(
            'no_prompts_message',
            array(
                'label'       => __( 'No prompts message', 'wp-mcp-ai' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __( 'No prompts have been saved for this assistant yet.', 'wp-mcp-ai' ),
                'placeholder' => __( 'Add guidance for when no prompts are available…', 'wp-mcp-ai' ),
                'label_block' => true,
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
        $assistant_setting = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : '';
        $assistant_id      = '' !== $assistant_setting ? absint( $assistant_setting ) : 0;
        $show_descriptions = ! empty( $settings['show_descriptions'] ) && 'yes' === $settings['show_descriptions'];
        $show_prompt_text  = ! empty( $settings['show_prompt_text'] ) && 'yes' === $settings['show_prompt_text'];
        $show_tool         = ! empty( $settings['show_tool_context'] ) && 'yes' === $settings['show_tool_context'];
        $empty_message     = isset( $settings['empty_message'] ) ? $settings['empty_message'] : '';
        $no_prompts_msg  = isset( $settings['no_prompts_message'] ) ? $settings['no_prompts_message'] : '';

        echo '<div class="wp-mcp-ai-assistant-prompts">';

        if ( '' !== $title ) {
            $title_output = $this->format_text_inline( $title );

            if ( '' !== $title_output ) {
                echo '<h3 class="wp-mcp-ai-assistant-prompts__title">' . $title_output . '</h3>';
            }
        }

        if ( ! $assistant_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
            $empty_output = $this->format_text_inline( $empty_message );

            if ( '' !== $empty_output ) {
                echo '<p class="wp-mcp-ai-assistant-prompts__notice">' . $empty_output . '</p>';
            }

            echo '</div>';
            return;
        }

        $config    = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
        $prompts = isset( $config['tool_prompts'] ) && is_array( $config['tool_prompts'] ) ? $config['tool_prompts'] : array();

        if ( empty( $prompts ) ) {
            $no_prompts_output = $this->format_text_inline( $no_prompts_msg );

            if ( '' !== $no_prompts_output ) {
                echo '<p class="wp-mcp-ai-assistant-prompts__notice">' . $no_prompts_output . '</p>';
            }

            echo '</div>';
            return;
        }

        $tool_names = $show_tool ? $this->get_tool_name_map() : array();

        echo '<ul class="wp-mcp-ai-assistant-prompts__list">';

        foreach ( $prompts as $prompt ) {
            $label       = isset( $prompt['label'] ) ? $prompt['label'] : '';
            $payload     = isset( $prompt['payload'] ) ? $prompt['payload'] : '';
            $description = isset( $prompt['description'] ) ? $prompt['description'] : '';
            $tool        = isset( $prompt['tool'] ) ? $prompt['tool'] : '';

            $label_text = '' !== $label ? $this->format_text_inline( $label ) : '';

            echo '<li class="wp-mcp-ai-assistant-prompts__item">';

            if ( '' !== $label_text ) {
                echo '<span class="wp-mcp-ai-assistant-prompts__label">' . $label_text . '</span>';
            }

            if ( $show_tool ) {
                $tool_label = $this->format_tool_label( $tool, $tool_names );

                if ( '' !== $tool_label ) {
                    echo '<span class="wp-mcp-ai-assistant-prompts__tool">' . esc_html__( 'Tool:', 'wp-mcp-ai' ) . ' ' . esc_html( $tool_label ) . '</span>';
                }
            }

            if ( $show_descriptions ) {
                $description_output = $this->format_text_block( $description );

                if ( '' !== $description_output ) {
                    echo '<div class="wp-mcp-ai-assistant-prompts__description">' . $description_output . '</div>';
                }
            }

            if ( $show_prompt_text && '' !== $payload ) {
                echo '<pre class="wp-mcp-ai-assistant-prompts__payload">' . esc_html( $payload ) . '</pre>';
            }

            echo '</li>';
        }

        echo '</ul>';
        echo '</div>';
    }

    /**
     * Retrieve the available assistants as select options.
     *
     * @return array
     */
    protected function get_assistant_options() {
        $assistants = get_posts(
            array(
                'post_type'        => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status'      => 'publish',
                'numberposts'      => -1,
                'orderby'          => 'title',
                'order'            => 'ASC',
                'suppress_filters' => false,
                'fields'           => 'ids',
            )
        );

        $options = array( '' => __( 'Select an assistant', 'wp-mcp-ai' ) );

        if ( empty( $assistants ) ) {
            return $options;
        }

        foreach ( $assistants as $assistant_id ) {
            $options[ (string) $assistant_id ] = get_the_title( $assistant_id );
        }

        return $options;
    }

    /**
     * Build a map of tool slugs to names for quick lookups.
     *
     * @return array<string, string>
     */
    protected function get_tool_name_map() {
        if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
            return array();
        }

        $registry = WP_MCP_AI_Tool_Registry::get_instance();
        $registry->init();

        $map = array();

        foreach ( $registry->get_tools() as $tool ) {
            if ( ! $tool ) {
                continue;
            }

            $slug = sanitize_key( $tool->get_slug() );

            if ( '' === $slug ) {
                continue;
            }

            $map[ $slug ] = $tool->get_name();
        }

        return $map;
    }

    /**
     * Format a human readable tool label.
     *
     * @param string $tool_slug Tool slug stored on the prompt.
     * @param array  $tool_names Map of tool slugs to human-readable names.
     * @return string
     */
    protected function format_tool_label( $tool_slug, $tool_names ) {
        $tool_slug = sanitize_key( $tool_slug );

        if ( '' === $tool_slug ) {
            return '';
        }

        if ( isset( $tool_names[ $tool_slug ] ) && '' !== $tool_names[ $tool_slug ] ) {
            return $tool_names[ $tool_slug ];
        }

        return $tool_slug;
    }
}

if ( ! class_exists( 'WP_MCP_AI_Elementor_Assistant_Prompt_Shortcuts_Widget' ) ) {
    class_alias( 'WP_MCP_AI_Elementor_Assistant_Prompts_Widget', 'WP_MCP_AI_Elementor_Assistant_Prompt_Shortcuts_Widget' );
}
