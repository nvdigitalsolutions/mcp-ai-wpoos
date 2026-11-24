<?php

namespace Nvoos\Laravel\Adapter;

/**
 * Where a worker's `data_base64` becomes a file this host can hand to the registry.
 *
 * Every worker endpoint that produces bytes answers the same way
 * (`{ data_base64, size, pages? }`), and every adapter needs the same three
 * steps: decode strictly, write under `oos.media_worker.output_dir`, name the
 * file by content. Doing that four times invites four subtly different failure
 * messages and one adapter that forgot `strict`.
 *
 * ## Content-addressed on purpose
 *
 * The name is `{prefix}-{sha1(fingerprint)}.{ext}`. Re-running the same request
 * rewrites one file instead of accumulating copies, which matters because an
 * agent retrying a failed turn would otherwise fill the disk with identical
 * documents. The caller supplies the fingerprint — the inputs that determine the
 * bytes — rather than this class hashing the output, because hashing the output
 * would need the bytes twice.
 *
 * @package Nvoos\Laravel
 * @since   1.1.0
 * @license MIT
 */
final class MediaWorkerOutput
{
    /**
     * Decode a `data_base64` payload, refusing anything malformed.
     *
     * `base64_decode( ..., true )` is strict on purpose: a lenient decode turns
     * a torn response into a corrupt file that only fails when someone opens it.
     *
     * @param mixed  $encoded The `data_base64` value the worker returned.
     * @param string $what    What was expected, for the message ("PDF", "document").
     *
     * @throws \RuntimeException When the value is absent or undecodable.
     */
    public static function decode(mixed $encoded, string $what = 'payload'): string
    {
        if (! is_string($encoded) || '' === $encoded) {
            throw new \RuntimeException('The media worker returned no '.$what.' payload.');
        }

        $bytes = base64_decode($encoded, true);

        if (false === $bytes || '' === $bytes) {
            throw new \RuntimeException('The media worker returned an undecodable '.$what.' payload.');
        }

        return $bytes;
    }

    /**
     * Write bytes to the worker output directory and return the path.
     *
     * @param string $bytes       The decoded payload.
     * @param string $prefix      File stem, e.g. `pdf`, `watermarked`, `docx`.
     * @param string $extension   Extension without the dot.
     * @param string $fingerprint Inputs that determine the bytes.
     *
     * @throws \RuntimeException When the directory or the file cannot be written.
     */
    public static function write(string $bytes, string $prefix, string $extension, string $fingerprint): string
    {
        $directory = (string) config('oos.media_worker.output_dir', '');

        if ('' === $directory) {
            $directory = storage_path('app/media-worker');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Media worker output directory is not writable: '.$directory);
        }

        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR
            .$prefix.'-'.sha1($fingerprint).'.'.$extension;

        if (strlen($bytes) !== file_put_contents($path, $bytes)) {
            throw new \RuntimeException('Unable to write the worker output to '.$path);
        }

        return $path;
    }

    /**
     * Decode and write in one step.
     *
     * @param mixed  $encoded     The `data_base64` value the worker returned.
     * @param string $prefix      File stem.
     * @param string $extension   Extension without the dot.
     * @param string $fingerprint Inputs that determine the bytes.
     * @param string $what        What was expected, for the message.
     *
     * @throws \RuntimeException
     */
    public static function persist(
        mixed $encoded,
        string $prefix,
        string $extension,
        string $fingerprint,
        string $what = 'payload',
    ): string {
        return self::write(self::decode($encoded, $what), $prefix, $extension, $fingerprint);
    }

    /**
     * A stable fingerprint for structured input.
     *
     * @param mixed $value Any JSON-serialisable value.
     */
    public static function fingerprint(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $encoded ? serialize($value) : $encoded;
    }
}
