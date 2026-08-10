<?php
/**
 * Test the sidecar cascade from WordPress.
 * Run: wsl docker compose exec -T wordpress php /var/www/html/wp-content/plugins/mcp-ai-wpoos/addons/media-worker/bin/test-wordpress.php
 */
require_once '/var/www/html/wp-load.php';

$tests = [
    'Prettier' => function() {
        $s = new WP_MCP_AI_Prettier_Service();
        return $s->format_code("const x=1;");
    },
    'MJML' => function() {
        $s = new WP_MCP_AI_MJML_Service();
        return $s->compile('<mjml><mj-body><mj-section><mj-column><mj-text>Hi</mj-text></mj-column></mj-section></mj-body></mjml>');
    },
    'Language' => function() {
        $s = new WP_MCP_AI_Language_Detection_Service();
        return $s->detect_language('Bonjour le monde');
    },
];

$url = defined('WP_MEDIA_WORKER_URL') ? WP_MEDIA_WORKER_URL : 'NOT SET';
echo "WP_MEDIA_WORKER_URL: $url\n\n";

foreach ($tests as $name => $fn) {
    echo "$name: ";
    $r = $fn();
    if (is_wp_error($r)) {
        echo "FAIL — {$r->get_error_message()}\n";
    } elseif (is_string($r)) {
        echo "OK — " . substr(str_replace("\n", " ", $r), 0, 60) . "\n";
    } elseif (is_array($r)) {
        echo "OK — " . json_encode(array_keys($r)) . "\n";
    } else {
        echo "OK\n";
    }
}
echo "\nDone.\n";
