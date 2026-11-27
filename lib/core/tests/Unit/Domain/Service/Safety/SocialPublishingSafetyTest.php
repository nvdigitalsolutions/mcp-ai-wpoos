<?php

/**
 * A social publish is an irreversible send, and the gate must say so.
 *
 * The four per-platform posters (`post_facebook_instagram`,
 * `post_linkedin_update`, `post_tiktok_video`, `post_google_business_update`)
 * call the platform's API from `execute()`, and they declared
 * `read-only, local-only`. That put them on `NecessityGate::isSafeTool()`'s fast
 * path: no approval, no warning, and — because the MCP annotations are derived
 * from the same flags — a `readOnlyHint: true` that told every client the call
 * was safe to auto-run.
 *
 * `post_to_multiple_platforms` and `publish_to_social` were quieter but the same
 * shape: `external-api` without a mutation flag lands on `MODERATE`, which a
 * helpful call treats as a warning, not a decision.
 *
 * These tests pin the *policy*, not the file: they read the generated catalogue
 * and ask the gate. A future edit that re-adds `read-only` to a poster fails
 * here rather than in production.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Safety;

use Nvoos\Core\Domain\Service\Safety\ActionSafetyProfile;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Domain\Service\Safety\NecessityGate;
use Nvoos\Core\Domain\Service\Safety\ToolCapabilityFlagCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialPublishingSafetyTest extends TestCase
{
    /**
     * Slugs whose `execute()` reaches a social platform's write API.
     *
     * Kept as a literal list rather than a prefix rule: `post_to_multiple_platforms`
     * and `publish_to_social` publish without starting with `post_`, and a rule
     * broad enough to catch them would sweep in `generate_post_ideas`.
     *
     * @return array<string, array{0: string}>
     */
    public static function publishingSlugs(): array
    {
        return array(
            'facebook/instagram' => array('post_facebook_instagram'),
            'linkedin' => array('post_linkedin_update'),
            'tiktok' => array('post_tiktok_video'),
            'google business' => array('post_google_business_update'),
            'multi-platform' => array('post_to_multiple_platforms'),
            'single platform' => array('publish_to_social'),
        );
    }

    /**
     * Slugs that only read, generate text, or analyse — none of them publishes.
     *
     * @return array<string, array{0: string}>
     */
    public static function readOnlySlugs(): array
    {
        return array(
            'captions' => array('generate_social_captions'),
            'post ideas' => array('generate_post_ideas'),
            'analytics' => array('get_social_analytics'),
            'cross-platform analytics' => array('get_cross_platform_analytics'),
            'instagram insights' => array('get_facebook_instagram_insights'),
            'linkedin insights' => array('get_linkedin_insights'),
            'tiktok insights' => array('get_tiktok_insights'),
            'google business insights' => array('get_google_business_insights'),
            'mentions' => array('monitor_mentions_replies'),
            'hashtags' => array('track_hashtag_performance'),
            'listening' => array('social_listening_trends'),
            'competitors' => array('competitor_analysis'),
            'influencers' => array('influencer_identification'),
            'calendar' => array('get_content_calendar'),
        );
    }

    #[DataProvider('publishingSlugs')]
    public function test_publishing_to_a_platform_requires_human_approval(string $slug): void
    {
        $this->assertTrue(
            ToolCapabilityFlagCatalogue::has($slug),
            $slug . ' has no catalogue row, so the gate would treat its flags as undeclared.'
        );

        $decision = NecessityGate::decide(
            ToolCapabilityFlagCatalogue::for($slug),
            $slug,
            // A well-formed call: content and a platform. Without arguments the
            // gate would classify the call OPTIONAL and prove nothing about the
            // flags.
            array('platform' => 'linkedin', 'content' => 'A published sentence.', 'dry_run' => false)
        );

        $this->assertSame(
            ActionSafetyProfile::VERDICT_APPROVAL_REQUIRED,
            $decision['verdict'],
            $slug . ' publishes to the outside world and must not run without a human: ' . $decision['reason']
        );

        $this->assertTrue($decision['requires_approval']);
        $this->assertSame(
            ActionSafetyProfile::IRREVERSIBILITY_PERMANENT,
            $decision['irreversibility'],
            $slug . ' must declare itself irreversible: a published post has been seen.'
        );
    }

    #[DataProvider('publishingSlugs')]
    public function test_no_publisher_claims_to_be_read_only(string $slug): void
    {
        $flags = CapabilityFlagVocabulary::normalise(ToolCapabilityFlagCatalogue::for($slug));

        $this->assertNotContains(
            'read-only',
            $flags,
            $slug . ' declares read-only, which both the fast path and the MCP annotation believe.'
        );

        $this->assertFalse(
            NecessityGate::isSafeTool($flags),
            $slug . ' is on the safe-tool fast path, so no guard ever sees the call.'
        );
    }

    #[DataProvider('readOnlySlugs')]
    public function test_a_read_only_social_tool_stays_on_the_safe_path(string $slug): void
    {
        $this->assertTrue(
            ToolCapabilityFlagCatalogue::has($slug),
            $slug . ' has no catalogue row.'
        );

        $flags = CapabilityFlagVocabulary::normalise(ToolCapabilityFlagCatalogue::for($slug));

        $this->assertContains(
            'read-only',
            $flags,
            $slug . ' reads or generates text and should be safe to auto-call.'
        );

        $this->assertTrue(
            NecessityGate::isSafeTool($flags),
            $slug . ' reads or generates text, so it should not cost an approval round trip: ' . implode(', ', $flags)
        );
    }
}
