<?php
/**
 * Fix WordPress coding standards for array alignment
 * WordPress standard: single space before =>, values can be aligned after
 */

function fix_array_alignment_wpcs( $file_path ) {
    if ( ! file_exists( $file_path ) ) {
        return false;
    }
    
    $content = file_get_contents( $file_path );
    
    // Fix multiple spaces before => to single space
    // Pattern: any key followed by multiple spaces before =>
    // This should be: 'key' => value (single space before =>)
    $pattern = '/([\'"]?\w+[\'"]?)\s{2,}\s*=>/';
    $replacement = '$1 =>';
    $content = preg_replace( $pattern, $replacement, $content );
    
    // Also handle the unquoted keys case
    $pattern = '/(\w+)\s{2,}\s*=>/';
    $replacement = '$1 =>';
    $content = preg_replace( $pattern, $replacement, $content );
    
    file_put_contents( $file_path, $content );
    return true;
}

$files = array(
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-calculate-derivative.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-calculate-integral.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-graph-function.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-matrix-operations.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-simplify-expression.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-solve-equation.php',
    'includes/tools/trait-wp-mcp-ai-tool-audio-response.php',
    'includes/tools/trait-wp-mcp-ai-tool-chart-accessibility.php',
    'includes/tools/trait-wp-mcp-ai-tool-document-response.php',
    'includes/tools/trait-wp-mcp-ai-tool-email-response.php',
    'includes/tools/trait-wp-mcp-ai-tool-image-response.php',
    'includes/tools/trait-wp-mcp-ai-tool-math-response.php',
    'includes/tools/trait-wp-mcp-ai-tool-video-response.php',
    'tests/test-image-response-trait.php',
    'addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-convert-sketch-to-floor-plan.php',
    'addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-create-floor-plan-variations.php',
    'addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-create-walkthrough-animation.php',
    'addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-generate-3d-model.php',
    'addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-generate-construction-drawings.php',
    'addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-generate-detail-drawings.php',
    'addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-generate-floor-plan.php',
    'addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-render-architectural-view.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-export-calendar-ics.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-generate-architectural-drawing.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-generate-email-template.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-generate-jukebox-music.php',
    'addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-excel-document.php',
    'addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php',
    'addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php',
    'addons/pro/includes/tools/class-wp-mcp-ai-tool-render-math-equation.php',
    'includes/tools/class-wp-mcp-ai-tool-create-chart.php',
    'includes/tools/class-wp-mcp-ai-tool-create-image-variation.php',
    'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php',
    'includes/tools/class-wp-mcp-ai-tool-edit-openai-image.php',
    'includes/tools/class-wp-mcp-ai-tool-generate-cloudflareai-image.php',
    'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php',
    'includes/tools/class-wp-mcp-ai-tool-generate-music.php',
    'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php',
    'includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php',
    'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php',
    'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php',
    'includes/tools/class-wp-mcp-ai-tool-image-base.php',
);

$fixed_count = 0;
foreach ( $files as $file ) {
    if ( fix_array_alignment_wpcs( $file ) ) {
        $fixed_count++;
        echo "Fixed: $file\n";
    }
}

echo "\nTotal files fixed: $fixed_count\n";
