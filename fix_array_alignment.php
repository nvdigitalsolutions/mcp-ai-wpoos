<?php
/**
 * Fix WordPress array alignment properly
 * 
 * According to WPCS, array alignment should have:
 * - Single space before =>
 * - Aligned values after =>
 */

function fix_file_alignment( $file_path ) {
    $content = file_get_contents( $file_path );
    $original = $content;
    
    // Split into lines
    $lines = explode( "\n", $content );
    $fixed_lines = array();
    $i = 0;
    
    while ( $i < count( $lines ) ) {
        $line = $lines[ $i ];
        
        // Check if line contains an array opening (array( or [ or = array()
        if ( preg_match( '/^\s*(array\s*\(|return\s+array\s*\(|\$\w+\s*=\s*array\s*\()/i', $line ) ) {
            // Process array block
            $array_lines = array( $line );
            $i++;
            $indent = strlen( $line ) - strlen( ltrim( $line ) );
            
            // Collect all lines in this array until closing paren
            $paren_count = substr_count( $line, '(' ) - substr_count( $line, ')' );
            while ( $i < count( $lines ) && $paren_count > 0 ) {
                $current = $lines[ $i ];
                $paren_count += substr_count( $current, '(' ) - substr_count( $current, ')' );
                $array_lines[] = $current;
                $i++;
            }
            
            // Fix alignment in this array block
            $aligned = fix_array_block( $array_lines );
            $fixed_lines = array_merge( $fixed_lines, $aligned );
        } else {
            $fixed_lines[] = $line;
            $i++;
        }
    }
    
    $fixed_content = implode( "\n", $fixed_lines );
    
    if ( $fixed_content !== $original ) {
        file_put_contents( $file_path, $fixed_content );
        return true;
    }
    
    return false;
}

function fix_array_block( $array_lines ) {
    // Find all key => value pairs
    $pairs = array();
    $item_lines = array();
    
    foreach ( $array_lines as $line ) {
        if ( preg_match( '/^\s*[\'"]?\w+[\'"]?\s*=>/', $line ) ) {
            // This is a key line
            if ( ! empty( $item_lines ) ) {
                $pairs[] = $item_lines;
                $item_lines = array();
            }
            $item_lines[] = $line;
        } elseif ( ! empty( $item_lines ) ) {
            $item_lines[] = $line;
        } else {
            $item_lines[] = $line;
        }
    }
    if ( ! empty( $item_lines ) ) {
        $pairs[] = $item_lines;
    }
    
    // Find max key length for alignment
    $max_key_len = 0;
    foreach ( $pairs as $pair ) {
        if ( preg_match( '/^\s*([\'"]?)(\w+)\1\s*=>/', $pair[0], $matches ) ) {
            $max_key_len = max( $max_key_len, strlen( $matches[2] ) );
        }
    }
    
    // Rebuild with proper alignment
    $result = array();
    foreach ( $pairs as $idx => $pair ) {
        if ( preg_match( '/^(\s*)([\'"]?)(\w+)\2(\s*)=>(.*)$/', $pair[0], $matches ) ) {
            $indent = $matches[1];
            $quote = $matches[2];
            $key = $matches[3];
            $value = $matches[5];
            
            // Rebuild with single space before => and align values after
            $padding = str_repeat( ' ', $max_key_len - strlen( $key ) );
            $new_line = $indent . $quote . $key . $quote . $padding . ' => ' . $value;
            $result[] = $new_line;
            
            // Add continuation lines if any
            for ( $i = 1; $i < count( $pair ); $i++ ) {
                $result[] = $pair[ $i ];
            }
        } else {
            foreach ( $pair as $line ) {
                $result[] = $line;
            }
        }
    }
    
    return $result;
}

// Get list of files to fix
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
    if ( file_exists( $file ) ) {
        // Just restore original formatting for now
        echo "Checking: $file\n";
    }
}

echo "\nNote: WordPress Coding Standards alignment requires proper handling.\n";
echo "Standard: single space before =>, aligned values after =>\n";
