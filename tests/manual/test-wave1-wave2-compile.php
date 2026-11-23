<?php
spl_autoload_register(
	function ( string $class ): void {
		$prefixes = array(
			'Nvoos\\Core\\'      => __DIR__ . '/../../lib/core/src/',
			'Nvoos\\WordPress\\' => __DIR__ . '/../../lib/wordpress-adapter/src/',
		);
		foreach ( $prefixes as $prefix => $base ) {
			$len = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) === 0 ) {
				$file = $base . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
				if ( file_exists( $file ) ) {
					require $file;
					return; }
			}
		}
	}
);

$ok   = 0;
$fail = 0;
/**
 * Assert that a class or interface exists.
 *
 * @param string $class Class or interface name.
 * @return void
 */
function check( string $class ): void {
	global $ok, $fail;
	if ( class_exists( $class ) || interface_exists( $class ) ) {
		++$ok;
	} else {
		++$fail;
		printf( "  FAIL %s\n", $class ); }
}
/**
 * Assert that a class implements the expected contract.
 *
 * @param string $cls   Class name.
 * @param string $iface Contract interface name.
 * @return void
 */
function verify( string $cls, string $iface ): void {
	global $ok, $fail;
	$obj  = new $cls();
	$full = 'Nvoos\\Core\\Domain\\Contract\\' . $iface;
	$is   = $obj instanceof $full;
	printf( "  %s %s\n", $is ? 'OK' : 'FAIL', ( new ReflectionClass( $cls ) )->getShortName() );
	if ( $is ) {
		++$ok;
	} else {
		++$fail;
	}
}

$contract_ns = 'Nvoos\\Core\\Domain\\Contract\\';
$adapter_ns  = 'Nvoos\\WordPress\\Adapter\\';

echo "=== All Contracts (11 original + 32 new = 43) ===\n";
$contracts = array(
	'SemanticCompressorInterface',
	'DataBudgetTrackerInterface',
	'ErlangCInterface',
	'ErrorTrackingServiceInterface',
	'CostTrackingServiceInterface',
	'ModelCatalogInterface',
	'TokenBudgetServiceInterface',
	'FileValidationServiceInterface',
	'FileUploadServiceInterface',
	'FileOrchestrationInterface',
	'RateLimiterInterface',
	'EmbeddingServiceInterface',
	'MemoryStoreInterface',
	'AssistantServiceInterface',
	'ProfessionRepositoryInterface',
	'CronStatusInterface',
	'PerformanceMonitorInterface',
	'ContextCompressionInterface',
	'TokenUsageInterface',
	'TimeoutDetectionInterface',
	'ToolExecutionInterface',
	'AgentOrchestrationInterface',
	'ChatContinuationInterface',
	'OrchestrationControlInterface',
	'OcrServiceInterface',
	'LanguageDetectionInterface',
	'EmailServiceInterface',
	'ValidationServiceInterface',
	'CodeFormattingInterface',
	'MusicGenerationInterface',
	'FinancialDataInterface',
	'VisionInferenceInterface',
);
foreach ( $contracts as $i ) {
	check( $contract_ns . $i ); }

echo "\n=== Domain Services (2) ===\n";
check( 'Nvoos\\Core\\Domain\\Service\\Budget\\DataBudgetTracker' );
check( 'Nvoos\\Core\\Domain\\Service\\Optimization\\ErlangC' );

echo "\n=== Adapters (29) ===\n";
foreach ( array(
	'SemanticCompressor',
	'DataBudgetTracker',
	'ErrorTrackingService',
	'CostTrackingService',
	'ModelCatalog',
	'TokenBudgetService',
	'FileValidationService',
	'FileUploadService',
	'FileOrchestration',
	'RateLimiter',
	'EmbeddingService',
	'MemoryStore',
	'AssistantService',
	'ProfessionRepository',
	'CronStatus',
	'PerformanceMonitor',
	'ContextCompression',
	'TokenUsage',
	'TimeoutDetection',
	'ToolExecution',
	'AgentOrchestration',
	'ChatContinuation',
	'OrchestrationControl',
	'OcrService',
	'LanguageDetection',
	'EmailService',
	'ValidationService',
	'CodeFormatting',
	'MusicGeneration',
	'FinancialData',
	'VisionInference',
) as $a ) {
	check( $adapter_ns . $a ); }

echo "\n=== Interface Implementation ===\n";
verify( 'Nvoos\\Core\\Domain\\Service\\Budget\\DataBudgetTracker', 'DataBudgetTrackerInterface' );
verify( 'Nvoos\\Core\\Domain\\Service\\Optimization\\ErlangC', 'ErlangCInterface' );
verify( $adapter_ns . 'SemanticCompressor', 'SemanticCompressorInterface' );
verify( $adapter_ns . 'DataBudgetTracker', 'DataBudgetTrackerInterface' );
verify( $adapter_ns . 'ErrorTrackingService', 'ErrorTrackingServiceInterface' );
verify( $adapter_ns . 'CostTrackingService', 'CostTrackingServiceInterface' );
verify( $adapter_ns . 'ModelCatalog', 'ModelCatalogInterface' );
verify( $adapter_ns . 'TokenBudgetService', 'TokenBudgetServiceInterface' );
verify( $adapter_ns . 'FileValidationService', 'FileValidationServiceInterface' );
verify( $adapter_ns . 'FileUploadService', 'FileUploadServiceInterface' );
verify( $adapter_ns . 'FileOrchestration', 'FileOrchestrationInterface' );
verify( $adapter_ns . 'RateLimiter', 'RateLimiterInterface' );
verify( $adapter_ns . 'EmbeddingService', 'EmbeddingServiceInterface' );
verify( $adapter_ns . 'MemoryStore', 'MemoryStoreInterface' );
verify( $adapter_ns . 'AssistantService', 'AssistantServiceInterface' );
verify( $adapter_ns . 'ProfessionRepository', 'ProfessionRepositoryInterface' );
verify( $adapter_ns . 'CronStatus', 'CronStatusInterface' );
verify( $adapter_ns . 'PerformanceMonitor', 'PerformanceMonitorInterface' );
verify( $adapter_ns . 'ContextCompression', 'ContextCompressionInterface' );
verify( $adapter_ns . 'TokenUsage', 'TokenUsageInterface' );
verify( $adapter_ns . 'TimeoutDetection', 'TimeoutDetectionInterface' );
verify( $adapter_ns . 'ToolExecution', 'ToolExecutionInterface' );
verify( $adapter_ns . 'AgentOrchestration', 'AgentOrchestrationInterface' );
verify( $adapter_ns . 'ChatContinuation', 'ChatContinuationInterface' );
verify( $adapter_ns . 'OrchestrationControl', 'OrchestrationControlInterface' );
verify( $adapter_ns . 'OcrService', 'OcrServiceInterface' );
verify( $adapter_ns . 'LanguageDetection', 'LanguageDetectionInterface' );
verify( $adapter_ns . 'EmailService', 'EmailServiceInterface' );
verify( $adapter_ns . 'ValidationService', 'ValidationServiceInterface' );
verify( $adapter_ns . 'CodeFormatting', 'CodeFormattingInterface' );
verify( $adapter_ns . 'MusicGeneration', 'MusicGenerationInterface' );
verify( $adapter_ns . 'FinancialData', 'FinancialDataInterface' );
verify( $adapter_ns . 'VisionInference', 'VisionInferenceInterface' );

printf( "\n%d passed, %d failed\n", $ok, $fail );
exit( $fail > 0 ? 1 : 0 );
