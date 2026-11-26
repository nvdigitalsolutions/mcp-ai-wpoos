<?php

/**
 * Tests for the capability flags the remaining native tools declare.
 *
 * These thirty have no WordPress counterpart with their slug, so each flag set
 * is a decision. The providers pin the decision; the last two cases pin the
 * properties that make the decisions safe — reads stay out of the read-only
 * gate, and writes stay inside it.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Domain\Service\Safety\ToolCapabilityFlagCatalogue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class NativeToolCapabilityFlagsTest extends TestCase
{
    /**
     * @dataProvider pureLocalTools
     *
     * @param class-string $class
     */
    public function test_a_pure_tool_declares_the_pure_local_flags(string $class): void
    {
        $this->assertSame(CapabilityFlagVocabulary::PURE_LOCAL, $this->tool($class)->getCapabilityFlags());
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function pureLocalTools(): array
    {
        return array(
            'list_skills'        => array(\Nvoos\Core\Tool\ListSkillsTool::class),
            'verify_information' => array(\Nvoos\Core\Tool\VerifyInformationTool::class),
        );
    }

    /**
     * @dataProvider localReadTools
     *
     * @param class-string $class
     */
    public function test_a_store_read_declares_the_local_read_flags(string $class): void
    {
        $flags = $this->tool($class)->getCapabilityFlags();

        $this->assertSame(CapabilityFlagVocabulary::LOCAL_READ, $flags);
        $this->assertNotContains(
            'cacheable',
            $flags,
            'A store read is not cacheable: the store can change between calls.'
        );
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function localReadTools(): array
    {
        return array(
            'check_capability'    => array(\Nvoos\Core\Tool\CheckCapabilityTool::class),
            'count_posts'         => array(\Nvoos\Core\Tool\CountPostsTool::class),
            'get_cache'           => array(\Nvoos\Core\Tool\GetCacheTool::class),
            'get_current_user'    => array(\Nvoos\Core\Tool\GetCurrentUserTool::class),
            'get_file_info'       => array(\Nvoos\Core\Tool\GetFileInfoTool::class),
            'get_job_status'      => array(\Nvoos\Core\Tool\GetJobStatusTool::class),
            'get_post_meta'       => array(\Nvoos\Core\Tool\GetPostMetaTool::class),
            'get_post_taxonomies' => array(\Nvoos\Core\Tool\GetPostTaxonomiesTool::class),
            'get_setting'         => array(\Nvoos\Core\Tool\GetSettingTool::class),
            'list_jobs'           => array(\Nvoos\Core\Tool\ListJobsTool::class),
            'list_settings'       => array(\Nvoos\Core\Tool\ListSettingsTool::class),
            'profession_stats'    => array(\Nvoos\Core\Tool\ProfessionStatsTool::class),
        );
    }

    /**
     * @dataProvider reversibleWriteTools
     *
     * @param class-string $class
     */
    public function test_a_reversible_write_declares_the_reversible_flags(string $class): void
    {
        $this->assertSame(
            CapabilityFlagVocabulary::LOCAL_WRITE_REVERSIBLE,
            $this->tool($class)->getCapabilityFlags()
        );
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function reversibleWriteTools(): array
    {
        return array(
            'delete_cache'    => array(\Nvoos\Core\Tool\DeleteCacheTool::class),
            'increment_cache' => array(\Nvoos\Core\Tool\IncrementCacheTool::class),
            'set_cache'       => array(\Nvoos\Core\Tool\SetCacheTool::class),
            'cancel_job'      => array(\Nvoos\Core\Tool\CancelJobTool::class),
            'enqueue_job'     => array(\Nvoos\Core\Tool\EnqueueJobTool::class),
            'schedule_job'    => array(\Nvoos\Core\Tool\ScheduleJobTool::class),
            'unschedule_job'  => array(\Nvoos\Core\Tool\UnscheduleJobTool::class),
        );
    }

    /**
     * @dataProvider persistentWriteTools
     *
     * @param class-string $class
     */
    public function test_a_persistent_write_is_not_declared_reversible(string $class): void
    {
        $flags = $this->tool($class)->getCapabilityFlags();

        $this->assertSame(CapabilityFlagVocabulary::LOCAL_WRITE, $flags);
        $this->assertNotContains('reversible', $flags);
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function persistentWriteTools(): array
    {
        return array(
            'bulk_import'    => array(\Nvoos\Core\Tool\BulkImportTool::class),
            'delete_setting' => array(\Nvoos\Core\Tool\DeleteSettingTool::class),
            'dispatch_event' => array(\Nvoos\Core\Tool\DispatchEventTool::class),
            'update_post'    => array(\Nvoos\Core\Tool\UpdatePostTool::class),
            'update_setting' => array(\Nvoos\Core\Tool\UpdateSettingTool::class),
            'upload_file'    => array(\Nvoos\Core\Tool\UploadFileTool::class),
        );
    }

    public function test_deleting_a_file_is_the_only_destructive_decision(): void
    {
        $flags = $this->tool(\Nvoos\Core\Tool\DeleteFileTool::class)->getCapabilityFlags();

        $this->assertSame(CapabilityFlagVocabulary::LOCAL_DESTRUCTIVE, $flags);
        $this->assertTrue(CapabilityFlagVocabulary::has($flags, 'irreversible'));
        $this->assertTrue(CapabilityFlagVocabulary::has($flags, 'data-destruction'));
    }

    public function test_the_remote_tool_is_outbound_without_being_a_mutation(): void
    {
        $flags = $this->tool(\Nvoos\Core\Tool\GenerateCloudflareAIImageTool::class)->getCapabilityFlags();

        $this->assertSame(CapabilityFlagVocabulary::REMOTE_CREDENTIALED, $flags);
        $this->assertTrue(CapabilityFlagVocabulary::has($flags, 'external-api'));
        $this->assertFalse(
            CapabilityFlagVocabulary::hasOutboundMutation($flags),
            'It returns the image; it does not persist it.'
        );
    }

    public function test_the_validated_sora_tool_inherits_its_parents_flags(): void
    {
        $this->assertSame(
            ToolCapabilityFlagCatalogue::for('generate_sora_video'),
            $this->tool(\Nvoos\Core\Tool\GenerateSoraVideoValidatedTool::class)->getCapabilityFlags()
        );
    }

    /**
     * Reads must not trip a read-only profile, or the profile is useless.
     */
    public function test_no_read_tool_carries_a_gated_or_outbound_flag(): void
    {
        foreach ($this->readClasses() as $class) {
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
     * Writes must trip it, or the profile is not a read-only profile.
     */
    public function test_every_write_tool_is_denied_by_a_read_only_profile(): void
    {
        foreach ($this->writeClasses() as $class) {
            $flags = $this->tool($class)->getCapabilityFlags();

            $this->assertNotSame(
                array(),
                array_values(array_intersect($flags, CapabilityFlagVocabulary::READ_ONLY_GATED)),
                sprintf('%s writes but carries no gated flag.', $class)
            );
        }
    }

    /**
     * @return list<class-string>
     */
    private function readClasses(): array
    {
        $classes = array();

        foreach (self::pureLocalTools() as $case) {
            $classes[] = $case[0];
        }

        foreach (self::localReadTools() as $case) {
            $classes[] = $case[0];
        }

        return $classes;
    }

    /**
     * @return list<class-string>
     */
    private function writeClasses(): array
    {
        $classes = array();

        foreach (self::reversibleWriteTools() as $case) {
            $classes[] = $case[0];
        }

        foreach (self::persistentWriteTools() as $case) {
            $classes[] = $case[0];
        }

        $classes[] = \Nvoos\Core\Tool\DeleteFileTool::class;

        return $classes;
    }

    /**
     * Build the tool, mocking every constructor dependency.
     *
     * @param class-string $class
     */
    private function tool(string $class): ToolCapabilityFlagsInterface
    {
        $reflection  = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $arguments   = array();

        foreach ($constructor?->getParameters() ?? array() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                $arguments[] = $parameter->isDefaultValueAvailable()
                    ? $parameter->getDefaultValue()
                    : null;
                continue;
            }

            $arguments[] = $this->createMock($type->getName());
        }

        $tool = $reflection->newInstanceArgs($arguments);

        $this->assertInstanceOf(ToolCapabilityFlagsInterface::class, $tool);

        return $tool;
    }
}
