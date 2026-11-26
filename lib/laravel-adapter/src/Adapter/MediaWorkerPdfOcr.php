<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\PdfOcrInterface;

/**
 * PDF OCR over the media worker — Proposal 164 §164.7.
 *
 * `POST /api/pdf/ocr` rasterises each page at `dpi` with pdfjs and recognises it
 * with tesseract.js, reusing one worker across pages. It exists because the
 * existing pieces could not be composed by a caller: `/api/pdf/render` returns
 * paths on the **worker's** filesystem, which the calling host cannot read.
 *
 * ## Why `isAvailable()` asks the capability matrix
 *
 * This route is newer than the PDF family it belongs to. A configured worker is
 * therefore *not* evidence that it can do this — the route may simply be absent.
 * `pdf_ocr` in `/api/health/full` is the authority, and asking it is what turns
 * a version skew into a named refusal instead of a 404 mid-run.
 *
 * @package Nvoos\Laravel
 * @since   1.1.0
 * @license MIT
 */
final class MediaWorkerPdfOcr implements PdfOcrInterface
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
        if ( ! $this->worker->isConfigured() ) {
            return false;
        }

        return $this->probe->report()->supports( 'pdf_ocr' );
    }

    /**
     * {@inheritDoc}
     */
    public function recognize(string $localPath, array $options = array()): array
    {
        if ( '' === trim( $localPath ) ) {
            throw new \InvalidArgumentException( 'A PDF path is required.' );
        }

        if ( ! is_file( $localPath ) ) {
            throw new \InvalidArgumentException( 'The PDF does not exist: '.$localPath );
        }

        $fields = array();

        $dpi = (int) ( $options['dpi'] ?? 0 );

        if ( $dpi > 0 ) {
            $fields['dpi'] = $dpi;
        }

        $maxPages = (int) ( $options['max_pages'] ?? 0 );

        if ( $maxPages > 0 ) {
            $fields['maxPages'] = $maxPages;
        }

        $language = trim( (string) ( $options['language'] ?? '' ) );

        if ( '' !== $language ) {
            $fields['language'] = $language;
        }

        $pages = $options['pages'] ?? null;

        if ( is_array( $pages ) && array() !== $pages ) {
            $fields['pages'] = implode( ',', array_map( 'intval', $pages ) );
        }

        $body = $this->worker->uploadFile(
            '/api/pdf/ocr',
            $localPath,
            basename( $localPath ),
            $fields,
            $this->timeout(),
        );

        if ( ! isset( $body['text'] ) || ! is_string( $body['text'] ) ) {
            throw new \RuntimeException( 'The media worker returned no text for this PDF.' );
        }

        $recognised = array();

        foreach ( (array) ( $body['pages'] ?? array() ) as $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }

            $recognised[] = array(
                'page'       => (int) ( $page['page'] ?? 0 ),
                'text'       => is_string( $page['text'] ?? null ) ? $page['text'] : '',
                'confidence' => (float) ( $page['confidence'] ?? 0 ),
            );
        }

        return array(
            'text'        => $body['text'],
            'pages'       => $recognised,
            'count'       => (int) ( $body['count'] ?? count( $recognised ) ),
            'total_pages' => (int) ( $body['total_pages'] ?? count( $recognised ) ),
            'truncated'   => (bool) ( $body['truncated'] ?? false ),
            'dpi'         => (int) ( $body['dpi'] ?? 300 ),
            'language'    => (string) ( $body['language'] ?? 'eng' ),
        );
    }

    /**
     * OCR is the slowest route on the worker: seconds per page at 300 DPI.
     */
    private function timeout(): int
    {
        return max( 1, (int) config( 'oos.media_worker.ocr_timeout', 180 ) );
    }
}
