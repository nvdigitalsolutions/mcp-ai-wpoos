<?php
/**
 * Docs Hub stub classes for integration-path tests.
 *
 * Defines minimal stand-ins for the standalone NV oOS Docs Hub plugin so the
 * docs_hub_* document generation tools can exercise their integration paths
 * (content-dir resolution via the plugin, rebuild triggering) without the
 * real plugin being loaded. Every declaration is guarded by class_exists()
 * so requiring this file is always safe.
 *
 * This file is required lazily from inside test methods — never from a test
 * class body — so the tools' "plugin inactive" code paths can be asserted
 * before the stubs exist.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

if ( ! class_exists( 'NV_oOS_Docs_Hub_Plugin' ) ) {
	/**
	 * Stub Docs Hub plugin class.
	 */
	class NV_oOS_Docs_Hub_Plugin {

		/**
		 * Resolve the content dir via the uploads basedir.
		 *
		 * @return string
		 */
		public static function uploads_docs_dir() {
			$info = wp_upload_dir();
			return untrailingslashit( (string) $info['basedir'] ) . '/nvoos-docs-hub/content';
		}

		/**
		 * Return settings with the uploads source enabled.
		 *
		 * @return array
		 */
		public static function get_settings() {
			return array(
				'sources' => array( 'uploads' ),
			);
		}
	}
}

if ( ! class_exists( 'NV_oOS_Docs_Hub_Rebuild_Job' ) ) {
	/**
	 * Stub Docs Hub rebuild job class.
	 */
	class NV_oOS_Docs_Hub_Rebuild_Job {

		/**
		 * Stub async enqueue.
		 *
		 * @return array
		 */
		public static function enqueue_async() {
			return array(
				'job_id'     => 'job-9',
				'phase'      => 'scan',
				'total'      => 0,
				'processed'  => 0,
				'percentage' => 0,
				'last_error' => '',
			);
		}

		/**
		 * Stub sync run.
		 *
		 * @return array
		 */
		public static function run() {
			return array(
				'success'      => true,
				'pages'        => 2,
				'broken_links' => 0,
				'duration_ms'  => 5,
			);
		}
	}
}
