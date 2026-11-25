<?php

/**
 * The caption formatter writes three formats from one cue list.
 *
 * These are exact-string assertions on purpose: a subtitle file is a wire format,
 * and "close enough" is a file that a player shows differently or not at all.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Media;

use Nvoos\Core\Application\Media\CaptionFormatter;
use PHPUnit\Framework\TestCase;

final class CaptionFormatterTest extends TestCase
{
    /**
     * @return list<array{start: float, end: float, text: string}>
     */
    private static function cues(): array
    {
        return array(
            array('start' => 1.0, 'end' => 2.0, 'text' => 'Hello'),
            array('start' => 2.5, 'end' => 3.25, 'text' => 'World'),
        );
    }

    public function test_srt_writes_an_index_a_comma_timestamp_and_a_blank_line_between_cues(): void
    {
        $this->assertSame(
            "1\n00:00:01,000 --> 00:00:02,000\nHello\n\n2\n00:00:02,500 --> 00:00:03,250\nWorld\n",
            CaptionFormatter::srt(self::cues()),
        );
    }

    public function test_srt_rounds_to_milliseconds_rather_than_truncating(): void
    {
        // 1.9996 s is two seconds to any player; truncation would say one.
        $this->assertStringContainsString(
            '00:00:02,000 --> 00:00:02,500',
            CaptionFormatter::srt(array(
                array('start' => 1.9996, 'end' => 2.4996, 'text' => 'Tick'),
            )),
        );
    }

    public function test_vtt_starts_with_the_signature_and_uses_a_period(): void
    {
        $vtt = CaptionFormatter::vtt(array(
            array('start' => 1.0, 'end' => 2.0, 'text' => 'Hello'),
        ));

        $this->assertStringStartsWith("WEBVTT\n\n", $vtt);
        $this->assertStringContainsString("00:00:01.000 --> 00:00:02.000", $vtt);
    }

    public function test_an_empty_vtt_is_still_a_document(): void
    {
        $this->assertSame("WEBVTT\n\n", CaptionFormatter::vtt(array()));
    }

    public function test_an_empty_srt_is_an_empty_file(): void
    {
        $this->assertSame('', CaptionFormatter::srt(array()));
    }

    public function test_vtt_escapes_the_characters_the_spec_forbids_in_cue_text(): void
    {
        $vtt = CaptionFormatter::vtt(array(
            array('start' => 0.0, 'end' => 1.0, 'text' => 'R&D <team> 10 --> 12'),
        ));

        // `-->` contains `>`, so escaping it is what keeps the cue payload legal.
        $this->assertStringContainsString('R&amp;D &lt;team&gt; 10 --&gt; 12', $vtt);
        $this->assertStringNotContainsString('R&D', $vtt);
    }

    public function test_srt_does_not_escape_because_the_format_has_no_escapes(): void
    {
        $srt = CaptionFormatter::srt(array(
            array('start' => 0.0, 'end' => 1.0, 'text' => 'R&D'),
        ));

        $this->assertStringContainsString('R&D', $srt);
    }

    public function test_ass_writes_a_script_header_and_a_dialogue_line(): void
    {
        $ass = CaptionFormatter::ass(array(
            array('start' => 1.0, 'end' => 3.25, 'text' => 'Hello'),
        ));

        $this->assertStringContainsString('[Script Info]', $ass);
        $this->assertStringContainsString('[V4+ Styles]', $ass);
        $this->assertStringContainsString("[Events]\n", $ass);
        $this->assertStringContainsString('Dialogue: 0,0:00:01.00,0:00:03.25,Default,,0,0,0,,Hello', $ass);
    }

    public function test_ass_counts_centiseconds_and_a_single_digit_hour(): void
    {
        $ass = CaptionFormatter::ass(array(
            array('start' => 3723.04, 'end' => 3723.99, 'text' => 'Late'),
        ));

        $this->assertStringContainsString('0,1:02:03.04,1:02:03.99,', $ass);
    }

    public function test_ass_escapes_override_braces_so_transcript_text_cannot_style_itself(): void
    {
        $ass = CaptionFormatter::ass(array(
            array('start' => 0.0, 'end' => 1.0, 'text' => '{\pos(0,0)}moved'),
        ));

        $dialogue = \substr($ass, (int) \strpos($ass, 'Dialogue: '));

        // Every brace is escaped, so no override block opens inside the text.
        $this->assertStringContainsString('\{\\pos(0,0)\}moved', $dialogue);
        $this->assertStringNotContainsString('{', \str_replace('\{', '', $dialogue));
    }

    public function test_ass_turns_a_line_break_into_the_dialogue_escape(): void
    {
        $ass = CaptionFormatter::ass(array(
            array('start' => 0.0, 'end' => 1.0, 'text' => "First line\nSecond line"),
        ));

        $this->assertStringContainsString('First line\\NSecond line', $ass);
        // A literal newline would end the Dialogue record and lose the rest.
        $this->assertStringNotContainsString("First line\nSecond line", $ass);
    }

    public function test_a_multiline_cue_never_produces_the_blank_line_that_ends_a_cue(): void
    {
        $srt = CaptionFormatter::srt(array(
            array('start' => 0.0, 'end' => 1.0, 'text' => "One\n\n\nTwo"),
        ));

        $this->assertStringContainsString("One\nTwo", $srt);
        $this->assertStringNotContainsString("One\n\nTwo", $srt);
    }

    public function test_empty_segments_are_dropped_and_the_indices_stay_contiguous(): void
    {
        $srt = CaptionFormatter::srt(array(
            array('start' => 0.0, 'end' => 1.0, 'text' => '  '),
            array('start' => 1.0, 'end' => 2.0, 'text' => 'Kept'),
        ));

        $this->assertStringStartsWith("1\n", $srt);
        $this->assertSame(1, \substr_count($srt, '-->'));
    }

    public function test_a_zero_length_cue_is_widened_by_a_millisecond_rather_than_hidden(): void
    {
        $srt = CaptionFormatter::srt(array(
            array('start' => 4.0, 'end' => 4.0, 'text' => 'Blink'),
        ));

        $this->assertStringContainsString('00:00:04,000 --> 00:00:04,001', $srt);
    }

    public function test_a_backwards_cue_is_swapped_rather_than_dropped(): void
    {
        $srt = CaptionFormatter::srt(array(
            array('start' => 5.0, 'end' => 4.0, 'text' => 'Backwards'),
        ));

        $this->assertStringContainsString('00:00:04,000 --> 00:00:05,000', $srt);
        $this->assertStringContainsString('Backwards', $srt);
    }

    public function test_a_negative_start_is_clamped_to_zero(): void
    {
        $srt = CaptionFormatter::srt(array(
            array('start' => -2.0, 'end' => 1.0, 'text' => 'Early'),
        ));

        $this->assertStringContainsString('00:00:00,000 --> 00:00:01,000', $srt);
    }

    public function test_the_dispatcher_reaches_every_format(): void
    {
        foreach (CaptionFormatter::FORMATS as $format) {
            $this->assertIsString(CaptionFormatter::format(self::cues(), $format));
        }
    }

    public function test_an_unknown_format_is_refused_with_the_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ass2');

        CaptionFormatter::format(self::cues(), 'ass2');
    }

    public function test_the_dispatcher_is_case_insensitive(): void
    {
        $this->assertSame(
            CaptionFormatter::vtt(self::cues()),
            CaptionFormatter::format(self::cues(), ' VTT '),
        );
    }
}
