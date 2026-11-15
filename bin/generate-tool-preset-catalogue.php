<?php

/**
 * Generate the core tool-preset catalogue from the WordPress one.
 *
 * `lib/core` cannot read the WordPress catalogue at runtime — that class needs
 * `ABSPATH`, `__()` and the admin bootstrap. So the tool lists are extracted
 * once here and checked in as data, and a WordPress test asserts the two stay
 * in step (`tests/test-tool-preset-catalogue-parity.php`).
 *
 * Only the data is copied: preset slug, English label, and tool slugs. The
 * translated names and descriptions stay in WordPress, because `lib/core` has
 * no translation domain.
 *
 * Usage:
 *
 *     php bin/generate-tool-preset-catalogue.php [--check]
 *
 * `--check` writes nothing and exits non-zero when the checked-in file is
 * stale, so CI can run it as a drift gate.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

const NV_GENERATOR_TARGET = 'lib/core/src/Domain/Service/Harness/ToolPresetCatalogue.php';
const NV_GENERATOR_SOURCE = 'includes/helpers/class-wp-mcp-ai-tool-presets-helper.php';

$root = \dirname(__DIR__);
$check = \in_array('--check', $argv, true);

// The WordPress helper guards on ABSPATH and touches three globals at load.
// Stubbing them is enough to call get_presets(), which is pure.
\define('ABSPATH', '/tmp/');

if (! \function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

if (! \function_exists('is_admin')) {
    function is_admin()
    {
        return false;
    }
}

if (! \function_exists('apply_filters')) {
    function apply_filters($tag, $value)
    {
        return $value;
    }
}

require $root . '/' . NV_GENERATOR_SOURCE;

$presets = WP_MCP_AI_Tool_Presets_Helper::get_presets();

if (empty($presets) || ! \is_array($presets)) {
    \fwrite(STDERR, "The WordPress catalogue returned nothing. Refusing to write an empty catalogue.\n");
    exit(1);
}

$source = build_catalogue($presets);
$target = $root . '/' . NV_GENERATOR_TARGET;

if ($check) {
    $current = \is_file($target) ? \file_get_contents($target) : '';

    if ($current !== $source) {
        \fwrite(STDERR, NV_GENERATOR_TARGET . " is stale. Run: php bin/generate-tool-preset-catalogue.php\n");
        exit(1);
    }

    echo 'Tool preset catalogue is in step with the WordPress source ('
        . \count($presets) . " presets).\n";
    exit(0);
}

\file_put_contents($target, $source);

$tools = 0;
foreach ($presets as $preset) {
    $tools += \count($preset['tools'] ?? array());
}

\printf("Wrote %s\n  %d presets, %d tool references\n", NV_GENERATOR_TARGET, \count($presets), $tools);

/**
 * Render the catalogue as PHP source.
 *
 * @param array<string, array<string, mixed>> $presets WordPress presets.
 */
function build_catalogue(array $presets): string
{
    $out = array();
    $out[] = '<?php';
    $out[] = '';
    $out[] = '/**';
    $out[] = ' * Tool preset catalogue — which tools belong to which preset families.';
    $out[] = ' *';
    $out[] = ' * The preset weights a harness profile carries are keyed by preset family, so';
    $out[] = ' * the router cannot apply one without knowing which tools that family contains.';
    $out[] = ' * WordPress keeps that mapping in a ~3,100-line translated array it cannot';
    $out[] = ' * share, which is why `tools.preset_weights` were inert on any other runtime.';
    $out[] = ' *';
    $out[] = ' * **Data only.** Slugs and an English label. The translated names and';
    $out[] = ' * descriptions stay in WordPress: this class has no translation domain, and';
    $out[] = ' * the router only needs the membership.';
    $out[] = ' *';
    $out[] = ' * **Generated file.** Do not edit by hand. Regenerate with:';
    $out[] = ' *';
    $out[] = ' *     php bin/generate-tool-preset-catalogue.php';
    $out[] = ' *';
    $out[] = ' * A WordPress test (`tests/test-tool-preset-catalogue-parity.php`) fails when';
    $out[] = ' * this drifts from `WP_MCP_AI_Tool_Presets_Helper::get_presets()`.';
    $out[] = ' *';
    $out[] = ' * @package Nvoos\Core';
    $out[] = ' * @since   1.6.0';
    $out[] = ' * @license MIT';
    $out[] = ' *';
    $out[] = ' * SPDX-License-Identifier: MIT';
    $out[] = ' */';
    $out[] = '';
    $out[] = 'declare(strict_types=1);';
    $out[] = '';
    $out[] = 'namespace Nvoos\Core\Domain\Service\Harness;';
    $out[] = '';
    $out[] = 'final class ToolPresetCatalogue';
    $out[] = '{';
    $out[] = '    /**';
    $out[] = '     * Preset slug to its label and tool slugs.';
    $out[] = '     *';
    $out[] = '     * @var array<string, array{label: string, tools: array<int, string>}>';
    $out[] = '     */';
    $out[] = '    private const PRESETS = array(';

    foreach ($presets as $slug => $preset) {
        $tools = isset($preset['tools']) && \is_array($preset['tools']) ? $preset['tools'] : array();
        $label = isset($preset['name']) ? (string) $preset['name'] : (string) $slug;

        $out[] = '        ' . var_export((string) $slug, true) . ' => array(';
        $out[] = '            ' . "'label' => " . var_export($label, true) . ',';
        $out[] = "            'tools' => array(";

        foreach ($tools as $tool) {
            $out[] = '                ' . var_export((string) $tool, true) . ',';
        }

        $out[] = '            ),';
        $out[] = '        ),';
    }

    $out[] = '    );';
    $out[] = '';
    $out[] = '    /**';
    $out[] = '     * The whole catalogue, shape-compatible with the router.';
    $out[] = '     *';
    $out[] = '     * @return array<string, array{label: string, tools: array<int, string>}>';
    $out[] = '     */';
    $out[] = '    public static function presets(): array';
    $out[] = '    {';
    $out[] = '        return self::PRESETS;';
    $out[] = '    }';
    $out[] = '';
    $out[] = '    /**';
    $out[] = '     * Tool slugs in one preset family.';
    $out[] = '     *';
    $out[] = '     * @return array<int, string>';
    $out[] = '     */';
    $out[] = '    public static function toolsFor(string $preset): array';
    $out[] = '    {';
    $out[] = '        return self::PRESETS[ $preset ][\'tools\'] ?? array();';
    $out[] = '    }';
    $out[] = '';
    $out[] = '    /**';
    $out[] = '     * Tool slug to the families it belongs to.';
    $out[] = '     *';
    $out[] = '     * Built once per process: the router would otherwise rebuild it on every';
    $out[] = '     * ranked turn, and the membership never changes at runtime.';
    $out[] = '     *';
    $out[] = '     * @return array<string, array<int, string>>';
    $out[] = '     */';
    $out[] = '    public static function index(): array';
    $out[] = '    {';
    $out[] = '        static $index = null;';
    $out[] = '';
    $out[] = '        return $index ??= ToolRouterHarness::buildPresetIndex(self::PRESETS);';
    $out[] = '    }';
    $out[] = '';
    $out[] = '    public static function has(string $preset): bool';
    $out[] = '    {';
    $out[] = '        return isset(self::PRESETS[ $preset ]);';
    $out[] = '    }';
    $out[] = '}';
    $out[] = '';

    return \implode("\n", $out);
}
