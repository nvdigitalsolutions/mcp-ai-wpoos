<?php

/**
 * Tests for the pre-approved corpus.
 *
 * The list exists to spend budget, not to waive judgement, so what matters is
 * that the default is exactly the paid-generation shape, that a missing
 * configuration keeps the default, and that an explicit empty list really does
 * restore the prompts — the difference between "unset" and "none" is the whole
 * reason the override is nullable.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Safety;

use Nvoos\Core\Domain\Service\Safety\PreApprovedTools;
use PHPUnit\Framework\TestCase;

final class PreApprovedToolsTest extends TestCase
{
    public function test_the_default_list_is_paid_generation(): void
    {
        $this->assertContains('generate_gemini_image', PreApprovedTools::slugs());
        $this->assertContains('edit_gemini_image_validated', PreApprovedTools::slugs());
        $this->assertContains('generate_veo_video_validated', PreApprovedTools::slugs());
    }

    public function test_a_destructive_tool_is_not_pre_approved(): void
    {
        $this->assertFalse(PreApprovedTools::contains('cancel_higgsfield_request'));
        $this->assertFalse(PreApprovedTools::contains('delete_post'));
        $this->assertFalse(PreApprovedTools::contains('send_group_email'));
    }

    public function test_an_unknown_slug_is_not_pre_approved(): void
    {
        $this->assertFalse(PreApprovedTools::contains('not_a_real_tool'));
    }

    public function test_null_keeps_the_default(): void
    {
        $this->assertTrue(PreApprovedTools::contains('generate_sora_video', null));
    }

    public function test_an_empty_list_restores_the_prompt(): void
    {
        $this->assertFalse(PreApprovedTools::contains('generate_sora_video', array()));
    }

    public function test_a_custom_list_replaces_the_default(): void
    {
        $this->assertTrue(PreApprovedTools::contains('my_tool', array('my_tool')));
        $this->assertFalse(PreApprovedTools::contains('generate_sora_video', array('my_tool')));
    }
}
