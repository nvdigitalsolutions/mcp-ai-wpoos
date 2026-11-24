<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;

/**
 * HTML→PDF over the media worker — Proposal 164 §164.4.2/§164.4.3.
 *
 * The worker already renders with a real browser (`/api/pdf/generate`, puppeteer
 * under the hood), and the WordPress `pro_pdf` tool has used it since before
 * this port. So this adapter translates the domain contract into that request
 * rather than adding a PHP renderer: `{ html, options }` in, `{ data_base64 }`
 * out.
 *
 * Three consequences are deliberate, and they mirror `MediaWorkerImageProcessing`:
 *
 *  - **`isAvailable()` is "configured", not "healthy".** It is asked before
 *    every call, and a health probe there would add a network round-trip to a
 *    capability check. A worker that is down surfaces as a `RuntimeException`
 *    from `render()`, which the tool reports as `pdf_failed`.
 *  - **Output is written by this adapter**, into `oos.media_worker.output_dir`
 *    (default `storage/app/media-worker`) under a content-addressed name, so an
 *    identical request rewrites the same file instead of accumulating copies.
 *  - **The request shape is the worker's, exactly**: `format`, `landscape` and
 *    `printBackground` are the three option keys the WordPress caller sends, and
 *    diverging would make one runtime render differently from the other.
 */
final class MediaWorkerHtmlToPdf implements HtmlToPdfInterface
{
    /**
     * Page sizes the worker's renderer accepts.
     */
    private const PAGE_FORMATS = array( 'A4', 'A3', 'A5', 'Letter', 'Legal', 'Tabloid' );

    public function __construct( private readonly MediaWorkerClient $worker ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool {
        return $this->worker->configured();
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedPageFormats(): array {
        return self::PAGE_FORMATS;
    }

    /**
     * {@inheritDoc}
     */
    public function render( string $html, array $options = array() ): array {
        if ( '' === trim( $html ) ) {
            throw new \InvalidArgumentException( 'The HTML body is empty.' );
        }

        $format    = $this->pageFormat( (string) ( $options['format'] ?? 'A4' ) );
        $landscape = (bool) ( $options['landscape'] ?? false );

        $body = $this->worker->postJson(
            '/api/pdf/generate',
            array(
                'html'    => $html,
                'options' => array(
                    'format'          => $format,
                    'landscape'       => $landscape,
                    'printBackground' => (bool) ( $options['print_background'] ?? true ),
                ),
            ),
            $this->renderTimeout(),
        );

        $encoded = $body['data_base64'] ?? null;

        if ( ! is_string( $encoded ) || '' === $encoded ) {
            throw new \RuntimeException( 'The media worker returned no PDF payload.' );
        }

        $bytes = base64_decode( $encoded, true );

        if ( false === $bytes || '' === $bytes ) {
            throw new \RuntimeException( 'The media worker returned an undecodable PDF payload.' );
        }

        $path = $this->outputPath( $html, $format, $landscape );

        if ( false === file_put_contents( $path, $bytes ) ) {
            throw new \RuntimeException( 'Unable to write the rendered PDF to '.$path );
        }

        return array(
            'path'      => $path,
            'mime_type' => 'application/pdf',
            'bytes'     => strlen( $bytes ),
            'format'    => strtolower( $format ).' '.($landscape ? 'landscape' : 'portrait'),
        );
    }

    /**
     * Canonical page size, or A4 for anything unrecognised.
     *
     * Case-insensitive because the WordPress tool accepts `a4` and `A4`.
     */
    private function pageFormat( string $requested ): string {
        foreach ( self::PAGE_FORMATS as $candidate ) {
            if ( 0 === strcasecmp( $candidate, $requested ) ) {
                return $candidate;
            }
        }

        return 'A4';
    }

    /**
     * Content-addressed output path, so repeating a render rewrites one file.
     */
    private function outputPath( string $html, string $format, bool $landscape ): string {
        $directory = (string) config( 'oos.media_worker.output_dir', '' );

        if ( '' === $directory ) {
            $directory = storage_path( 'app/media-worker' );
        }

        if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'PDF output directory is not writable: '.$directory );
        }

        $hash = sha1( $html."\0".$format."\0".( $landscape ? '1' : '0' ) );

        return rtrim( $directory, '/\\' ).DIRECTORY_SEPARATOR.'pdf-'.$hash.'.pdf';
    }

    /**
     * Rendering is slower than a transform, so it gets its own timeout.
     */
    private function renderTimeout(): int {
        return max( 1, (int) config( 'oos.media_worker.render_timeout', 120 ) );
    }
}
