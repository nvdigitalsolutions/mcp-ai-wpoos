<?php
/**
 * Temporary test script to debug admin hook values
 * 
 * Add this to the product research page class to see what hook value is passed
 */

// Add this temporarily to the enqueue_assets function:
public static function enqueue_assets( $hook ) {
    // DEBUG: Log the hook value
    error_log( 'Product Research Page - Hook value: ' . $hook );
    error_log( 'Product Research Page - Expected: wp-mcp-ai-ecommerce-toolkit_page_research-product' );
    error_log( 'Product Research Page - Match: ' . ( 'wp-mcp-ai-ecommerce-toolkit_page_' . self::PAGE_SLUG === $hook ? 'YES' : 'NO' ) );
    
    // Continue with original logic
    if ( 'wp-mcp-ai-ecommerce-toolkit_page_' . self::PAGE_SLUG !== $hook ) {
        return;
    }
    // ... rest of function
}
