<?php
/**
 * Minimal Elementor stubs for unit tests.
 */

namespace Elementor {
    if ( ! class_exists( '\\Elementor\\Plugin', false ) ) {
        class Plugin {
            /**
             * Retrieve the singleton instance.
             *
             * @return Plugin
             */
            public static function instance() {
                static $instance = null;

                if ( null === $instance ) {
                    $instance = new self();
                }

                return $instance;
            }
        }
    }
}

namespace ElementorPro {
    if ( ! class_exists( '\\ElementorPro\\Plugin', false ) ) {
        class Plugin {
            /**
             * Retrieve the singleton instance.
             *
             * @return Plugin
             */
            public static function instance() {
                static $instance = null;

                if ( null === $instance ) {
                    $instance = new self();
                }

                return $instance;
            }
        }
    }
}

namespace {
    if ( ! function_exists( 'wp_mcp_ai_register_elementor_library_post_type' ) ) {
        /**
         * Register a simplified Elementor library post type for testing.
         */
        function wp_mcp_ai_register_elementor_library_post_type() {
            if ( post_type_exists( 'elementor_library' ) ) {
                return;
            }

            register_post_type(
                'elementor_library',
                array(
                    'label'               => 'Elementor Library',
                    'public'              => false,
                    'show_ui'             => true,
                    'capability_type'     => 'page',
                    'map_meta_cap'        => true,
                    'supports'            => array( 'title', 'editor' ),
                    'rewrite'             => false,
                    'publicly_queryable'  => false,
                    'query_var'           => false,
                    'show_in_rest'        => false,
                )
            );
        }
    }
}
