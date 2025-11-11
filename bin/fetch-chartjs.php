#!/usr/bin/env php
<?php
/**
 * Fetch the Chart.js distribution for the plugin.
 */

declare(strict_types=1);

$chartVersion = '4.4.1';
$downloadUrl  = sprintf('https://cdn.jsdelivr.net/npm/chart.js@%s/dist/chart.umd.min.js', $chartVersion);
$projectRoot  = dirname(__DIR__);
$targetPath   = $projectRoot . '/assets/js/vendor/chart.min.js';
$targetDir    = dirname($targetPath);

if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    fwrite(STDERR, sprintf("[chartjs] Failed to create directory: %s\n", $targetDir));
    exit(1);
}

if (file_exists($targetPath)) {
    $existingContent = file_get_contents($targetPath) ?: '';
    if (false !== strpos($existingContent, 'Chart.js v' . $chartVersion)) {
        fwrite(STDOUT, sprintf("[chartjs] Chart.js %s is already installed.\n", $chartVersion));
        exit(0);
    }
}

$chartContents = downloadChartJs($downloadUrl);

if ('' === $chartContents) {
    fwrite(STDERR, sprintf("[chartjs] Failed to download Chart.js from %s\n", $downloadUrl));
    exit(1);
}

if (false === file_put_contents($targetPath, $chartContents)) {
    fwrite(STDERR, sprintf("[chartjs] Failed to write Chart.js to %s\n", $targetPath));
    exit(1);
}

fwrite(STDOUT, sprintf("[chartjs] Downloaded Chart.js %s to %s\n", $chartVersion, $targetPath));

/**
 * Download Chart.js via cURL or stream wrapper.
 */
function downloadChartJs(string $url): string
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if (false === $curl) {
            return '';
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'wp-mcp-ai-composer-script',
        ]);

        $data = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if (false !== $data && '' !== $data) {
            return (string) $data;
        }

        if ($error) {
            fwrite(STDERR, sprintf("[chartjs] cURL error: %s\n", $error));
        }
    }

    $contextOptions = [
        'http' => [
            'timeout' => 60,
            'header'  => "User-Agent: wp-mcp-ai-composer-script\r\n",
        ],
    ];

    $context = stream_context_create($contextOptions);

    $contents = @file_get_contents($url, false, $context);

    return false === $contents ? '' : (string) $contents;
}

