<?php
/**
 * Testable fixture exposing the protected envelope + chat-response helpers
 * of the D8-compat ported traits so their canonical shapes can be asserted
 * directly.
 *
 * Loadable in both matrices: it only composes the tool traits, which the
 * base plugin owns in the monolith matrix and the addon's ported copies own
 * in the standalone matrix.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphPro\Tests;

if ( ! class_exists( __NAMESPACE__ . '\Test_Tool_Response_Fixture' ) ) {
	/**
	 * Test fixture for the ported tool traits.
	 */
	class Test_Tool_Response_Fixture {
		use \WP_MCP_AI_Tool_Envelope;
		use \WP_MCP_AI_Tool_Chat_Response;

		/**
		 * Public passthrough for the canonical success envelope helper.
		 *
		 * @param string $message Success message.
		 * @param mixed  $data    Optional payload.
		 * @return array
		 */
		public function expose_success_response( $message, $data = null ) {
			return $this->format_success_response( $message, $data );
		}

		/**
		 * Public passthrough for the chat response formatter.
		 *
		 * @param mixed  $data    Tool result data.
		 * @param string $message Optional message.
		 * @param array  $options Optional formatting options.
		 * @return array
		 */
		public function expose_chat_response( $data, $message = '', $options = array() ) {
			return $this->format_chat_response( $data, $message, $options );
		}

		/**
		 * Public passthrough for the empty-result formatter.
		 *
		 * @param string $explanation Optional explanation.
		 * @return array
		 */
		public function expose_empty_result_response( $explanation = '' ) {
			return $this->format_empty_result_response( $explanation );
		}

		/**
		 * Public passthrough for the collection formatter.
		 *
		 * @param array  $items   Items.
		 * @param string $message Optional message.
		 * @param array  $options Optional options.
		 * @return array
		 */
		public function expose_collection_response( $items, $message = '', $options = array() ) {
			return $this->format_collection_response( $items, $message, $options );
		}

		/**
		 * Public passthrough for the message guarantee helper.
		 *
		 * @param array  $response         Existing response.
		 * @param string $fallback_message Optional fallback.
		 * @return array
		 */
		public function expose_ensure_response_message( $response, $fallback_message = '' ) {
			return $this->ensure_response_message( $response, $fallback_message );
		}
	}
}
