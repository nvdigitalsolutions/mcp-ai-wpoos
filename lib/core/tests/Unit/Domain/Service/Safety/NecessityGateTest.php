<?php

/**
 * Tests for the necessity gate.
 *
 * Two properties matter and both are pinned here: the gate never guesses
 * `unnecessary` from ambiguous input, and every verdict it returns is a
 * structured decision a runtime can render rather than an error it has to
 * interpret.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Safety;

use Nvoos\Core\Domain\Service\Safety\ActionSafetyProfile;
use Nvoos\Core\Domain\Service\Safety\NecessityGate;
use PHPUnit\Framework\TestCase;

final class NecessityGateTest extends TestCase
{
    /**
     * @dataProvider safeToolCases
     *
     * @param array<int, string> $flags
     */
    public function test_safe_tools_need_no_further_thought(array $flags, bool $expected): void
    {
        $this->assertSame($expected, NecessityGate::isSafeTool($flags));
    }

    public static function safeToolCases(): array
    {
        return array(
            'read-only' => array(array('read-only'), true),
            'read-only with a benign flag' => array(array('read-only', 'external-api'), true),
            'case insensitive' => array(array('READ-ONLY'), true),
            'write' => array(array('write'), false),
            'read-only but destructive' => array(array('read-only', 'data-destruction'), false),
            'read-only but irreversible' => array(array('read-only', 'irreversible'), false),
            'read-only but financial' => array(array('read-only', 'financial-impact'), false),
            'read-only but sends mail' => array(array('read-only', 'external-communication'), false),
            'read-only but sends mail, underscore' => array(array('read-only', 'external_side_effect'), false),
            'read-only but writes over the api' => array(array('read-only', 'external-api', 'write'), false),
            'read-only with a network lookup' => array(array('read-only', 'network-dependent'), true),
            'read-only with underscore state change' => array(array('read-only', 'state_changing'), false),
            'read-only contradicting itself with write' => array(array('read-only', 'write'), false),
            'read-only but changes access' => array(array('read-only', 'access-control-change'), false),
            'read-only but mass mails' => array(array('read-only', 'mass-email'), false),
            'undeclared' => array(array(), false),
        );
    }

    /**
     * @dataProvider necessityCases
     *
     * @param array<string, mixed> $arguments
     */
    public function test_necessity_classification(string $slug, array $arguments, string $expected): void
    {
        $this->assertSame($expected, NecessityGate::classifyNecessity($slug, $arguments));
    }

    public static function necessityCases(): array
    {
        return array(
            'no arguments at all' => array('update_setting', array(), ActionSafetyProfile::NECESSITY_OPTIONAL),
            'ordinary call' => array('create_post', array('title' => 'x'), ActionSafetyProfile::NECESSITY_HELPFUL),
            'search with no query' => array('web_search', array('limit' => 5), ActionSafetyProfile::NECESSITY_OPTIONAL),
            'search with a query' => array('web_search', array('query' => 'weather'), ActionSafetyProfile::NECESSITY_HELPFUL),
            'search with the older key' => array('brave_local_search', array('search' => 'cafe'), ActionSafetyProfile::NECESSITY_HELPFUL),
            'id tool with no id' => array('get_post', array('fields' => 'title'), ActionSafetyProfile::NECESSITY_OPTIONAL),
            'id tool with an id' => array('get_post', array('post_id' => 7), ActionSafetyProfile::NECESSITY_HELPFUL),
            'id tool with a zero id' => array('delete_post', array('post_id' => 0), ActionSafetyProfile::NECESSITY_UNNECESSARY),
            'delete with no target' => array('delete_post', array(), ActionSafetyProfile::NECESSITY_UNNECESSARY),
            'email with no recipient' => array('send_email', array('subject' => 'hi'), ActionSafetyProfile::NECESSITY_UNNECESSARY),
            'email with a recipient' => array('send_email', array('to' => 'a@b.c'), ActionSafetyProfile::NECESSITY_HELPFUL),
            'product with no reference' => array('create_woo_product', array('name' => 'x'), ActionSafetyProfile::NECESSITY_UNNECESSARY),
            'product with a sku' => array('create_woo_product', array('sku' => 'ABC'), ActionSafetyProfile::NECESSITY_HELPFUL),
        );
    }

    public function test_a_classifier_override_wins(): void
    {
        $classifier = static fn (string $slug, array $arguments): ?string => 'essential';

        $this->assertSame(
            ActionSafetyProfile::NECESSITY_ESSENTIAL,
            NecessityGate::classifyNecessity('delete_post', array(), $classifier)
        );
    }

    public function test_an_invalid_classifier_override_falls_through(): void
    {
        $classifier = static fn (string $slug, array $arguments): ?string => 'nonsense';

        $this->assertSame(
            ActionSafetyProfile::NECESSITY_UNNECESSARY,
            NecessityGate::classifyNecessity('delete_post', array(), $classifier)
        );
    }

    public function test_a_safe_tool_is_allowed_without_classifying(): void
    {
        $decision = NecessityGate::decide(array('read-only'), 'list_posts', array());

        $this->assertSame(ActionSafetyProfile::VERDICT_ALLOW, $decision['verdict']);
        $this->assertSame(NecessityGate::REASON_SAFE_TOOL, $decision['reason_code']);
        $this->assertNull($decision['necessity'], 'Tier 1 short-circuits before classification.');
        $this->assertSame(0.0, $decision['irreversibility']);
        $this->assertFalse($decision['requires_approval']);
    }

    /**
     * A delete with no target is the canonical overeager call: unnecessary, and
     * only moderately damaging, so it is skipped rather than surfaced as an
     * error the model has to recover from.
     */
    public function test_an_overeager_write_is_skipped(): void
    {
        $decision = NecessityGate::decide(array('write', 'state-changing'), 'delete_post', array());

        $this->assertSame(ActionSafetyProfile::VERDICT_SKIP, $decision['verdict']);
        $this->assertSame(ActionSafetyProfile::REASON_UNNECESSARY_SKIPPED, $decision['reason_code']);
        $this->assertSame(1.5, $decision['risk_score']);
        $this->assertFalse($decision['requires_approval']);
    }

    public function test_an_overeager_permanent_action_is_blocked(): void
    {
        $decision = NecessityGate::decide(array('irreversible'), 'delete_post', array());

        $this->assertSame(ActionSafetyProfile::VERDICT_BLOCK, $decision['verdict']);
        $this->assertSame(ActionSafetyProfile::REASON_UNNECESSARY_IRREVERSIBLE, $decision['reason_code']);
        $this->assertTrue($decision['requires_approval']);
    }

    public function test_a_targeted_moderate_write_warns(): void
    {
        $decision = NecessityGate::decide(array('write', 'state-changing'), 'delete_post', array('post_id' => 12));

        $this->assertSame(ActionSafetyProfile::VERDICT_WARN, $decision['verdict']);
        $this->assertSame(ActionSafetyProfile::REASON_HELPFUL_WITHIN_BOUNDS, $decision['reason_code']);
        $this->assertSame(1.0, $decision['risk_score']);
    }

    /**
     * Sending mail is externally irreversible, so a needed send still needs a
     * human — that is the whole point of the matrix.
     */
    public function test_a_needed_external_send_requires_approval(): void
    {
        $decision = NecessityGate::decide(
            array('external-communication'),
            'send_email',
            array('to' => 'a@b.c', 'subject' => 'hi')
        );

        $this->assertSame(ActionSafetyProfile::VERDICT_APPROVAL_REQUIRED, $decision['verdict']);
        $this->assertSame(ActionSafetyProfile::REASON_HELPFUL_NEEDS_APPROVAL, $decision['reason_code']);
        $this->assertTrue($decision['requires_approval']);
    }

    public function test_an_explicit_irreversibility_score_beats_the_flags(): void
    {
        $fromFlags = NecessityGate::decide(array('write', 'state-changing'), 'create_post', array('title' => 'x'));
        $explicit = NecessityGate::decide(
            array('write', 'state-changing'),
            'create_post',
            array('title' => 'x'),
            ActionSafetyProfile::IRREVERSIBILITY_PERMANENT
        );

        $this->assertSame(ActionSafetyProfile::VERDICT_WARN, $fromFlags['verdict']);
        $this->assertSame(ActionSafetyProfile::VERDICT_BLOCK, $explicit['verdict']);
        $this->assertSame(1.0, $explicit['irreversibility']);
    }
}
