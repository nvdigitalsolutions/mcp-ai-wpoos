<?php

/**
 * A `multipart/form-data` body, built without a dependency.
 *
 * The core speaks HTTP through one string-in/string-out contract, so a provider
 * that wants a file has to be handed a body it can read. Two tools needed that
 * shape — audio transcription from a URL and audio transcription from a stored
 * file — and the second could not reuse the first's copy: that copy was welded
 * into one tool's `execute()`, with the filename and the MIME type hard-coded.
 *
 * ## The quoting is the security surface
 *
 * A field *name* or *filename* is interpolated into a header line. A `"` ends
 * the quoted string, and a CR or LF starts a new header — so a filename of
 * `x"\r\nX-Evil: 1` could inject a header into a request this host signs with a
 * provider key. The two callers today pass literals, but a helper that is only
 * safe because of its callers is not safe; the values are sanitised here instead.
 *
 * Field *values* are never header-interpolated (they live in the body after a
 * blank line), so they are sent verbatim: escaping them would corrupt the text
 * being transcribed.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Http;

final class MultipartBody
{
    /**
     * Build the body and the header that describes it.
     *
     * The boundary is random rather than constant: a fixed boundary would let a
     * file whose bytes happen to contain it terminate the part early.
     *
     * @param array<string, string> $fields Text parts, name → value.
     * @param array{name: string, filename: string, mime: string, bytes: string}|null $file
     *        The binary part, if the caller has one.
     *
     * @return array{body: string, contentType: string}
     */
    public static function build(array $fields, ?array $file = null): array
    {
        $boundary = '----nvoos' . \bin2hex(\random_bytes(12));
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . self::header($name) . "\"\r\n\r\n";
            $body .= $value . "\r\n";
        }

        if (null !== $file) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . self::header($file['name'])
                . '"; filename="' . self::header($file['filename']) . "\"\r\n";
            $body .= 'Content-Type: ' . $file['mime'] . "\r\n\r\n";
            $body .= $file['bytes'] . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return array(
            'body'        => $body,
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
        );
    }

    /**
     * Make a value safe to sit between double quotes in a header line.
     *
     * A quote is replaced rather than escaped: `\"` is not an escape every
     * multipart parser honours, while dropping the character cannot extend the
     * quoted string.
     */
    private static function header(string $value): string
    {
        return \str_replace(array("\r", "\n", '"'), array('', '', "'"), $value);
    }
}
