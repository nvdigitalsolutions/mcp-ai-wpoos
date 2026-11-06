<?php
/**
 * Knowledge-base fact-sync gate.
 *
 * Asserts the shipped profession knowledge base (the text site-side
 * assistants read via RAG) stays in sync with the plugin header facts:
 * product name, provider list, tool count, and release version. A version
 * bump without a knowledge-base refresh fails this suite on purpose.
 *
 * The canonical files live under includes/knowledge-base/; the mirrors under
 * plugins/nvoos-content-graph-ai-platform/data/knowledge-base/ must stay
 * byte-identical — edit the canonical file, then copy it across.
 *
 * Historical changelog entries inside the knowledge-base files are exempt
 * from the stale-marker assertions (per the repo's "historical per-version
 * blocks stay untouched" rule), so assertions run against the active
 * content only — everything before the changelog marker.
 *
 * @package mcp-ai-wpoos
 */
class Test_Knowledge_Base_Facts_Sync extends WP_UnitTestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Canonical knowledge-base file => mirror file pairs.
	 *
	 * @var array<string, string>
	 */
	private $pairs;

	/**
	 * Changelog marker per knowledge-base file (stale-marker exemption).
	 *
	 * @var array<string, string>
	 */
	private $markers;

	/**
	 * Set up the canonical/mirror file map and changelog markers.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->root    = dirname( __DIR__ );
		$this->pairs   = array(
			$this->root . '/includes/knowledge-base/profession-documents/wpoos_developer.txt'            => $this->root . '/plugins/nvoos-content-graph-ai-platform/data/knowledge-base/profession-documents/wpoos_developer.txt',
			$this->root . '/includes/knowledge-base/profession-playbooks/professions/wpoos_developer.txt' => $this->root . '/plugins/nvoos-content-graph-ai-platform/data/knowledge-base/profession-playbooks/professions/wpoos_developer.txt',
			$this->root . '/includes/knowledge-base/professions/technology.json'                          => $this->root . '/plugins/nvoos-content-graph-ai-platform/data/knowledge-base/professions/technology.json',
		);
		$this->markers = array(
			$this->pairs[ array_key_first( $this->pairs ) ] => '=== CHANGELOG ===',
			array_keys( $this->pairs )[1] => 'Change log',
		);
	}

	/**
	 * Plugin header data from the main plugin file.
	 *
	 * @return array<string, string>
	 */
	private function plugin_data() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		return get_plugin_data( $this->root . '/mcp-ai-wpoos.php', false, false );
	}

	/**
	 * Active (pre-changelog) content of a knowledge-base text file.
	 *
	 * @param string $path Path of the knowledge-base file.
	 * @return string
	 */
	private function active_content( $path ) {
		$this->assertFileExists( $path, 'Knowledge-base file missing: ' . $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local repo files in tests, not remote URLs.
		$content = (string) file_get_contents( $path );
		if ( isset( $this->markers[ $path ] ) ) {
			$parts   = explode( $this->markers[ $path ], $content, 2 );
			$content = $parts[0];
		}
		return $content;
	}

	/**
	 * The canonical wpoos_developer knowledge-base document path.
	 *
	 * @return string
	 */
	private function document_path() {
		return array_key_first( $this->pairs );
	}

	/**
	 * The canonical wpoos_developer playbook path.
	 *
	 * @return string
	 */
	private function playbook_path() {
		return array_keys( $this->pairs )[1];
	}

	/**
	 * The canonical technology.json path.
	 *
	 * @return string
	 */
	private function technology_path() {
		return array_keys( $this->pairs )[2];
	}

	/**
	 * A release version bump must refresh the document and the playbook.
	 */
	public function test_version_stamps_match_the_plugin_header() {
		$version = $this->plugin_data()['Version'];
		$this->assertStringContainsString( 'Spec current as of v' . $version, $this->active_content( $this->document_path() ) );
		$this->assertStringContainsString( 'Spec current as of v' . $version, $this->active_content( $this->playbook_path() ) );
	}

	/**
	 * The document carries the corrected product facts and no stale markers.
	 */
	public function test_document_facts_are_current() {
		$doc = $this->active_content( $this->document_path() );
		$this->assertStringContainsString( 'NV Digital Open Operator System', $doc );
		$this->assertStringContainsString( '18 AI providers', $doc );
		$this->assertStringContainsString( 'DeepSeek', $doc );
		$this->assertStringContainsString( 'live registry authoritative', $doc );
		$this->assertStringContainsString( '~1,735', $doc );
		$this->assertStringNotContainsString( 'WordPress Open Operator', $doc );
		$this->assertStringNotContainsString( '519 built-in', $doc );
		$this->assertStringNotContainsString( 'GPT-4 Turbo', $doc );
		$this->assertStringNotContainsString( 'o1-preview', $doc );
		$this->assertStringNotContainsString( 'Gemini 1.5', $doc );
	}

	/**
	 * Every provider named in the plugin header must appear in the document.
	 */
	public function test_document_lists_every_provider_from_the_plugin_header() {
		$doc  = $this->active_content( $this->document_path() );
		$data = $this->plugin_data();
		$this->assertSame( 1, preg_match( '/\b(\d+) AI providers \(([^)]+)\)/', $data['Description'], $matches ) );
		$providers = array_map( 'trim', explode( ',', $matches[2] ) );
		$this->assertNotEmpty( $providers );
		foreach ( $providers as $provider ) {
			$this->assertStringContainsString( $provider, $doc, 'Provider missing from the knowledge base: ' . $provider );
		}
	}

	/**
	 * The playbook carries the corrected facts and no stale markers.
	 */
	public function test_playbook_facts_are_current() {
		$playbook = $this->active_content( $this->playbook_path() );
		$this->assertStringContainsString( 'NV Digital Open Operator System', $playbook );
		$this->assertStringContainsString( 'DeepSeek', $playbook );
		$this->assertStringContainsString( 'live registry authoritative', $playbook );
		$this->assertStringContainsString( '~1,735', $playbook );
		$this->assertStringNotContainsString( 'WordPress Open Operator', $playbook );
		$this->assertStringNotContainsString( '519', $playbook );
		$this->assertStringNotContainsString( 'GPT-4 Turbo', $playbook );
		$this->assertStringNotContainsString( 'Gemini 1.5', $playbook );
	}

	/**
	 * Technology.json parses and the wpoos_developer entry is current.
	 */
	public function test_technology_json_facts_are_current() {
		$raw  = $this->active_content( $this->technology_path() );
		$json = json_decode( $raw, true );
		$this->assertIsArray( $json, 'technology.json is not valid JSON: ' . json_last_error_msg() );
		$this->assertArrayHasKey( 'professions', $json );
		$this->assertStringNotContainsString( '519', $raw );
		$this->assertStringContainsString( 'NV Digital Open Operator System', $raw );
		$this->assertStringContainsString( 'DeepSeek', $raw );
		$this->assertStringContainsString( 'live registry authoritative', $raw );
		$entry = null;
		foreach ( $json['professions'] as $profession ) {
			if ( 'wpoos_developer' === $profession['slug'] ) {
				$entry = $profession;
				break;
			}
		}
		$this->assertNotNull( $entry, 'wpoos_developer profession entry missing from technology.json' );
		$this->assertStringContainsString( 'NV Digital Open Operator System', $entry['description'] );
	}

	/**
	 * The content-graph mirror files stay byte-identical to the canonical set.
	 */
	public function test_mirror_files_are_byte_identical() {
		foreach ( $this->pairs as $canonical => $mirror ) {
			$this->assertFileExists( $mirror, 'Knowledge-base mirror missing: ' . $mirror );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local repo files in tests, not remote URLs.
			$canonical_content = (string) file_get_contents( $canonical );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local repo files in tests, not remote URLs.
			$mirror_content = (string) file_get_contents( $mirror );
			$this->assertSame(
				$canonical_content,
				$mirror_content,
				'Knowledge-base mirror drifted from the canonical file: ' . $canonical
			);
		}
	}
}
