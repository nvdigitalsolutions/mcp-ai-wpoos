<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\BrowserCaptureInterface;

/**
 * Page screenshots over the media worker's browser route — Proposal 164 §164.53.
 *
 * `POST /api/browser/screenshot` is a Puppeteer render behind an SSRF guard. It
 * accepts `url` (or `html`) and `options` with `width`, `height`, `fullPage`,
 * `format`, `quality`, `clip` and `timeout`, and writes the image to the
 * worker's own volume. The `b64` field is the delivery half: a path on the
 * worker is not readable from this host, which is why §164.51 deferred the tool
 * rather than returning one.
 *
 * Two mappings are worth naming:
 *
 *  - The tool's `timeout` is seconds for the whole operation, while the route's
 *    `options.timeout` is the *navigation* budget in milliseconds. Both come
 *    from the one value, and the HTTP budget is that value plus headroom, so a
 *    slow page fails as a navigation timeout instead of a transport error.
 *  - The route treats `fullPage` as true unless it is exactly `false`. This
 *    adapter always sends the boolean, so the tool's default cannot drift from
 *    the worker's.
 */
final class MediaWorkerBrowserCapture implements BrowserCaptureInterface
{
    public function __construct(
        private readonly MediaWorkerClient $worker,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return $this->worker->isConfigured();
    }

    /**
     * {@inheritDoc}
     */
    public function capture(string $url, array $options = array()): array
    {
        $seconds = max(5, min(60, (int) ($options['timeout'] ?? 30)));
        $format  = 'jpeg' === ($options['format'] ?? 'png') ? 'jpeg' : 'png';

        $route = array(
            'fullPage' => (bool) ($options['full_page'] ?? true),
            'format'   => $format,
            'timeout'  => $seconds * 1000,
        );

        if (isset($options['width'], $options['height'])) {
            $route['width']  = (int) $options['width'];
            $route['height'] = (int) $options['height'];
        }

        // The route passes `quality` straight to Puppeteer, which rejects it for
        // PNG. Only JPEG carries it, which the tool has already enforced.
        if ('jpeg' === $format && isset($options['quality'])) {
            $route['quality'] = max(1, min(100, (int) $options['quality']));
        }

        $body = $this->worker->postJson(
            '/api/browser/screenshot',
            array(
                'url'     => $url,
                'options' => $route,
            ),
            $seconds + 15,
        );

        $b64 = isset($body['b64']) && \is_string($body['b64']) ? $body['b64'] : '';

        if ('' === $b64) {
            throw new \RuntimeException(
                'The media worker returned no screenshot bytes for ' . $url . '. The browser route predates the b64 field; update the worker.',
            );
        }

        return array(
            'b64'        => $b64,
            'mime_type'  => isset($body['mime']) && \is_string($body['mime']) && '' !== $body['mime']
                ? $body['mime']
                : 'image/' . $format,
            'size_bytes' => isset($body['size']) ? (int) $body['size'] : 0,
            'format'     => isset($body['format']) && \is_string($body['format']) ? $body['format'] : $format,
        );
    }
}
