<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\ControlledDocumentInterface;
use Nvoos\Core\Domain\Contract\DocsCorpusInterface;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Qms\DocumentLifecycle;

/**
 * Controlled documents over the docs corpus — Proposal 164 §164.37.
 *
 * The corpus from §164.34 already owns the path guard, the extension allowlist
 * and the frontmatter reader/writer, so this composes it rather than re-deriving
 * any of that. What it adds is the two things a corpus does not have:
 *
 *  - **a controlled state** in `qms_status`, validated against
 *    {@see DocumentLifecycle}, and
 *  - **an append-only audit trail**, one JSONL file per document under the audit
 *    root.
 *
 * ## Why `qms_status` and not `status`
 *
 * `archive_documents` (§164.35) already writes `status: archived`, which means
 * "withdrawn from the content corpus". A controlled record's lifecycle is a
 * different question — an *approved* document can be archived as content and
 * still be an approved record. Sharing one key would make the two subsystems
 * overwrite each other's meaning, so this one is namespaced.
 *
 * ## The audit trail
 *
 * Every entry is one JSON object on one line, appended with `FILE_APPEND |
 * LOCK_EX`. There is no rewrite path, no update and no delete: §11.10(e) requires
 * that changes "shall not obscure previously recorded information", and a trail
 * you can edit proves nothing. `audit()` only ever reads.
 *
 * Each line carries `event`, `at` (ISO 8601 UTC), `actor`, `from`, `to`,
 * `meaning`, `reason` and `revision` as applicable — the Part 11 §11.50 shape,
 * where a signature must show who, when and *why*.
 *
 * ## What this is not
 *
 * There is no re-authentication ceremony, so a signature here is an **attributed
 * action**, not a verified electronic signature under §11.200, and nothing in the
 * payload claims otherwise.
 *
 * @package Nvoos\Laravel
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */
final class FilesystemControlledDocuments implements ControlledDocumentInterface
{
    /** The frontmatter key holding the lifecycle state. */
    private const STATUS_KEY = 'qms_status';

    /** The most entries `audit()` will return in one call. */
    private const MAX_AUDIT = 500;

    /**
     * @param DocsCorpusInterface $corpus    The corpus the documents live in.
     * @param string              $auditRoot Directory for the JSONL trails; '' disables the register.
     */
    public function __construct(
        private readonly DocsCorpusInterface $corpus,
        private readonly string $auditRoot,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isActive(): bool {
        return '' !== \trim($this->auditRoot) && $this->corpus->isActive();
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $path, string $content, array $meta = array()): array {
        $this->assertActive();

        $title = isset($meta['title']) && \is_string($meta['title']) ? $meta['title'] : '';
        $owner = isset($meta['owner']) && \is_numeric($meta['owner']) ? (int) $meta['owner'] : 0;
        $revision = isset($meta['revision']) && \is_string($meta['revision']) ? $meta['revision'] : 'A';
        $due = isset($meta['review_due']) && \is_string($meta['review_due']) ? $meta['review_due'] : '';

        $written = $this->corpus->write($path, $content, '' !== $title ? array('title' => $title) : array());

        $fields = array(
            self::STATUS_KEY => DocumentLifecycle::DRAFT,
            'qms_revision' => $revision,
            'qms_owner' => $owner,
        );

        if ('' !== $due) {
            $fields['qms_review_due'] = $due;
        }

        $merged = $this->corpus->setFields($written['path'], $fields);

        $this->append($written['path'], array(
            'event' => 'created',
            'at' => $this->now(),
            'actor' => $owner,
            'from' => '',
            'to' => DocumentLifecycle::DRAFT,
            'revision' => $revision,
        ));

        return array(
            'path' => $merged['path'],
            'title' => $merged['title'],
            'status' => DocumentLifecycle::DRAFT,
            'revision' => $revision,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function transition(string $path, string $to, array $meta = array()): array {
        $this->assertActive();

        $to = \strtolower(\trim($to));

        if (!DocumentLifecycle::isState($to)) {
            throw new \InvalidArgumentException(\sprintf(
                '"%s" is not a lifecycle state. One of: %s.',
                $to,
                \implode(', ', DocumentLifecycle::STATES),
            ));
        }

        $current = $this->status($path);
        $from = $current['status'];

        if ($from === $to) {
            throw new \InvalidArgumentException(\sprintf('The document is already in "%s".', $to));
        }

        if (!DocumentLifecycle::canTransition($from, $to)) {
            throw new \InvalidArgumentException(\sprintf(
                '"%s" cannot move to "%s"%s.',
                $from,
                $to,
                array() === DocumentLifecycle::sourcesFor($to)
                    ? ''
                    : sprintf('; only %s may', \implode(' or ', DocumentLifecycle::sourcesFor($to))),
            ));
        }

        $reason = isset($meta['reason']) && \is_string($meta['reason']) ? \trim($meta['reason']) : '';
        $actor = isset($meta['user_id']) && \is_numeric($meta['user_id']) ? (int) $meta['user_id'] : 0;
        $at = $this->now();

        $fields = array(self::STATUS_KEY => $to);

        if (DocumentLifecycle::OBSOLETE === $to || DocumentLifecycle::SUPERSEDED === $to) {
            $fields['qms_withdrawn_at'] = $at;
        }

        if (DocumentLifecycle::RELEASED === $to) {
            $fields['qms_released_at'] = $at;
        }

        if (isset($meta['superseded_by']) && \is_string($meta['superseded_by']) && '' !== \trim($meta['superseded_by'])) {
            $fields['qms_superseded_by'] = $meta['superseded_by'];
        }

        if (isset($meta['review_due']) && \is_string($meta['review_due']) && '' !== \trim($meta['review_due'])) {
            $fields['qms_review_due'] = $meta['review_due'];
        }

        // A release clears the due date: the document has just been reviewed as
        // part of approval, so a stale date would misreport the next obligation.
        if (DocumentLifecycle::RELEASED === $to) {
            $fields['qms_review_due'] = null;
        }

        $merged = $this->corpus->setFields($path, $fields);

        $this->append($merged['path'], array(
            'event' => 'transition',
            'at' => $at,
            'actor' => $actor,
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
            'revision' => $current['revision'],
        ));

        return array(
            'path' => $merged['path'],
            'title' => $merged['title'],
            'from' => $from,
            'to' => $to,
            'at' => $at,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function sign(string $path, array $meta = array()): array {
        $this->assertActive();

        $meaning = isset($meta['meaning']) && \is_string($meta['meaning']) ? \strtolower(\trim($meta['meaning'])) : '';

        if (!DocumentLifecycle::isMeaning($meaning)) {
            throw new \InvalidArgumentException(\sprintf(
                '"%s" is not a signature meaning. One of: %s.',
                $meaning,
                \implode(', ', DocumentLifecycle::MEANINGS),
            ));
        }

        $current = $this->status($path);
        $actor = isset($meta['user_id']) && \is_numeric($meta['user_id']) ? (int) $meta['user_id'] : 0;
        $comment = isset($meta['comment']) && \is_string($meta['comment']) ? \trim($meta['comment']) : '';
        $at = $this->now();

        $this->append($current['path'], array(
            'event' => 'signed',
            'at' => $at,
            'actor' => $actor,
            'from' => $current['status'],
            'to' => $current['status'],
            'meaning' => $meaning,
            'comment' => $comment,
            'revision' => $current['revision'],
        ));

        return array(
            'path' => $current['path'],
            'meaning' => $meaning,
            'signed_at' => $at,
            'signed_by' => $actor,
            'revision' => $current['revision'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function scheduleReview(string $path, string $due, array $meta = array()): array {
        $this->assertActive();

        $due = \trim($due);

        // A date, not a promise: an unparseable value would make the "review
        // overdue" question unanswerable, so it is refused rather than stored.
        if (1 !== \preg_match('/^\d{4}-\d{2}-\d{2}/', $due)) {
            throw new \InvalidArgumentException('The review date must be ISO 8601, e.g. 2027-03-31.');
        }

        $current = $this->status($path);
        $actor = isset($meta['user_id']) && \is_numeric($meta['user_id']) ? (int) $meta['user_id'] : 0;
        $at = $this->now();

        $merged = $this->corpus->setFields($current['path'], array('qms_review_due' => $due));

        $this->append($merged['path'], array(
            'event' => 'review_scheduled',
            'at' => $at,
            'actor' => $actor,
            'from' => $current['status'],
            'to' => $current['status'],
            'review_due' => $due,
            'comment' => isset($meta['comment']) && \is_string($meta['comment']) ? \trim($meta['comment']) : '',
            'revision' => $current['revision'],
        ));

        return array(
            'path' => $merged['path'],
            'title' => $merged['title'],
            'review_due' => $due,
            'status' => $current['status'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function audit(string $path, int $limit = 100): array {
        $this->assertActive();

        $document = $this->corpus->read($path);
        $entries = $this->readTrail($document['path']);
        $limit = \max(1, \min(self::MAX_AUDIT, $limit));

        return array(
            'entries' => \array_slice($entries, 0, $limit),
            'total' => \count($entries),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function list(string $status = '', int $limit = 100): array {
        $this->assertActive();

        $status = \strtolower(\trim($status));

        if ('' !== $status && !DocumentLifecycle::isState($status)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a lifecycle state.', $status));
        }

        $corpus = $this->corpus->list('', 500);
        $documents = array();

        foreach ($corpus['documents'] as $entry) {
            try {
                $row = $this->status($entry['path']);
            } catch (\Throwable $e) {
                continue;
            }

            // A document with no lifecycle field is not controlled; listing it
            // would claim a record exists where none was ever created.
            if ('' === $row['status']) {
                continue;
            }

            if ('' !== $status && $row['status'] !== $status) {
                continue;
            }

            $documents[] = $row;
        }

        $limit = \max(1, \min(self::MAX_AUDIT, $limit));

        return array(
            'documents' => \array_slice($documents, 0, $limit),
            'total' => \count($documents),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function status(string $path): array {
        $this->assertActive();

        $document = $this->corpus->read($path);
        $fields = $this->fieldsOf($document['content']);

        $signed = 0;

        foreach ($this->readTrail($document['path']) as $entry) {
            if ('signed' === ($entry['event'] ?? '')) {
                $signed++;
            }
        }

        return array(
            'path' => $document['path'],
            'title' => $document['title'],
            'status' => \strtolower($fields[self::STATUS_KEY] ?? ''),
            'revision' => $fields['qms_revision'] ?? 'A',
            'review_due' => $fields['qms_review_due'] ?? '',
            'signatures' => $signed,
        );
    }

    /**
     * Read a document's trail file, oldest first.
     *
     * Shared by `audit()` and `status()` so neither can call the other — a
     * signature count that went through the public reader would recurse, because
     * both of them start by resolving the document.
     *
     * @return list<array<string, mixed>>
     */
    private function readTrail(string $path): array {
        $file = $this->trailFile($path);

        if (!\is_file($file)) {
            return array();
        }

        $raw = \file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
        $entries = array();

        foreach ($raw as $line) {
            $decoded = \json_decode($line, true);

            if (\is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * The frontmatter fields of a document, lower-cased.
     *
     * A compact re-read rather than a corpus method: `setFields()` returns the
     * merged map, but `status()` and `list()` need to *read* one, and adding a
     * second reader to the interface for four keys would widen it for no gain.
     *
     * @return array<string, string>
     */
    private function fieldsOf(string $content): array {
        if (1 !== \preg_match('/^---\R(.*?)\R---\R/s', $content, $match)) {
            return array();
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

                $fields[\strtolower($pair[1])] = $value;
            }
        }

        return $fields;
    }

    /**
     * Append one event to a document's trail.
     *
     * `FILE_APPEND | LOCK_EX` is the whole concurrency story: two processes
     * appending at once interleave lines only if the write is not locked, and a
     * locked append of a single short line is atomic on the platforms this ships
     * to. A rewritten trail is not offered at all.
     *
     * @param array<string, mixed> $event
     */
    private function append(string $path, array $event): void {
        $file = $this->trailFile($path);
        $directory = \dirname($file);

        if (!\is_dir($directory) && !@\mkdir($directory, 0775, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Could not create the audit directory.');
        }

        $line = \json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $line) {
            throw new \RuntimeException('Could not encode the audit entry.');
        }

        if (false === \file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX)) {
            throw new \RuntimeException('Could not append to the audit trail.');
        }
    }

    /**
     * The trail file for a document.
     *
     * Named by a hash of the corpus-relative path so a nested document cannot
     * collide with another, and so the audit directory never has to mirror the
     * corpus tree.
     */
    private function trailFile(string $path): string {
        return \rtrim($this->auditRoot, '/\\').'/'.\sha1($path).'.jsonl';
    }

    /**
     * An ISO 8601 UTC timestamp with seconds precision.
     */
    private function now(): string {
        return \gmdate('c');
    }

    /**
     * Refuse every operation when no register is configured.
     */
    private function assertActive(): void {
        if (!$this->isActive()) {
            throw new \RuntimeException('No controlled-document register is configured on this host.');
        }
    }
}
