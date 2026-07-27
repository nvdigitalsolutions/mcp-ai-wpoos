<?php
/**
 * OOS Bridge — Wave 2+ Service Bridges.
 *
 * Loaded by includes/bootstrap/oos-bridge.php to keep the main bridge file
 * manageable. Each function returns a domain contract implementation.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( PHP_VERSION_ID < 80100 ) {
	return;
}

// ─── Model Catalog ────────────────────────────────────────────────────

function wp_mcp_ai_oos_model_catalog(): Nvoos\Core\Domain\Contract\ModelCatalogInterface {
	static $instance = null;
	if ( null !== $instance ) { return $instance; }
	if ( wp_mcp_ai_oos_engine_enabled() ) {
		$instance = new Nvoos\WordPress\Adapter\ModelCatalog();
	} else {
		$instance = new class implements Nvoos\Core\Domain\Contract\ModelCatalogInterface {
			public function getModelsForProvider( string $p, array $a = [] ): array {
				return \class_exists( 'WP_MCP_AI_Model_Service' ) ? ( new \WP_MCP_AI_Model_Service() )->get_models_for_provider( $p, $a ) : [];
			}
			public function getAllModels(): array { return []; }
			public function modelExists( string $id ): bool { return false; }
			public function getModelTokenLimit( string $id ): int { return 0; }
			public function discover( array $p = [] ): array {
				return \class_exists( 'WP_MCP_AI_Model_Discovery_Service' )
					? ( new \WP_MCP_AI_Model_Discovery_Service() )->run( $p, [ 'persist' => false ] )
					: [ 'additions' => [], 'sunsets' => [], 'price_changes' => [], 'errors' => [], 'status' => 'unavailable' ];
			}
		};
	}
	return $instance;
}

// ─── Token Budget ─────────────────────────────────────────────────────

function wp_mcp_ai_oos_token_budget(): Nvoos\Core\Domain\Contract\TokenBudgetServiceInterface {
	static $instance = null;
	if ( null !== $instance ) { return $instance; }
	$instance = wp_mcp_ai_oos_engine_enabled()
		? new Nvoos\WordPress\Adapter\TokenBudgetService()
		: new class implements Nvoos\Core\Domain\Contract\TokenBudgetServiceInterface {
			private $legacy = null;
			public function __construct() {
				if ( \class_exists( 'WP_MCP_AI_Token_Budget_Manager' ) ) {
					$this->legacy = new \WP_MCP_AI_Token_Budget_Manager();
				}
			}
			public function getModelLimit( string $id ): int {
				return $this->legacy && \method_exists( $this->legacy, 'get_model_limit' )
					? (int) $this->legacy->get_model_limit( $id ) : 0;
			}
			public function chunkDocument( string $t, string $id ): array { return [ $t ]; }
			public function remainingBudget( int $u, string $id ): int {
				return \max( 0, $this->getModelLimit( $id ) - $u );
			}
			public function fitsInBudget( int $e, string $id ): bool {
				return $e <= $this->remainingBudget( 0, $id );
			}
		};
	return $instance;
}

// ─── File Validation ──────────────────────────────────────────────────

function wp_mcp_ai_oos_file_validation(): Nvoos\Core\Domain\Contract\FileValidationServiceInterface {
	static $instance = null;
	if ( null !== $instance ) { return $instance; }
	$instance = wp_mcp_ai_oos_engine_enabled()
		? new Nvoos\WordPress\Adapter\FileValidationService()
		: new class implements Nvoos\Core\Domain\Contract\FileValidationServiceInterface {
			public function validateForVectorStore( string $p, string $pu = 'assistants' ): array {
				return \class_exists( 'WP_MCP_AI_File_Preprocessing_Helper' )
					? \WP_MCP_AI_File_Preprocessing_Helper::validate_file_for_vector_store( $p, $pu )
					: [ 'valid' => false, 'warnings' => [ 'Unavailable' ], 'recommendations' => [], 'file_info' => [] ];
			}
			public function isFormatSupported( string $e, string $p = 'assistants' ): bool { return true; }
			public function getSupportedFormats( string $p = 'assistants' ): array { return [ 'txt', 'pdf', 'json', 'md' ]; }
		};
	return $instance;
}

// ─── File Upload ──────────────────────────────────────────────────────

function wp_mcp_ai_oos_file_upload(): Nvoos\Core\Domain\Contract\FileUploadServiceInterface {
	static $instance = null;
	if ( null !== $instance ) { return $instance; }
	$instance = wp_mcp_ai_oos_engine_enabled()
		? new Nvoos\WordPress\Adapter\FileUploadService()
		: new class implements Nvoos\Core\Domain\Contract\FileUploadServiceInterface {
			public function validate( array $file, array $context = [] ): array {
				$errors = [];
				if ( ( $file['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) {
					$errors[] = 'Upload error code ' . ( $file['error'] ?? 'unknown' );
				}
				if ( ( $file['size'] ?? 0 ) > self::DEFAULT_MAX_FILE_SIZE ) {
					$errors[] = 'File too large';
				}
				return [ 'valid' => empty( $errors ), 'errors' => $errors, 'file_info' => [
					'name' => $file['name'] ?? '', 'size' => $file['size'] ?? 0, 'type' => $file['type'] ?? ''
				] ];
			}
			public function upload( array $file, array $context = [] ): array {
				$v = $this->validate( $file, $context );
				return $v['valid'] ? [ 'success' => true, 'path' => $file['tmp_name'] ?? '' ]
					: [ 'success' => false, 'error' => implode( '; ', $v['errors'] ) ];
			}
			public function prepareDocument( string $fp ): array {
				return file_exists( $fp ) ? [ 'content' => (string) file_get_contents( $fp ), 'metadata' => [
					'path' => $fp, 'size' => filesize( $fp ) ?: 0
				] ] : [ 'content' => '', 'metadata' => [ 'error' => 'Not found' ] ];
			}
			public function isMimeTypeAllowed( string $m ): bool { return in_array( $m, self::DEFAULT_ALLOWED_TYPES, true ); }
		};
	return $instance;
}

// ─── File Orchestration ───────────────────────────────────────────────

function wp_mcp_ai_oos_file_orchestration( string $provider = 'openai' ): Nvoos\Core\Domain\Contract\FileOrchestrationInterface {
	$instance = wp_mcp_ai_oos_engine_enabled()
		? new Nvoos\WordPress\Adapter\FileOrchestration( $provider )
		: new class( $provider ) implements Nvoos\Core\Domain\Contract\FileOrchestrationInterface {
			private string $p;
			public function __construct( string $p ) { $this->p = $p; }
			public function uploadFile( string $fp, string $mt, array $o = [] ): array {
				return [ 'success' => false, 'error' => 'File orchestration requires OOS engine' ];
			}
			public function pollStatus( string $id ): array { return [ 'status' => 'unknown' ]; }
			public function deleteFile( string $id ): array { return [ 'success' => false ]; }
			public function setMaxPollingAttempts( int $a ): void {}
			public function setPollingDelay( int $s ): void {}
		};
	return $instance;
}

// ─── Rate Limiter ─────────────────────────────────────────────────────

function wp_mcp_ai_oos_rate_limiter(): Nvoos\Core\Domain\Contract\RateLimiterInterface {
	static $instance = null;
	if ( null !== $instance ) { return $instance; }
	$instance = wp_mcp_ai_oos_engine_enabled()
		? new Nvoos\WordPress\Adapter\RateLimiter()
		: new class implements Nvoos\Core\Domain\Contract\RateLimiterInterface {
			public function isAllowed( string $k, int $m, int $w ): bool { return true; }
			public function record( string $k, int $w = 60 ): void {}
			public function remaining( string $k, int $m, int $w ): int { return $m; }
			public function reset( string $k ): void {}
		};
	return $instance;
}
