<?php

/**
 * The thirteenth regulatory-registration slice: authority submission and updates.
 *
 * Proposal 164 section 164.160. What is pinned: a submission records a
 * reference, a status and a log entry locally and reports `transmitted: false`;
 * a second submission appends rather than replaces; a registration with no
 * documents is refused before anything is written; the reference prefix is the
 * first two characters of the stored country, so a full name yields `SR`; an
 * empty update store returns no records and no samples; and the reader's filters,
 * date window and paging each narrow one set.
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
use Nvoos\Core\Tool\Regulatory\GetRegulatoryUpdatesTool;
use Nvoos\Core\Tool\Regulatory\SubmitToAuthorityTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryAuthorityToolsTest extends TestCase
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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Forbidden.'): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message),
            )
        );
    }

    // ─── Submission ──────────────────────────────────────────────────────

    public function test_a_submission_records_the_reference_status_and_log(): void
    {
        $store = new InMemoryContentStore();
        $store->add(
            $this->registration(
                10,
                array(),
                array(
                    'mcp_ai_reg_category' => array('cosmetics'),
                    'mcp_ai_reg_status'   => array('draft'),
                )
            )
        );
        $store->add($this->document(20, 10));

        $result = $this->submit($store)->execute(
            array('registration_id' => 10, 'priority' => 'expedited', 'notes' => 'Filed by hand.'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);

        // Nothing left the machine, and the response says so rather than
        // echoing a success message about a regulatory authority.
        $this->assertFalse($result['data']['transmitted']);
        $this->assertArrayNotHasKey('estimated_review', $result['data']);
        $this->assertStringStartsWith('SUB-LK-10-', $result['data']['submission_reference']);
        $this->assertSame(1, $result['data']['documents_submitted']);
        $this->assertSame('submitted', $result['data']['status']);

        $registration = $store->byType('mcp_ai_registration')[0];

        $this->assertSame('new', $registration->meta['_submission_type']);
        $this->assertSame('expedited', $registration->meta['_submission_priority']);
        $this->assertSame('Filed by hand.', $registration->meta['_submission_notes']);
        $this->assertSame(
            $result['data']['submission_reference'],
            $registration->meta['_submission_reference']
        );
        $this->assertSame($result['data']['submitted_at'], $registration->meta['submission_date']);

        $this->assertCount(1, $registration->meta['_submission_log']);
        $this->assertSame('SUB-LK-10', substr($registration->meta['_submission_log'][0]['reference'], 0, 9));

        // The status taxonomy is replaced; the category taxonomy is not named,
        // so it survives.
        $this->assertSame(array('submitted'), $registration->taxonomy['mcp_ai_reg_status']);
        $this->assertSame(array('cosmetics'), $registration->taxonomy['mcp_ai_reg_category']);
    }

    public function test_a_second_submission_appends_to_the_log(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));
        $store->add($this->document(20, 10));

        $tool = $this->submit($store);

        $tool->execute(array('registration_id' => 10), array('user_id' => 7));
        $tool->execute(array('registration_id' => 10, 'submission_type' => 'renewal'), array('user_id' => 8));

        $log = $store->byType('mcp_ai_registration')[0]->meta['_submission_log'];

        $this->assertCount(2, $log);
        $this->assertSame('new', $log[0]['type']);
        $this->assertSame('renewal', $log[1]['type']);
        $this->assertSame(8, $log[1]['user_id']);
    }

    public function test_a_registration_without_documents_is_refused(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));

        $result = $this->submit($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('registration_id', $result['error']['data']);

        // Refused before the write: no reference and no log entry.
        $registration = $store->byType('mcp_ai_registration')[0];

        $this->assertArrayNotHasKey('_submission_reference', $registration->meta);
        $this->assertArrayNotHasKey('_submission_log', $registration->meta);
    }

    public function test_an_unknown_registration_is_not_found(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->submit($store)->execute(
            array('registration_id' => 99),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_an_unknown_submission_type_is_refused(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));
        $store->add($this->document(20, 10));

        $result = $this->submit($store)->execute(
            array('registration_id' => 10, 'submission_type' => 'nonsense'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('submission_type', $result['error']['data']);
        $this->assertArrayNotHasKey('_submission_log', $store->byType('mcp_ai_registration')[0]->meta);
    }

    public function test_the_reference_prefix_uses_the_stored_country(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10, array('country' => 'Sri Lanka')));
        $store->add($this->document(20, 10));

        $result = $this->submit($store)->execute(
            array('registration_id' => 10),
            array('user_id' => 7)
        );

        // WordPress took the first two characters of whatever was stored, so a
        // full country name yields SR rather than LK. Kept and documented.
        $this->assertStringStartsWith('SUB-SR-10-', $result['data']['submission_reference']);
    }

    // ─── Updates ─────────────────────────────────────────────────────────

    public function test_an_empty_store_returns_no_updates_and_no_samples(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->updates($store)->execute(array(), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['updates']);
        $this->assertSame(0, $result['data']['total']);
        $this->assertSame(0, $result['data']['store_total']);
        $this->assertStringContainsString('No sample or example records were substituted', $result['message']);
    }

    public function test_stored_updates_are_returned_newest_first(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->update(1, 'Older', '2026-01-01T09:00:00+00:00'));
        $store->add($this->update(2, 'Newer', '2026-02-01T09:00:00+00:00'));

        $result = $this->updates($store)->execute(
            array('since_date' => '2025-01-01'),
            array('user_id' => 7)
        );

        $this->assertSame(2, $result['data']['total']);
        $this->assertSame('Newer', $result['data']['updates'][0]['title']);
        $this->assertSame('2026-02-01 09:00:00', $result['data']['updates'][0]['published_at']);

        $row = $result['data']['updates'][0];

        $this->assertSame('LK', $row['country']);
        $this->assertSame('NMRA', $row['authority']);
        $this->assertSame('amendment', $row['update_type']);
        $this->assertSame('2026-03-01', $row['effective_date']);
        $this->assertSame('https://example.test/notice', $row['reference_url']);
    }

    public function test_filters_narrow_the_store(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->update(1, 'Sri Lanka notice', '2026-01-01T09:00:00+00:00'));
        $store->add($this->update(2, 'UAE notice', '2026-01-02T09:00:00+00:00', array('country' => 'AE')));

        $result = $this->updates($store)->execute(
            array('country' => 'AE', 'since_date' => '2025-01-01'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(2, $result['data']['store_total']);
        $this->assertSame('AE', $result['data']['updates'][0]['country']);
        $this->assertSame('AE', $result['data']['filters']['country']);
    }

    public function test_since_date_excludes_older_updates(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->update(1, 'January', '2026-01-01T09:00:00+00:00'));
        $store->add($this->update(2, 'June', '2026-06-01T09:00:00+00:00'));

        $result = $this->updates($store)->execute(
            array('since_date' => '2026-03-01'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('June', $result['data']['updates'][0]['title']);
        $this->assertSame('2026-03-01', $result['data']['since_date']);
    }

    public function test_paging_slices_the_matched_set(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->update(1, 'One', '2026-01-01T09:00:00+00:00'));
        $store->add($this->update(2, 'Two', '2026-01-02T09:00:00+00:00'));
        $store->add($this->update(3, 'Three', '2026-01-03T09:00:00+00:00'));

        $first = $this->updates($store)->execute(
            array('since_date' => '2025-01-01', 'per_page' => 2),
            array('user_id' => 7)
        );

        $this->assertSame(3, $first['data']['total']);
        $this->assertCount(2, $first['data']['updates']);
        $this->assertSame(2, $first['data']['pages']);
        $this->assertSame('Three', $first['data']['updates'][0]['title']);

        $second = $this->updates($store)->execute(
            array('since_date' => '2025-01-01', 'per_page' => 2, 'page' => 2),
            array('user_id' => 7)
        );

        $this->assertCount(1, $second['data']['updates']);
        $this->assertSame('One', $second['data']['updates'][0]['title']);

        // WordPress passed absint straight through; the port clamps to the
        // range its own schema declares.
        $clamped = $this->updates($store)->execute(
            array('since_date' => '2025-01-01', 'per_page' => 1000),
            array('user_id' => 7)
        );

        $this->assertSame(100, $clamped['data']['per_page']);
    }

    public function test_an_out_of_range_update_type_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->updates($store)->execute(
            array('update_type' => 'nonsense'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('update_type', $result['error']['data']);
    }

    public function test_a_malformed_since_date_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->updates($store)->execute(
            array('since_date' => '2026-13-99'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('since_date', $result['error']['data']);
    }

    public function test_an_unauthenticated_call_is_refused(): void
    {
        // The WordPress tool checked no capability at all; the port requires a
        // caller, which is the minimum every other tool in the family applies.
        $this->expectException(AccessDeniedException::class);

        $this->updates(new InMemoryContentStore())->execute(array());
    }

    // ─── Tools ───────────────────────────────────────────────────────────

    private function submit(InMemoryContentStore $store): SubmitToAuthorityTool
    {
        return new SubmitToAuthorityTool($this->errors, $store);
    }

    private function updates(InMemoryContentStore $store): GetRegulatoryUpdatesTool
    {
        return new GetRegulatoryUpdatesTool($this->errors, $store);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $taxonomy
     */
    private function registration(int $id, array $meta = array(), array $taxonomy = array()): ContentItem
    {
        return $this->item(
            $id,
            'Registration ' . $id,
            'mcp_ai_registration',
            array_merge(
                array(
                    'country'    => 'LK',
                    'authority'  => 'NMRA',
                    'product_id' => 1,
                ),
                $meta
            ),
            $taxonomy
        );
    }

    private function document(int $id, int $registrationId): ContentItem
    {
        return $this->item(
            $id,
            'Document ' . $id,
            'mcp_ai_reg_document',
            array('registration_id' => $registrationId, 'document_type' => 'loa')
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function update(
        int $id,
        string $title,
        string $createdAt,
        array $meta = array()
    ): ContentItem {
        return $this->item(
            $id,
            $title,
            'mcp_ai_reg_update',
            array_merge(
                array(
                    'country'        => 'LK',
                    'authority'      => 'NMRA',
                    'update_type'    => 'amendment',
                    'effective_date' => '2026-03-01',
                    'reference_url'  => 'https://example.test/notice',
                ),
                $meta
            ),
            array(),
            $createdAt
        );
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $taxonomy
     */
    private function item(
        int $id,
        string $title,
        string $type,
        array $meta = array(),
        array $taxonomy = array(),
        string $createdAt = '2026-01-01T09:00:00+00:00'
    ): ContentItem {
        return new ContentItem(
            id: $id,
            title: $title,
            content: 'Body of ' . $title . '.',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: new \DateTimeImmutable($createdAt),
            updatedAt: new \DateTimeImmutable($createdAt),
            meta: $meta,
            taxonomy: $taxonomy,
        );
    }
}
