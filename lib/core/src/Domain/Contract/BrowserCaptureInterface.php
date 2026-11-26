<?php

/**
 * Render one public page to one image, and hand the bytes back.
 *
 * Proposal 164 §164.53. The WordPress tool (`capture_webpage_screenshot`) sits on
 * a Playwright service and falls back to WordPress.com's mshots thumbnail API.
 * This contract is the first half only: there is no mshots equivalent here, and
 * a silent downgrade to a third-party thumbnailer would be a different picture
 * from the one that was asked for.
 *
 * ## Why one shot and not a session
 *
 * `web_browser` is still deferred (§164.51) because six of its seven actions act
 * on a page between calls — `click`, `type`, `submit` and `extract` need the page
 * to stay alive, and the worker's browser route is a one-shot render. This
 * contract is deliberately the same shape, URL in and bytes out, so it maps onto
 * the route that exists rather than pretending one exists that does not.
 *
 * ## Why bytes, not a path
 *
 * The worker writes each render to its own volume and answers `output_path`,
 * which this host cannot read. §164.51 recorded that as a delivery gap; carrying
 * the bytes is the delivery. The tool persists them through the file registry
 * (§162), so what a caller receives is a stored file with an id, a slug and a URL
 * rather than a payload it has to write somewhere itself.
 *
 * @package Nvoos\Core
 * @since   2.7.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface BrowserCaptureInterface
{
    /**
     * Whether a capture engine is bound on this host.
     */
    public function isAvailable(): bool;

    /**
     * Render one page and return its bytes.
     *
     * @param string $url The public URL to render.
     * @param array{width?: int, height?: int, full_page?: bool, format?: string, quality?: int, timeout?: int} $options
     *
     * @return array{b64: string, mime_type: string, size_bytes: int, format: string}
     *
     * @throws \RuntimeException When the engine is unconfigured, unreachable, refuses
     *                           the URL, or answers without bytes.
     */
    public function capture(string $url, array $options = array()): array;
}
