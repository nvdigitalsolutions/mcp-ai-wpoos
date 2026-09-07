<?php
/**
 * NV oOS Comic Reader — MIME Signature Tests
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.2.1
 */

/**
 * Test archive magic-byte validation.
 */
class Test_Comic_Reader_Mime extends WP_UnitTestCase {

	/**
	 * Write a temp file and return its path.
	 *
	 * @param string $contents File contents.
	 * @return string Temp file path.
	 */
	private function write_temp( $contents ) {
		$tmp = tempnam( sys_get_temp_dir(), 'nvoos-mime-' );
		file_put_contents( $tmp, $contents );
		return $tmp;
	}

	/**
	 * Test ZIP signatures are accepted.
	 *
	 * @return void
	 */
	public function test_zip_signature_accepted() {
		if ( ! class_exists( 'NV_oOS_Comic_Reader_Mime' ) ) {
			$this->markTestSkipped( 'MIME class not loaded.' );
		}

		$tmp = $this->write_temp( "PK\x03\x04" . str_repeat( 'a', 100 ) );
		$this->assertTrue( NV_oOS_Comic_Reader_Mime::has_valid_archive_signature( $tmp ) );
		unlink( $tmp );
	}

	/**
	 * Test RAR signatures are accepted.
	 *
	 * @return void
	 */
	public function test_rar_signature_accepted() {
		if ( ! class_exists( 'NV_oOS_Comic_Reader_Mime' ) ) {
			$this->markTestSkipped( 'MIME class not loaded.' );
		}

		$tmp = $this->write_temp( "Rar!\x1A\x07\x01\x00" . str_repeat( 'b', 100 ) );
		$this->assertTrue( NV_oOS_Comic_Reader_Mime::has_valid_archive_signature( $tmp ) );
		unlink( $tmp );
	}

	/**
	 * Test 7-Zip signatures are accepted.
	 *
	 * @return void
	 */
	public function test_7z_signature_accepted() {
		if ( ! class_exists( 'NV_oOS_Comic_Reader_Mime' ) ) {
			$this->markTestSkipped( 'MIME class not loaded.' );
		}

		$tmp = $this->write_temp( "7z\xBC\xAF\x27\x1C" . str_repeat( 'c', 100 ) );
		$this->assertTrue( NV_oOS_Comic_Reader_Mime::has_valid_archive_signature( $tmp ) );
		unlink( $tmp );
	}

	/**
	 * Test TAR archives (ustar marker at offset 257) are accepted.
	 *
	 * @return void
	 */
	public function test_tar_signature_accepted() {
		if ( ! class_exists( 'NV_oOS_Comic_Reader_Mime' ) ) {
			$this->markTestSkipped( 'MIME class not loaded.' );
		}

		$header = str_repeat( "\0", 257 ) . 'ustar' . str_repeat( "\0", 250 );
		$tmp    = $this->write_temp( $header . str_repeat( 'd', 512 ) );
		$this->assertTrue( NV_oOS_Comic_Reader_Mime::has_valid_archive_signature( $tmp ) );
		unlink( $tmp );
	}

	/**
	 * Test plain text files are rejected.
	 *
	 * @return void
	 */
	public function test_text_file_rejected() {
		if ( ! class_exists( 'NV_oOS_Comic_Reader_Mime' ) ) {
			$this->markTestSkipped( 'MIME class not loaded.' );
		}

		$tmp = $this->write_temp( 'hello, this is plain text and definitely not an archive' );
		$this->assertFalse( NV_oOS_Comic_Reader_Mime::has_valid_archive_signature( $tmp ) );
		unlink( $tmp );
	}

	/**
	 * Test missing files are rejected without warnings.
	 *
	 * @return void
	 */
	public function test_missing_file_rejected() {
		if ( ! class_exists( 'NV_oOS_Comic_Reader_Mime' ) ) {
			$this->markTestSkipped( 'MIME class not loaded.' );
		}

		$this->assertFalse( NV_oOS_Comic_Reader_Mime::has_valid_archive_signature( '/nonexistent/file.cbz' ) );
	}
}
