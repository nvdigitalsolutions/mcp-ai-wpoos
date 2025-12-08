<?php
/**
 * Performance Benchmark: Old vs New Validation Pattern
 *
 * Compares performance of manual validation vs Symfony Validator.
 */

// Simulate WordPress environment
define('ABSPATH', __DIR__ . '/');
define('WP_MCP_AI_PATH', __DIR__ . '/');

require_once __DIR__ . '/vendor/autoload.php';

// Mock WordPress functions for benchmark
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim(strip_tags($str)); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($str) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $str)); }
}
if (!function_exists('absint')) {
    function absint($val) { return abs(intval($val)); }
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public function __construct($code, $message) {
            $this->errors[$code] = [$message];
        }
    }
}

// Load validator service and validation class
require_once __DIR__ . '/includes/validators/class-wp-mcp-ai-validator-service.php';
require_once __DIR__ . '/includes/validators/arguments/class-search-content-arguments.php';

/**
 * Benchmark: Manual Validation (Old Pattern)
 */
function benchmark_manual_validation($arguments, $iterations = 1000) {
    $start = microtime(true);
    
    for ($i = 0; $i < $iterations; $i++) {
        // Manual validation logic (simplified from search_content tool)
        $search_term = isset($arguments['search_term']) ? sanitize_text_field($arguments['search_term']) : '';
        $post_type = isset($arguments['post_type']) ? sanitize_key($arguments['post_type']) : 'any';
        $limit = isset($arguments['limit']) ? absint($arguments['limit']) : 10;
        $limit = $limit > 0 ? min($limit, 50) : 10;
        
        // Validate search_term
        if ('' === $search_term && empty($arguments['taxonomy_filters']) && empty($arguments['meta_filters'])) {
            $error = new WP_Error('wp_mcp_ai_missing_criteria', 'Provide search criteria');
            continue;
        }
        
        // Validate limit
        if ($limit < 1 || $limit > 50) {
            $error = new WP_Error('wp_mcp_ai_invalid_limit', 'Limit must be between 1 and 50');
            continue;
        }
        
        // Validate taxonomy filters
        if (isset($arguments['taxonomy_filters']) && is_array($arguments['taxonomy_filters'])) {
            foreach ($arguments['taxonomy_filters'] as $filter) {
                if (!isset($filter['taxonomy']) || !isset($filter['terms'])) {
                    $error = new WP_Error('invalid_filter', 'Invalid taxonomy filter');
                    continue 2;
                }
                if (!is_array($filter['terms']) || empty($filter['terms'])) {
                    $error = new WP_Error('invalid_terms', 'Invalid terms');
                    continue 2;
                }
            }
        }
        
        // Success
        $validated = true;
    }
    
    $end = microtime(true);
    return $end - $start;
}

/**
 * Benchmark: Symfony Validator (New Pattern)
 */
function benchmark_symfony_validation($arguments, $iterations = 1000) {
    $validator = \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::get_instance();
    
    $start = microtime(true);
    
    for ($i = 0; $i < $iterations; $i++) {
        // Create validation object
        $validated = new \WP_MCP_AI\Tools\Arguments\SearchContentArguments();
        
        // Map arguments
        foreach ($arguments as $key => $value) {
            if (property_exists($validated, $key)) {
                $validated->$key = $value;
            }
        }
        
        // Validate
        $violations = $validator->validate($validated);
        
        if (!$validator->is_valid($violations)) {
            continue;
        }
        
        // Success
        $result = true;
    }
    
    $end = microtime(true);
    return $end - $start;
}

// Test cases
$test_cases = [
    'simple_search' => [
        'search_term' => 'test query',
        'post_type' => 'post',
        'limit' => 10,
    ],
    'complex_search' => [
        'search_term' => 'wordpress development',
        'post_type' => 'post',
        'limit' => 25,
        'taxonomy_filters' => [
            [
                'taxonomy' => 'category',
                'terms' => ['development', 'wordpress'],
                'operator' => 'IN',
            ],
        ],
        'meta_filters' => [
            [
                'key' => 'featured',
                'value' => '1',
                'compare' => '=',
            ],
        ],
    ],
];

echo "Performance Benchmark: Manual Validation vs Symfony Validator\n";
echo "==============================================================\n\n";

$iterations = 1000;

foreach ($test_cases as $name => $arguments) {
    echo "Test Case: $name\n";
    echo str_repeat('-', 60) . "\n";
    
    // Warm up
    benchmark_manual_validation($arguments, 10);
    benchmark_symfony_validation($arguments, 10);
    
    // Run benchmarks
    $manual_time = benchmark_manual_validation($arguments, $iterations);
    $symfony_time = benchmark_symfony_validation($arguments, $iterations);
    
    $manual_per_call = ($manual_time / $iterations) * 1000; // ms
    $symfony_per_call = ($symfony_time / $iterations) * 1000; // ms
    
    printf("Manual Validation:   %.4f seconds (%.4f ms per call)\n", $manual_time, $manual_per_call);
    printf("Symfony Validation:  %.4f seconds (%.4f ms per call)\n", $symfony_time, $symfony_per_call);
    
    $diff = $symfony_time - $manual_time;
    $percent = (($symfony_time - $manual_time) / $manual_time) * 100;
    
    if ($diff > 0) {
        printf("Difference:          +%.4f seconds (+%.1f%% slower)\n", $diff, $percent);
    } else {
        printf("Difference:          %.4f seconds (%.1f%% faster)\n", abs($diff), abs($percent));
    }
    
    echo "\n";
}

echo "Summary\n";
echo str_repeat('=', 60) . "\n";
echo "Iterations per test: $iterations\n";
echo "\nNotes:\n";
echo "- Manual validation is typically faster for simple cases\n";
echo "- Symfony validation provides type safety and better error messages\n";
echo "- Development time savings offset the minimal performance difference\n";
echo "- Validation is a small part of total request time (< 1ms)\n";
echo "- Code maintainability and developer experience are the main benefits\n";
