<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\TabularReaderInterface;

/**
 * Spreadsheet and delimited-file reading over the media worker — Proposal 164 §164.4.3.
 *
 * `POST /api/ingest/parse` already reads `xlsx`, `csv`, `tsv`, `json`, `jsonl`
 * and `ndjson` with ExcelJS and the repo's own delimited parser, and returns the
 * positional matrix `{columns, rows, meta}`. So `excel_data_import` does not
 * need a spreadsheet library on this host — it needs this adapter.
 *
 * Two deliberate behaviours:
 *
 *  - **`maxRows` is bounded on both sides.** It is sent to the worker (which
 *    slices the response) *and* applied here, so a worker that predates the
 *    argument cannot hand back a 100 000-row matrix that the runtime then
 *    forwards into a prompt.
 *  - **`.xls` and `.ods` are refused, not silently retried.** The worker answers
 *    those with a 503 naming the missing capability; the adapter lets that
 *    surface rather than pretending the file was empty.
 */
final class MediaWorkerTabularReader implements TabularReaderInterface
{
    /**
     * Formats the worker parses today.
     *
     * `xls` and `ods` are absent on purpose: the worker answers 503 for both.
     *
     * @var list<string>
     */
    private const FORMATS = array( 'xlsx', 'csv', 'tsv', 'json', 'jsonl', 'ndjson' );

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
    public function getSupportedFormats(): array
    {
        return self::FORMATS;
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $localPath, array $options = array()): array
    {
        if ( '' === trim( $localPath ) ) {
            throw new \InvalidArgumentException( 'A file path is required.' );
        }

        if ( ! is_file( $localPath ) ) {
            throw new \InvalidArgumentException( 'The file does not exist: '.$localPath );
        }

        $format = strtolower( trim( (string) ( $options['format'] ?? '' ) ) );

        if ( '' === $format ) {
            $format = strtolower( (string) pathinfo( $localPath, PATHINFO_EXTENSION ) );
        }

        if ( ! in_array( $format, self::FORMATS, true ) ) {
            throw new \InvalidArgumentException(
                'Unsupported tabular format: '.$format.'. Accepted: '.implode( ', ', self::FORMATS ).'.'
            );
        }

        $fields = array( 'format' => $format );

        $sheet = trim( (string) ( $options['sheet'] ?? '' ) );

        if ( '' !== $sheet ) {
            $fields['sheet'] = $sheet;
        }

        $limit = max( 0, (int) ( $options['max_rows'] ?? 0 ) );

        if ( $limit > 0 ) {
            $fields['maxRows'] = $limit;
        }

        $body = $this->worker->uploadFile(
            '/api/ingest/parse',
            $localPath,
            basename( $localPath ),
            $fields,
            $this->timeout(),
        );

        $columns = array();
        $rows    = array();

        foreach ( (array) ( $body['columns'] ?? array() ) as $column ) {
            $columns[] = (string) $column;
        }

        foreach ( (array) ( $body['rows'] ?? array() ) as $row ) {
            $cells = array();

            foreach ( (array) $row as $cell ) {
                // The matrix is string-typed by contract; an engine that hands
                // back a number or null is normalised rather than leaked into
                // a payload whose schema says "list of strings".
                $cells[] = is_scalar( $cell ) ? (string) $cell : '';
            }

            $rows[] = $cells;
        }

        $meta = is_array( $body['meta'] ?? null ) ? $body['meta'] : array();

        // Belt and braces: a worker that predates `maxRows` ignores it, so the
        // bound is enforced here too and the flag tells the truth either way.
        if ( $limit > 0 && count( $rows ) > $limit ) {
            $rows             = array_slice( $rows, 0, $limit );
            $meta['truncated'] = true;
        }

        if ( ! isset( $meta['truncated'] ) ) {
            $meta['truncated'] = false;
        }

        return array(
            'columns' => $columns,
            'rows'    => $rows,
            'meta'    => $meta,
        );
    }

    private function timeout(): int
    {
        return max( 1, (int) config( 'oos.media_worker.ingest_timeout', 120 ) );
    }
}
