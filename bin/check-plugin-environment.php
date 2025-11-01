#!/usr/bin/env php
<?php
/**
 * Simple environment health check for WP MCP AI plugin.
 */

$minPhpVersion = '7.4.0';
$currentPhpVersion = PHP_VERSION;

printf("PHP version: %s\n", $currentPhpVersion);
if (version_compare($currentPhpVersion, $minPhpVersion, '>=')) {
    printf("PHP requirement (>= %s): OK\n", $minPhpVersion);
} else {
    fprintf(STDERR, "PHP requirement (>= %s): FAIL\n", $minPhpVersion);
    exit(1);
}

$pluginFile = __DIR__ . '/../wp-mcp-ai.php';
if (!is_file($pluginFile)) {
    fprintf(STDERR, "Plugin file not found at %s\n", $pluginFile);
    exit(1);
}

$pluginContents = file_get_contents($pluginFile);
if (false === $pluginContents) {
    fprintf(STDERR, "Unable to read plugin file: %s\n", $pluginFile);
    exit(1);
}

$pluginVersion = null;

if (preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/mi', $pluginContents, $matches)) {
    $pluginVersion = trim($matches[1]);
} elseif (preg_match("/define\\s*\\(\\s*'WP_MCP_AI_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $pluginContents, $matches)) {
    $pluginVersion = trim($matches[1]);
}

if (!$pluginVersion) {
    fprintf(STDERR, "Unable to detect plugin version from %s\n", $pluginFile);
    exit(1);
}

printf("Detected plugin version: %s\n", $pluginVersion);

$changelogFile = __DIR__ . '/../CHANGELOG.md';
$latestRelease = null;
if (is_file($changelogFile)) {
    $handle = fopen($changelogFile, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^## \[([0-9]+\.[0-9]+\.[0-9]+)\]/', trim($line), $matches)) {
                $latestRelease = $matches[1];
                break;
            }
        }
        fclose($handle);
    }
}

if ($latestRelease) {
    printf("Latest release noted in changelog: %s\n", $latestRelease);
    if (version_compare($pluginVersion, $latestRelease, '==')) {
        echo "Plugin is on the latest recorded version.\n";
    } elseif (version_compare($pluginVersion, $latestRelease, '<')) {
        echo "Plugin is behind the changelog version; consider updating.\n";
    } else {
        echo "Plugin version is ahead of the changelog entry; verify changelog.\n";
    }
} else {
    echo "No release information found in changelog.\n";
}

$errorLogPath = ini_get('error_log');
if (!is_string($errorLogPath) || '' === trim($errorLogPath)) {
    echo "PHP error_log path not configured; unable to inspect logs.\n";
    exit(0);
}

$errorLogPath = trim($errorLogPath);

printf("PHP error_log path: %s\n", $errorLogPath);

if ('syslog' === strtolower($errorLogPath)) {
    echo "Error log is set to syslog; manual inspection required.\n";
    exit(0);
}

if (!is_file($errorLogPath)) {
    echo "Error log file not found on disk; nothing to inspect.\n";
    exit(0);
}

$logLines = @file($errorLogPath);
if (false === $logLines) {
    echo "Unable to read error log file.\n";
    exit(0);
}

$recentLines = array_slice($logLines, -20);
$syntaxIssues = array();
foreach ($recentLines as $line) {
    if (stripos($line, 'syntax') !== false) {
        $syntaxIssues[] = trim($line);
    }
}

if ($syntaxIssues) {
    echo "Recent syntax-related log entries:\n";
    foreach ($syntaxIssues as $issue) {
        echo "  - {$issue}\n";
    }
} else {
    echo "No recent syntax-related entries detected in PHP error log.\n";
}
