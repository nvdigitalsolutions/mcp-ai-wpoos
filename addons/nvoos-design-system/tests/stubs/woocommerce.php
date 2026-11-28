<?php
// phpcs:ignoreFile -- These are third-party WooCommerce API stubs, not plugin code: the class names, method names and file layout must mirror WooCommerce, so the WordPress coding standards do not apply.
/**
 * Lightweight WooCommerce stubs for the design-system test suite.
 *
 * Only defined when WooCommerce is not present in the environment, so the
 * integration's hook wiring can be exercised without a full WooCommerce
 * install.
 *
 * @package NV_oOS_Design_System
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * WooCommerce facade stub.
	 */
	class WooCommerce {
		/**
		 * Mailer accessor.
		 *
		 * @return WC_Emails
		 */
		public function mailer() {
			return $GLOBALS['nds_test_mailer'];
		}
	}
}

if ( ! class_exists( 'WC_Email' ) ) {
	/**
	 * WC_Email stub.
	 */
	class WC_Email {}
}

if ( ! class_exists( 'WC_Emails' ) ) {
	/**
	 * WC_Emails stub with the default header/footer handlers.
	 */
	class WC_Emails {
		/**
		 * Default header handler.
		 *
		 * @param string   $heading Heading.
		 * @param WC_Email $email   Email object.
		 * @return void
		 */
		public function email_header( $heading, $email ) {
			echo 'DEFAULT-HEADER';
		}

		/**
		 * Default footer handler.
		 *
		 * @param WC_Email $email Email object.
		 * @return void
		 */
		public function email_footer( $email ) {
			echo 'DEFAULT-FOOTER';
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * WooCommerce facade accessor.
	 *
	 * @return WooCommerce
	 */
	function WC() {
		return $GLOBALS['nds_test_wc'];
	}
}
