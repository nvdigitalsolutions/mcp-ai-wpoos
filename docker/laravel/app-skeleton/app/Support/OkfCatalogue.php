<?php

namespace App\Support;

use Nvoos\Core\Domain\Okf\OkfReadException;
use Nvoos\Core\Domain\Okf\OkfReader;
use Nvoos\Core\Infrastructure\Okf\FilesystemBundleRoot;

/**
 * OKF knowledge bundles on this runtime.
 *
 * WordPress keeps bundles under `uploads/mcp-ai-wpoos/knowledge/<bundle>/` and
 * resolves them through `WP_MCP_AI_OKF_Bundle_Manager`. This runtime reads
 * `config('oos.okf.path')` instead — `storage/app/knowledge` by default — with
 * one directory per bundle, and does the same descriptor work in-process using
 * the framework-agnostic `OkfReader`.
 *
 * **A descriptor is a summary, not a validation report.** WordPress builds one
 * from `WP_MCP_AI_OKF_Writer::validate_bundle()`, which lives in the plugin.
 * Here `conformant`/`issue_count` mean "every concept parses and no internal
 * `.md` link is broken" — a documented subset, computed from the reader. The
 * console renders only the counts and types; nothing shows a validation badge.
 *
 * Bundle names are constrained the way WordPress constrains the route, and a
 * resolved directory must stay inside the root (realpath, symlinks included).
 * Concept paths are guarded by `FilesystemBundleRoot::contains()`, so a
 * traversal attempt is a 404 from the reader rather than a file read.
 */
final class OkfCatalogue
{
    /** Matches WordPress's route pattern for `{bundle}`. */
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,99}$/';

    public function __construct(private readonly string $root)
    {
    }

    public static function fromConfig(): self
    {
        return new self((string) config('oos.okf.path', storage_path('app/knowledge')));
    }

    /**
     * Bundle names, sorted. Dot-directories (WordPress's `.trash`) are skipped.
     *
     * @return list<string>
     */
    public function names(): array
    {
        if ('' === $this->root || ! is_dir($this->root)) {
            return [];
        }

        $entries = scandir($this->root);
        $entries = is_array($entries) ? $entries : [];
        $realRoot = realpath($this->root);

        $names = [];

        foreach ($entries as $entry) {
            if ('' === $entry || '.' === $entry[0]) {
                continue;
            }

            if (1 !== preg_match(self::NAME_PATTERN, $entry)) {
                continue;
            }

            $path = $this->root.'/'.$entry;

            if (! is_dir($path)) {
                continue;
            }

            $real = realpath($path);

            if (false === $realRoot || false === $real) {
                continue;
            }

            if ($real !== $realRoot && ! str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $names[] = $entry;
        }

        sort($names);

        return $names;
    }

    /**
     * Bundle descriptors, in the shape the console's bundle list renders.
     *
     * @return list<array<string, mixed>>
     */
    public function bundles(): array
    {
        $bundles = [];

        foreach ($this->names() as $name) {
            $bundles[] = $this->describe($name);
        }

        return $bundles;
    }

    /**
     * Descriptor for one bundle.
     *
     * @return array<string, mixed>
     *
     * @throws OkfReadException When the bundle does not exist.
     */
    public function describe(string $name): array
    {
        $reader = $this->readerFor($name);
        $concepts = $reader->search([]);

        $trustTiers = [
            'unverified' => 0,
            'machine-confirmed' => 0,
            'human-reviewed' => 0,
        ];

        $stale = 0;
        $deprecated = 0;
        $issues = 0;

        foreach ($concepts as $concept) {
            $tier = is_scalar($concept['trust_tier'] ?? null) ? (string) $concept['trust_tier'] : 'unverified';

            if (array_key_exists($tier, $trustTiers)) {
                ++$trustTiers[$tier];
            }

            if (! empty($concept['stale'])) {
                ++$stale;
            }

            if ('deprecated' === strtolower((string) ($concept['status'] ?? ''))) {
                ++$deprecated;
            }

            $issues += count($reader->findBrokenLinks((string) $concept['concept_id']));
        }

        $modified = @filemtime($this->pathFor($name)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing mtime is 0, not an error.

        return [
            'name' => $name,
            // No protected bundles exist on this runtime: protection is a
            // WordPress uploads-directory concern.
            'protected' => false,
            'concept_count' => count($concepts),
            'stale_count' => $stale,
            'deprecated_count' => $deprecated,
            'conformant' => 0 === $issues,
            'issue_count' => $issues,
            'types' => $reader->getTypes(),
            'trust_tiers' => $trustTiers,
            'modified' => false === $modified ? 0 : (int) $modified,
        ];
    }

    /**
     * Reader for one bundle.
     *
     * @throws OkfReadException When the name is invalid or the directory is
     *                          missing or escapes the root.
     */
    public function readerFor(string $name): OkfReader
    {
        return new OkfReader(new FilesystemBundleRoot($this->pathFor($name)));
    }

    /**
     * Resolve a bundle name to its directory, or fail.
     *
     * @throws OkfReadException
     */
    public function pathFor(string $name): string
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new OkfReadException('okf_not_found', 'Unknown OKF bundle.');
        }

        if ('' === $this->root || ! is_dir($this->root)) {
            throw new OkfReadException('okf_not_found', 'The OKF knowledge root does not exist.');
        }

        $path = rtrim($this->root, '/').'/'.$name;

        if (! is_dir($path)) {
            throw new OkfReadException('okf_not_found', 'Unknown OKF bundle.');
        }

        $realRoot = realpath($this->root);
        $real = realpath($path);

        if (false === $realRoot || false === $real || ! str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR)) {
            throw new OkfReadException('okf_not_found', 'Unknown OKF bundle.');
        }

        return $real;
    }

    /**
     * The configured knowledge root, for diagnostics.
     */
    public function root(): string
    {
        return $this->root;
    }
}
