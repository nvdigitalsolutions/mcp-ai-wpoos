<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\PdfTextExtractorInterface;

/**
 * PDF text extraction over the media worker — Proposal 164 §164.4.3.
 *
 * `POST /api/pdf/extract` is `pdf-parse` behind a multipart upload. It answers
 * `{ success, text, pages, info }`; the worker's own `output_path` is ignored
 * because it names a path on the worker's filesystem, which this host cannot
 * read.
 *
 * The page limit travels as `maxPages` — the worker's spelling, not `max_pages`.
 * Sending the snake_case form would be silently ignored, and a caller who asked
 * for one page would get the whole document.
 */
final class MediaWorkerPdfTextExtractor implements PdfTextExtractorInterface
{
    public function __construct(private readonly MediaWorkerClient $worker)
    {
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
    public function extract(string $localPath, int $maxPages = 0): array
    {
        if ('' === trim($localPath)) {
            throw new \InvalidArgumentException('A PDF path is required.');
        }

        if (! is_file($localPath)) {
            throw new \InvalidArgumentException('The PDF does not exist: '.$localPath);
        }

        $fields = $maxPages > 0 ? ['maxPages' => $maxPages] : [];

        $body = $this->worker->uploadFile(
            '/api/pdf/extract',
            $localPath,
            basename($localPath),
            $fields,
            $this->timeout(),
        );

        if (! isset($body['text']) || ! is_string($body['text'])) {
            throw new \RuntimeException('The media worker returned no text for this PDF.');
        }

        return [
            'text' => $body['text'],
            'pages' => (int) ($body['pages'] ?? 0),
            'info' => is_array($body['info'] ?? null) ? $body['info'] : [],
        ];
    }

    private function timeout(): int
    {
        return max(1, (int) config('oos.media_worker.extract_timeout', 120));
    }
}
