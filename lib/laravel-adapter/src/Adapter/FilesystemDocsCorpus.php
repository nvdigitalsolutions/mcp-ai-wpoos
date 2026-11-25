<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\DocsCorpusInterface;
use Nvoos\Core\Domain\Error\NotFoundException;

/**
 * A Markdown corpus on the local filesystem — Proposal 164 §164.34.
 *
 * The five `docs_hub_*` tools are readers and writers of a directory of `.md` /
 * `.txt` files with YAML frontmatter. In WordPress that directory belongs to the
 * standalone docs-hub plugin and its path rules live in
 * `WP_MCP_AI_Docs_Hub_Helper`; here it is one configured root, and this class is
 * the only thing that decides whether a path is inside it.
 *
 * ## The path guard is ported, not re-derived
 *
 * `sanitize()` and `resolve()` are transcriptions of the WordPress helper's two
 * checks, because they were written against real attacks and re-inventing them
 * is how one of the layers gets dropped:
 *
 *  - **`sanitize()`** normalises Windows separators *before* validating (so
 *    `..\secret` cannot slip past), refuses a drive-letter prefix and any
 *    absolute or self-referencing prefix, then requires every segment to match
 *    `^[A-Za-z0-9][A-Za-z0-9 _.-]*$` — which is what rejects `..` and `.`
 *    without a special case.
 *  - **`resolve()`** compares `realpath()` of the target against `realpath()` of
 *    the root, so a symlinked subdirectory cannot escape. An unwritten path walks
 *    up to its deepest existing ancestor and checks *that*, so a write cannot
 *    create its way outside either.
 *
 * A refused path is an `\InvalidArgumentException`; a valid path with nothing
 * behind it is a {@see NotFoundException}. A caller has to be able to tell a bad
 * request from an empty shelf.
 *
 * ## Containment failures read as "not found", deliberately
 *
 * The two layers fail differently on purpose. A path that breaks a *segment*
 * rule is refused outright, because the caller can see the rule. A path that
 * passes validation but *resolves* outside the root — in practice a symlink — is
 * reported as not-found, so the corpus cannot be used to test whether an
 * arbitrary file exists. Writes are the exception: a write that cannot be placed
 * inside the root is refused, because there is nothing to disclose and the
 * caller needs to know why nothing was written.
 *
 * ## There is no index
 *
 * `rebuild()` scans rather than reindexes: the filesystem is authoritative here,
 * and reporting duplicates and unreadable files is the useful part of what the
 * WordPress rebuild job surfaces.
 *
 * @package Nvoos\Laravel
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */
final class FilesystemDocsCorpus implements DocsCorpusInterface
{
    /** The longest corpus-relative path accepted. */
    private const MAX_PATH_LENGTH = 240;

    /**
     * @param string       $root     Absolute corpus root; '' disables the corpus.
     * @param int          $maxBytes Largest body accepted, in bytes.
     * @param list<string> $extensions Extensions served.
     */
    public function __construct(
        private readonly string $root,
        private readonly int $maxBytes = 2097152,
        private readonly array $extensions = array('md', 'txt'),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isActive(): bool {
        return '' !== \trim($this->root);
    }

    /**
     * {@inheritDoc}
     */
    public function maxBytes(): int {
        return $this->maxBytes;
    }

    /**
     * {@inheritDoc}
     */
    public function allowedExtensions(): array {
        return \array_values($this->extensions);
    }

    /**
     * {@inheritDoc}
     */
    public function list(string $subdirectory = '', int $limit = 100): array {
        $this->assertActive();

        $prefix = '' === \trim($subdirectory) ? '' : $this->sanitize($subdirectory, false);

        $directory = \rtrim($this->root, '/\\');

        if ('' !== $prefix) {
            $directory .= '/'.$prefix;

            if (!\is_dir($directory)) {
                return array('documents' => array(), 'total' => 0);
            }
        }

        $found = array();
        $this->scan($directory, '' === $prefix ? '' : $prefix.'/', $found);

        \usort($found, static fn (array $a, array $b): int => \strcmp($a['path'], $b['path']));

        $total = \count($found);
        $limit = \max(1, $limit);

        return array(
            'documents' => \array_slice($found, 0, $limit),
            'total' => $total,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $path): array {
        $this->assertActive();

        $relative = $this->sanitize($path);
        $absolute = $this->resolve($relative);

        if ('' === $absolute || !\is_file($absolute)) {
            throw new NotFoundException(\sprintf('No document at "%s".', $relative));
        }

        $size = (int) \filesize($absolute);

        if ($size > $this->maxBytes) {
            throw new \InvalidArgumentException(
                \sprintf('The document exceeds the %d-byte limit.', $this->maxBytes),
            );
        }

        $content = \file_get_contents($absolute);

        if (false === $content) {
            throw new \RuntimeException(\sprintf('Could not read "%s".', $relative));
        }

        return array(
            'path' => $relative,
            'title' => $this->title($content, $relative),
            'content' => $content,
            'size' => $size,
            'modified' => (int) \filemtime($absolute),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $path, string $content, array $meta = array()): array {
        $this->assertActive();

        $relative = $this->sanitize($path);
        $body = $this->frontmatter($meta).$content;

        if (\strlen($body) > $this->maxBytes) {
            throw new \InvalidArgumentException(
                \sprintf('The document exceeds the %d-byte limit.', $this->maxBytes),
            );
        }

        $absolute = $this->resolve($relative);

        if ('' === $absolute) {
            throw new \InvalidArgumentException(\sprintf('The path "%s" is not inside the corpus.', $relative));
        }

        $created = !\is_file($absolute);
        $directory = \dirname($absolute);

        if (!\is_dir($directory) && !@\mkdir($directory, 0775, true) && !\is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Could not create the directory for "%s".', $relative));
        }

        if (false === \file_put_contents($absolute, $body)) {
            throw new \RuntimeException(\sprintf('Could not write "%s".', $relative));
        }

        return array(
            'path' => $relative,
            'title' => $this->title($body, $relative),
            'size' => \strlen($body),
            'created' => $created,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $path): bool {
        $this->assertActive();

        $relative = $this->sanitize($path);
        $absolute = $this->resolve($relative);

        if ('' === $absolute || !\is_file($absolute)) {
            throw new NotFoundException(\sprintf('No document at "%s".', $relative));
        }

        if (!@\unlink($absolute)) {
            throw new \RuntimeException(\sprintf('Could not delete "%s".', $relative));
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function setFields(string $path, array $fields): array {
        $this->assertActive();

        $relative = $this->sanitize($path);
        $absolute = $this->resolve($relative);

        if ('' === $absolute || !\is_file($absolute)) {
            throw new NotFoundException(\sprintf('No document at "%s".', $relative));
        }

        $raw = \file_get_contents($absolute);

        if (false === $raw) {
            throw new \RuntimeException(\sprintf('Could not read "%s".', $relative));
        }

        [$current, $body] = $this->split($raw);

        foreach ($fields as $key => $value) {
            $normalised = \strtolower((string) $key);

            if (null === $value || '' === \trim((string) $value)) {
                unset($current[$normalised]);
                continue;
            }

            $current[$normalised] = (string) $value;
        }

        $written = $this->frontmatterFrom($current).$body;

        if (false === \file_put_contents($absolute, $written)) {
            throw new \RuntimeException(\sprintf('Could not write "%s".', $relative));
        }

        return array(
            'path' => $relative,
            'fields' => $current,
            'title' => $current['title'] ?? $this->title($body, $relative),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function archive(string $path, array $meta = array()): array {
        $this->assertActive();

        $relative = $this->sanitize($path);
        $absolute = $this->resolve($relative);

        if ('' === $absolute || !\is_file($absolute)) {
            throw new NotFoundException(\sprintf('No document at "%s".', $relative));
        }

        $raw = \file_get_contents($absolute);

        if (false === $raw) {
            throw new \RuntimeException(\sprintf('Could not read "%s".', $relative));
        }

        [$fields, $body] = $this->split($raw);

        // Already withdrawn: report what the first archive recorded rather than
        // re-stamping it. An archive record exists to prove *when* a document
        // was withdrawn, and rewriting that would destroy the evidence.
        if (isset($fields['status']) && 'archived' === \strtolower($fields['status'])) {
            return array(
                'path' => $relative,
                'title' => $fields['title'] ?? $this->title($body, $relative),
                'status' => 'archived',
                'archived_at' => (string) ( $fields['archived_at'] ?? '' ),
                'archived_by' => (int) ( $fields['archived_by'] ?? 0 ),
                'archive_reason' => (string) ( $fields['archive_reason'] ?? '' ),
                'already_archived' => true,
            );
        }

        $reason = isset($meta['reason']) && \is_string($meta['reason']) ? \trim($meta['reason']) : '';
        $userId = isset($meta['user_id']) && \is_numeric($meta['user_id']) ? (int) $meta['user_id'] : 0;
        $now = \gmdate('c');

        $fields['status'] = 'archived';
        $fields['archived_at'] = $now;
        $fields['archived_by'] = (string) $userId;

        if ('' !== $reason) {
            $fields['archive_reason'] = $reason;
        }

        $written = $this->frontmatterFrom($fields).$body;

        if (false === \file_put_contents($absolute, $written)) {
            throw new \RuntimeException(\sprintf('Could not archive "%s".', $relative));
        }

        return array(
            'path' => $relative,
            'title' => $fields['title'] ?? $this->title($body, $relative),
            'status' => 'archived',
            'archived_at' => $now,
            'archived_by' => $userId,
            'archive_reason' => $reason,
            'already_archived' => false,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function rebuild(): array {
        $this->assertActive();

        $documents = array();
        $this->scan(\rtrim($this->root, '/\\'), '', $documents);

        $byTitle = array();
        $duplicates = array();
        $unreadable = array();

        foreach ($documents as $document) {
            $absolute = $this->resolve($document['path']);
            $content = '' === $absolute ? false : @\file_get_contents($absolute);

            if (false === $content) {
                $unreadable[] = $document['path'];
                continue;
            }

            $title = \strtolower($this->title($content, $document['path']));

            if (isset($byTitle[$title])) {
                $duplicates[] = $title;
                continue;
            }

            $byTitle[$title] = true;
        }

        return array(
            'indexed' => \count($documents),
            'duplicates' => \array_values(\array_unique($duplicates)),
            'unreadable' => $unreadable,
        );
    }

    /**
     * Walk a directory, collecting served documents.
     *
     * Dot-entries are skipped so an editor's `.git` or `.obsidian` directory
     * cannot become part of the corpus, and symlinked directories are not
     * descended — `resolve()` would refuse their contents anyway, and following
     * them first would hang on a cycle.
     *
     * @param list<array{path: string, title: string, size: int, modified: int}> $found
     */
    private function scan(string $directory, string $prefix, array &$found): void {
        $entries = @\scandir($directory);

        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || \str_starts_with($entry, '.')) {
                continue;
            }

            $absolute = $directory.'/'.$entry;
            $relative = $prefix.$entry;

            if (\is_link($absolute)) {
                continue;
            }

            if (\is_dir($absolute)) {
                $this->scan($absolute, $relative.'/', $found);
                continue;
            }

            if (!$this->serves($relative)) {
                continue;
            }

            $content = @\file_get_contents($absolute);

            $found[] = array(
                'path' => $relative,
                'title' => false === $content ? $entry : $this->title($content, $relative),
                'size' => (int) @\filesize($absolute),
                'modified' => (int) @\filemtime($absolute),
            );
        }
    }

    /**
     * Whether an extension is served.
     */
    private function serves(string $relative): bool {
        return \in_array(\strtolower(\pathinfo($relative, PATHINFO_EXTENSION)), $this->extensions, true);
    }

    /**
     * Validate a corpus-relative path.
     *
     * Ported from `WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path()`.
     *
     * @param bool $requireDocFile Require a served extension (false for a directory prefix).
     *
     * @throws \InvalidArgumentException When the path is refused.
     */
    private function sanitize(string $raw, bool $requireDocFile = true): string {
        $path = \trim($raw);

        // Normalise Windows separators *before* validating, so `..\secret` is
        // judged by the same segment rule as `../secret`.
        $path = \str_replace('\\', '/', $path);

        if ('' === $path || \strlen($path) > self::MAX_PATH_LENGTH) {
            throw new \InvalidArgumentException('The path is empty or too long.');
        }

        // Drive letters survive normalisation.
        if (1 === \preg_match('#^[a-zA-Z]:#', $path)) {
            throw new \InvalidArgumentException('The path must be relative to the corpus root.');
        }

        if (1 === \preg_match('#^[./]#', $path)) {
            throw new \InvalidArgumentException('The path must be relative to the corpus root.');
        }

        foreach (\explode('/', $path) as $segment) {
            // `..` and `.` fail the first-character rule, so no special case is
            // needed for them here.
            if ('' === $segment || 1 !== \preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]*$/', $segment)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not an allowed path segment.', $segment));
            }
        }

        if ($requireDocFile && !$this->serves($path)) {
            throw new \InvalidArgumentException(
                \sprintf('Only %s files are served.', \implode(', ', $this->extensions)),
            );
        }

        return $path;
    }

    /**
     * Resolve a validated relative path inside the root.
     *
     * Ported from `WP_MCP_AI_Docs_Hub_Helper::resolve_safe_path()`. Returns ''
     * when the target escapes the root.
     */
    private function resolve(string $relative): string {
        $root = \rtrim($this->root, '/\\');

        if (!\is_dir($root)) {
            // Nothing exists yet: the concatenation is safe because $relative
            // already passed segment validation.
            return $root.'/'.$relative;
        }

        $candidate = $root.'/'.$relative;
        $realRoot = \realpath($root);

        if (false === $realRoot) {
            return '';
        }

        if (\file_exists($candidate) || \is_link($candidate)) {
            $resolved = \realpath($candidate);

            return false !== $resolved && $this->inside($resolved, $realRoot) ? $resolved : '';
        }

        // Not created yet: check the deepest existing ancestor instead, so a
        // write cannot create its way out of the root.
        $ancestor = \dirname($candidate);

        while (!\is_dir($ancestor) && $ancestor !== $root) {
            $parent = \dirname($ancestor);

            if ($parent === $ancestor) {
                return '';
            }

            $ancestor = $parent;
        }

        $realAncestor = \realpath($ancestor);

        if (false === $realAncestor || !$this->inside($realAncestor, $realRoot)) {
            return '';
        }

        return $candidate;
    }

    /**
     * Whether a resolved path is the root or below it.
     */
    private function inside(string $resolved, string $realRoot): bool {
        return $resolved === $realRoot
            || 0 === \strncmp($resolved, $realRoot.DIRECTORY_SEPARATOR, \strlen($realRoot) + 1)
            || 0 === \strncmp($resolved, $realRoot.'/', \strlen($realRoot) + 1);
    }

    /**
     * Split a document into its frontmatter fields and its body.
     *
     * Understands the subset this corpus writes: single-line `key: value`
     * scalars between two `---` fences. Anything it does not understand stays in
     * the body untouched, which is the safe direction — a document with nested
     * YAML is not silently rewritten into something else.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function split(string $raw): array {
        if (1 !== \preg_match('/^---\R(.*?)\R---\R+/s', $raw, $match)) {
            return array(array(), $raw);
        }

        $fields = array();

        foreach (\preg_split('/\R/', $match[1]) ?: array() as $line) {
            if (1 === \preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(.*)$/', $line, $pair)) {
                $value = \trim($pair[2]);

                if (\strlen($value) > 1 && (
                    ("'" === $value[0] && "'" === $value[\strlen($value) - 1])
                    || ('"' === $value[0] && '"' === $value[\strlen($value) - 1])
                )) {
                    $value = \substr($value, 1, -1);
                }

                $fields[\strtolower($pair[1])] = \str_replace('\\"', '"', $value);
            }
        }

        return array($fields, \substr($raw, \strlen($match[0])));
    }

    /**
     * Render frontmatter from a field map.
     *
     * Known keys are emitted first in a stable order so a document archived twice
     * for different reasons still diffs cleanly; the rest keep their own order.
     *
     * @param array<string, string> $fields Field map.
     */
    private function frontmatterFrom(array $fields): string {
        if (array() === $fields) {
            return '';
        }

        $order = array('title', 'slug', 'order', 'status', 'archived_at', 'archived_by', 'archive_reason');
        $lines = array();

        foreach ($order as $key) {
            if (isset($fields[$key]) && '' !== $fields[$key]) {
                $lines[] = $key.': '.$this->yaml($fields[$key]);
                unset($fields[$key]);
            }
        }

        foreach ($fields as $key => $value) {
            if ('' !== $value) {
                $lines[] = $key.': '.$this->yaml($value);
            }
        }

        return array() === $lines ? '' : "---\n".\implode("\n", $lines)."\n---\n\n";
    }

    /**
     * The document's title: its frontmatter, else its first heading, else its name.
     */
    private function title(string $content, string $relative): string {
        if (1 === \preg_match('/^---\R(.*?)\R---\R/s', $content, $match)) {
            if (1 === \preg_match('/^title:\s*["\']?(.+?)["\']?\s*$/mi', $match[1], $title)) {
                return \trim($title[1]);
            }
        }

        if (1 === \preg_match('/^#\s+(.+)$/m', $content, $heading)) {
            return \trim($heading[1]);
        }

        return \pathinfo($relative, PATHINFO_FILENAME);
    }

    /**
     * Build a YAML frontmatter block, or '' when there is nothing to record.
     *
     * Only the three fields the write tool accepts are emitted, so a caller that
     * supplies none gets its content verbatim and stays free to manage its own
     * frontmatter.
     *
     * @param array{title?: string, slug?: string, order?: int} $meta
     */
    private function frontmatter(array $meta): string {
        $lines = array();

        $title = isset($meta['title']) && \is_string($meta['title']) ? \trim($meta['title']) : '';
        if ('' !== $title) {
            $lines[] = 'title: '.$this->yaml($title);
        }

        $slug = isset($meta['slug']) && \is_string($meta['slug']) ? \trim($meta['slug']) : '';
        if ('' !== $slug) {
            $lines[] = 'slug: '.$this->yaml($slug);
        }

        if (isset($meta['order']) && \is_numeric($meta['order'])) {
            $lines[] = 'order: '.(int) $meta['order'];
        }

        return array() === $lines ? '' : "---\n".\implode("\n", $lines)."\n---\n\n";
    }

    /**
     * Quote a frontmatter scalar when a bare value would be ambiguous.
     */
    private function yaml(string $value): string {
        return 1 === \preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $value)
            ? $value
            : '"'.\str_replace('"', '\\"', $value).'"';
    }

    /**
     * Refuse every operation when no corpus is configured.
     */
    private function assertActive(): void {
        if (!$this->isActive()) {
            throw new \RuntimeException('No docs corpus is configured on this host.');
        }
    }
}
