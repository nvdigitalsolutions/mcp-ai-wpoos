<?php

/**
 * Tests for the capability flags the pure core tools declare.
 *
 * These nineteen tools have no WordPress counterpart with their slug, so their
 * flags are a decision rather than a port. The providers pin the decision, and
 * the last test asserts the property that makes the decision safe: nothing here
 * may carry a flag the read-only profile gates on.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use PHPUnit\Framework\TestCase;

final class PureToolCapabilityFlagsTest extends TestCase
{
    /**
     * @dataProvider deterministicTools
     *
     * @param class-string $class
     */
    public function test_a_deterministic_tool_declares_the_pure_local_flags(string $class): void
    {
        $this->assertSame(CapabilityFlagVocabulary::PURE_LOCAL, $this->tool($class)->getCapabilityFlags());
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function deterministicTools(): array
    {
        return array(
            'base64'            => array(\Nvoos\Core\Tool\Base64Tool::class),
            'color_convert'     => array(\Nvoos\Core\Tool\ColorConvertTool::class),
            'extract_domain'    => array(\Nvoos\Core\Tool\ExtractDomainTool::class),
            'format_bytes'      => array(\Nvoos\Core\Tool\FormatBytesTool::class),
            'format_date'       => array(\Nvoos\Core\Tool\FormatDateTool::class),
            'generate_chart'    => array(\Nvoos\Core\Tool\GenerateChartTool::class),
            'generate_slug'     => array(\Nvoos\Core\Tool\GenerateSlugTool::class),
            'hash_string'       => array(\Nvoos\Core\Tool\HashStringTool::class),
            'math_eval'         => array(\Nvoos\Core\Tool\MathEvalTool::class),
            'merge_arrays'      => array(\Nvoos\Core\Tool\MergeArraysTool::class),
            'parse_csv'         => array(\Nvoos\Core\Tool\ParseCsvTool::class),
            'strip_html'        => array(\Nvoos\Core\Tool\StripHtmlTool::class),
            'time_ago'          => array(\Nvoos\Core\Tool\TimeAgoTool::class),
            'truncate_text'     => array(\Nvoos\Core\Tool\TruncateTextTool::class),
            'validate_json'     => array(\Nvoos\Core\Tool\ValidateJsonTool::class),
            'validate_workflow' => array(\Nvoos\Core\Tool\ValidateWorkflowTool::class),
        );
    }

    /**
     * @dataProvider nonDeterministicTools
     *
     * @param class-string $class
     */
    public function test_a_non_deterministic_tool_is_not_cacheable(string $class): void
    {
        $flags = $this->tool($class)->getCapabilityFlags();

        $this->assertSame(CapabilityFlagVocabulary::PURE_LOCAL_NON_DETERMINISTIC, $flags);
        $this->assertContains('read-only', $flags);
        $this->assertNotContains('cacheable', $flags);
        $this->assertNotContains('idempotent', $flags);
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function nonDeterministicTools(): array
    {
        return array(
            'generate_mermaid' => array(\Nvoos\Core\Tool\GenerateMermaidTool::class),
            'generate_uuid'    => array(\Nvoos\Core\Tool\GenerateUuidTool::class),
        );
    }

    public function test_wait_for_user_is_read_only_but_not_cacheable(): void
    {
        $flags = $this->tool(\Nvoos\Core\Tool\WaitForUserTool::class)->getCapabilityFlags();

        $this->assertContains('read-only', $flags);
        $this->assertContains('local-only', $flags);
        $this->assertNotContains('cacheable', $flags);
    }

    /**
     * The property that makes these declarations safe: none of them may trip a
     * read-only profile, because none of them does anything to trip it.
     */
    public function test_no_pure_tool_carries_a_gated_or_outbound_flag(): void
    {
        foreach ($this->everyPureClass() as $class) {
            $flags = $this->tool($class)->getCapabilityFlags();

            $this->assertSame(
                array(),
                array_values(array_intersect($flags, CapabilityFlagVocabulary::READ_ONLY_GATED)),
                sprintf('%s declares a flag the read-only profile gates on.', $class)
            );

            $this->assertFalse(
                CapabilityFlagVocabulary::hasOutboundMutation($flags),
                sprintf('%s declares an outbound mutation.', $class)
            );
        }
    }

    /**
     * @return list<class-string>
     */
    private function everyPureClass(): array
    {
        $classes = array();

        foreach (self::deterministicTools() as $case) {
            $classes[] = $case[0];
        }

        foreach (self::nonDeterministicTools() as $case) {
            $classes[] = $case[0];
        }

        $classes[] = \Nvoos\Core\Tool\WaitForUserTool::class;

        return $classes;
    }

    /**
     * @param class-string $class
     */
    private function tool(string $class): ToolCapabilityFlagsInterface
    {
        $tool = new $class($this->createMock(ErrorFactoryInterface::class));

        $this->assertInstanceOf(ToolCapabilityFlagsInterface::class, $tool);

        return $tool;
    }
}
