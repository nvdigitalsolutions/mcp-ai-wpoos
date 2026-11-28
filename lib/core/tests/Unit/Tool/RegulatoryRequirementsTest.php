<?php

/**
 * The fifteenth regulatory-registration slice: requirements.
 *
 * Proposal 164 section 164.162. What is pinned: a requirement created without
 * `is_mandatory` is mandatory, which is the bug §164.152 recorded and this slice
 * fixes; the fixed flag then makes `check_product_compliance` fail on a missing
 * document, end to end; an unknown kind, a malformed date and a malformed URL are
 * refused; and the reader's mandatory filter reads the stored value rather than
 * the string `1`, so a boolean or a `'true'` string matches.
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
use Nvoos\Core\Tool\Regulatory\AddRegulatoryRequirementTool;
use Nvoos\Core\Tool\Regulatory\CheckProductComplianceTool;
use Nvoos\Core\Tool\Regulatory\GetRegulatoryRequirementsTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryRequirementsTest extends TestCase
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

    // ─── Writing ─────────────────────────────────────────────────────────

    public function test_a_requirement_without_the_flag_is_mandatory(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->add($store)->execute(
            array(
                'title'            => 'loa',
                'country'          => 'LK',
                'authority'        => 'NMRA',
                'requirement_type' => 'document',
            ),
            array('user_id' => 7)
        );

        // The schema documents a default of true; WordPress's ! empty() stored
        // false, so the requirement was silently skipped by the compliance check.
        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['is_mandatory']);
        $this->assertTrue($store->byType('mcp_ai_requirement')[0]->meta['is_mandatory']);
    }

    public function test_a_requirement_can_be_created_as_optional(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->add($store)->execute(
            array(
                'title'            => 'gmp',
                'country'          => 'LK',
                'authority'        => 'NMRA',
                'requirement_type' => 'certification',
                'is_mandatory'     => false,
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['is_mandatory']);
        $this->assertFalse($store->byType('mcp_ai_requirement')[0]->meta['is_mandatory']);
    }

    public function test_a_string_false_makes_it_optional(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->add($store)->execute(
            array(
                'title'            => 'gmp',
                'country'          => 'LK',
                'authority'        => 'NMRA',
                'requirement_type' => 'certification',
                'is_mandatory'     => 'false',
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['is_mandatory']);
    }

    public function test_an_unknown_requirement_type_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->add($store)->execute(
            array(
                'title'            => 'loa',
                'country'          => 'LK',
                'authority'        => 'NMRA',
                'requirement_type' => 'loa',
            ),
            array('user_id' => 7)
        );

        // WordPress accepted any string here, including a document type
        // mistaken for a requirement kind.
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('requirement_type', $result['error']['data']);
        $this->assertCount(0, $store->byType('mcp_ai_requirement'));
    }

    public function test_a_missing_required_field_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->add($store)->execute(
            array('title' => 'loa', 'country' => 'LK', 'requirement_type' => 'document'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('authority', $result['error']['data']);
    }

    public function test_an_invalid_effective_date_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->add($store)->execute(
            array(
                'title'            => 'loa',
                'country'          => 'LK',
                'authority'        => 'NMRA',
                'requirement_type' => 'document',
                'effective_date'   => '2026-13-99',
            ),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('effective_date', $result['error']['data']);
    }

    public function test_an_invalid_reference_url_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->add($store)->execute(
            array(
                'title'            => 'loa',
                'country'          => 'LK',
                'authority'        => 'NMRA',
                'requirement_type' => 'document',
                'reference_url'    => 'not a url',
            ),
            array('user_id' => 7)
        );

        // esc_url_raw() stored the empty string for this, so the citation
        // vanished instead of the call being refused.
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('reference_url', $result['error']['data']);
    }

    public function test_the_requirement_is_stored_as_a_requirement_item(): void
    {
        $store = new InMemoryContentStore();

        $this->add($store)->execute(
            array(
                'title'            => 'loa',
                'country'          => 'LK',
                'authority'        => 'NMRA',
                'requirement_type' => 'document',
                'description'      => 'Signed letter of authorisation.',
            ),
            array('user_id' => 7)
        );

        $item = $store->byType('mcp_ai_requirement')[0];

        $this->assertSame('loa', $item->title);
        $this->assertSame('Signed letter of authorisation.', $item->content);
        $this->assertSame('LK', $item->meta['country']);
        $this->assertSame('document', $item->meta['requirement_type']);

        // Optional meta is absent rather than empty, so a reader can tell the
        // difference between "applies to all categories" and "category blank".
        $this->assertArrayNotHasKey('product_category', $item->meta);
        $this->assertArrayNotHasKey('effective_date', $item->meta);
        $this->assertArrayNotHasKey('reference_url', $item->meta);
    }

    // ─── The bug, end to end ─────────────────────────────────────────────

    public function test_a_requirement_written_without_the_flag_blocks_compliance(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->product(1, 'Rose'));

        $this->add($store)->execute(
            array(
                'title'            => 'loa',
                'country'          => 'LK',
                'authority'        => 'NMRA',
                'requirement_type' => 'document',
            ),
            array('user_id' => 7)
        );

        $result = (new CheckProductComplianceTool($this->errors, $store))->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        // Before the fix the requirement was stored non-mandatory, the checker
        // skipped it, and this product read as compliant with no documents.
        $this->assertFalse($result['data']['is_compliant']);
        $this->assertSame(1, $result['data']['failed_checks']);
        $this->assertSame('critical', $result['data']['compliance_issues'][0]['severity']);
        $this->assertStringContainsString('loa', $result['data']['compliance_issues'][0]['message']);
    }

    // ─── Reading ─────────────────────────────────────────────────────────

    public function test_the_stored_flag_is_reported_as_a_boolean(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->requirement(1, 'LK', array('is_mandatory' => 'false')));
        $store->add($this->requirement(2, 'LK', array('is_mandatory' => true)));

        $result = $this->list($store)->execute(array('country' => 'LK'), array('user_id' => 7));

        // WordPress cast with (bool), where the string 'false' is true.
        $this->assertFalse($result['data']['requirements'][0]['is_mandatory']);
        $this->assertTrue($result['data']['requirements'][1]['is_mandatory']);
    }

    public function test_mandatory_only_reads_the_stored_value(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->requirement(1, 'LK', array('is_mandatory' => true)));
        $store->add($this->requirement(2, 'LK', array('is_mandatory' => 'true')));
        $store->add($this->requirement(3, 'LK', array('is_mandatory' => false)));

        $result = $this->list($store)->execute(
            array('country' => 'LK', 'mandatory_only' => true),
            array('user_id' => 7)
        );

        // WordPress queried the meta value '1', which only matched the first.
        $this->assertSame(2, $result['data']['total']);
        $this->assertSame(array(1, 2), array_column($result['data']['requirements'], 'requirement_id'));
    }

    public function test_a_string_false_does_not_switch_the_filter_on(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->requirement(1, 'LK', array('is_mandatory' => true)));
        $store->add($this->requirement(2, 'LK', array('is_mandatory' => false)));

        $result = $this->list($store)->execute(
            array('country' => 'LK', 'mandatory_only' => 'false'),
            array('user_id' => 7)
        );

        $this->assertSame(2, $result['data']['total']);
    }

    public function test_filters_narrow_by_country_and_authority(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->requirement(1, 'LK'));
        $store->add($this->requirement(2, 'AE', array('authority' => 'MOHAP')));
        $store->add($this->requirement(3, 'LK', array('authority' => 'OTHER')));

        $byCountry = $this->list($store)->execute(array('country' => 'LK'), array('user_id' => 7));
        $this->assertSame(2, $byCountry['data']['total']);

        $both = $this->list($store)->execute(
            array('country' => 'LK', 'authority' => 'NMRA'),
            array('user_id' => 7)
        );
        $this->assertSame(1, $both['data']['total']);
        $this->assertSame(1, $both['data']['requirements'][0]['requirement_id']);
    }

    public function test_results_are_grouped_by_kind_and_paged(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->requirement(1, 'LK', array('requirement_type' => 'test')));
        $store->add($this->requirement(2, 'LK', array('requirement_type' => 'document')));

        $first = $this->list($store)->execute(
            array('country' => 'LK', 'per_page' => 1),
            array('user_id' => 7)
        );

        $this->assertSame(2, $first['data']['total']);
        $this->assertSame(2, $first['data']['total_pages']);
        $this->assertCount(1, $first['data']['requirements']);
        $this->assertSame('document', $first['data']['requirements'][0]['requirement_type']);

        $second = $this->list($store)->execute(
            array('country' => 'LK', 'per_page' => 1, 'page' => 2),
            array('user_id' => 7)
        );

        $this->assertSame('test', $second['data']['requirements'][0]['requirement_type']);
    }

    public function test_country_is_required(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->list($store)->execute(array(), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('country', $result['error']['data']);
    }

    public function test_an_unknown_requirement_type_filter_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->list($store)->execute(
            array('country' => 'LK', 'requirement_type' => 'nonsense'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('requirement_type', $result['error']['data']);
    }

    public function test_an_unauthenticated_call_is_refused(): void
    {
        // The WordPress reader checked no capability at all.
        $this->expectException(AccessDeniedException::class);

        $this->list(new InMemoryContentStore())->execute(array('country' => 'LK'));
    }

    // ─── Tools ───────────────────────────────────────────────────────────

    private function add(InMemoryContentStore $store): AddRegulatoryRequirementTool
    {
        return new AddRegulatoryRequirementTool($this->errors, $store);
    }

    private function list(InMemoryContentStore $store): GetRegulatoryRequirementsTool
    {
        return new GetRegulatoryRequirementsTool($this->errors, $store);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $meta
     */
    private function requirement(int $id, string $country, array $meta = array()): ContentItem
    {
        return $this->item(
            $id,
            'Requirement ' . $id,
            'mcp_ai_requirement',
            array_merge(
                array(
                    'country'          => $country,
                    'authority'        => 'NMRA',
                    'requirement_type' => 'document',
                    'is_mandatory'     => true,
                ),
                $meta
            )
        );
    }

    private function product(int $id, string $title): ContentItem
    {
        return $this->item($id, $title, 'mcp_ai_reg_product', array('country' => 'LK'));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function item(int $id, string $title, string $type, array $meta = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
        );
    }
}
