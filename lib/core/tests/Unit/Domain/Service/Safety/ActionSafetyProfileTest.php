<?php

/**
 * Tests for the action safety matrix.
 *
 * The matrix decides whether an agent may run a tool call, so the test pins
 * every cell of it. The expected verdicts were captured by running the
 * WordPress implementation over the same 20 combinations and asserting the two
 * agree, not by reading the code and hoping.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Safety;

use Nvoos\Core\Domain\Service\Safety\ActionSafetyProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActionSafetyProfileTest extends TestCase
{
    #[DataProvider('matrix')]
    public function test_the_matrix_matches_the_wordpress_original(
        float $irreversibility,
        string $necessity,
        string $expectedVerdict,
        float $expectedRisk,
        bool $expectedApproval
    ): void {
        $verdict = ActionSafetyProfile::determineVerdict($irreversibility, $necessity);

        $this->assertSame($expectedVerdict, $verdict['verdict']);
        $this->assertSame($expectedRisk, $verdict['risk_score']);
        $this->assertSame($expectedApproval, $verdict['requires_approval']);
        $this->assertNotSame('', $verdict['reason_code'], 'Every verdict carries a stable reason code.');
    }

    public static function matrix(): array
    {
        // [irreversibility, necessity, verdict, risk, requires_approval]
        $cells = array(
            array(0.0, 'essential', 'allow', 0.0, false),
            array(0.25, 'essential', 'allow', 0.25, false),
            array(0.5, 'essential', 'allow', 0.5, false),
            array(0.75, 'essential', 'warn', 0.75, false),
            array(1.0, 'essential', 'approval_required', 1.0, true),

            array(0.0, 'helpful', 'allow', 0.5, false),
            array(0.25, 'helpful', 'allow', 0.75, false),
            array(0.5, 'helpful', 'warn', 1.0, false),
            array(0.75, 'helpful', 'approval_required', 1.25, true),
            // §156: was 'block'. `classifyNecessity()` cannot return `essential`
            // without an explicit classifier override, so `helpful` is the
            // ceiling — which made this cell a hard block on every permanent
            // action (send_group_email, delete_post). A needed, well-formed
            // permanent action is the case a human should be asked about.
            array(1.0, 'helpful', 'approval_required', 1.5, true),

            array(0.0, 'optional', 'warn', 0.75, false),
            array(0.25, 'optional', 'warn', 1.0, false),
            array(0.5, 'optional', 'approval_required', 1.25, true),
            array(0.75, 'optional', 'block', 1.5, true),
            array(1.0, 'optional', 'block', 1.75, true),

            array(0.0, 'unnecessary', 'skip', 1.0, false),
            array(0.25, 'unnecessary', 'skip', 1.25, false),
            array(0.5, 'unnecessary', 'skip', 1.5, false),
            array(0.75, 'unnecessary', 'block', 1.75, true),
            array(1.0, 'unnecessary', 'block', 2.0, true),
        );

        $named = array();
        foreach ($cells as $cell) {
            $named[ $cell[1] . ' @ ' . $cell[0] ] = $cell;
        }

        return $named;
    }

    /**
     * The boundary is deliberate: moderate irreversibility is the last rung
     * before a human looks at it, so exactly 0.5 warns while 0.25 allows.
     */
    public function test_helpful_warns_at_exactly_moderate_irreversibility(): void
    {
        $this->assertSame(
            ActionSafetyProfile::VERDICT_ALLOW,
            ActionSafetyProfile::determineVerdict(0.25, 'helpful')['verdict']
        );
        $this->assertSame(
            ActionSafetyProfile::VERDICT_WARN,
            ActionSafetyProfile::determineVerdict(0.5, 'helpful')['verdict']
        );
    }

    public function test_an_unknown_necessity_is_weighted_as_helpful(): void
    {
        $this->assertSame(
            ActionSafetyProfile::computeRiskScore(0.5, 'helpful'),
            ActionSafetyProfile::computeRiskScore(0.5, 'not-a-level')
        );
    }

    public function test_the_risk_score_is_clamped_to_the_scale(): void
    {
        $this->assertSame(0.0, ActionSafetyProfile::computeRiskScore(-3.0, 'essential'));
        $this->assertSame(2.0, ActionSafetyProfile::computeRiskScore(9.0, 'unnecessary'));
    }

    /**
     * @param array<int, string> $flags
     */
    #[DataProvider('flagDerivations')]
    public function test_irreversibility_is_derived_from_flags(array $flags, float $expected): void
    {
        $this->assertSame($expected, ActionSafetyProfile::deriveIrreversibilityFromFlags($flags));
    }

    public static function flagDerivations(): array
    {
        return array(
            'undeclared errs high' => array(array(), 0.5),
            'read-only' => array(array('read-only'), 0.0),
            'read-only is not cancelled by external-api' => array(array('read-only', 'external-api'), 0.0),
            'write alone is not state-changing' => array(array('write'), 0.5),
            'write + state-changing' => array(array('write', 'state-changing'), 0.5),
            'write + underscore state_changing' => array(array('write', 'state_changing'), 0.5),
            'outbound write is high' => array(array('external-api', 'write'), 0.75),
            'underscore outbound state change is high' => array(array('external_api', 'state_changing'), 0.75),
            'underscore side effect aliases to a send' => array(array('external_side_effect'), 0.75),
            'read-only network lookup stays benign' => array(array('read-only', 'network-dependent'), 0.0),
            'mass mail is high' => array(array('mass-email'), 0.75),
            'a contradictory read-only write does not score zero' => array(array('read-only', 'write'), 0.5),
            'reversible write' => array(array('write', 'state-changing', 'reversible'), 0.25),
            'irreversible trumps everything' => array(array('irreversible'), 1.0),
            'financial impact' => array(array('financial-impact'), 0.75),
            'external communication' => array(array('external-communication'), 0.75),
            'data destruction' => array(array('data-destruction'), 0.75),
            'access control change' => array(array('access-control-change'), 0.75),
            'dangerous flag beats read-only' => array(array('read-only', 'irreversible'), 1.0),
            'flags are matched case-insensitively' => array(array('READ-ONLY'), 0.0),
        );
    }

    public function test_irreversibility_and_necessity_validators(): void
    {
        foreach (array(0.0, 0.25, 0.5, 0.75, 1.0) as $score) {
            $this->assertTrue(ActionSafetyProfile::isValidIrreversibility($score));
        }

        $this->assertFalse(ActionSafetyProfile::isValidIrreversibility(0.6));

        foreach (array('essential', 'helpful', 'optional', 'unnecessary') as $level) {
            $this->assertTrue(ActionSafetyProfile::isValidNecessity($level));
        }

        $this->assertFalse(ActionSafetyProfile::isValidNecessity('essentialish'));
    }
}
