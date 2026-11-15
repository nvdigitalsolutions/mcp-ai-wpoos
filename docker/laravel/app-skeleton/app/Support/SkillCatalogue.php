<?php

namespace App\Support;

use Nvoos\Core\Application\Skill\SkillRegistry;

/**
 * Installed Agent Skills, read from a directory of skill folders.
 *
 * Each folder holds one `SKILL.md` whose YAML frontmatter declares the name,
 * description and optional licence and compatibility fields.
 *
 * Read-only on purpose: WordPress ships a manager (install from raw SKILL.md
 * or a remote catalogue, replace, delete). Those are file writes driven by
 * HTTP input and deserve their own security pass; until then the picker is
 * populated and nothing pretends to install.
 *
 * WordPress serves its catalogue from `GET mcp-ai/v1/skills` (base plugin,
 * administrators only) out of the uploads directory. This runtime reads
 * `config('oos.skills.path')` instead — the deployment's own storage — and
 * serves the same four fields, because the console's `SkillsClient` is
 * shared.
 *
 * A missing directory is an empty catalogue. That is not the "missing
 * provider" case other surfaces answer with 501: the implementation is here,
 * the deployment simply has no skills yet.
 */
final class SkillCatalogue
{
    public function __construct(private readonly string $path)
    {
    }

    public static function fromConfig(): self
    {
        return new self((string) config('oos.skills.path', storage_path('app/skills')));
    }

    /**
     * Catalogue entries in the shape the console reads.
     *
     * @return list<array{name: string, description: string, license: string, compatibility: string}>
     */
    public function all(): array
    {
        if ('' === $this->path || ! is_dir($this->path)) {
            return [];
        }

        $directories = glob($this->path.'/*', GLOB_ONLYDIR);
        $directories = is_array($directories) ? $directories : [];

        sort($directories);

        $skills = [];

        foreach ($directories as $directory) {
            $file = $directory.'/SKILL.md';

            if (! is_readable($file)) {
                continue;
            }

            $content = file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local skill file, not remote.
            $parsed = is_string($content) ? SkillRegistry::parseSkillMd($content) : null;

            if (null === $parsed) {
                continue;
            }

            $skills[] = [
                'name' => (string) $parsed['name'],
                'description' => (string) $parsed['description'],
                'license' => (string) $parsed['license'],
                'compatibility' => (string) $parsed['compatibility'],
            ];
        }

        usort($skills, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $skills;
    }

    /**
     * The configured directory, exposed for the diagnostics surface.
     */
    public function path(): string
    {
        return $this->path;
    }
}
