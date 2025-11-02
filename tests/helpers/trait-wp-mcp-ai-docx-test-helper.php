<?php
/**
 * Helper utilities for generating DOCX fixtures in tests.
 */
trait WP_MCP_AI_Docx_Test_Helper {
	/**
	 * Create a DOCX upload using the provided text content.
	 *
	 * @param string $filename Desired file name.
	 * @param string $text     Text content to embed.
	 * @return array
	 */
	protected function create_docx_upload( $filename, $text ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'The ZipArchive extension is required for DOCX tests.' );
		}

		$binary = $this->generate_docx_binary( $text );

		$upload = wp_upload_bits( $filename, null, $binary );
		$this->assertIsArray( $upload );
		$this->assertFalse( $upload['error'] );

		return $upload;
	}

	/**
	 * Generate the binary contents of a DOCX file containing the provided text.
	 *
	 * @param string $text Text content.
	 * @return string
	 */
	protected function generate_docx_binary( $text ) {
		$temp_file = wp_tempnam( 'docx' );

		$zip    = new ZipArchive();
		$opened = $zip->open( $temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $opened ) {
			$this->fail( 'Unable to create DOCX archive for testing.' );
		}

		$zip->addFromString( '[Content_Types].xml', $this->get_docx_content_types_xml() );
		$zip->addEmptyDir( '_rels' );
		$zip->addFromString( '_rels/.rels', $this->get_docx_relationships_xml() );
		$zip->addEmptyDir( 'docProps' );
		$zip->addFromString( 'docProps/core.xml', $this->get_docx_core_properties_xml() );
		$zip->addFromString( 'docProps/app.xml', $this->get_docx_app_properties_xml() );
		$zip->addEmptyDir( 'word' );
		$zip->addEmptyDir( 'word/_rels' );
		$zip->addFromString( 'word/_rels/document.xml.rels', $this->get_docx_document_relationships_xml() );
		$zip->addFromString( 'word/document.xml', $this->get_docx_document_xml( $text ) );

		$zip->close();

		$binary = file_get_contents( $temp_file );
		unlink( $temp_file );

		$this->assertNotFalse( $binary );

		return $binary;
	}

	/**
	 * Get the document XML for the provided text content.
	 *
	 * @param string $text Text content.
	 * @return string
	 */
	protected function get_docx_document_xml( $text ) {
		$paragraphs = preg_split( '/\r\n|\r|\n/', (string) $text );

		if ( empty( $paragraphs ) ) {
			$paragraphs = array( '' );
		}

		$namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
		$xml       = array();

		$xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml[] = '<w:document xmlns:w="' . $namespace . '">';
		$xml[] = '<w:body>';

		foreach ( $paragraphs as $paragraph ) {
			$xml[] = '<w:p><w:r><w:t>' . htmlspecialchars( $paragraph, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</w:t></w:r></w:p>';
		}

		$xml[] = '<w:sectPr/>';
		$xml[] = '</w:body>';
		$xml[] = '</w:document>';

		return implode( '', $xml );
	}

	/**
	 * Get the content types XML.
	 *
	 * @return string
	 */
	protected function get_docx_content_types_xml() {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
	}

	/**
	 * Get the package relationships XML.
	 *
	 * @return string
	 */
	protected function get_docx_relationships_xml() {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
	}

	/**
	 * Get the core properties XML.
	 *
	 * @return string
	 */
	protected function get_docx_core_properties_xml() {
		$created  = gmdate( 'Y-m-d\TH:i:s\Z' );
		$modified = $created;

		return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>Test Document</dc:title>
  <dc:creator>Unit Test</dc:creator>
  <cp:lastModifiedBy>Unit Test</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">$created</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">$modified</dcterms:modified>
</cp:coreProperties>
XML;
	}

	/**
	 * Get the application properties XML.
	 *
	 * @return string
	 */
	protected function get_docx_app_properties_xml() {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>PHPUnit</Application>
</Properties>
XML;
	}

	/**
	 * Get the document relationships XML.
	 *
	 * @return string
	 */
	protected function get_docx_document_relationships_xml() {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>
XML;
	}
}
