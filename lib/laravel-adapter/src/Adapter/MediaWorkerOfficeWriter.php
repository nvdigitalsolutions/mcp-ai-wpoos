<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\OfficeWriterInterface;

/**
 * Word and Excel generation over the media worker — Proposal 164 §164.4.3.
 *
 * These two routes are JSON-in/bytes-out (`docx` and `exceljs` behind them), so
 * they use `postJson()` rather than the multipart family. The payloads are the
 * worker's own dialect:
 *
 *  - `/api/document/word` reads `content` — `title`, `paragraphs`, `table`,
 *    `header`, `footer`. It reads no `options`, which is why `docx()` takes none.
 *  - `/api/document/excel` reads `sheets` and `options.creator` / `options.title`.
 *
 * MIME types are the OOXML ones rather than a generic
 * `application/octet-stream`, because the file registry sniffs the bytes and a
 * hint that disagrees with them is a refusal waiting to happen.
 */
final class MediaWorkerOfficeWriter implements OfficeWriterInterface
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

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
    public function docx(array $content): array
    {
        if ([] === $content) {
            throw new \InvalidArgumentException('Document content is required.');
        }

        $body = $this->worker->postJson(
            '/api/document/word',
            ['content' => $content],
            $this->timeout(),
        );

        return $this->written($body, 'docx', 'docx', self::DOCX_MIME, MediaWorkerOutput::fingerprint($content));
    }

    /**
     * {@inheritDoc}
     */
    public function xlsx(array $sheets, array $options = []): array
    {
        if ([] === $sheets) {
            throw new \InvalidArgumentException('At least one sheet is required.');
        }

        $body = $this->worker->postJson(
            '/api/document/excel',
            [
                'sheets' => array_values($sheets),
                'options' => $this->properties($options),
            ],
            $this->timeout(),
        );

        return $this->written(
            $body,
            'xlsx',
            'xlsx',
            self::XLSX_MIME,
            MediaWorkerOutput::fingerprint([$sheets, $this->properties($options)])
        );
    }

    /**
     * Turn a `data_base64` response into a file and the shape the tools expect.
     *
     * @param array<string, mixed> $body
     *
     * @return array{path: string, mime_type: string, bytes: int}
     */
    private function written(
        array $body,
        string $prefix,
        string $extension,
        string $mimeType,
        string $fingerprint,
    ): array {
        $path = MediaWorkerOutput::persist($body['data_base64'] ?? null, $prefix, $extension, $fingerprint, 'document');

        return [
            'path' => $path,
            'mime_type' => $mimeType,
            'bytes' => (int) filesize($path),
        ];
    }

    /**
     * The two workbook properties the excel route reads, and nothing else.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private function properties(array $options): array
    {
        $properties = [];

        foreach (['creator', 'title'] as $key) {
            $value = $options[$key] ?? null;

            if (is_string($value) && '' !== trim($value)) {
                $properties[$key] = trim($value);
            }
        }

        return $properties;
    }

    private function timeout(): int
    {
        return max(1, (int) config('oos.media_worker.document_timeout', 120));
    }
}
