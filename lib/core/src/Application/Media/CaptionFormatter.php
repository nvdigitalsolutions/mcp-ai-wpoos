<?php

/**
 * Cues in, a subtitle file out — SRT, WebVTT or ASS.
 *
 * Proposal 164 §164.27. This is deliberately a pure function over a list of
 * cues: the provider gives timings, the formatter gives a file, and nothing in
 * between needs a network or a disk. That is what makes three output formats
 * testable to the character.
 *
 * ## Why the timings are formatted here rather than asked for
 *
 * `whisper-1` can answer `srt` and `vtt` directly — and `gpt-4o-transcribe`
 * cannot, it answers `json` only. Asking the provider to format would tie the
 * tool to one model for no gain, and would leave `ass` unserved forever because
 * no provider emits it. The provider is asked for `verbose_json` — the timed
 * segments — and every format is built from those.
 *
 * ## Escaping is per format, because the formats disagree
 *
 * - **SRT** has no escaping rules; the text is what it is.
 * - **WebVTT** cue text may not contain `&`, `<` or `>`, and may not contain
 *   `--` `>`, so the three characters are written as character references.
 *   Without this a transcript that says "R&D" produces a file that parsers
 *   disagree about.
 * - **ASS** treats `{...}` as an override block, which is how a transcript
 *   containing braces would otherwise be able to inject positioning or drawing
 *   commands into a player. Braces are escaped so the words stay words.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

final class CaptionFormatter
{
    /**
     * The subtitle formats this formatter can write.
     */
    public const FORMATS = array('srt', 'vtt', 'ass');

    /**
     * The ASS style block, written once.
     *
     * `Alignment 2` is bottom-centre, which is what a caption is. The margins
     * are in the coordinate space set by `PlayResX`/`PlayResY`, so they scale
     * with the video rather than being pixel-fixed.
     */
    private const ASS_HEADER = "[Script Info]\n"
        . "ScriptType: v4.00+\n"
        . "WrapStyle: 0\n"
        . "ScaledBorderAndShadow: yes\n"
        . "PlayResX: 1920\n"
        . "PlayResY: 1080\n"
        . "\n"
        . "[V4+ Styles]\n"
        . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
        . "Style: Default,Arial,56,&H00FFFFFF,&H000000FF,&H00000000,&H80000000,0,0,0,0,100,100,0,0,1,3,0,2,80,80,60,1\n"
        . "\n"
        . "[Events]\n"
        . "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

    /**
     * Render cues in the requested format.
     *
     * @param list<array{start: float, end: float, text: string}> $cues   Ordered cues.
     * @param string                                              $format One of {@see FORMATS}.
     *
     * @throws \InvalidArgumentException When the format is not one of {@see FORMATS}.
     */
    public static function format(array $cues, string $format): string
    {
        $format = \strtolower(\trim($format));

        return match ($format) {
            'srt' => self::srt($cues),
            'vtt' => self::vtt($cues),
            'ass' => self::ass($cues),
            default => throw new \InvalidArgumentException(
                'Unsupported caption format: ' . $format . '. Allowed: ' . \implode(', ', self::FORMATS) . '.',
            ),
        };
    }

    /**
     * SubRip: an index, a comma-separated timestamp range, the words, a blank line.
     *
     * @param list<array{start: float, end: float, text: string}> $cues
     */
    public static function srt(array $cues): string
    {
        $blocks = array();

        foreach (self::usable($cues) as $cue) {
            $blocks[] = \sprintf(
                "%d\n%s --> %s\n%s",
                \count($blocks) + 1,
                self::clock($cue['start'], ','),
                self::clock($cue['end'], ','),
                \implode("\n", self::lines($cue['text'])),
            );
        }

        return array() === $blocks ? '' : \implode("\n\n", $blocks) . "\n";
    }

    /**
     * WebVTT: the `WEBVTT` signature, then cues separated by blank lines.
     *
     * A cue identifier is emitted because it is free and makes a file easier to
     * diff; the spec treats it as optional.
     *
     * @param list<array{start: float, end: float, text: string}> $cues
     */
    public static function vtt(array $cues): string
    {
        $blocks = array();

        foreach (self::usable($cues) as $cue) {
            $blocks[] = \sprintf(
                "%d\n%s --> %s\n%s",
                \count($blocks) + 1,
                self::clock($cue['start'], '.'),
                self::clock($cue['end'], '.'),
                \implode("\n", \array_map(
                    static fn (string $line): string => \str_replace(
                        array('&', '<', '>'),
                        array('&amp;', '&lt;', '&gt;'),
                        $line,
                    ),
                    self::lines($cue['text']),
                )),
            );
        }

        // The signature and one blank line are required even when there is
        // nothing to say: an empty file is not a valid WebVTT document.
        return "WEBVTT\n\n" . (array() === $blocks ? '' : \implode("\n\n", $blocks) . "\n");
    }

    /**
     * Advanced SubStation Alpha: a script header, a default style, then `Dialogue:`.
     *
     * @param list<array{start: float, end: float, text: string}> $cues
     */
    public static function ass(array $cues): string
    {
        $events = '';

        foreach (self::usable($cues) as $cue) {
            // A real line break inside a cue is `\N` in ASS; a literal newline
            // would end the `Dialogue:` line and lose the rest of the cue.
            $text = \implode('\N', \array_map(
                static fn (string $line): string => \str_replace(
                    array('{', '}'),
                    array('\{', '\}'),
                    $line,
                ),
                self::lines($cue['text']),
            ));

            $events .= \sprintf(
                "Dialogue: 0,%s,%s,Default,,0,0,0,,%s\n",
                self::assClock($cue['start']),
                self::assClock($cue['end']),
                $text,
            );
        }

        return self::ASS_HEADER . $events;
    }

    /**
     * Normalise and drop cues a player would not show.
     *
     * An empty segment is common — a pause the provider labelled — and emitting
     * `3\n00:00:04,200 --> 00:00:04,900\n` with no text produces a subtitle that
     * flickers into existence with nothing in it. Widening a zero-length cue to
     * a millisecond is the smallest fix that keeps the words visible; a cue that
     * ends before it starts is swapped rather than dropped, because the words are
     * the part worth keeping.
     *
     * @param list<array{start: float, end: float, text: string}> $cues
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    private static function usable(array $cues): array
    {
        $usable = array();

        foreach ($cues as $cue) {
            if (! \is_array($cue)) {
                continue;
            }

            $text = \trim((string) ($cue['text'] ?? ''));

            if ('' === $text) {
                continue;
            }

            $start = \max(0.0, (float) ($cue['start'] ?? 0.0));
            $end   = \max(0.0, (float) ($cue['end'] ?? 0.0));

            if ($end < $start) {
                $swap  = $start;
                $start = $end;
                $end   = $swap;
            }

            if ($end === $start) {
                $end = $start + 0.001;
            }

            $usable[] = array('start' => $start, 'end' => $end, 'text' => $text);
        }

        return $usable;
    }

    /**
     * Split a cue into display lines, never leaving a blank one.
     *
     * A blank line inside a cue is the cue separator in SRT and VTT, so a
     * paragraph break in a transcript would split one cue into two — the second
     * half with no timing at all.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = \preg_split('/\r\n|\r|\n/', $text) ?: array();
        $kept  = array();

        foreach ($lines as $line) {
            $line = \trim($line);

            if ('' !== $line) {
                $kept[] = $line;
            }
        }

        return array() === $kept ? array('') : $kept;
    }

    /**
     * `HH:MM:SS,mmm` for SRT and `HH:MM:SS.mmm` for WebVTT.
     *
     * The hours field is not capped at 99: a three-hour lecture is `03`, and a
     * hundred-hour one is `100`, which both formats tolerate.
     */
    private static function clock(float $seconds, string $separator): string
    {
        $millis = self::millis($seconds);

        return \sprintf(
            '%02d:%02d:%02d%s%03d',
            \intdiv($millis, 3600000),
            \intdiv($millis % 3600000, 60000),
            \intdiv($millis % 60000, 1000),
            $separator,
            $millis % 1000,
        );
    }

    /**
     * `H:MM:SS.cc` — ASS counts hundredths, with a single-digit hour.
     *
     * Derived from the same millisecond value as the other two formats so that a
     * cue cannot start at `00:00:01,999` in SRT and `0:00:02.00` in ASS.
     */
    private static function assClock(float $seconds): string
    {
        $centis = \intdiv(self::millis($seconds), 10);

        return \sprintf(
            '%d:%02d:%02d.%02d',
            \intdiv($centis, 360000),
            \intdiv($centis % 360000, 6000),
            \intdiv($centis % 6000, 100),
            $centis % 100,
        );
    }

    private static function millis(float $seconds): int
    {
        return (int) \round(\max(0.0, $seconds) * 1000);
    }
}
