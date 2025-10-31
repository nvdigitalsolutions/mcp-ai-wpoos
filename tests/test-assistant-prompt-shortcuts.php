<?php
/**
 * Tests for assistant prompt shortcut aggregation.
 *
 * @package WP_MCP_AI\Tests
 */

class Test_Assistant_Prompt_Shortcuts extends WP_UnitTestCase {
    /**
     * Registered stub tool instance.
     *
     * @var WP_MCP_AI_Test_Prompt_Shortcut_Tool
     */
    protected $stub_tool;

    /**
     * Tool registry reference.
     *
     * @var WP_MCP_AI_Tool_Registry
     */
    protected $registry;

    public function setUp(): void {
        parent::setUp();

        if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
            wp_mcp_ai_bootstrap();
        }

        $this->registry = WP_MCP_AI_Tool_Registry::get_instance();

        if ( method_exists( $this->registry, 'init' ) ) {
            $this->registry->init();
        }

        $this->stub_tool = new WP_MCP_AI_Test_Prompt_Shortcut_Tool();
        $this->registry->register_tool( $this->stub_tool );
    }

    public function tearDown(): void {
        if ( $this->registry instanceof WP_MCP_AI_Tool_Registry ) {
            $this->registry->unregister_tool( $this->stub_tool->get_slug() );
        }

        parent::tearDown();
    }

    /**
     * Ensure the default prompt shortcuts from a tool are merged with the global fallback entry.
     */
    public function test_tool_default_shortcuts_are_included_with_global_fallback() {
        $assistant_id = self::factory()->post->create(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Prompt Shortcut Assistant',
            )
        );

        update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( $this->stub_tool->get_slug() ) );

        $shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

        $this->assertNotEmpty( $shortcuts, 'Expected shortcut entries to be returned.' );

        $tool_shortcuts = array_filter(
            $shortcuts,
            function ( $shortcut ) {
                return is_array( $shortcut ) && isset( $shortcut['tool'] ) && $this->stub_tool->get_slug() === $shortcut['tool'];
            }
        );

        $this->assertCount( 1, $tool_shortcuts, 'Default tool shortcut should be present exactly once.' );

        $tool_shortcut = array_values( $tool_shortcuts )[0];
        $this->assertSame( 'Default summary', $tool_shortcut['label'] );
        $this->assertSame( 'summarize the latest updates', $tool_shortcut['payload'] );
        $this->assertSame( 'Provide a quick site summary.', $tool_shortcut['description'] );

        $fallback_entries = array_filter(
            $shortcuts,
            static function ( $shortcut ) {
                return is_array( $shortcut ) && isset( $shortcut['tool'] ) && 'default' === $shortcut['tool'];
            }
        );

        $this->assertCount( 1, $fallback_entries, 'Global fallback shortcut should be appended once.' );

        $fallback_shortcut = array_values( $fallback_entries )[0];
        $this->assertSame( 'What can you do?', $fallback_shortcut['label'] );
        $this->assertSame( 'what are some things you can do', $fallback_shortcut['payload'] );
    }

    /**
     * Ensure custom pre-built shortcuts override the default tool entries.
     */
    public function test_custom_prebuilt_shortcuts_override_default_tool_entries() {
        $assistant_id = self::factory()->post->create(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Custom Prompt Shortcut Assistant',
            )
        );

        update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( $this->stub_tool->get_slug() ) );

        update_post_meta(
            $assistant_id,
            WP_MCP_AI_Assistant_CPT::META_TOOL_PREBUILT_SHORTCUTS,
            array(
                $this->stub_tool->get_slug() => array(
                    'mode'      => 'custom',
                    'shortcuts' => array(
                        array(
                            'label'       => 'Review open support tickets',
                            'payload'     => 'list unresolved support tickets',
                            'description' => 'Surface outstanding issues that need attention.',
                        ),
                    ),
                ),
            )
        );

        $shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

        $tool_shortcuts = array_filter(
            $shortcuts,
            function ( $shortcut ) {
                return is_array( $shortcut ) && isset( $shortcut['tool'] ) && $this->stub_tool->get_slug() === $shortcut['tool'];
            }
        );

        $this->assertCount( 1, $tool_shortcuts, 'Only the custom tool shortcut should be included.' );

        $tool_shortcut = array_values( $tool_shortcuts )[0];
        $this->assertSame( 'Review open support tickets', $tool_shortcut['label'] );
        $this->assertSame( 'list unresolved support tickets', $tool_shortcut['payload'] );
        $this->assertSame( 'Surface outstanding issues that need attention.', $tool_shortcut['description'] );

        $fallback_entries = array_filter(
            $shortcuts,
            static function ( $shortcut ) {
                return is_array( $shortcut ) && isset( $shortcut['tool'] ) && 'default' === $shortcut['tool'];
            }
        );

        $this->assertCount( 1, $fallback_entries, 'Global fallback shortcut should remain appended.' );
    }
}

class WP_MCP_AI_Test_Prompt_Shortcut_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Shortcuts_Interface {
    public function get_slug() {
        return 'test_prompt_shortcut_tool';
    }

    public function get_name() {
        return 'Test Prompt Shortcut Tool';
    }

    public function get_description() {
        return 'Stub tool for exercising prompt shortcut logic.';
    }

    public function get_parameters_schema() {
        return array();
    }

    public function execute( array $arguments = array(), array $context = array() ) {
        return array();
    }

    public function get_shortcut_tasks() {
        return array(
            array(
                'label'       => 'Default summary',
                'payload'     => 'summarize the latest updates',
                'description' => 'Provide a quick site summary.',
            ),
        );
    }
}
