<?php
/**
 * Asset Inventory System for ISO 27001 Compliance (Control A.5.9).
 *
 * Manages comprehensive inventory of information and other associated assets
 * with classification tagging and automated discovery.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset Inventory class.
 *
 * Implements ISO 27001:2022 Control A.5.9 - Inventory of Information and Other Associated Assets.
 */
class WP_MCP_AI_Asset_Inventory {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Asset_Inventory
	 */
	protected static $instance = null;

	/**
	 * Asset classification levels per ISO 27001.
	 *
	 * @var array
	 */
	const CLASSIFICATION_LEVELS = array(
		'public'       => 'Public',
		'internal'     => 'Internal',
		'confidential' => 'Confidential',
		'restricted'   => 'Restricted',
	);

	/**
	 * Asset types in the plugin.
	 *
	 * @var array
	 */
	const ASSET_TYPES = array(
		'api_key'         => 'API Key/Credential',
		'user_data'       => 'User Data',
		'chat_transcript' => 'Chat Transcript',
		'code'            => 'Source Code',
		'configuration'   => 'Configuration',
		'database'        => 'Database',
		'third_party'     => 'Third-Party Integration',
		'documentation'   => 'Documentation',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Asset_Inventory
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	protected function init_hooks() {
		// Register cron job for periodic asset discovery.
		add_action( 'wp_mcp_ai_asset_discovery', array( $this, 'run_asset_discovery' ) );

		// Schedule weekly asset discovery if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_asset_discovery' ) ) {
			wp_schedule_event( time(), 'weekly', 'wp_mcp_ai_asset_discovery' );
		}
	}

	/**
	 * Discover all assets in the plugin.
	 *
	 * @return array Array of discovered assets.
	 */
	public function discover_assets() {
		$assets = array();

		// Discover code assets.
		$assets = array_merge( $assets, $this->discover_code_assets() );

		// Discover configuration assets.
		$assets = array_merge( $assets, $this->discover_configuration_assets() );

		// Discover API integrations.
		$assets = array_merge( $assets, $this->discover_third_party_integrations() );

		// Discover data storage assets.
		$assets = array_merge( $assets, $this->discover_data_assets() );

		// Discover documentation assets.
		$assets = array_merge( $assets, $this->discover_documentation_assets() );

		// Store the asset inventory.
		$this->store_asset_inventory( $assets );

		return $assets;
	}

	/**
	 * Discover source code assets.
	 *
	 * @return array Array of code assets.
	 */
	protected function discover_code_assets() {
		$assets = array();

		$code_locations = array(
			'includes' => WP_MCP_AI_PATH . 'includes',
			'assets'   => WP_MCP_AI_PATH . 'assets',
			'core'     => WP_MCP_AI_PATH . 'core',
			'shared'   => WP_MCP_AI_PATH . 'shared',
			'addons'   => WP_MCP_AI_PATH . 'addons',
		);

		foreach ( $code_locations as $name => $path ) {
			if ( file_exists( $path ) ) {
				$assets[] = array(
					'id'             => 'code_' . $name,
					'name'           => ucfirst( $name ) . ' Directory',
					'type'           => 'code',
					'classification' => 'confidential',
					'location'       => $path,
					'owner'          => 'Development Team',
					'description'    => 'Plugin source code in ' . $name . ' directory',
					'last_modified'  => $this->get_directory_last_modified( $path ),
				);
			}
		}

		return $assets;
	}

	/**
	 * Discover configuration assets.
	 *
	 * @return array Array of configuration assets.
	 */
	protected function discover_configuration_assets() {
		$assets = array();

		// WordPress options.
		$config_keys = array(
			'wp_mcp_ai_openai_key'     => array( 'API Keys', 'restricted' ),
			'wp_mcp_ai_gemini_key'     => array( 'API Keys', 'restricted' ),
			'wp_mcp_ai_ollama_url'     => array( 'Ollama Configuration', 'confidential' ),
			'wp_mcp_ai_settings'       => array( 'Plugin Settings', 'internal' ),
			'wp_mcp_ai_encryption_key' => array( 'Encryption Key', 'restricted' ),
		);

		foreach ( $config_keys as $key => $info ) {
			if ( get_option( $key ) !== false ) {
				$assets[] = array(
					'id'             => 'config_' . $key,
					'name'           => $info[0],
					'type'           => 'configuration',
					'classification' => $info[1],
					'location'       => 'WordPress Options Table',
					'owner'          => 'Security Team',
					'description'    => 'Configuration stored as WordPress option: ' . $key,
					'last_modified'  => gmdate( 'Y-m-d H:i:s' ),
				);
			}
		}

		return $assets;
	}

	/**
	 * Discover third-party integration assets.
	 *
	 * @return array Array of third-party integration assets.
	 */
	protected function discover_third_party_integrations() {
		$assets = array();

		$integrations = array(
			'openai'      => array( 'OpenAI GPT API', 'confidential' ),
			'gemini'      => array( 'Google Gemini API', 'confidential' ),
			'ollama'      => array( 'Ollama Local AI', 'internal' ),
			'huggingface' => array( 'Hugging Face API', 'internal' ),
			'wordpress'   => array( 'WordPress Core', 'internal' ),
			'jetengine'   => array( 'JetEngine Integration', 'internal' ),
			'woocommerce' => array( 'WooCommerce Integration', 'internal' ),
			'elementor'   => array( 'Elementor Integration', 'internal' ),
		);

		foreach ( $integrations as $key => $info ) {
			$assets[] = array(
				'id'             => 'integration_' . $key,
				'name'           => $info[0],
				'type'           => 'third_party',
				'classification' => $info[1],
				'location'       => 'External API/Service',
				'owner'          => 'Development Team',
				'description'    => 'Third-party integration: ' . $info[0],
				'last_modified'  => gmdate( 'Y-m-d H:i:s' ),
			);
		}

		return $assets;
	}

	/**
	 * Discover data storage assets.
	 *
	 * @return array Array of data assets.
	 */
	protected function discover_data_assets() {
		global $wpdb;

		$assets = array();

		// Custom Post Types.
		$cpts = array(
			'mcp_ai_assistant'  => array( 'AI Assistants', 'confidential' ),
			'mcp_ai_team'       => array( 'AI Teams', 'internal' ),
			'mcp_ai_profession' => array( 'AI Professions', 'internal' ),
		);

		foreach ( $cpts as $cpt => $info ) {
			$count = wp_count_posts( $cpt );
			if ( $count && $count->publish > 0 ) {
				$assets[] = array(
					'id'             => 'data_cpt_' . $cpt,
					'name'           => $info[0] . ' (CPT)',
					'type'           => 'database',
					'classification' => $info[1],
					'location'       => $wpdb->posts,
					'owner'          => 'Data Management Team',
					'description'    => 'Custom Post Type: ' . $cpt . ' (' . $count->publish . ' items)',
					'last_modified'  => gmdate( 'Y-m-d H:i:s' ),
				);
			}
		}

		// User meta data.
		$assets[] = array(
			'id'             => 'data_user_meta',
			'name'           => 'User Metadata',
			'type'           => 'user_data',
			'classification' => 'confidential',
			'location'       => $wpdb->usermeta,
			'owner'          => 'Data Management Team',
			'description'    => 'Plugin-related user metadata',
			'last_modified'  => gmdate( 'Y-m-d H:i:s' ),
		);

		// Chat transcripts (localStorage + optional CCT).
		$assets[] = array(
			'id'             => 'data_chat_transcripts',
			'name'           => 'Chat Transcripts',
			'type'           => 'chat_transcript',
			'classification' => 'confidential',
			'location'       => 'Browser localStorage / JetEngine CCT',
			'owner'          => 'Data Management Team',
			'description'    => 'User chat conversation data',
			'last_modified'  => gmdate( 'Y-m-d H:i:s' ),
		);

		return $assets;
	}

	/**
	 * Discover documentation assets.
	 *
	 * @return array Array of documentation assets.
	 */
	protected function discover_documentation_assets() {
		$assets = array();

		$doc_locations = array(
			'readme'       => array( WP_MCP_AI_PATH . 'README.md', 'public' ),
			'security'     => array( WP_MCP_AI_PATH . 'SECURITY.md', 'public' ),
			'changelog'    => array( WP_MCP_AI_PATH . 'CHANGELOG.md', 'public' ),
			'contributing' => array( WP_MCP_AI_PATH . 'CONTRIBUTING.md', 'public' ),
			'isms'         => array( WP_MCP_AI_PATH . 'docs/compliance/iso27001', 'confidential' ),
		);

		foreach ( $doc_locations as $key => $info ) {
			if ( file_exists( $info[0] ) ) {
				$assets[] = array(
					'id'             => 'doc_' . $key,
					'name'           => ucfirst( $key ) . ' Documentation',
					'type'           => 'documentation',
					'classification' => $info[1],
					'location'       => $info[0],
					'owner'          => 'Documentation Team',
					'description'    => 'Documentation: ' . $key,
					'last_modified'  => file_exists( $info[0] ) ? gmdate( 'Y-m-d H:i:s', filemtime( $info[0] ) ) : gmdate( 'Y-m-d H:i:s' ),
				);
			}
		}

		return $assets;
	}

	/**
	 * Get last modified time for a directory.
	 *
	 * @param string $path Directory path.
	 * @return string Last modified time.
	 */
	protected function get_directory_last_modified( $path ) {
		if ( ! file_exists( $path ) ) {
			return gmdate( 'Y-m-d H:i:s' );
		}

		$latest = filemtime( $path );

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CATCH_GET_CHILD // Handle permission errors.
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$mtime = $file->getMTime();
					if ( $mtime > $latest ) {
						$latest = $mtime;
					}
				}
			}
		} catch ( Exception $e ) {
			// Log error but continue with directory mtime.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'warning',
					'Error reading directory for asset discovery',
					array(
						'path'  => $path,
						'error' => $e->getMessage(),
					)
				);
			}
		}

		return gmdate( 'Y-m-d H:i:s', $latest );
	}

	/**
	 * Store asset inventory in WordPress options.
	 *
	 * @param array $assets Array of assets to store.
	 */
	protected function store_asset_inventory( $assets ) {
		$inventory = array(
			'assets'       => $assets,
			'generated_at' => gmdate( 'Y-m-d H:i:s' ),
			'total_count'  => count( $assets ),
		);

		update_option( 'wp_mcp_ai_asset_inventory', $inventory, false );

		// Log the inventory update.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				'Asset inventory updated',
				array(
					'total_assets' => count( $assets ),
					'timestamp'    => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
	}

	/**
	 * Run automated asset discovery (cron job).
	 */
	public function run_asset_discovery() {
		$this->discover_assets();
	}

	/**
	 * Get current asset inventory.
	 *
	 * @return array|false Asset inventory or false if not found.
	 */
	public function get_asset_inventory() {
		return get_option( 'wp_mcp_ai_asset_inventory', false );
	}

	/**
	 * Get assets by classification level.
	 *
	 * @param string $classification Classification level.
	 * @return array Array of assets with specified classification.
	 */
	public function get_assets_by_classification( $classification ) {
		$inventory = $this->get_asset_inventory();

		if ( ! $inventory || ! isset( $inventory['assets'] ) ) {
			return array();
		}

		return array_filter(
			$inventory['assets'],
			function ( $asset ) use ( $classification ) {
				return isset( $asset['classification'] ) && $asset['classification'] === $classification;
			}
		);
	}

	/**
	 * Get assets by type.
	 *
	 * @param string $type Asset type.
	 * @return array Array of assets with specified type.
	 */
	public function get_assets_by_type( $type ) {
		$inventory = $this->get_asset_inventory();

		if ( ! $inventory || ! isset( $inventory['assets'] ) ) {
			return array();
		}

		return array_filter(
			$inventory['assets'],
			function ( $asset ) use ( $type ) {
				return isset( $asset['type'] ) && $asset['type'] === $type;
			}
		);
	}

	/**
	 * Get asset statistics.
	 *
	 * @return array Asset statistics.
	 */
	public function get_asset_statistics() {
		$inventory = $this->get_asset_inventory();

		if ( ! $inventory || ! isset( $inventory['assets'] ) ) {
			return array(
				'total'             => 0,
				'by_type'           => array(),
				'by_classification' => array(),
			);
		}

		$assets = $inventory['assets'];
		$stats  = array(
			'total'             => count( $assets ),
			'by_type'           => array(),
			'by_classification' => array(),
			'generated_at'      => $inventory['generated_at'],
		);

		// Count by type.
		foreach ( $assets as $asset ) {
			$type = $asset['type'] ?? 'unknown';
			if ( ! isset( $stats['by_type'][ $type ] ) ) {
				$stats['by_type'][ $type ] = 0;
			}
			++$stats['by_type'][ $type ];

			$classification = $asset['classification'] ?? 'unknown';
			if ( ! isset( $stats['by_classification'][ $classification ] ) ) {
				$stats['by_classification'][ $classification ] = 0;
			}
			++$stats['by_classification'][ $classification ];
		}

		return $stats;
	}

	/**
	 * Prevent cloning.
	 */
	protected function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Double-underscore magic method (__wakeup/__clone) required by PHP serialization interface; PSR-2 exception for magic methods.
}
