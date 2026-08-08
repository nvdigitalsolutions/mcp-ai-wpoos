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
function check( string $class ): void {
	global $ok, $fail;
	if ( class_exists( $class ) || interface_exists( $class ) ) {
		++$ok;
	} else {
		++$fail;
		printf( "  FAIL %s\n", $class ); }
}
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

$C = 'Nvoos\\Core\\Domain\\Contract\\';
$W = 'Nvoos\\WordPress\\Adapter\\';

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
	check( $C . $i ); }

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
	check( $W . $a ); }

echo "\n=== Interface Implementation ===\n";
verify( 'Nvoos\\Core\\Domain\\Service\\Budget\\DataBudgetTracker', 'DataBudgetTrackerInterface' );
verify( 'Nvoos\\Core\\Domain\\Service\\Optimization\\ErlangC', 'ErlangCInterface' );
verify( $W . 'SemanticCompressor', 'SemanticCompressorInterface' );
verify( $W . 'DataBudgetTracker', 'DataBudgetTrackerInterface' );
verify( $W . 'ErrorTrackingService', 'ErrorTrackingServiceInterface' );
verify( $W . 'CostTrackingService', 'CostTrackingServiceInterface' );
verify( $W . 'ModelCatalog', 'ModelCatalogInterface' );
verify( $W . 'TokenBudgetService', 'TokenBudgetServiceInterface' );
verify( $W . 'FileValidationService', 'FileValidationServiceInterface' );
verify( $W . 'FileUploadService', 'FileUploadServiceInterface' );
verify( $W . 'FileOrchestration', 'FileOrchestrationInterface' );
verify( $W . 'RateLimiter', 'RateLimiterInterface' );
verify( $W . 'EmbeddingService', 'EmbeddingServiceInterface' );
verify( $W . 'MemoryStore', 'MemoryStoreInterface' );
verify( $W . 'AssistantService', 'AssistantServiceInterface' );
verify( $W . 'ProfessionRepository', 'ProfessionRepositoryInterface' );
verify( $W . 'CronStatus', 'CronStatusInterface' );
verify( $W . 'PerformanceMonitor', 'PerformanceMonitorInterface' );
verify( $W . 'ContextCompression', 'ContextCompressionInterface' );
verify( $W . 'TokenUsage', 'TokenUsageInterface' );
verify( $W . 'TimeoutDetection', 'TimeoutDetectionInterface' );
verify( $W . 'ToolExecution', 'ToolExecutionInterface' );
verify( $W . 'AgentOrchestration', 'AgentOrchestrationInterface' );
verify( $W . 'ChatContinuation', 'ChatContinuationInterface' );
verify( $W . 'OrchestrationControl', 'OrchestrationControlInterface' );
verify( $W . 'OcrService', 'OcrServiceInterface' );
verify( $W . 'LanguageDetection', 'LanguageDetectionInterface' );
verify( $W . 'EmailService', 'EmailServiceInterface' );
verify( $W . 'ValidationService', 'ValidationServiceInterface' );
verify( $W . 'CodeFormatting', 'CodeFormattingInterface' );
verify( $W . 'MusicGeneration', 'MusicGenerationInterface' );
verify( $W . 'FinancialData', 'FinancialDataInterface' );
verify( $W . 'VisionInference', 'VisionInferenceInterface' );

printf( "\n%d passed, %d failed\n", $ok, $fail );
exit( $fail > 0 ? 1 : 0 );
