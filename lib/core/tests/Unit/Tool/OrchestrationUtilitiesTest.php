<?php

/**
 * The four orchestration utilities that need no store.
 *
 * Each tool's surprising edges are pinned here: the password generator's
 * charset arithmetic (and the strength scale that cannot reach 4), the
 * regex-based HTML conversion (including alt-after-src), the selector/tag split
 * in structured extraction, and the positional correlation plus the single-point
 * trend that used to divide by zero.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Orchestration\AnalyzeDataPatternsTool;
use Nvoos\Core\Tool\Orchestration\ConvertHtmlToMarkdownTool;
use Nvoos\Core\Tool\Orchestration\ExtractStructuredDataTool;
use Nvoos\Core\Tool\Orchestration\GeneratePasswordTool;
use PHPUnit\Framework\TestCase;

final class OrchestrationUtilitiesTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );
    }

    // ─── generate_password ────────────────────────────────────────────────

    public function test_a_single_password_has_the_requested_length(): void
    {
        $result = $this->password()->execute(array('length' => 20), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(20, $result['data']['length']);
        $this->assertSame(20, strlen($result['data']['password']));
        $this->assertSame(20, $result['data']['options']['length']);
    }

    public function test_the_charset_is_exactly_what_was_selected(): void
    {
        $result = $this->password()->execute(
            array(
                'length'    => 64,
                'uppercase' => true,
                'lowercase' => false,
                'numbers'   => false,
                'symbols'   => false,
            ),
            self::CONTEXT
        );

        $this->assertMatchesRegularExpression('/^[A-Z]{64}$/', $result['data']['password']);
    }

    public function test_avoid_ambiguous_drops_the_five_confusable_characters(): void
    {
        $result = $this->password()->execute(
            array(
                'length'          => 128,
                'uppercase'       => true,
                'lowercase'       => true,
                'numbers'         => true,
                'symbols'         => false,
                'avoid_ambiguous' => true,
            ),
            self::CONTEXT
        );

        $this->assertDoesNotMatchRegularExpression('/[0OIl1]/', $result['data']['password']);
    }

    public function test_count_returns_a_list(): void
    {
        $result = $this->password()->execute(array('count' => 3, 'length' => 12), self::CONTEXT);

        $this->assertCount(3, $result['data']['passwords']);
        $this->assertSame(3, $result['data']['count']);
        $this->assertSame(12, $result['data']['passwords'][0]['length']);
    }

    public function test_out_of_range_length_and_count_are_refused(): void
    {
        $this->assertSame(
            'invalid_password_length',
            $this->password()->execute(array('length' => 11), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'invalid_password_length',
            $this->password()->execute(array('length' => 129), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'invalid_password_count',
            $this->password()->execute(array('count' => 11), self::CONTEXT)['error']['code']
        );
    }

    public function test_no_character_set_is_refused(): void
    {
        $result = $this->password()->execute(
            array(
                'uppercase' => false,
                'lowercase' => false,
                'numbers'   => false,
                'symbols'   => false,
            ),
            self::CONTEXT
        );

        $this->assertSame('no_character_sets', $result['error']['code']);
    }

    public function test_the_strength_scale_cannot_reach_four(): void
    {
        // Three points for length plus four for class diversity is the maximum
        // score of 7, halved to 3. The 0–4 scale's top is unreachable.
        $result = $this->password()->execute(array('length' => 128), self::CONTEXT);

        $this->assertSame(3, $result['data']['strength']);
    }

    public function test_a_lowercase_only_password_scores_low(): void
    {
        $result = $this->password()->execute(
            array(
                'length'    => 12,
                'uppercase' => false,
                'numbers'   => false,
                'symbols'   => false,
            ),
            self::CONTEXT
        );

        // 12 chars (2 points) + lowercase (1) = 3, halved and floored to 1.
        $this->assertSame(1, $result['data']['strength']);
    }

    // ─── convert_html_to_markdown ─────────────────────────────────────────

    public function test_headings_paragraphs_and_emphasis_convert(): void
    {
        $result = $this->markdown()->execute(
            array('html' => '<h1>Title</h1><p>Hello <strong>world</strong> and <em>all</em></p>'),
            self::CONTEXT
        );

        $this->assertSame("# Title\n\nHello **world** and *all*", $result['data']['markdown']);
        $this->assertSame('html converted to markdown.', strtolower($result['message']));
    }

    public function test_links_are_kept_or_unwrapped(): void
    {
        $kept = $this->markdown()->execute(
            array('html' => '<a href="https://example.test">Example</a>'),
            self::CONTEXT
        );
        $bare = $this->markdown()->execute(
            array('html' => '<a href="https://example.test">Example</a>', 'preserve_links' => false),
            self::CONTEXT
        );

        $this->assertSame('[Example](https://example.test)', $kept['data']['markdown']);
        $this->assertSame('Example', $bare['data']['markdown']);
    }

    public function test_an_image_with_alt_after_src_keeps_its_alt(): void
    {
        $result = $this->markdown()->execute(
            array('html' => '<img src="a.png" alt="A picture">'),
            self::CONTEXT
        );

        $this->assertSame('![A picture](a.png)', $result['data']['markdown']);
    }

    public function test_an_image_with_alt_before_src_loses_the_alt(): void
    {
        // The first pattern requires src before alt; the fallback keeps the URL
        // only. This is the WordPress order, pinned rather than fixed.
        $result = $this->markdown()->execute(
            array('html' => '<img alt="A picture" src="a.png">'),
            self::CONTEXT
        );

        $this->assertSame('![](a.png)', $result['data']['markdown']);
    }

    public function test_images_can_be_removed(): void
    {
        $result = $this->markdown()->execute(
            array('html' => 'Before <img src="a.png" alt="A"> after', 'preserve_images' => false),
            self::CONTEXT
        );

        $this->assertSame('Before  after', $result['data']['markdown']);
    }

    public function test_lists_and_pre_blocks_convert(): void
    {
        $list = $this->markdown()->execute(
            array('html' => '<ul><li>One</li><li>Two</li></ul>'),
            self::CONTEXT
        );
        $pre  = $this->markdown()->execute(
            array('html' => '<pre>code here</pre>'),
            self::CONTEXT
        );

        $this->assertSame("- One\n- Two", $list['data']['markdown']);
        $this->assertSame("```\ncode here\n```", $pre['data']['markdown']);
    }

    public function test_script_contents_are_removed_with_the_tag(): void
    {
        $result = $this->markdown()->execute(
            array('html' => '<p>Hi</p><script>alert(1)</script>'),
            self::CONTEXT
        );

        $this->assertSame('Hi', $result['data']['markdown']);
    }

    public function test_lengths_and_compression_are_reported(): void
    {
        $html   = '<h1>A</h1>';
        $result = $this->markdown()->execute(array('html' => $html), self::CONTEXT);

        $this->assertSame(strlen($html), $result['data']['original_length']);
        $this->assertSame(strlen($result['data']['markdown']), $result['data']['markdown_length']);
        $this->assertStringEndsWith('%', $result['data']['compression']);
    }

    public function test_html_is_required_and_must_be_a_string(): void
    {
        $this->assertSame('html_required', $this->markdown()->execute(array(), self::CONTEXT)['error']['code']);
        $this->assertSame('html_required', $this->markdown()->execute(array('html' => ''), self::CONTEXT)['error']['code']);
        $this->assertSame('invalid_html', $this->markdown()->execute(array('html' => array('x')), self::CONTEXT)['error']['code']);
    }

    public function test_the_string_zero_is_a_valid_document(): void
    {
        // WordPress's empty() check would refuse "0"; the port narrows the edge.
        $result = $this->markdown()->execute(array('html' => '0'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('0', $result['data']['markdown']);
    }

    // ─── extract_structured_data ──────────────────────────────────────────

    public function test_a_tag_selector_returns_one_or_many(): void
    {
        $one  = $this->extract()->execute(
            array('content' => '<h1>Title</h1>', 'selectors' => array('title' => 'h1')),
            self::CONTEXT
        );
        $many = $this->extract()->execute(
            array('content' => '<h1>Title</h1><h1>Other</h1>', 'selectors' => array('title' => 'h1')),
            self::CONTEXT
        );

        $this->assertSame('Title', $one['data']['extracted_data']['title']);
        $this->assertSame(array('Title', 'Other'), $many['data']['extracted_data']['title']);
    }

    public function test_a_pattern_selector_returns_its_matches(): void
    {
        $result = $this->extract()->execute(
            array(
                'content'   => 'Price $12.50 and $3',
                'selectors' => array('price' => '\$[0-9.]+'),
            ),
            self::CONTEXT
        );

        $this->assertSame(array('$12.50', '$3'), $result['data']['extracted_data']['price']);
    }

    public function test_a_non_string_selector_reports_an_empty_field(): void
    {
        $result = $this->extract()->execute(
            array('content' => '<p>x</p>', 'selectors' => array('nested' => array('p'))),
            self::CONTEXT
        );

        $this->assertSame('', $result['data']['extracted_data']['nested']);
        $this->assertSame(1, $result['data']['fields_extracted']);
    }

    public function test_an_invalid_pattern_is_empty_rather_than_a_warning(): void
    {
        $result = $this->extract()->execute(
            array('content' => 'anything', 'selectors' => array('bad' => '[')),
            self::CONTEXT
        );

        $this->assertSame('', $result['data']['extracted_data']['bad']);
    }

    public function test_json_csv_and_markdown_output(): void
    {
        $arguments = array(
            'content'   => '<h1>Title</h1>',
            'selectors' => array('title' => 'h1'),
        );

        $json = $this->extract()->execute($arguments + array('output_format' => 'json'), self::CONTEXT);
        $csv  = $this->extract()->execute($arguments + array('output_format' => 'csv'), self::CONTEXT);
        $md   = $this->extract()->execute($arguments + array('output_format' => 'markdown'), self::CONTEXT);

        $this->assertSame(array('title' => 'Title'), json_decode($json['data']['formatted_output'], true));
        $this->assertSame("title,Title\n", $csv['data']['formatted_output']);
        $this->assertSame("# Extracted Data\n\n## Title\n\nTitle\n\n", $md['data']['formatted_output']);
    }

    public function test_content_and_selectors_are_required(): void
    {
        $this->assertSame(
            'content_selectors_required',
            $this->extract()->execute(array('selectors' => array('t' => 'h1')), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'content_selectors_required',
            $this->extract()->execute(array('content' => '<p>x</p>'), self::CONTEXT)['error']['code']
        );
        // An empty selector map is the same "missing" case in WordPress.
        $this->assertSame(
            'content_selectors_required',
            $this->extract()->execute(array('content' => '<p>x</p>', 'selectors' => array()), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'invalid_selectors',
            $this->extract()->execute(array('content' => '<p>x</p>', 'selectors' => 'h1'), self::CONTEXT)['error']['code']
        );
    }

    // ─── analyze_data_patterns ────────────────────────────────────────────

    public function test_the_summary_block_is_computed(): void
    {
        $result = $this->patterns()->execute(array('dataset' => array(1, 2, 3, 4)), self::CONTEXT);
        $stats  = $result['data']['analysis'];

        $this->assertSame(4, $stats['count']);
        $this->assertSame(1, $stats['min']);
        $this->assertSame(4, $stats['max']);
        $this->assertSame(2.5, $stats['mean']);
        $this->assertSame(2.5, $stats['median']);
        $this->assertSame(3, $stats['range']);
        $this->assertSame('increasing', $stats['trend']);
    }

    public function test_the_median_of_an_odd_count_is_the_middle_value(): void
    {
        $result = $this->patterns()->execute(array('dataset' => array(10, 1, 5)), self::CONTEXT);

        $this->assertSame(5.0, $result['data']['analysis']['median']);
    }

    public function test_trend_directions_and_a_single_point(): void
    {
        $down   = $this->patterns()->execute(array('dataset' => array(10, 9, 1, 1)), self::CONTEXT);
        $flat   = $this->patterns()->execute(array('dataset' => array(10, 10, 10, 10)), self::CONTEXT);
        $single = $this->patterns()->execute(array('dataset' => array(5)), self::CONTEXT);

        $this->assertSame('decreasing', $down['data']['analysis']['trend']);
        $this->assertSame('stable', $flat['data']['analysis']['trend']);
        // WordPress divided by an empty half and raised DivisionByZeroError.
        $this->assertSame('stable', $single['data']['analysis']['trend']);
    }

    public function test_frequency_buckets_round_to_two_decimals(): void
    {
        $result = $this->patterns()->execute(
            array('dataset' => array(1, 1, 2, 2, 2, 3.001, 3.004), 'analysis_type' => 'frequency'),
            self::CONTEXT
        );
        $buckets = $result['data']['analysis']['frequency'];

        $this->assertCount(3, $buckets);
        $this->assertSame(2.0, $buckets[0]['value']);
        $this->assertSame(3, $buckets[0]['count']);
        $this->assertSame(3.001, $buckets[2]['value']);
        $this->assertSame(2, $buckets[2]['count']);
    }

    public function test_correlation_is_against_position(): void
    {
        $strong = $this->patterns()->execute(
            array('dataset' => array(1, 2, 3, 4), 'analysis_type' => 'correlation'),
            self::CONTEXT
        );
        $short  = $this->patterns()->execute(
            array('dataset' => array(1), 'analysis_type' => 'correlation'),
            self::CONTEXT
        );

        $this->assertSame(1.0, $strong['data']['analysis']['correlation']['coefficient']);
        $this->assertSame('strong positive correlation', $strong['data']['analysis']['correlation']['interpretation']);
        $this->assertSame('insufficient data', $short['data']['analysis']['correlation']['interpretation']);
    }

    public function test_outliers_use_nearest_rank_fences(): void
    {
        $result = $this->patterns()->execute(
            array('dataset' => array(1, 2, 3, 4, 100), 'analysis_type' => 'outliers'),
            self::CONTEXT
        );
        $block = $result['data']['analysis']['outliers'];

        $this->assertSame(array(100.0), $block['outliers']);
        $this->assertSame(-1.0, $block['lower_bound']);
        $this->assertSame(7.0, $block['upper_bound']);
    }

    public function test_numeric_strings_survive_in_min_and_max(): void
    {
        $result = $this->patterns()->execute(array('dataset' => array('9', '10')), self::CONTEXT);
        $stats  = $result['data']['analysis'];

        $this->assertSame('9', $stats['min']);
        $this->assertSame('10', $stats['max']);
        $this->assertSame(9.5, $stats['mean']);
        $this->assertSame(1, $stats['range']);
    }

    public function test_data_without_numbers_is_refused(): void
    {
        $result = $this->patterns()->execute(
            array('dataset' => array('a', 'b', null)),
            self::CONTEXT
        );

        $this->assertSame('no_numeric_data', $result['error']['code']);
    }

    public function test_an_unknown_analysis_type_adds_no_block(): void
    {
        $result = $this->patterns()->execute(
            array('dataset' => array(1, 2, 3), 'analysis_type' => 'histogram'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('trend', $result['data']['analysis']);
        $this->assertArrayNotHasKey('frequency', $result['data']['analysis']);
    }

    public function test_high_variance_is_only_insighted_above_twice_the_mean(): void
    {
        $wide = $this->patterns()->execute(array('dataset' => array(1, 1, 1, 1000)), self::CONTEXT);
        $narrow = $this->patterns()->execute(array('dataset' => array(1, 2, 3)), self::CONTEXT);

        $this->assertContains('High variance detected - data is widely spread', $wide['data']['insights']);
        $this->assertNotContains('High variance detected - data is widely spread', $narrow['data']['insights']);
    }

    // ─── Shared ───────────────────────────────────────────────────────────

    public function test_every_utility_requires_a_user(): void
    {
        foreach (
            array(
                $this->password(),
                $this->markdown(),
                $this->extract(),
                $this->patterns(),
            ) as $tool
        ) {
            try {
                $tool->execute(array());
                $this->fail('Expected AccessDeniedException from ' . $tool->getSlug());
            } catch (AccessDeniedException $expected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function password(): GeneratePasswordTool
    {
        return new GeneratePasswordTool($this->errors);
    }

    private function markdown(): ConvertHtmlToMarkdownTool
    {
        return new ConvertHtmlToMarkdownTool($this->errors);
    }

    private function extract(): ExtractStructuredDataTool
    {
        return new ExtractStructuredDataTool($this->errors);
    }

    private function patterns(): AnalyzeDataPatternsTool
    {
        return new AnalyzeDataPatternsTool($this->errors);
    }
}
