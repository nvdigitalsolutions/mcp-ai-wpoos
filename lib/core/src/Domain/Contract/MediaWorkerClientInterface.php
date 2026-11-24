<?php

/**
 * The media worker, as `lib/core` is allowed to see it.
 *
 * Proposal 164 §164.4.2. The worker is not a new component — it is the sidecar
 * the WordPress plugin has called since Proposal 025 — so this contract mirrors
 * `WP_MCP_AI_Media_Worker_Config` rather than inventing a second dialect:
 *
 * | Concern | WordPress | here |
 * |---|---|---|
 * | Base URL | `WP_MEDIA_WORKER_URL` → option | `MEDIA_WORKER_URL` → config |
 * | Token | constant → per-blog option → site option → `wp_hash( home_url() )` | `MEDIA_WORKER_TOKEN` → config, **no salted fallback** |
 * | Auth headers | `X-Site-Token`, `X-Site-Url` | same headers, same meaning |
 * | On/off switch | URL configured | URL configured |
 *
 * The salted fallback is deliberately absent: a Laravel host has no WordPress
 * auth salts, and inventing one would silently mint a token no worker accepts.
 * A missing token is a named absence, not a guessed value.
 *
 * ## Why this is narrower than a transport
 *
 * The proposal sketched a `request( $endpoint, $method, $body, $timeout )`
 * method. It is not here, because nothing would call it: every domain adapter
 * (`MediaWorkerHtmlToPdf`, `MediaWorkerImageProcessing`, the document and video
 * adapters to come) owns its own endpoint and request shape, and a generic
 * passthrough would be a public surface with no reader — the same defect as a
 * rendered control with no handler. When something genuinely needs to drive an
 * arbitrary endpoint, the method can be added without breaking an implementer
 * that does not yet have it.
 *
 * ## What a caller may conclude
 *
 * `capabilities()` answers *what this worker can do*, once. It is the
 * `/api/health/full` matrix, not a per-call discovery, so a tool can refuse
 * with a named reason before spending a request — and diagnostics can report
 * `video: unavailable` instead of discovering the truth by failing.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface MediaWorkerClientInterface
{
    /**
     * The WordPress error code for "no worker is configured".
     *
     * Carried here so both runtimes log the same string. WordPress returns
     * `WP_Error( 'wp_mcp_ai_worker_not_configured' )`; this runtime reports the
     * same code through a tool's capability error rather than inventing a
     * near-miss a log search would miss.
     */
    public const ERROR_NOT_CONFIGURED = 'wp_mcp_ai_worker_not_configured';

    /**
     * Whether this host has a worker to talk to.
     *
     * Config presence, not liveness: a health probe on every capability check
     * would add a network round-trip to a question asked per call. Liveness is
     * `health()`.
     */
    public function isConfigured(): bool;

    /**
     * The worker's own liveness.
     *
     * Never throws: a timeout, a DNS failure and a refused connection all mean
     * "not available", which is a fact the command centre must still be able to
     * print beside the local numbers.
     *
     * @return array{available: bool, version: string|null, uptime: float|null}
     */
    public function health(): array;

    /**
     * The worker's own capability matrix, from `GET /api/health/full`.
     *
     * Keys are the worker's (`pdf_generation`, `video_processing`,
     * `document_ocr`, …), values are availability. An empty array means "no
     * worker answered" — either unconfigured, unreachable, or answering an
     * error — and must not be read as "the worker can do nothing".
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;
}
