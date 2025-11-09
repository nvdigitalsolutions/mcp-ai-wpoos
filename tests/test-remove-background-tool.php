<?php

require_once WP_MCP_AI_PATH . 'includes/tools/remove-background.php';

/**
 * Tests for the remove background helper.
 */
class WP_MCP_AI_Remove_Background_Tool_Test extends WP_UnitTestCase {

        /**
         * Ensure raw filesystem paths are rejected.
         */
        public function test_rejects_raw_filesystem_path() {
                $result = wp_mcp_ai_remove_image_background( '/etc/passwd' );

                $this->assertWPError( $result );
                $this->assertSame( 'wp_mcp_ai_invalid_attachment_reference', $result->get_error_code() );
        }

        /**
         * Ensure attachments stored outside the uploads directory are blocked.
         */
        public function test_rejects_attachments_outside_uploads_directory() {
                list( $attachment_id ) = $this->create_mock_image_attachment();

                $outside_path = tempnam( sys_get_temp_dir(), 'wp-mcp-ai-test' );
                $this->assertIsString( $outside_path );
                file_put_contents( $outside_path, 'fake' );

                update_attached_file( $attachment_id, $outside_path );

                $result = wp_mcp_ai_remove_image_background( $attachment_id );

                $this->assertWPError( $result );
                $this->assertSame( 'wp_mcp_ai_attachment_outside_uploads', $result->get_error_code() );

                @unlink( $outside_path );
        }

        /**
         * Ensure inaccessible attachments are rejected.
         */
        public function test_rejects_attachments_without_permission() {
                list( $attachment_id ) = $this->create_mock_image_attachment();

                wp_set_current_user( 0 );

                add_filter( 'wp_mcp_ai_remove_background_can_access_attachment', '__return_false' );
                $result = wp_mcp_ai_remove_image_background( $attachment_id );
                remove_filter( 'wp_mcp_ai_remove_background_can_access_attachment', '__return_false' );

                $this->assertWPError( $result );
                $this->assertSame( 'wp_mcp_ai_attachment_forbidden', $result->get_error_code() );
        }

        /**
         * Create a mock image attachment for testing.
         *
         * @return array{0:int,1:string}
         */
        protected function create_mock_image_attachment() {
                $upload = wp_upload_bits( 'sample-image.png', null, 'fake-image-contents' );
                $this->assertFalse( $upload['error'] );

                $attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

                return array( $attachment_id, $upload['file'] );
        }
}
