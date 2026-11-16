<?php

namespace App\Support;

use RuntimeException;

/**
 * Generates the Filament admin palette from the ADS DTCG tokens
 * (Proposal 149 T-0.6).
 *
 * Why the admin reads ADS directly and not the NDS export
 * -------------------------------------------------------
 * Q-3 asked whether the NDS document carries the semantic layer Filament
 * needs. It does not, and the exporter says so itself
 * (`examples/nvoos-astro-velocity/mcp/tools/ops.ts` → `ADS_UNMAPPED`): NDS has
 * no on-accent text token, no error/danger token and no composite shadow
 * token, so `accent-ink`, `danger` and `focus-ring` are reported as unmapped.
 * All three exist in ADS. Round-tripping through NDS would therefore *lose*
 * exactly the tokens an admin panel cannot do without — a danger colour and a
 * focus ring. The admin is a direct ADS consumer, per §10 of the proposal.
 *
 * Resolution order for a semantic token: the active theme's light mode wins,
 * then the semantic layer, with `{core.*}` references resolved through core.
 *
 * Determinism: keys are emitted in the source order of `semantic.json`, and
 * values are normalised to lowercase hex, so regenerating from unchanged
 * tokens cannot produce a spurious diff. `AdminThemeDriftTest` depends on it.
 */
final class AdminThemeGenerator
{
    /**
     * Semantic colour tokens the admin consumes, in emission order.
     *
     * This is a curated subset, not the whole semantic layer: `radius`, `font`
     * and `space` are not Filament palette inputs, and emitting them here would
     * create a second definition of values the CSS pipeline already owns.
     *
     * @var list<string>
     */
    public const COLORS = array(
        'ink',
        'muted',
        'surface',
        'surface-alt',
        'line',
        'line-strong',
        'accent',
        'accent-ink',
        'danger',
        'success',
        'warning',
        'info',
    );

    /**
     * Filament palette key => ADS semantic token.
     *
     * `gray` is seeded from `muted` rather than Filament's own grey: the ADS
     * palette is warm-neutral, and mixing a cold grey scale into a warm surface
     * set is visible in every border and zebra row.
     *
     * @var array<string, string>
     */
    public const FILAMENT_MAP = array(
        'primary' => 'accent',
        'danger' => 'danger',
        'gray' => 'muted',
        'success' => 'success',
        'warning' => 'warning',
        'info' => 'info',
    );

    /**
     * Filament palette keys the generator checks against the token source.
     *
     * This is the *vocabulary Filament wants*, not a list of known gaps. If a
     * key is absent from the DTCG the generator reports it and omits it, so a
     * token that is ever removed from ADS surfaces at build time instead of
     * silently reverting to Filament's default colour.
     *
     * History: `success`, `warning` and `info` were absent until 2026-11-16
     * (Proposal 149 Q-4), when they were added to `core.json`/`semantic.json`
     * — one definition, inherited by all four consumers.
     *
     * @var list<string>
     */
    public const FILAMENT_KEYS = array('primary', 'danger', 'gray', 'success', 'warning', 'info');

    /** Committed artefact, relative to the application base path. */
    public const TARGET = 'app/Support/AdminTheme.php';

    /** Guards a malformed reference cycle in the token source. */
    private const MAX_REF_DEPTH = 8;

    public function __construct(private readonly string $tokensPath)
    {
    }

    public function tokensPath(): string
    {
        return $this->tokensPath;
    }

    /**
     * Resolve the tokens the admin needs.
     *
     * @return array{colors: array<string, string>, focusRing: string}
     */
    public function resolve(): array
    {
        // `core.json` keys are referenced as `{core.palette.ink}`, so the
        // flattened map carries the `core.` prefix.
        $core = $this->flatten($this->load('core.json'), 'core');
        $semantic = $this->flatten($this->load('semantic.json'));

        $theme = $this->load('themes/default.json');
        $light = is_array($theme['light'] ?? null) ? $theme['light'] : array();

        $colors = array();

        foreach (self::COLORS as $key) {
            $raw = $light[$key] ?? $semantic[$key] ?? null;

            if (null === $raw) {
                continue;
            }

            $value = $this->resolveRefs($raw, $core);

            if (is_string($value)) {
                $colors[$key] = strtolower($value);
            }
        }

        $focusRing = $this->resolveRefs($semantic['focus-ring'] ?? '', $core);

        return array(
            'colors' => $colors,
            'focusRing' => is_string($focusRing) ? $focusRing : '',
        );
    }

    /**
     * The Filament `colors()` payload: only the keys ADS can actually fill.
     *
     * @param array<string, string> $colors
     *
     * @return array<string, string>
     */
    public function filamentColors(array $colors): array
    {
        $out = array();

        foreach (self::FILAMENT_MAP as $filamentKey => $semanticKey) {
            if (isset($colors[$semanticKey])) {
                $out[$filamentKey] = $colors[$semanticKey];
            }
        }

        return $out;
    }

    /**
     * The generated PHP source, ready to write to {@see self::TARGET}.
     */
    public function php(): string
    {
        $resolved = $this->resolve();
        $colors = $resolved['colors'];
        $filament = $this->filamentColors($colors);
        $missing = array_values(array_diff(self::FILAMENT_KEYS, array_keys($filament)));

        $source = "<?php\n\n";
        $source .= "namespace App\\Support;\n\n";
        $source .= "/**\n";
        $source .= " * GENERATED FILE — do not edit.\n";
        $source .= " *\n";
        $source .= " * Source: ADS DTCG tokens at\n";
        $source .= ' * `'.self::relativeTokensPath($this->tokensPath)."`\n";
        $source .= " * Regenerate: `php artisan admin:theme`\n";
        $source .= " * Verify:     `php artisan admin:theme --check` (Pest: AdminThemeDriftTest)\n";
        $source .= " *\n";
        $source .= " * Proposal 149 T-0.6. Resolved for the light theme; dark mode is a\n";
        $source .= " * separate pass (T-0.6 follow-up) and must reuse the same generator.\n";
        $source .= " */\n";
        $source .= "final class AdminTheme\n{\n";
        $source .= $this->emitConst('COLORS', $colors);
        $source .= "\n";
        $source .= '    public const FOCUS_RING = '.var_export($resolved['focusRing'], true).";\n\n";
        $source .= $this->emitConst('FILAMENT_COLORS', $filament);
        $source .= "\n";
        $source .= $this->emitConst('MISSING_FILAMENT_KEYS', $missing);
        $source .= "}\n";

        return $source;
    }

    /**
     * Whether the committed artefact matches a fresh generation.
     */
    public function isInSync(string $targetFile): bool
    {
        if (! is_file($targetFile)) {
            return false;
        }

        return (string) file_get_contents($targetFile) === $this->php();
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $file): array
    {
        $path = rtrim($this->tokensPath, '/\\').'/'.$file;

        if (! is_file($path)) {
            throw new RuntimeException(
                "Admin theme token source not found: {$path}. "
                .'Set oos.admin_theme.tokens_path (or NVOOS_TOKENS_PATH).'
            );
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Admin theme token source is not valid JSON: {$path}");
        }

        return $decoded;
    }

    /**
     * Flatten a DTCG tree into `path => $value`, skipping `$`-prefixed keys.
     *
     * `$comment` and friends are metadata, and treating them as tokens is what
     * produced the theme-name leak the storefront's token build had to filter
     * (Proposal 095 gotcha).
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function flatten(array $node, string $prefix = ''): array
    {
        $flat = array();

        foreach ($node as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, '$')) {
                continue;
            }

            $path = '' === $prefix ? $key : $prefix.'.'.$key;

            if (is_array($value) && array_key_exists('$value', $value)) {
                $flat[$path] = $value['$value'];

                continue;
            }

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            }
        }

        return $flat;
    }

    /**
     * Replace `{core.path.to.token}` references.
     *
     * An unresolved reference is left verbatim rather than blanked: a visible
     * `{core.typo.here}` in the generated file is a bug report, whereas an
     * empty string silently renders as black.
     *
     * @param array<string, mixed> $core
     */
    private function resolveRefs(mixed $value, array $core, int $depth = 0): mixed
    {
        if (! is_string($value) || $depth > self::MAX_REF_DEPTH) {
            return $value;
        }

        $resolved = preg_replace_callback(
            '/\{([a-zA-Z0-9_.\-]+)\}/',
            function (array $matches) use ($core, $depth): string {
                $reference = $matches[1];

                if (! array_key_exists($reference, $core)) {
                    return $matches[0];
                }

                $replacement = $this->resolveRefs($core[$reference], $core, $depth + 1);

                return is_string($replacement) ? $replacement : (string) json_encode($replacement);
            },
            $value
        );

        return null === $resolved ? $value : $resolved;
    }

    /**
     * Emit a class constant.
     *
     * List arrays (e.g. the missing-key report) are emitted as plain values, not
     * `0 => 'success'` pairs — an integer key is meaningless there and makes the
     * generated file read like a mistake.
     *
     * @param array<string, string>|list<string> $values
     */
    private function emitConst(string $name, array $values): string
    {
        if (array() === $values) {
            return "    public const {$name} = array();\n";
        }

        $out = "    public const {$name} = array(\n";

        if (array_is_list($values)) {
            foreach ($values as $value) {
                $out .= '        '.var_export((string) $value, true).",\n";
            }

            return $out."    );\n";
        }

        foreach ($values as $key => $value) {
            $out .= '        '.var_export((string) $key, true).' => '.var_export((string) $value, true).",\n";
        }

        return $out."    );\n";
    }

    /**
     * Render the token path as a repo-relative string for the header comment.
     */
    private static function relativeTokensPath(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);
        $marker = 'examples/';
        $position = strpos($normalised, $marker);

        return false === $position ? $normalised : substr($normalised, $position);
    }
}
