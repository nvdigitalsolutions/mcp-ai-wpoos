<?php
/**
 * Skill registry — discovers and loads agent skills.
 *
 * Manages the lifecycle of SKILL.md files that provide progressive
 * disclosure of tool capabilities to the LLM. Supports bundled
 * skills and remote catalogues (Pro).
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Application\Skill;

class SkillRegistry
{
    /**
     * Loaded skills keyed by name.
     *
     * @var array<string, array{name: string, description: string, body: string}>
     */
    private array $skills = [];

    /**
     * Register a skill from its SKILL.md parsed data.
     *
     * @param array{name: string, description: string, body: string} $skill
     */
    public function register(array $skill): void
    {
        $this->skills[$skill['name']] = $skill;
    }

    /**
     * Load a skill by name. Returns the full SKILL.md body.
     *
     * @return string|null  Null if skill not found.
     */
    public function load(string $name): ?string
    {
        $skill = $this->skills[$name] ?? null;

        if (null === $skill) {
            return null;
        }

        return "# {$skill['name']}\n\n{$skill['description']}\n\n{$skill['body']}";
    }

    /**
     * Get a catalogue of all registered skills (name + description only).
     *
     * Used for progressive disclosure — the LLM receives this first,
     * then calls load_skill({ name }) to get the full body.
     *
     * @return array<int, array{name: string, description: string}>
     */
    public function catalogue(): array
    {
        $entries = [];

        foreach ($this->skills as $skill) {
            $entries[] = [
                'name'        => $skill['name'],
                'description' => $skill['description'],
            ];
        }

        \usort($entries, static fn($a, $b) => \strcasecmp($a['name'], $b['name']));

        return $entries;
    }

    /**
     * Build a Markdown catalogue string for inclusion in system prompts.
     */
    public function buildPromptCatalogue(): string
    {
        $entries = $this->catalogue();

        if ([] === $entries) {
            return '';
        }

        $lines = ["## Available Skills\n"];

        foreach ($entries as $skill) {
            $lines[] = "- **{$skill['name']}** — {$skill['description']}";
        }

        $lines[] = "\nTo load a skill's full instructions, call the `load_skill` tool with the skill name.";

        return \implode("\n", $lines);
    }

    /**
     * Check if a skill is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->skills[$name]);
    }

    /**
     * Get the number of registered skills.
     */
    public function count(): int
    {
        return \count($this->skills);
    }

    /**
     * Parse a SKILL.md file into its structured components.
     *
     * @return array{name: string, description: string, body: string}|null
     */
    public static function parseSkillMd(string $content): ?array
    {
        // Parse YAML frontmatter.
        if ( ! \preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            return null;
        }

        $frontmatter = $matches[1];
        $body        = \trim($matches[2]);

        $name        = '';
        $description = '';

        foreach (\explode("\n", $frontmatter) as $line) {
            if (\preg_match('/^name:\s*(.+)$/i', $line, $m)) {
                $name = \trim($m[1]);
            }
            if (\preg_match('/^description:\s*(.+)$/i', $line, $m)) {
                $description = \trim($m[1]);
            }
        }

        if ('' === $name) {
            return null;
        }

        return [
            'name'        => $name,
            'description' => $description,
            'body'        => $body,
        ];
    }

    /**
     * Register skills from a directory of SKILL.md files.
     *
     * @param string $directory  Path to a directory containing skill subdirectories.
     * @return int  Number of skills loaded.
     */
    public function loadFromDirectory(string $directory): int
    {
        if ( ! \is_dir($directory)) {
            return 0;
        }

        $loaded = 0;

        foreach (\scandir($directory) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $skillFile = $directory . '/' . $entry . '/SKILL.md';

            if ( ! \file_exists($skillFile)) {
                continue;
            }

            $content = \file_get_contents($skillFile);
            if (false === $content) {
                continue;
            }

            $parsed = self::parseSkillMd($content);
            if (null === $parsed) {
                continue;
            }

            $this->register($parsed);
            ++$loaded;
        }

        return $loaded;
    }
}
