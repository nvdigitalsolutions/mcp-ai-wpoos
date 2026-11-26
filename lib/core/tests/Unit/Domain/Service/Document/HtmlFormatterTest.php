<?php

/**
 * `HtmlFormatter`.
 *
 * The composition half of the PDF tools, tested on its own so a rendering bug
 * can be told apart from an engine bug. Two behaviours carry the weight:
 *
 *  - **Text reaches the page as text.** Everything a caller supplies is escaped
 *    once, so a body containing markup renders the characters and a body
 *    containing prose keeps its comparisons.
 *  - **A stylesheet cannot be escaped.** Option values are substituted into a
 *    `<style>` block, where `esc_attr()` is the wrong escaper: `}` closes the
 *    rule. Values carrying those characters fall back to the default rather
 *    than being repaired, because a font stack with a brace in it was never
 *    intended.
 *
 * @package Nvoos\Core\Tests
 * @since   2.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Document;

use Nvoos\Core\Domain\Service\Document\HtmlFormatter;
use PHPUnit\Framework\TestCase;

final class HtmlFormatterTest extends TestCase
{
    private HtmlFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new HtmlFormatter();
    }

    // ─── Document shell ────────────────────────────────────────────────────

    public function test_it_wraps_content_in_a_complete_document(): void
    {
        $html = $this->formatter->createDocument('<p>Body</p>', array('title' => 'Report'));

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<title>Report</title>', $html);
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('<p>Body</p>', $html);
    }

    public function test_the_title_is_escaped(): void
    {
        $html = $this->formatter->createDocument('', array('title' => 'A & B <script>'));

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_author_and_description_become_meta_tags(): void
    {
        $html = $this->formatter->createDocument('', array('author' => 'Ada', 'description' => 'Notes'));

        $this->assertStringContainsString('<meta name="author" content="Ada">', $html);
        $this->assertStringContainsString('<meta name="description" content="Notes">', $html);
    }

    public function test_no_meta_tags_are_emitted_when_both_are_empty(): void
    {
        $html = $this->formatter->createDocument('Body');

        $this->assertStringNotContainsString('<meta name="author"', $html);
        $this->assertStringNotContainsString('<meta name="description"', $html);
    }

    // ─── Text ──────────────────────────────────────────────────────────────

    public function test_blank_lines_become_paragraphs(): void
    {
        $html = $this->formatter->textToHtml("One.\n\nTwo.");

        $this->assertSame("<p>One.</p>\n<p>Two.</p>", $html);
    }

    public function test_a_single_newline_becomes_a_line_break(): void
    {
        $html = $this->formatter->textToHtml("One.\nTwo.");

        $this->assertSame("<p>One.<br />\nTwo.</p>", $html);
    }

    public function test_paragraphs_can_be_turned_off(): void
    {
        $html = $this->formatter->textToHtml("One.\nTwo.", array('auto_paragraphs' => false));

        $this->assertStringNotContainsString('<p>', $html);
        $this->assertStringContainsString('<br />', $html);
    }

    public function test_markup_in_text_is_escaped(): void
    {
        $html = $this->formatter->textToHtml('a < b and <b>bold</b>');

        $this->assertStringContainsString('a &lt; b', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
    }

    public function test_empty_blocks_are_dropped(): void
    {
        $html = $this->formatter->textToHtml("One.\n\n\n\n   \n\nTwo.");

        $this->assertSame(2, \substr_count($html, '<p>'));
    }

    // ─── Sections ──────────────────────────────────────────────────────────

    public function test_sections_render_headings_and_bodies(): void
    {
        $html = $this->formatter->sectionsToHtml(
            array(
                array('heading' => 'Alpha', 'content' => 'First.'),
                array('heading' => 'Beta', 'content' => 'Second.', 'level' => 3),
            )
        );

        $this->assertStringContainsString('<h2>Alpha</h2>', $html);
        $this->assertStringContainsString('<h3>Beta</h3>', $html);
        $this->assertStringContainsString('<p>First.</p>', $html);
        $this->assertStringContainsString('<p>Second.</p>', $html);
    }

    public function test_a_section_level_is_clamped_to_one_and_six(): void
    {
        $low = $this->formatter->sectionsToHtml(array(array('heading' => 'Low', 'level' => 0)));
        $high = $this->formatter->sectionsToHtml(array(array('heading' => 'High', 'level' => 42)));

        $this->assertStringContainsString('<h1>Low</h1>', $low);
        $this->assertStringContainsString('<h6>High</h6>', $high);
    }

    public function test_a_section_with_only_a_heading_is_rendered(): void
    {
        $html = $this->formatter->sectionsToHtml(array(array('heading' => 'Alone')));

        $this->assertSame('<h2>Alone</h2>', $html);
    }

    public function test_non_array_sections_are_skipped(): void
    {
        $html = $this->formatter->sectionsToHtml(array('nope', 42, array('heading' => 'Yes')));

        $this->assertSame('<h2>Yes</h2>', $html);
    }

    // ─── Tables and lists ──────────────────────────────────────────────────

    public function test_a_table_renders_headers_and_rows(): void
    {
        $html = $this->formatter->createTable(
            array(
                'headers' => array('Name', 'Qty'),
                'rows' => array(array('Widget', 3)),
            )
        );

        $this->assertStringContainsString('<th>Name</th>', $html);
        $this->assertStringContainsString('<td>Widget</td>', $html);
        $this->assertStringContainsString('<td>3</td>', $html);
    }

    public function test_a_list_defaults_to_unordered(): void
    {
        $html = $this->formatter->createList(array('a', 'b'));

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>a</li>', $html);
    }

    public function test_an_ordered_list_is_honoured(): void
    {
        $this->assertStringContainsString('<ol>', $this->formatter->createList(array('a'), 'ol'));
    }

    public function test_an_unknown_list_type_falls_back_to_unordered(): void
    {
        $this->assertStringContainsString('<ul>', $this->formatter->createList(array('a'), 'dl'));
    }

    // ─── Page geometry ─────────────────────────────────────────────────────

    public function test_page_widths_are_known_for_the_three_distinct_sizes(): void
    {
        $this->assertSame('816px', HtmlFormatter::pageWidth('A4'));
        $this->assertSame('1123px', HtmlFormatter::pageWidth('A3'));
        $this->assertSame('559px', HtmlFormatter::pageWidth('A5'));
    }

    public function test_an_unknown_page_width_falls_back_to_a4(): void
    {
        $this->assertSame('816px', HtmlFormatter::pageWidth('A0'));
    }

    // ─── Stylesheet hardening ──────────────────────────────────────────────

    public function test_a_closing_brace_in_font_family_cannot_escape_the_rule(): void
    {
        $html = $this->formatter->createDocument(
            'Body',
            array('font_family' => 'Arial} body{display:none')
        );

        $this->assertStringNotContainsString('body{display:none', $html);
        $this->assertStringContainsString('Arial, Helvetica, sans-serif', $html);
    }

    public function test_a_semicolon_in_a_value_is_refused(): void
    {
        $html = $this->formatter->createDocument('Body', array('margin' => '1in; color: red'));

        $this->assertStringNotContainsString('color: red', $html);
        $this->assertStringContainsString('padding: 1in', $html);
    }

    public function test_a_url_function_is_refused(): void
    {
        $html = $this->formatter->createDocument(
            'Body',
            array('font_family' => 'x url(https://evil.test/a.css)')
        );

        $this->assertStringNotContainsString('evil.test', $html);
    }

    public function test_an_expression_function_is_refused(): void
    {
        $html = $this->formatter->createDocument(
            'Body',
            array('font_family' => 'x expression(alert(1))')
        );

        $this->assertStringNotContainsString('expression(', $html);
    }

    public function test_a_legitimate_font_stack_is_kept(): void
    {
        $html = $this->formatter->createDocument(
            'Body',
            array('font_family' => "'Iowan Old Style', Georgia, serif")
        );

        $this->assertStringContainsString("'Iowan Old Style', Georgia, serif", $html);
    }

    public function test_a_non_positive_font_size_falls_back_to_one_point(): void
    {
        $html = $this->formatter->createDocument('Body', array('font_size' => 0));

        $this->assertStringContainsString('font-size: 1pt', $html);
    }

    public function test_a_non_positive_line_height_falls_back_to_the_default(): void
    {
        $html = $this->formatter->createDocument('Body', array('line_height' => 0));

        $this->assertStringContainsString('line-height: 1.6', $html);
    }

    // ─── Break helpers ─────────────────────────────────────────────────────

    public function test_page_break_and_avoid_break_emit_their_classes(): void
    {
        $this->assertSame('<div class="page-break"></div>', $this->formatter->pageBreak());
        $this->assertSame('<div class="avoid-break">x</div>', $this->formatter->avoidBreak('x'));
    }
}
