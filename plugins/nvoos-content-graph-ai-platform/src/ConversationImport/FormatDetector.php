<?php
/**
 * Conversation export format detection (Wave E4, sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Format_Detector`: byte-identical size
 * cap (128 MB, `wp_mcp_ai_conversation_import_max_file_bytes` filter),
 * file/extension/readability guards, the 64-depth JSON decode with
 * `JSON_THROW_ON_ERROR`, the line-by-line JSONL decode with its
 * `wp_mcp_ai_import_jsonl_decode_failed` envelope, the adapter routing
 * with the `wp_mcp_ai_import_unknown_format` envelope, and the
 * `wp_mcp_ai_conversation_import_adapters` filter.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - The default adapter map resolves per install mode via
 *    `default_adapters()` (base `WP_MCP_AI_Conversation_Import_Adapter_*`
 *    classes monolith, this package's adapters standalone) and adapter
 *    instances are accepted through the per-mode `is_adapter_instance()`
 *    seam — a fixed interface hint cannot accept monolith base adapters.
 *  - `Exception` and `WP_Error` are fully qualified.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * Detects which import adapter can parse a given payload file.
 *
 * @since 2.1.0
 */
class FormatDetector {

	const DEFAULT_MAX_FILE_BYTES = 134217728; // 128 MB.

	/**
	 * Registry of available adapters, keyed by platform slug.
	 *
	 * @var AdapterInterface[]
	 */
	protected $adapters;

	/**
	 * Constructor.
	 *
	 * @param AdapterInterface[] $adapters Optional adapter override (tests).
	 */
	public function __construct( array $adapters = array() ) {
		$this->adapters = $adapters;

		if ( empty( $this->adapters ) ) {
			$adapters = $this->default_adapters();

			/**
			 * Filter the registered conversation import adapters.
			 *
			 * Keys are platform slugs; values are adapter class names
			 * implementing {@see AdapterInterface}.
			 *
			 * @since 2.1.0
			 *
			 * @param array $adapters Platform slug => adapter class name map.
			 */
			$adapters = apply_filters( 'wp_mcp_ai_conversation_import_adapters', $adapters );

			foreach ( $adapters as $platform => $class_name ) {
				if ( is_string( $class_name ) && class_exists( $class_name ) ) {
					$instance = new $class_name();
					if ( $this->is_adapter_instance( $instance ) ) {
						$this->adapters[ $platform ] = $instance;
					}
				}
			}
		}
	}

	/**
	 * Resolve the built-in adapter class map per install mode.
	 *
	 * Monolith installs use the base plugin's adapter classes
	 * (byte-identical); standalone installs use this package's adapters.
	 *
	 * @return array<string,string> Platform slug => adapter class name.
	 */
	protected function default_adapters(): array {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return array(
				'chatgpt'      => 'WP_MCP_AI_Conversation_Import_Adapter_Chatgpt',
				'gemini'       => 'WP_MCP_AI_Conversation_Import_Adapter_Gemini',
				'claude'       => 'WP_MCP_AI_Conversation_Import_Adapter_Claude',
				'sharegpt'     => 'WP_MCP_AI_Conversation_Import_Adapter_Sharegpt',
				'openai_jsonl' => 'WP_MCP_AI_Conversation_Import_Adapter_Openai_Jsonl',
			);
		}

		return array(
			'chatgpt'      => AdapterChatgpt::class,
			'gemini'       => AdapterGemini::class,
			'claude'       => AdapterClaude::class,
			'sharegpt'     => AdapterSharegpt::class,
			'openai_jsonl' => AdapterOpenaiJsonl::class,
		);
	}

	/**
	 * Whether an object satisfies the adapter interface for this install
	 * mode.
	 *
	 * Monolith installs accept the base plugin's adapter interface
	 * (byte-identical); standalone installs accept this package's
	 * interface. The discriminator is `defined( 'WP_MCP_AI_PATH' )` —
	 * never bare `instanceof` — because the two modes have different
	 * interface classes.
	 *
	 * @param object $candidate Candidate adapter instance.
	 * @return bool
	 */
	protected function is_adapter_instance( $candidate ): bool {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return $candidate instanceof \WP_MCP_AI_Conversation_Import_Adapter_Interface;
		}

		return $candidate instanceof AdapterInterface;
	}

	/**
	 * List registered adapters.
	 *
	 * @return AdapterInterface[]
	 */
	public function get_adapters() {
		return $this->adapters;
	}

	/**
	 * Decode a JSON payload file into a PHP array.
	 *
	 * Enforces a size cap and a decode depth limit. Returns an associative
	 * array (or list) mirroring `json_decode( ..., true )`.
	 *
	 * @param string $file_path Absolute path to the payload file.
	 * @return mixed|\WP_Error Decoded structure, or a WP_Error.
	 */
	public function decode_file( $file_path ) {
		$file_path = wp_normalize_path( (string) $file_path );

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_file_unreadable',
				__( 'The import payload file is not readable.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$extension = strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'json', 'jsonl' ), true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_not_json',
				__( 'The import payload file is not a JSON file.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$max_bytes = $this->get_max_file_bytes();
		$size      = (int) filesize( $file_path );
		if ( $size > $max_bytes ) {
			return new \WP_Error(
				'wp_mcp_ai_import_file_too_large',
				/* translators: %s: human-readable size limit. */
				sprintf( __( 'The import file exceeds the %s size limit.', 'nvoos-content-graph-ai-platform' ), size_format( $max_bytes ) )
			);
		}

		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local payload read; wp_filesystem is overkill for a size-capped file.
		if ( false === $contents ) {
			return new \WP_Error(
				'wp_mcp_ai_import_file_read_failed',
				__( 'Could not read the import payload file.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$contents = trim( $contents );
		if ( '' === $contents ) {
			return new \WP_Error(
				'wp_mcp_ai_import_file_empty',
				__( 'The import payload file is empty.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// JSONL exports (Claude, OpenAI fine-tuning) decode line-by-line into
		// a list of per-line structures — the same shape adapters already
		// consume for JSON documents.
		if ( 'jsonl' === $extension ) {
			return $this->decode_jsonl( $contents );
		}

		try {
			$decoded = json_decode( $contents, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'wp_mcp_ai_import_json_decode_failed',
				/* translators: %s: parser error message. */
				sprintf( __( 'The import payload is not valid JSON: %s', 'nvoos-content-graph-ai-platform' ), $e->getMessage() )
			);
		}

		return $decoded;
	}

	/**
	 * Decode a JSONL payload line by line.
	 *
	 * @param string $contents Raw file contents.
	 * @return array|\WP_Error List of per-line decoded structures, or a WP_Error.
	 */
	protected function decode_jsonl( $contents ) {
		$decoded = array();
		$lines   = preg_split( '/\r\n|\n|\r/', $contents );

		foreach ( $lines as $index => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			try {
				$item = json_decode( $line, true, 64, JSON_THROW_ON_ERROR );
			} catch ( \Exception $e ) {
				return new \WP_Error(
					'wp_mcp_ai_import_jsonl_decode_failed',
					/* translators: 1: line number, 2: parser error message. */
					sprintf( __( 'Invalid JSON on line %1$d: %2$s', 'nvoos-content-graph-ai-platform' ), $index + 1, $e->getMessage() )
				);
			}

			if ( null !== $item ) {
				$decoded[] = $item;
			}
		}

		return $decoded;
	}

	/**
	 * Detect the adapter that can parse the given payload file.
	 *
	 * @param string $file_path Absolute path to the payload file.
	 * @return array|\WP_Error {
	 *     Detection result.
	 *
	 *     @type string $platform Platform slug.
	 *     @type object $adapter  Adapter instance.
	 * }
	 */
	public function detect( $file_path ) {
		$decoded = $this->decode_file( $file_path );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		foreach ( $this->adapters as $platform => $adapter ) {
			if ( $adapter->supports_decoded( $decoded ) ) {
				return array(
					'platform' => sanitize_key( (string) $platform ),
					'adapter'  => $adapter,
				);
			}
		}

		return new \WP_Error(
			'wp_mcp_ai_import_unknown_format',
			__( 'The file does not match any supported conversation export format.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Retrieve the configured max import file size in bytes.
	 *
	 * @return int
	 */
	public function get_max_file_bytes() {
		/**
		 * Filter the max conversation import payload size in bytes.
		 *
		 * @since 2.1.0
		 *
		 * @param int $max_bytes Default 128 MB.
		 */
		return (int) apply_filters( 'wp_mcp_ai_conversation_import_max_file_bytes', self::DEFAULT_MAX_FILE_BYTES );
	}
}
