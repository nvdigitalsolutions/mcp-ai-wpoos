<?php

/**
 * A corpus of authored documents on disk.
 *
 * The five `docs_hub_*` tools are readers and writers of a flat corpus of
 * Markdown/plain-text files with YAML frontmatter — a docs site's source, not a
 * database. Proposal 164 §164.34. Keeping the corpus behind a contract means the
 * tools never learn where it lives, and the path rules live in one place instead
 * of five.
 *
 * ## Paths are relative and already validated by the implementation
 *
 * Every method takes a corpus-relative path such as `guides/install.md`. The
 * implementation is responsible for refusing traversal, absolute paths, drive
 * letters and symlinked escapes — a tool that re-implemented those checks would
 * be the fifth copy of a rule that only needs to be right once.
 *
 * A refused path throws `\InvalidArgumentException`; a valid path that names
 * nothing throws {@see NotFoundException}. The two are different failures and a
 * caller has to be able to tell them apart: one is a bad request, the other is an
 * empty shelf.
 *
 * An implementation should also report a path that *resolves* outside the corpus
 * as not-found rather than refused, so the corpus cannot be used to probe for
 * files elsewhere. Writes are the exception: a write that cannot be placed inside
 * the corpus is refused, because there is nothing to disclose.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Error\NotFoundException;

interface DocsCorpusInterface
{
    /**
     * Whether a corpus is configured on this host.
     *
     * Asked before every call so a host without a corpus answers a named
     * capability error instead of an empty listing that looks like "no documents
     * yet" (§164.4.8).
     */
    public function isActive(): bool;

    /**
     * Documents in the corpus, newest path order, optionally under a prefix.
     *
     * @param string $subdirectory Corpus-relative prefix, or '' for the root.
     * @param int    $limit        Maximum documents to return; the total is
     *                             always reported regardless.
     *
     * @return array{documents: list<array{path: string, title: string, size: int, modified: int}>, total: int}
     *
     * @throws \InvalidArgumentException When the prefix is refused.
     */
    public function list(string $subdirectory = '', int $limit = 100): array;

    /**
     * One document, with its frontmatter title extracted.
     *
     * @return array{path: string, title: string, content: string, size: int, modified: int}
     *
     * @throws \InvalidArgumentException When the path is refused.
     * @throws NotFoundException         When the path names nothing.
     */
    public function read(string $path): array;

    /**
     * Write a document, creating parent directories as needed.
     *
     * Frontmatter is prepended when `title`, `slug` or `order` is supplied;
     * without them the content is written verbatim, so a caller can manage its
     * own frontmatter.
     *
     * @param array{title?: string, slug?: string, order?: int} $meta
     *
     * @return array{path: string, title: string, size: int, created: bool}
     *
     * @throws \InvalidArgumentException When the path is refused or the body
     *                                   exceeds the corpus size cap.
     */
    public function write(string $path, string $content, array $meta = array()): array;

    /**
     * Delete a document.
     *
     * @return bool True when a file was removed.
     *
     * @throws \InvalidArgumentException When the path is refused.
     * @throws NotFoundException         When the path names nothing.
     */
    public function delete(string $path): bool;

    /**
     * Documents whose retention has run out, as of a date.
     *
     * ISO 15489 counts retention **from a trigger event**, not from creation, so a
     * document's expiry is either stated outright (`expires_at`) or derived from a
     * trigger date plus a period (`retention_months`). Both are reported, with
     * `source` saying which, because a derived date an auditor cannot reconstruct
     * is worth less than one they can.
     *
     * This is a **report**, not a disposal. ISO 15489 keeps disposal approval,
     * execution and verification apart, and a query that destroyed records as a
     * side effect would collapse all three into one unreviewable step.
     *
     * @param string $asOf ISO 8601 date the question is asked at, e.g. "2027-01-01".
     * @param int    $limit Maximum documents to return.
     *
     * @return array{documents: list<array{path: string, title: string, expires_at: string, trigger: string, period_months: int, disposition: string, source: string}>, total: int}
     */
    public function expiring(string $asOf, int $limit = 100): array;

    /**
     * Merge fields into a document's frontmatter, leaving the body untouched.
     *
     * The primitive behind every lifecycle write: an implementation reads the
     * existing block, merges the given keys, and writes both back. A `null`
     * value removes a key, which is how a caller clears a due date.
     *
     * Fields the caller did not mention survive, because a lifecycle write that
     * dropped a document's title would be a rewrite rather than a record.
     *
     * @param array<string, string|int|null> $fields
     *
     * @return array{path: string, fields: array<string, string>, title: string}
     *
     * @throws \InvalidArgumentException When the path is refused.
     * @throws NotFoundException         When the path names nothing.
     */
    public function setFields(string $path, array $fields): array;

    /**
     * Mark a document obsolete without removing it.
     *
     * ISO 9001 §7.5.3 requires obsolete documented information to be clearly
     * marked and prevented from unintended use, and retained so it can still
     * answer an audit. So this is a **state**, not a delete: the body is
     * untouched and the frontmatter gains `status: archived`, `archived_at`,
     * `archived_by` and `archive_reason`.
     *
     * Archiving an already-archived document changes nothing and reports
     * `already_archived`. Rewriting the original timestamp would obscure when the
     * document was actually withdrawn, which is the one thing an archive record
     * exists to prove.
     *
     * @param array{reason?: string, user_id?: int} $meta
     *
     * @return array{path: string, title: string, status: string, archived_at: string, archived_by: int, archive_reason: string, already_archived: bool}
     *
     * @throws \InvalidArgumentException When the path is refused.
     * @throws NotFoundException         When the path names nothing.
     */
    public function archive(string $path, array $meta = array()): array;

    /**
     * Scan the corpus and report what it holds.
     *
     * The WordPress tool queues a rebuild job that regenerates the docs-hub
     * plugin's site index. There is no separate index here — the filesystem is
     * authoritative — so this reports the scan rather than pretending to rebuild
     * something that does not exist. `duplicates` names documents that would
     * collide on title, which is the one thing a corpus can carry that a plain
     * listing hides.
     *
     * @return array{indexed: int, duplicates: list<string>, unreadable: list<string>}
     */
    public function rebuild(): array;

    /**
     * The largest body this corpus accepts, in bytes.
     */
    public function maxBytes(): int;

    /**
     * File extensions the corpus serves.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array;
}
