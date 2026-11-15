<?php

namespace App\Support\Pro;

use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

/**
 * Loads toolkit manifests from a directory of JSON documents.
 *
 * Manifests are validated with `ToolkitManifest::validate()`; a document that
 * fails validation — or whose slug does not match its filename — is skipped
 * and reported through `errors()` rather than being served. That is the
 * "invalid manifests fail closed" gate in Proposal 090-B §8.
 *
 * The directory is `NVOOS_TOOLKIT_MANIFESTS` when set, otherwise
 * `resources/pro-toolkits`.
 */
class ToolkitManifestLoader
{
    /** @var array<string, ToolkitManifest>|null */
    private ?array $manifests = null;

    /** @var array<string, list<string>> */
    private array $errors = [];

    public function __construct(private readonly ?string $directory = null)
    {
    }

    public function directory(): string
    {
        $configured = $this->directory ?? (string) config('oos.toolkit_manifests_path', '');

        return $configured !== '' ? $configured : resource_path('pro-toolkits');
    }

    /**
     * @return array<string, ToolkitManifest> Slug → manifest, sorted by slug.
     */
    public function all(): array
    {
        $this->load();

        $manifests = $this->manifests ?? [];
        ksort($manifests);

        return $manifests;
    }

    public function find(string $slug): ?ToolkitManifest
    {
        $this->load();

        return $this->manifests[$slug] ?? null;
    }

    /**
     * Manifests that were rejected, keyed by filename.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $this->load();

        return $this->errors;
    }

    public function reload(): void
    {
        $this->manifests = null;
        $this->errors = [];
    }

    private function load(): void
    {
        if ($this->manifests !== null) {
            return;
        }

        $this->manifests = [];
        $this->errors = [];

        $directory = $this->directory();

        if (! is_dir($directory)) {
            return;
        }

        $files = glob($directory.'/*.json');

        if ($files === false) {
            return;
        }

        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            $raw = json_decode((string) file_get_contents($file), true);

            if (! is_array($raw)) {
                $this->errors[$name] = ['invalid JSON'];
                continue;
            }

            $errors = ToolkitManifest::validate($raw);

            if ($errors !== []) {
                $this->errors[$name] = $errors;
                continue;
            }

            $manifest = ToolkitManifest::fromArray($raw);
            $expected = pathinfo($name, PATHINFO_FILENAME);

            if ($manifest->slug() !== $expected) {
                $this->errors[$name] = ['slug "'.$manifest->slug().'" must match the filename "'.$expected.'"'];
                continue;
            }

            if (isset($this->manifests[$manifest->slug()])) {
                $this->errors[$name] = ['duplicate slug "'.$manifest->slug().'"'];
                continue;
            }

            $this->manifests[$manifest->slug()] = $manifest;
        }
    }
}
