<?php

/**
 * Write OKF concept documents, log entries and index files.
 *
 * The runtime could only *read* a bundle (`OkfReader`, `OkfParser`), so the
 * contract recorded `writer: none` and the admin page was honestly read-only.
 * This is the other half.
 *
 * ## What is enforced, and why
 *
 * The OKF spec makes exactly one field mandatory — a non-empty `type` — and
 * treats everything else as guidance; conformance is "if it has frontmatter with
 * `type`, it is valid OKF". So the checks here are the small set whose absence
 * produces a bundle that is quietly wrong rather than merely sparse:
 *
 *  - **`type` is required** (§4.1, §9). A file without it is not a concept, so a
 *    write that omitted it would look like an asset and disappear from the
 *    reader's view.
 *  - **`index.md` and `log.md` are reserved** (§3.1). A concept that took one of
 *    those names would corrupt the directory's own listing.
 *  - **A concept id cannot escape the bundle root.** `..` is refused outright and
 *    the final path must satisfy `BundleRootInterface::contains()`.
 *  - **Regenerating `index.md` keeps its frontmatter.** v0.3 §8.1 says a producer
 *    that regenerates the bundle-root index must preserve `okf_version`, `title`,
 *    `description` and `license`; the block is copied through verbatim.
 *
 * ## What is deliberately not done
 *
 * - **No `validate()`.** Conformance is a reader's job and mostly a two-line
 *   check; duplicating it here would give two answers that can disagree.
 * - **No re-serialisation of unchanged values.** A rewrite replaces the whole
 *   frontmatter block, which is what `writeConcept()` means. Preserving unknown
 *   keys is the caller's responsibility: pass back what you read.
 * - **No hard delete.** `deleteConcept()` renames to `.deleted`, matching the
 *   WordPress writer's recovery path; a bundle is versioned data and a mistaken
 *   delete should be recoverable without git.
 *
 * ## Atomicity
 *
 * Every write lands on a sibling temp file and is published with `rename()`, so a
 * concurrent reader sees the old file or the new one — never a half-written
 * frontmatter block.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Okf;

use Nvoos\Core\Domain\Contract\BundleRootInterface;

final class OkfWriter
{
    /** Filenames the spec reserves at every level (§3.1). */
    private const RESERVED = array('index', 'log');

    private readonly OkfParserInterface $parser;

    public function __construct(
        private readonly BundleRootInterface $root,
        ?OkfParserInterface $parser = null,
    ) {
        $this->parser = $parser ?? new OkfParser();
    }

    /**
     * Write one concept, creating parent directories as needed.
     *
     * @param string               $conceptId   Path relative to the bundle root, `.md` optional.
     * @param array<string, mixed> $frontmatter Must contain a non-empty `type`.
     * @param string               $body        Markdown after the frontmatter.
     *
     * @return string The absolute path written.
     *
     * @throws OkfWriteException
     */
    public function writeConcept(string $conceptId, array $frontmatter, string $body = ''): string
    {
        if ('' === \trim((string) ($frontmatter['type'] ?? ''))) {
            throw new OkfWriteException(
                'okf_missing_type',
                'An OKF concept requires a non-empty "type" field in its frontmatter.',
            );
        }

        $path = $this->conceptPath($conceptId);

        $content = OkfFrontmatter::serialize($frontmatter) . "\n" . \rtrim($body) . "\n";

        $this->writeAtomically($path, $content);

        return $path;
    }

    /**
     * Retire a concept without destroying it.
     *
     * @return bool True when a concept was moved, false when there was nothing there.
     *
     * @throws OkfWriteException
     */
    public function deleteConcept(string $conceptId): bool
    {
        $path = $this->conceptPath($conceptId);

        if (! \is_file($path)) {
            return false;
        }

        $retired = $path . '.deleted';

        if (! @\rename($path, $retired)) {
            throw new OkfWriteException('okf_write_error', "Could not retire {$path}.");
        }

        return true;
    }

    /**
     * Append a dated entry to `log.md` at the given level.
     *
     * Entries are prose; the bold leading word is a convention, not a requirement
     * (§7). Today's section is reused when it already exists, so a burst of writes
     * lands under one date rather than repeating the heading.
     *
     * @param string $path   Directory relative to the bundle root; '' for the root.
     * @param string $entry  One line of prose.
     * @param string $action Leading word, conventionally Create|Update|Deprecation.
     *
     * @throws OkfWriteException
     */
    public function appendLog(string $path, string $entry, string $action = 'Update'): void
    {
        $directory = $this->directoryPath($path);
        $logPath   = $directory . '/log.md';
        $today     = \gmdate('Y-m-d');

        $prose = \trim(\preg_replace('/\s+/', ' ', \strip_tags($entry)) ?? '');
        $word  = \trim(\preg_replace('/[^A-Za-z ]/', '', $action) ?? '') ?: 'Update';

        if ('' === $prose) {
            throw new OkfWriteException('okf_empty_entry', 'A log entry cannot be empty.');
        }

        $line = '* **' . $word . '**: ' . $prose;

        if (! \is_file($logPath)) {
            $this->writeAtomically($logPath, "# Directory Update Log\n\n## {$today}\n\n{$line}\n");

            return;
        }

        $existing = \file_get_contents($logPath);

        if (false === $existing) {
            throw new OkfWriteException('okf_read_error', "Could not read {$logPath}.");
        }

        $heading = '## ' . $today;

        if (\str_contains($existing, $heading)) {
            // Insert under today's heading, newest first within the day.
            $updated = \preg_replace(
                '/(' . \preg_quote($heading, '/') . '\n)/',
                "$1\n{$line}\n",
                $existing,
                1,
            );
        } else {
            // A new date section goes directly under the title, most recent first.
            $updated = \preg_replace(
                '/(# Directory Update Log\n)/',
                "$1\n{$heading}\n\n{$line}\n",
                $existing,
                1,
            );
        }

        if (null === $updated) {
            throw new OkfWriteException('okf_write_error', "Could not update {$logPath}.");
        }

        $this->writeAtomically($logPath, $updated);
    }

    /**
     * Rebuild `index.md` for a directory from what is actually on disk.
     *
     * Existing frontmatter is preserved verbatim: v0.3 §8.1 requires the
     * bundle-root index's `okf_version`, `title`, `description` and `license` to
     * survive regeneration.
     *
     * @param string $path Directory relative to the bundle root; '' for the root.
     *
     * @throws OkfWriteException
     */
    public function regenerateIndex(string $path = ''): void
    {
        $directory = $this->directoryPath($path);
        $indexPath = $directory . '/index.md';

        $frontmatter = '';

        if (\is_file($indexPath)) {
            $existing = (string) \file_get_contents($indexPath);

            if (\preg_match('/^---\n.*?\n---\n/s', $existing, $matches)) {
                $frontmatter = $matches[0] . "\n";
            }
        }

        $title = '' === \trim($path) ? \basename($this->root->root()) : \basename($directory);

        $directories = array();
        $concepts    = array();

        foreach ((array) \scandir($directory) as $entry) {
            if ('.' === $entry || '..' === $entry || \str_starts_with($entry, '.')) {
                continue;
            }

            if (\is_dir($directory . '/' . $entry)) {
                $directories[] = $entry;
                continue;
            }

            if (! \str_ends_with($entry, '.md') || \in_array($entry, array('index.md', 'log.md'), true)) {
                continue;
            }

            $concepts[] = $entry;
        }

        \sort($directories);
        \sort($concepts);

        $lines = array($frontmatter . '# ' . $title);

        if (array() !== $directories) {
            $lines[] = '';
            $lines[] = '## Directories';

            foreach ($directories as $name) {
                $lines[] = '* [' . $this->label($name) . '](' . \rawurlencode($name) . '/)';
            }
        }

        if (array() !== $concepts) {
            $lines[] = '';
            $lines[] = '## Concepts';

            foreach ($concepts as $name) {
                $meta = $this->frontmatterOf($directory . '/' . $name);

                $label = \trim((string) ($meta['title'] ?? '')) ?: \substr($name, 0, -3);
                $line  = '* [' . $label . '](' . \rawurlencode($name) . ')';

                $description = \trim((string) ($meta['description'] ?? ''));

                if ('' !== $description) {
                    $line .= ' - ' . $description;
                }

                $lines[] = $line;
            }
        }

        $this->writeAtomically($indexPath, \implode("\n", $lines) . "\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function frontmatterOf(string $path): array
    {
        $content = @\file_get_contents($path);

        if (false === $content) {
            return array();
        }

        try {
            $document = $this->parser->parse($content);
        } catch (\Throwable) {
            return array();
        }

        return null === $document ? array() : $document->frontmatter;
    }

    /**
     * `notes/on-call` → `On-call`, used when a directory has no index of its own.
     */
    private function label(string $name): string
    {
        return \ucfirst(\str_replace(array('-', '_'), ' ', $name));
    }

    /**
     * Resolve a concept id to a path inside the bundle.
     *
     * @throws OkfWriteException
     */
    private function conceptPath(string $conceptId): string
    {
        $id = \str_replace('\\', '/', \trim($conceptId));
        $id = \ltrim($id, '/');
        $id = (string) \preg_replace('/\.md$/i', '', $id);

        if ('' === $id) {
            throw new OkfWriteException('okf_missing_id', 'A concept id is required.');
        }

        if (\str_contains('/' . $id . '/', '/../')) {
            throw new OkfWriteException('okf_path_traversal', 'A concept id cannot contain "..".');
        }

        if (\in_array(\strtolower(\basename($id)), self::RESERVED, true)) {
            throw new OkfWriteException(
                'okf_reserved_name',
                'index.md and log.md are reserved and cannot be written as concepts.',
            );
        }

        $path = $this->root->root() . '/' . $id . '.md';

        if (! $this->root->contains($path)) {
            throw new OkfWriteException('okf_path_traversal', 'The concept path escapes the bundle root.');
        }

        return $path;
    }

    /**
     * @throws OkfWriteException
     */
    private function directoryPath(string $path): string
    {
        $directory = $this->root->root();
        $relative  = \trim($path, '/');

        if ('' !== $relative) {
            $directory .= '/' . $relative;

            // A concept id is checked against `..` and `contains()` above; a
            // directory passed to `regenerateIndex()` needs the same check, or
            // `path: ../outside` writes an index outside the bundle.
            if (! $this->root->contains($directory)) {
                throw new OkfWriteException('okf_path_traversal', 'The index path escapes the bundle root.');
            }
        }

        if (! \is_dir($directory)) {
            throw new OkfWriteException('okf_not_found', "OKF directory not found: {$path}");
        }

        return $directory;
    }

    /**
     * Publish a file with `rename()`, so no reader sees a partial write.
     *
     * @throws OkfWriteException
     */
    private function writeAtomically(string $path, string $content): void
    {
        $directory = \dirname($path);

        if (! \is_dir($directory) && ! @\mkdir($directory, 0775, true) && ! \is_dir($directory)) {
            throw new OkfWriteException('okf_write_error', "Could not create {$directory}.");
        }

        $temporary = $path . '.tmp-' . \bin2hex(\random_bytes(4));

        if (false === @\file_put_contents($temporary, $content)) {
            throw new OkfWriteException('okf_write_error', "Could not write {$temporary}.");
        }

        if (! @\rename($temporary, $path)) {
            @\unlink($temporary);

            throw new OkfWriteException('okf_write_error', "Could not publish {$path}.");
        }
    }
}
