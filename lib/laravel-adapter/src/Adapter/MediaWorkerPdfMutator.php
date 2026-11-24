<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\PdfMutatorInterface;

/**
 * PDF merge and watermark over the media worker — Proposal 164 §164.4.3.
 *
 * Both routes take multipart uploads and answer `{ data_base64, size, pages }`:
 *
 *  - `POST /api/pdf/merge` reads `files` / `files[n]` parts and sorts them by
 *    index, so the order is carried in the field name. That is why the merge
 *    goes through `uploadFiles()` rather than `uploadFile()`: a merge that
 *    reordered pages would be silent corruption, not a visible failure.
 *  - `POST /api/pdf/watermark` reads `file` plus a `watermark` field. It draws
 *    the mark centred and rotated −45° by default, and honours optional
 *    `opacity` and `position` when the worker advertises `pdf_watermark_style`.
 *    On a worker without that capability the style cannot be honoured, which
 *    `watermarkStyleSupported()` reports rather than silently stamping the
 *    default mark under a different name.
 */
final class MediaWorkerPdfMutator implements PdfMutatorInterface
{
    public function __construct(
        private readonly MediaWorkerClient $worker,
        private readonly MediaWorkerCapabilityProbe $probe,
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
    public function merge(array $localPaths): array
    {
        $paths = [];

        foreach ($localPaths as $path) {
            if (is_string($path) && '' !== trim($path)) {
                $paths[] = $path;
            }
        }

        if (count($paths) < 2) {
            throw new \InvalidArgumentException('At least two PDFs are required to merge.');
        }

        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new \InvalidArgumentException('The PDF does not exist: '.$path);
            }
        }

        $body = $this->worker->uploadFiles(
            '/api/pdf/merge',
            $paths,
            [],
            $this->timeout(),
        );

        return $this->written($body, 'merged', 'merged'."\0".implode("\0", $paths));
    }

    /**
     * {@inheritDoc}
     */
    public function watermarkStyleSupported(): bool
    {
        if ( ! $this->worker->isConfigured() ) {
            return false;
        }

        return $this->probe->report()->supports( 'pdf_watermark_style' );
    }

    /**
     * {@inheritDoc}
     */
    public function watermark(string $localPath, string $text, array $options = array()): array
    {
        if ( '' === trim( $localPath ) ) {
            throw new \InvalidArgumentException( 'A PDF path is required.' );
        }

        if ( '' === trim( $text ) ) {
            throw new \InvalidArgumentException( 'Watermark text is required.' );
        }

        if ( ! is_file( $localPath ) ) {
            throw new \InvalidArgumentException( 'The PDF does not exist: '.$localPath );
        }

        $fields = array( 'watermark' => $text );

        // Only send a style the caller actually asked for. Sending the defaults
        // would change the bytes for every existing caller on a worker that
        // treats an explicit `0.3` differently from an absent field.
        $opacity = $options['opacity'] ?? null;

        if ( is_numeric( $opacity ) ) {
            $fields['opacity'] = (string) max( 0, min( 1, (float) $opacity ) );
        }

        $position = strtolower( trim( (string) ( $options['position'] ?? '' ) ) );

        if ( in_array( $position, array( 'diagonal', 'center', 'top', 'bottom' ), true ) ) {
            $fields['position'] = $position;
        }

        $body = $this->worker->uploadFile(
            '/api/pdf/watermark',
            $localPath,
            basename( $localPath ),
            $fields,
            $this->timeout(),
        );

        return $this->written( $body, 'watermarked', 'watermarked'."\0".$localPath."\0".$text.'\0'.implode( '\0', $fields ) );
    }

    /**
     * Turn a `data_base64` response into a file and the shape the tools expect.
     *
     * @param array<string, mixed> $body
     *
     * @return array{path: string, mime_type: string, bytes: int, pages: int}
     */
    private function written(array $body, string $prefix, string $fingerprint): array
    {
        $path = MediaWorkerOutput::persist($body['data_base64'] ?? null, $prefix, 'pdf', $fingerprint, 'PDF');

        return [
            'path' => $path,
            'mime_type' => 'application/pdf',
            'bytes' => (int) filesize($path),
            // The page count is what proves a merge kept every page; when the
            // worker omits it the tool still returns the file, so this reports
            // 0 rather than inventing one.
            'pages' => (int) ($body['pages'] ?? 0),
        ];
    }

    private function timeout(): int
    {
        return max(1, (int) config('oos.media_worker.pdf_timeout', 120));
    }
}
