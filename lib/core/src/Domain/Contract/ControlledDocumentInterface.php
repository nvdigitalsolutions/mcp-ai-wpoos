<?php

/**
 * Controlled documents: a lifecycle, signatures and an append-only audit trail.
 *
 * Proposal 164 §164.37. The five `docs_hub_*` tools treat the corpus as content;
 * these treat it as *records*. The difference is the three things 21 CFR Part 11
 * and ISO 9001 ask for and a plain file store cannot provide:
 *
 *  - **A controlled state.** Only a released document is current (ISO 9001
 *    §7.5.3), and the transitions between states are a closed table
 *    ({@see DocumentLifecycle}).
 *  - **An attributable signature.** Who signed, when, and — per §11.50 — *what
 *    the signature means*.
 *  - **An audit trail that cannot be rewritten.** §11.10(e) requires a
 *    computer-generated, time-stamped trail, and that changes "shall not obscure
 *    previously recorded information".
 *
 * ## What this contract does not claim
 *
 * Part 11 §11.100/§11.200 requires signatures to be unique, non-transferable and
 * verified by **two distinct components** — an identification plus a re-entered
 * credential. An implementation here has a user id and no re-authentication
 * ceremony, so what it records is an **attributed action**, not a verified
 * electronic signature. Implementations must say so in their own documentation
 * and must not describe their records as Part 11-compliant.
 *
 * ## The audit trail is append-only, and that is enforced, not promised
 *
 * There is no `update` and no `delete` for an audit entry. A trail that can be
 * edited after the fact proves nothing, so the contract simply does not offer the
 * operation — the same reasoning that made archiving a state rather than a
 * deletion in §164.36.
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

interface ControlledDocumentInterface
{
    /**
     * Whether a register is configured on this host.
     */
    public function isActive(): bool;

    /**
     * Create a controlled document in `draft`.
     *
     * @param array{title?: string, owner?: int, revision?: string, review_due?: string} $meta
     *
     * @return array{path: string, title: string, status: string, revision: string}
     *
     * @throws \InvalidArgumentException When the path is refused.
     */
    public function create(string $path, string $content, array $meta = array()): array;

    /**
     * Move a document to another state.
     *
     * The implementation refuses a transition the machine does not allow and
     * appends the outcome to the audit trail — including the reason, when one is
     * given, because a rejection that records no reason is not auditable.
     *
     * @param array{reason?: string, user_id?: int, superseded_by?: string, review_due?: string} $meta
     *
     * @return array{path: string, title: string, from: string, to: string, at: string}
     *
     * @throws \InvalidArgumentException When the path is refused or the transition is not allowed.
     * @throws NotFoundException         When the path names nothing.
     */
    public function transition(string $path, string $to, array $meta = array()): array;

    /**
     * Record a signature against a document.
     *
     * `meaning` is required, so the argument is too — an audit entry that records
     * no meaning is not auditable, and a default of `array()` would advertise an
     * omission the implementation has to refuse anyway.
     *
     * @param array{meaning: string, user_id?: int, comment?: string} $meta
     *
     * @return array{path: string, meaning: string, signed_at: string, signed_by: int, revision: string}
     *
     * @throws \InvalidArgumentException When the path is refused, or the meaning
     *                                   is not one of
     *                                   {@see \Nvoos\Core\Domain\Qms\DocumentLifecycle::MEANINGS}.
     * @throws NotFoundException         When the path names nothing.
     */
    public function sign(string $path, array $meta): array;

    /**
     * Set the date the next periodic review is due.
     *
     * Does not change the lifecycle state: a released document stays current while
     * a review is pending. It is a separate operation because ISO 9001 §7.5.2
     * expects review at planned intervals, and a pending review is not a state.
     *
     * @param array{user_id?: int, comment?: string} $meta
     *
     * @return array{path: string, title: string, review_due: string, status: string}
     *
     * @throws \InvalidArgumentException When the path is refused or the date is not ISO 8601.
     * @throws NotFoundException         When the path names nothing.
     */
    public function scheduleReview(string $path, string $due, array $meta = array()): array;

    /**
     * The document's audit trail, oldest first.
     *
     * @return array{entries: list<array<string, mixed>>, total: int}
     *
     * @throws \InvalidArgumentException When the path is refused.
     */
    public function audit(string $path, int $limit = 100): array;

    /**
     * Controlled documents, optionally filtered by state.
     *
     * @return array{documents: list<array<string, mixed>>, total: int}
     */
    public function list(string $status = '', int $limit = 100): array;

    /**
     * The current state of one document.
     *
     * @return array{path: string, title: string, status: string, revision: string, review_due: string, signatures: int}
     *
     * @throws \InvalidArgumentException When the path is refused.
     * @throws NotFoundException         When the path names nothing.
     */
    public function status(string $path): array;
}
