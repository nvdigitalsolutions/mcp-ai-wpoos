<?php

/**
 * The sixteenth regulatory-registration slice: the registration timeline.
 *
 * Proposal 164 section 164.163. What is pinned: an unsubmitted registration is
 * in preparation, a submitted one is under review, and an **approved one is
 * complete** — the WordPress status rule never finished the review phase, so an
 * approved registration reported 66.67% and "Under Review" forever; the country
 * row wins over the type row and the chosen row is named in `basis`; `total` is
 * the sum of the phases; an unreadable date is named rather than computed as
 * 1970; and the processing-time variance compares actual to expected.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Regulatory\GetRegistrationTimelineTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryTimelineTest extends TestCase
{
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

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message = 'Resource not found.'): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message),
            )
        );
    }

    // ─── Phases ──────────────────────────────────────────────────────────

    public function test_an_unsubmitted_registration_is_in_preparation(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));

        $result = $this->timeline($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Preparation', $result['data']['progress']['current_phase']);
        $this->assertSame(0, $result['data']['progress']['completed_milestones']);
        $this->assertSame(0.0, $result['data']['progress']['percentage']);
        $this->assertSame('in_progress', $result['data']['milestones'][0]['status']);
        $this->assertSame('pending', $result['data']['milestones'][1]['status']);
        $this->assertSame('pending', $result['data']['milestones'][2]['status']);
    }

    public function test_a_submitted_registration_is_under_review(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10, array('submission_date' => $this->ago(10))));

        $result = $this->timeline($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        $this->assertSame('Under Review', $result['data']['progress']['current_phase']);
        $this->assertSame(1, $result['data']['progress']['completed_milestones']);
        $this->assertSame(33.33, $result['data']['progress']['percentage']);
        $this->assertSame('completed', $result['data']['milestones'][0]['status']);
        $this->assertSame('in_progress', $result['data']['milestones'][1]['status']);
        $this->assertSame(10, $result['data']['time_analysis']['days_since_submission']);
        $this->assertArrayHasKey('expected_approval_date', $result['data']['time_analysis']);
    }

    public function test_an_approved_registration_is_complete(): void
    {
        $store = new InMemoryContentStore();
        $store->add(
            $this->registration(
                10,
                array('submission_date' => $this->ago(100), 'approval_date' => $this->ago(20))
            )
        );

        $result = $this->timeline($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        // WordPress left the review in_progress whenever a submission existed,
        // so this registration read 66.67% and "Under Review" for ever.
        $this->assertSame(3, $result['data']['progress']['completed_milestones']);
        $this->assertSame(100.0, $result['data']['progress']['percentage']);
        $this->assertSame('Completed', $result['data']['progress']['current_phase']);
        $this->assertSame('completed', $result['data']['milestones'][1]['status']);
        $this->assertSame($this->ago(20), $result['data']['milestones'][2]['actual_date']);
    }

    // ─── The expectation table ───────────────────────────────────────────

    public function test_the_country_row_wins_over_the_type_row(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10, array('country' => 'LK', 'registration_type' => 'variation')));

        $result = $this->timeline($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        $timeline = $result['data']['expected_timeline'];

        // Sri Lanka has no variation row, so the country's new row applies
        // before the default variation schedule — the WordPress precedence.
        $this->assertSame('LK/new', $timeline['basis']);
        $this->assertSame(45, $timeline['preparation']);
        $this->assertSame(120, $timeline['review']);
    }

    public function test_total_is_the_sum_of_the_phases(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10, array('country' => 'LK')));

        $result = $this->timeline($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        $timeline = $result['data']['expected_timeline'];

        $this->assertSame(
            $timeline['preparation'] + $timeline['submission'] + $timeline['review']
                + $timeline['queries'] + $timeline['approval'],
            $timeline['total']
        );
        $this->assertSame(210, $timeline['total']);
    }

    public function test_an_unknown_country_uses_the_default_row_for_the_type(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10, array('country' => 'XX', 'registration_type' => 'renewal')));

        $result = $this->timeline($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        $this->assertSame('default/renewal', $result['data']['expected_timeline']['basis']);
        $this->assertSame(96, $result['data']['expected_timeline']['total']);
    }

    // ─── Unreadable dates and arithmetic ─────────────────────────────────

    public function test_an_unreadable_submission_date_is_named_and_not_dated(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10, array('submission_date' => 'sometime last spring')));

        $result = $this->timeline($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        $this->assertSame(array('submission_date'), $result['data']['unreadable_dates']);
        $this->assertSame('sometime last spring', $result['data']['dates']['submission']);
        $this->assertSame('Preparation', $result['data']['progress']['current_phase']);
        $this->assertSame(array(), $result['data']['time_analysis']);
        $this->assertNull($result['data']['milestones'][1]['target_date']);
    }

    public function test_processing_time_compares_actual_to_expected(): void
    {
        $store = new InMemoryContentStore();
        $store->add(
            $this->registration(
                10,
                array('submission_date' => $this->ago(100), 'approval_date' => $this->ago(20))
            )
        );

        $result = $this->timeline($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        $analysis = $result['data']['time_analysis'];

        $this->assertSame(80, $analysis['actual_processing_time']);
        $this->assertSame(210, $analysis['expected_processing_time']);
        $this->assertSame(-130, $analysis['processing_time_variance']);
        $this->assertSame(20, $analysis['days_since_approval']);
        $this->assertArrayNotHasKey('expected_approval_date', $analysis);
    }

    public function test_expiry_status_follows_the_stored_date(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->registration(1, array('expiry_date' => $this->ago(-10))));
        $store->add($this->registration(2, array('expiry_date' => $this->ago(1))));
        $store->add($this->registration(3, array('expiry_date' => $this->ago(-200))));

        $soon = $this->timeline($store)->execute(array('registration_id' => 1), array('user_id' => 7));
        $this->assertSame('expiring_soon', $soon['data']['time_analysis']['expiry_status']);
        $this->assertSame(10, $soon['data']['time_analysis']['days_to_expiry']);

        $expired = $this->timeline($store)->execute(array('registration_id' => 2), array('user_id' => 7));
        $this->assertSame('expired', $expired['data']['time_analysis']['expiry_status']);
        $this->assertSame(-1, $expired['data']['time_analysis']['days_to_expiry']);

        $valid = $this->timeline($store)->execute(array('registration_id' => 3), array('user_id' => 7));
        $this->assertSame('valid', $valid['data']['time_analysis']['expiry_status']);
    }

    // ─── Boundaries ──────────────────────────────────────────────────────

    public function test_an_unknown_registration_is_not_found(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->timeline($store)->execute(
            array('registration_id' => 99),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_an_unauthenticated_call_is_refused(): void
    {
        // The WordPress handler read no capability at all.
        $this->expectException(AccessDeniedException::class);

        $this->timeline(new InMemoryContentStore())->execute(array('registration_id' => 10));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function timeline(InMemoryContentStore $store): GetRegistrationTimelineTool
    {
        return new GetRegistrationTimelineTool($this->errors, $store);
    }

    /**
     * A `Y-m-d` date a number of days in the past; a negative count is ahead.
     */
    private function ago(int $days): string
    {
        return gmdate('Y-m-d', strtotime(sprintf('-%d days', $days)));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function registration(int $id, array $meta = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: 'Registration ' . $id,
            content: '',
            status: 'publish',
            type: 'mcp_ai_registration',
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: array_merge(
                array(
                    'country'           => 'LK',
                    'authority'         => 'NMRA',
                    'registration_type' => 'new',
                ),
                $meta
            ),
        );
    }
}
