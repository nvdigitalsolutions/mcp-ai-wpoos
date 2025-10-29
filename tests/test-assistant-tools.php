<?php
/**
 * Tests covering assistant tool registrations and sanitization.
 */
class WP_MCP_AI_Assistant_Tools_Test extends WP_UnitTestCase {

    /**
     * Ensure tool slugs are restricted to the registered tool list.
     */
    public function test_sanitize_tools_meta_discards_unregistered_slugs() {
        $registry = WP_MCP_AI_Tool_Registry::get_instance();
        $registry->init();

        $sanitized = WP_MCP_AI_Assistant_CPT::sanitize_tools_meta(
            array(
                'get_recent_posts',
                'invalid-tool',
                'GET_SITE_SUMMARY',
                '',
            )
        );

        $this->assertSame(
            array(
                'get_recent_posts',
                'get_site_summary',
            ),
            $sanitized
        );
    }

    /**
     * Ensure invalid input types do not trigger notices and return an empty array.
     */
    public function test_sanitize_tools_meta_handles_non_array_values() {
        $this->assertSame(
            array(),
            WP_MCP_AI_Assistant_CPT::sanitize_tools_meta( null )
        );

        $this->assertSame(
            array(),
            WP_MCP_AI_Assistant_CPT::sanitize_tools_meta( 'get_recent_posts' )
        );
    }

    /**
     * Ensure argument-less tools expose a valid empty object for their parameter schema.
     */
    public function test_tools_without_arguments_expose_object_properties_schema() {
        $registry = WP_MCP_AI_Tool_Registry::get_instance();
        $registry->init();

        $tools_requiring_empty_properties = array(
            'get_site_summary',
            'open_openai_logs',
            'open_openai_usage',
        );

        foreach ( $tools_requiring_empty_properties as $slug ) {
            $tool   = $registry->get_tool( $slug );
            $schema = $tool ? $tool->get_parameters_schema() : null;

            $this->assertNotNull( $tool, sprintf( 'Tool %s should be registered.', $slug ) );
            $this->assertIsArray( $schema );
            $this->assertArrayHasKey( 'properties', $schema );
            $this->assertSame(
                '{}',
                wp_json_encode( $schema['properties'] ),
                sprintf( 'Tool %s should expose an empty object for the properties schema.', $slug )
            );
        }
    }
}
