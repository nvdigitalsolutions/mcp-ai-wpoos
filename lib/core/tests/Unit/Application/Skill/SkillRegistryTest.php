<?php
/**
 * Tests for the Agent Skills registry and its SKILL.md parser.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Skill;

use Nvoos\Core\Application\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class SkillRegistryTest extends TestCase
{
    private const SKILL = <<<'MD'
---
name: proposal-writer
description: Drafts and reviews project proposals.
license: MIT
compatibility: nvoos>=2.5
---

# Proposal writer

Use this skill when a proposal needs structure.
MD;

    public function test_parse_skill_md_reads_the_catalogue_fields(): void
    {
        $parsed = SkillRegistry::parseSkillMd(self::SKILL);

        $this->assertNotNull($parsed);
        $this->assertSame('proposal-writer', $parsed['name']);
        $this->assertSame('Drafts and reviews project proposals.', $parsed['description']);
        $this->assertSame('MIT', $parsed['license']);
        $this->assertSame('nvoos>=2.5', $parsed['compatibility']);
        $this->assertStringContainsString('Use this skill when a proposal needs structure.', $parsed['body']);
    }

    public function test_optional_fields_default_to_empty_strings(): void
    {
        $parsed = SkillRegistry::parseSkillMd("---\nname: minimal\ndescription: Just enough.\n---\n\nBody.\n");

        $this->assertNotNull($parsed);
        $this->assertSame('minimal', $parsed['name']);
        $this->assertSame('', $parsed['license']);
        $this->assertSame('', $parsed['compatibility']);
    }

    public function test_a_file_without_frontmatter_is_not_a_skill(): void
    {
        $this->assertNull(SkillRegistry::parseSkillMd("# Just markdown\n"));
    }

    public function test_a_file_without_a_name_is_not_a_skill(): void
    {
        $this->assertNull(SkillRegistry::parseSkillMd("---\ndescription: No name.\n---\n\nBody.\n"));
    }

    public function test_catalogue_is_sorted_and_carries_only_the_prompt_fields(): void
    {
        $registry = new SkillRegistry();
        $registry->register(['name' => 'zeta', 'description' => 'Z.', 'body' => 'z']);
        $registry->register(['name' => 'alpha', 'description' => 'A.', 'body' => 'a']);

        $this->assertSame(
            [
                ['name' => 'alpha', 'description' => 'A.'],
                ['name' => 'zeta', 'description' => 'Z.'],
            ],
            $registry->catalogue()
        );
    }

    public function test_load_from_directory_registers_each_skill_folder(): void
    {
        $root = sys_get_temp_dir().'/nvoos-skills-'.bin2hex(random_bytes(4));
        mkdir($root.'/proposal-writer', 0777, true);
        mkdir($root.'/empty-folder', 0777, true);
        file_put_contents($root.'/proposal-writer/SKILL.md', self::SKILL);

        try {
            $registry = new SkillRegistry();
            $loaded = $registry->loadFromDirectory($root);

            $this->assertSame(1, $loaded);
            $this->assertTrue($registry->has('proposal-writer'));
            $this->assertFalse($registry->has('empty-folder'));
        } finally {
            @unlink($root.'/proposal-writer/SKILL.md');
            @rmdir($root.'/proposal-writer');
            @rmdir($root.'/empty-folder');
            @rmdir($root);
        }
    }

    public function test_load_from_a_missing_directory_is_zero_not_an_error(): void
    {
        $registry = new SkillRegistry();

        $this->assertSame(0, $registry->loadFromDirectory(sys_get_temp_dir().'/nvoos-skills-missing'));
    }
}
