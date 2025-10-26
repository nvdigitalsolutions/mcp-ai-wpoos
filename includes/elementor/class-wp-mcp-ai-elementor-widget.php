<?php
/**
 * Elementor widget for rendering the MCP AI chat shortcode.
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
 * Elementor widget definition.
 */
class WP_MCP_AI_Elementor_Widget extends \Elementor\Widget_Base {
    /**
     * Widget slug.
     */
    public function get_name() {
        return 'wp_mcp_ai_chat';
    }

    /**
     * Widget title shown in the Elementor editor.
     */
    public function get_title() {
        return __( 'MCP AI Chat', 'wp-mcp-ai' );
    }

    /**
     * Widget icon for Elementor panel.
     */
    public function get_icon() {
        return 'eicon-ai';
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
        return array( 'ai', 'chat', 'assistant', 'mcp' );
    }

    /**
     * Register controls for the widget settings.
     */
    protected function register_controls() {
        $this->start_controls_section(
            'section_settings',
            array(
                'label' => __( 'Chat Settings', 'wp-mcp-ai' ),
            )
        );

        $this->add_control(
            'assistant',
            array(
                'label'       => __( 'Assistant', 'wp-mcp-ai' ),
                'type'        => \Elementor\Controls_Manager::SELECT,
                'options'     => $this->get_assistant_options(),
                'default'     => '',
                'label_block' => true,
                'description' => __( 'Select the assistant to use. Leave empty to use the default assistant configured in the plugin settings.', 'wp-mcp-ai' ),
            )
        );

        $this->add_control(
            'allow_guests',
            array(
                'label'        => __( 'Allow Guests', 'wp-mcp-ai' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
                'label_off'    => __( 'No', 'wp-mcp-ai' ),
                'return_value' => 'true',
                'default'      => 'false',
                'description'  => __( 'Enable guest access using temporary tokens when the assistant allows it.', 'wp-mcp-ai' ),
            )
        );

        $this->end_controls_section();
    }

    /**
     * Retrieve the available assistants as select options.
     *
     * @return array
     */
    protected function get_assistant_options() {
        $assistants = get_posts(
            array(
                'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status'    => 'publish',
                'numberposts'    => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'suppress_filters' => false,
                'fields'         => 'ids',
            )
        );

        $options = array( '' => __( 'Default Assistant', 'wp-mcp-ai' ) );

        if ( empty( $assistants ) ) {
            return $options;
        }

        foreach ( $assistants as $assistant_id ) {
            $options[ (string) $assistant_id ] = get_the_title( $assistant_id );
        }

        return $options;
    }

    /**
     * Render the widget on the front-end.
     */
    protected function render() {
        $settings = $this->get_settings_for_display();

        $attributes = array();

        if ( ! empty( $settings['assistant'] ) ) {
            $attributes['assistant'] = (string) absint( $settings['assistant'] );
        }

        $allow_guests = ! empty( $settings['allow_guests'] ) && 'true' === $settings['allow_guests'];
        $attributes['allow_guests'] = $allow_guests ? 'true' : 'false';

        $shortcode = '[' . WP_MCP_AI_Shortcode::SHORTCODE;

        foreach ( $attributes as $key => $value ) {
            $shortcode .= ' ' . $key . '="' . esc_attr( $value ) . '"';
        }

        $shortcode .= ']';

        echo do_shortcode( $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
