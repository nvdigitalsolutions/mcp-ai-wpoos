<?php

/**
 * The multipart builder is safe by construction, not by its callers.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Http;

use Nvoos\Core\Infrastructure\Http\MultipartBody;
use PHPUnit\Framework\TestCase;

final class MultipartBodyTest extends TestCase
{
    public function test_every_field_becomes_its_own_part(): void
    {
        $multipart = MultipartBody::build(array('model' => 'whisper-1', 'response_format' => 'verbose_json'));

        $this->assertStringContainsString('name="model"', $multipart['body']);
        $this->assertStringContainsString("verbose_json\r\n", $multipart['body']);
        $this->assertStringContainsString('multipart/form-data; boundary=', $multipart['contentType']);
    }

    public function test_the_boundary_in_the_header_is_the_one_in_the_body(): void
    {
        $multipart = MultipartBody::build(array('a' => 'b'));

        $boundary = \substr($multipart['contentType'], \strlen('multipart/form-data; boundary='));

        $this->assertStringContainsString('--' . $boundary . "\r\n", $multipart['body']);
        $this->assertStringEndsWith('--' . $boundary . "--\r\n", $multipart['body']);
    }

    public function test_a_file_part_carries_its_name_filename_mime_and_bytes(): void
    {
        $multipart = MultipartBody::build(
            array('model' => 'whisper-1'),
            array('name' => 'file', 'filename' => 'clip.mp3', 'mime' => 'audio/mpeg', 'bytes' => 'RAW-AUDIO'),
        );

        $this->assertStringContainsString('name="file"; filename="clip.mp3"', $multipart['body']);
        $this->assertStringContainsString("Content-Type: audio/mpeg\r\n\r\nRAW-AUDIO\r\n", $multipart['body']);
    }

    public function test_a_quote_and_a_newline_in_a_name_cannot_inject_a_header(): void
    {
        $multipart = MultipartBody::build(array(), array(
            'name'     => 'file',
            'filename' => "x\"\r\nX-Evil: 1",
            'mime'     => 'audio/mpeg',
            'bytes'    => 'BYTES',
        ));

        // The injected CRLF must not survive to start a second header line.
        $this->assertStringNotContainsString("\r\nX-Evil", $multipart['body']);
    }

    public function test_field_values_are_not_escaped_because_they_are_the_payload(): void
    {
        $multipart = MultipartBody::build(array('prompt' => 'R&D "quoted" <tag>'));

        $this->assertStringContainsString('R&D "quoted" <tag>', $multipart['body']);
    }

    public function test_an_empty_value_is_still_sent(): void
    {
        $multipart = MultipartBody::build(array('language' => ''));

        $this->assertStringContainsString('name="language"', $multipart['body']);
    }

    public function test_two_bodies_do_not_share_a_boundary(): void
    {
        $first  = MultipartBody::build(array('a' => 'b'));
        $second = MultipartBody::build(array('a' => 'b'));

        $this->assertNotSame($first['contentType'], $second['contentType']);
    }
}
