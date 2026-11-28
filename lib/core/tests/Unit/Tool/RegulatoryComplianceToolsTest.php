<?php

/**
 * The ninth regulatory-registration slice: the compliance remainder.
 *
 * Proposal 164 section 164.152. What is pinned: both compliance checks run on a
 * bare call and the response says when none ran; a requirement whose ingredient
 * list is missing is reported rather than skipped; a filtered or missing
 * registration is visible in the authority batch; and a document type matches
 * its requirement regardless of case.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Regulatory\CheckAuthorityStatusTool;
use Nvoos\Core\Tool\Regulatory\CheckProductComplianceTool;
use Nvoos\Core\Tool\Regulatory\ValidateDocumentChecklistTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryComplianceToolsTest extends TestCase
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

    // ─── Compliance: the defaults ────────────────────────────────────────

    public function test_a_bare_call_runs_both_checks(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->requirement(2, 'loa', 'document'),
            $this->document(3, array('product_id' => 1, 'document_type' => 'loa')),
        ));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['checks_ran']);
        $this->assertSame(array(), $result['data']['checks_skipped']);
        $this->assertSame(1, $result['data']['requirements_found']);
        $this->assertTrue($result['data']['is_compliant']);
        $this->assertSame('compliant', $result['data']['status']);
        $this->assertSame(100.0, $result['data']['compliance_score']);
    }

    public function test_no_requirements_is_reported_and_not_called_compliance(): void
    {
        $store = $this->store(array($this->product(1, 'Rose')));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['data']['checks_ran']);
        $this->assertSame(1, $result['data']['total_checks']);
        $this->assertSame(0, $result['data']['requirements_found']);
        $this->assertStringContainsString('No compliance checks ran', $result['message']);
        $this->assertStringContainsString('LK', $result['message']);
    }

    public function test_disabling_both_checks_is_named_as_the_reason(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->requirement(2, 'loa', 'document'),
        ));

        $result = $this->compliance($store)->execute(
            array(
                'product_id'        => 1,
                'country'           => 'LK',
                'check_documents'   => false,
                'check_ingredients' => false,
            ),
            array('user_id' => 7)
        );

        $this->assertFalse($result['data']['checks_ran']);
        $this->assertSame(array('documents', 'ingredients'), $result['data']['checks_skipped']);
        $this->assertStringContainsString('both check types were disabled', $result['message']);
    }

    // ─── Compliance: documents ───────────────────────────────────────────

    public function test_a_missing_mandatory_document_is_a_critical_issue(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->requirement(2, 'loa', 'document'),
            $this->requirement(3, 'gmp', 'document'),
            $this->document(4, array('product_id' => 1, 'document_type' => 'loa')),
        ));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['data']['is_compliant']);
        $this->assertSame('partially_compliant', $result['data']['status']);
        $this->assertSame(1, $result['data']['failed_checks']);
        $this->assertSame('critical', $result['data']['compliance_issues'][0]['severity']);
        $this->assertStringContainsString('gmp', $result['data']['compliance_issues'][0]['message']);
        $this->assertStringContainsString('1 compliance issue', $result['message']);
    }

    public function test_a_document_type_matches_its_requirement_regardless_of_case(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->requirement(2, 'Certificate of Free Sale', 'document'),
            $this->document(3, array('product_id' => 1, 'document_type' => 'free sale')),
        ));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['is_compliant']);
    }

    public function test_a_non_mandatory_requirement_is_neither_passed_nor_failed(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->requirement(2, 'loa', 'document', false),
        ));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertSame(array(), $result['data']['compliance_issues']);
        $this->assertNotContains('loa', array_column($result['data']['passed_requirements'], 'requirement'));
        $this->assertSame(1, $result['data']['total_checks']);
        $this->assertSame('No ingredient restrictions', $result['data']['passed_requirements'][0]['requirement']);
    }

    public function test_a_requirement_for_another_category_is_not_applied(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose', array(), array('mcp_ai_reg_category' => array('skincare'))),
            $this->requirement(2, 'loa', 'document', true, 'haircare'),
        ));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertSame(0, $result['data']['requirements_found']);
    }

    public function test_a_requirement_without_a_category_applies_to_every_product(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose', array(), array('mcp_ai_reg_category' => array('skincare'))),
            $this->requirement(2, 'loa', 'document'),
        ));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['requirements_found']);
        $this->assertFalse($result['data']['is_compliant']);
    }

    // ─── Compliance: ingredients ─────────────────────────────────────────

    public function test_a_restricted_ingredient_in_the_list_is_critical(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose', array('inci_ingredients' => 'Aqua, Hydroquinone')),
            $this->requirement(2, 'Hydroquinone', 'ingredient_restriction'),
        ));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['data']['is_compliant']);
        $this->assertSame('critical', $result['data']['compliance_issues'][0]['severity']);
        $this->assertStringContainsString(
            'contains restricted ingredient',
            $result['data']['compliance_issues'][0]['message']
        );
    }

    public function test_a_clean_ingredient_list_passes_the_restriction(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose', array('inci_ingredients' => 'Aqua, Glycerin')),
            $this->requirement(2, 'Hydroquinone', 'ingredient_restriction'),
        ));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['is_compliant']);
        $this->assertSame('ingredient_restriction', $result['data']['passed_requirements'][0]['type']);
    }

    public function test_a_missing_ingredient_list_is_unverified_not_a_pass(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->requirement(2, 'Hydroquinone', 'ingredient_restriction'),
        ));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['data']['is_compliant']);
        $this->assertSame(0, $result['data']['passed_checks']);
        $this->assertSame('warning', $result['data']['compliance_issues'][0]['severity']);
        $this->assertStringContainsString(
            'Cannot verify restriction',
            $result['data']['compliance_issues'][0]['message']
        );
    }

    public function test_no_restrictions_configured_is_an_explicit_pass(): void
    {
        $store = $this->store(array($this->product(1, 'Rose')));

        $result = $this->compliance($store)->execute(
            array('product_id' => 1, 'country' => 'LK', 'check_documents' => false),
            array('user_id' => 7)
        );

        $this->assertSame(
            array(array('requirement' => 'No ingredient restrictions', 'type' => 'ingredient')),
            $result['data']['passed_requirements']
        );
    }

    public function test_the_compliance_validation_and_not_found_paths(): void
    {
        $store = $this->store();

        $missingCountry = $this->compliance($store)->execute(
            array('product_id' => 1),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $missingCountry['error']['code']);
        $this->assertArrayHasKey('country', $missingCountry['error']['data']);

        $absent = $this->compliance($store)->execute(
            array('product_id' => 99, 'country' => 'LK'),
            array('user_id' => 7)
        );
        $this->assertSame('not_found', $absent['error']['code']);

        $wrongType = $this->store(array($this->requirement(2, 'loa', 'document')));
        $mismatch  = $this->compliance($wrongType)->execute(
            array('product_id' => 2, 'country' => 'LK'),
            array('user_id' => 7)
        );
        $this->assertSame('not_found', $mismatch['error']['code']);
    }

    public function test_the_compliance_capability_is_read(): void
    {
        $this->assertSame('read', $this->compliance($this->store())->getRequiredCapability());
    }

    // ─── Authority status ────────────────────────────────────────────────

    public function test_a_batch_reports_each_registration(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                'authority'     => 'NMRA',
                'expiry_date'   => '2027-01-01',
                '_nmra_status'  => 'Under Review',
                '_nmra_reference' => 'NMRA-77',
            )),
        ));

        $result = $this->authority($store)->execute(
            array('registration_ids' => array(1)),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['requested']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('NMRA', $result['data']['results'][0]['authority']);
        $this->assertSame('Under Review', $result['data']['results'][0]['nmra_status']);
        $this->assertSame('NMRA-77', $result['data']['results'][0]['nmra_reference']);
        $this->assertArrayNotHasKey('mohap_status', $result['data']['results'][0]);
    }

    public function test_uae_registrations_carry_the_mohap_fields(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'AE', array('_mohap_status' => 'Submitted', '_mohap_tracking_id' => 'M-9')),
        ));

        $row = $this->authority($store)->execute(
            array('registration_ids' => array(1)),
            array('user_id' => 7)
        )['data']['results'][0];

        $this->assertSame('Submitted', $row['mohap_status']);
        $this->assertSame('M-9', $row['mohap_tracking_id']);
    }

    public function test_a_missing_registration_is_an_item_with_found_false(): void
    {
        $store = $this->store(array());

        $result = $this->authority($store)->execute(
            array('registration_ids' => array(5)),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(1, $result['data']['not_found']);
        $this->assertFalse($result['data']['results'][0]['found']);
        $this->assertSame('Registration not found', $result['data']['results'][0]['error']);
    }

    public function test_a_country_filter_skips_visibly(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK'),
            $this->registration(2, 'AE'),
        ));

        $result = $this->authority($store)->execute(
            array('registration_ids' => array(1, 2), 'countries' => array('AE')),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('AE', $result['data']['results'][0]['country']);
        $this->assertSame(1, count($result['data']['skipped']));
        $this->assertSame(1, $result['data']['skipped'][0]['registration_id']);
        $this->assertStringContainsString('skipped', $result['message']);
    }

    public function test_status_history_is_an_empty_list_when_never_written(): void
    {
        $store = $this->store(array($this->registration(1, 'LK')));

        $row = $this->authority($store)->execute(
            array('registration_ids' => array(1), 'include_history' => true),
            array('user_id' => 7)
        )['data']['results'][0];

        $this->assertSame(array(), $row['status_history']);
    }

    public function test_a_stored_status_history_is_returned(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('_status_history' => array(array('status' => 'Submitted')))),
        ));

        $row = $this->authority($store)->execute(
            array('registration_ids' => array(1), 'include_history' => true),
            array('user_id' => 7)
        )['data']['results'][0];

        $this->assertSame(array(array('status' => 'Submitted')), $row['status_history']);
    }

    public function test_a_batch_is_bounded_and_needs_ids(): void
    {
        $store = $this->store();

        $none = $this->authority($store)->execute(array('registration_ids' => array()), array('user_id' => 7));
        $this->assertSame('validation_failed', $none['error']['code']);

        $tooMany = $this->authority($store)->execute(
            array('registration_ids' => range(1, 101)),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $tooMany['error']['code']);
        $this->assertArrayHasKey('registration_ids', $tooMany['error']['data']);
    }

    public function test_the_authority_capability_matches_the_handler(): void
    {
        $this->assertSame('read', $this->authority($this->store())->getRequiredCapability());
    }

    // ─── Document checklist ──────────────────────────────────────────────

    public function test_a_full_checklist_is_compliant(): void
    {
        $store = $this->store(array($this->product(1, 'Rose')));

        foreach ($this->defaultDocuments() as $index => $type) {
            $store->add($this->document(100 + $index, array('product_id' => 1, 'document_type' => $type)));
        }

        $result = $this->checklist($store)->execute(
            array('product_id' => 1, 'country' => 'AE'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['is_compliant']);
        $this->assertSame('compliant', $result['data']['status']);
        $this->assertSame(100.0, $result['data']['completion_percentage']);
        $this->assertSame(8, $result['data']['total_required']);
    }

    public function test_a_missing_document_is_named(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->document(2, array('product_id' => 1, 'document_type' => 'loa')),
        ));

        $result = $this->checklist($store)->execute(
            array('product_id' => 1, 'country' => 'AE'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['data']['is_compliant']);
        $this->assertSame('partially_compliant', $result['data']['status']);
        $this->assertSame(7, $result['data']['total_missing']);
        $this->assertContains('fsc', $result['data']['missing_documents']);
        $this->assertSame(12.5, $result['data']['completion_percentage']);
    }

    public function test_a_document_type_matches_regardless_of_case(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->document(2, array('product_id' => 1, 'document_type' => 'LoA')),
            $this->document(3, array('product_id' => 1, 'document_type' => 'FSC')),
        ));

        $result = $this->checklist($store)->execute(
            array('product_id' => 1, 'country' => 'AE'),
            array('user_id' => 7)
        );

        $this->assertNotContains('loa', $result['data']['missing_documents']);
        $this->assertNotContains('fsc', $result['data']['missing_documents']);
        $this->assertSame(array('LoA', 'FSC'), $result['data']['present_documents']);
    }

    public function test_an_expired_document_breaks_compliance(): void
    {
        $store = $this->store(array($this->product(1, 'Rose')));

        foreach ($this->defaultDocuments() as $index => $type) {
            $store->add($this->document(100 + $index, array(
                'product_id'    => 1,
                'document_type' => $type,
                'expiry_date'   => '2020-01-01',
            )));
        }

        $result = $this->checklist($store)->execute(
            array('product_id' => 1, 'country' => 'AE'),
            array('user_id' => 7)
        );

        $this->assertSame(array(), $result['data']['missing_documents']);
        $this->assertFalse($result['data']['is_compliant']);
        $this->assertSame(8, $result['data']['total_expired']);
        $this->assertStringContainsString('expired', $result['message']);
    }

    public function test_an_unparseable_expiry_date_is_expired_and_named(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->document(2, array(
                'product_id'    => 1,
                'document_type' => 'loa',
                'expiry_date'   => 'not a date',
            )),
        ));

        $result = $this->checklist($store)->execute(
            array('product_id' => 1, 'country' => 'AE'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['data']['is_compliant']);
        $this->assertSame(1, $result['data']['total_expired']);
        $this->assertSame('not a date', $result['data']['unparsed_expiry_dates'][0]['expiry_date']);
        $this->assertStringContainsString(
            'could not be read',
            $result['data']['expired_documents'][0]['reason']
        );
    }

    public function test_a_country_table_adds_its_own_document(): void
    {
        $store = $this->store(array($this->product(1, 'Rose')));

        foreach ($this->defaultDocuments() as $index => $type) {
            $store->add($this->document(100 + $index, array('product_id' => 1, 'document_type' => $type)));
        }

        $result = $this->checklist($store)->execute(
            array('product_id' => 1, 'country' => 'lk'),
            array('user_id' => 7)
        );

        $this->assertSame(9, $result['data']['total_required']);
        $this->assertSame(array('iso'), $result['data']['missing_documents']);
        $this->assertSame('lk', $result['data']['country']);
    }

    public function test_the_registration_country_fills_an_omitted_country(): void
    {
        $store = $this->store(array($this->registration(1, 'IN')));

        $result = $this->checklist($store)->execute(
            array('registration_id' => 1),
            array('user_id' => 7)
        );

        $this->assertSame('IN', $result['data']['country']);
        $this->assertSame(9, $result['data']['total_required']);
        $this->assertContains('stability', $result['data']['required_documents']);
    }

    public function test_a_registration_wins_over_a_product(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose'),
            $this->registration(2, 'AE'),
            $this->document(3, array('registration_id' => 2, 'document_type' => 'loa')),
            $this->document(4, array('product_id' => 1, 'document_type' => 'fsc')),
        ));

        $result = $this->checklist($store)->execute(
            array('product_id' => 1, 'registration_id' => 2),
            array('user_id' => 7)
        );

        $this->assertSame('registration', $result['data']['entity_type']);
        $this->assertSame(2, $result['data']['entity_id']);
        $this->assertSame(array('loa'), $result['data']['present_documents']);
    }

    public function test_the_checklist_validation_and_not_found_paths(): void
    {
        $store = $this->store(array($this->product(1, 'Rose')));

        $neither = $this->checklist($store)->execute(array(), array('user_id' => 7));
        $this->assertSame('validation_failed', $neither['error']['code']);

        $absent = $this->checklist($store)->execute(
            array('registration_id' => 42),
            array('user_id' => 7)
        );
        $this->assertSame('not_found', $absent['error']['code']);

        $product = $this->checklist($store)->execute(
            array('product_id' => 1, 'country' => 'AE'),
            array('user_id' => 7)
        );
        $this->assertSame('product', $product['data']['entity_type']);
        $this->assertSame(8, $product['data']['total_required']);
    }

    public function test_the_checklist_capability_is_read(): void
    {
        $this->assertSame('read', $this->checklist($this->store())->getRequiredCapability());
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function compliance(ContentStoreInterface $store): CheckProductComplianceTool
    {
        return new CheckProductComplianceTool($this->errors, $store);
    }

    private function authority(ContentStoreInterface $store): CheckAuthorityStatusTool
    {
        return new CheckAuthorityStatusTool($this->errors, $store);
    }

    private function checklist(ContentStoreInterface $store): ValidateDocumentChecklistTool
    {
        return new ValidateDocumentChecklistTool($this->errors, $store);
    }

    /**
     * @return list<string>
     */
    private function defaultDocuments(): array
    {
        return array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula');
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $taxonomy
     */
    private function product(int $id, string $title, array $meta = array(), array $taxonomy = array()): ContentItem
    {
        return $this->item($id, $title, 'mcp_ai_reg_product', $meta, $taxonomy);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function registration(int $id, string $country, array $meta = array()): ContentItem
    {
        return $this->item($id, 'Registration ' . $id, 'mcp_ai_registration', array_merge(array('country' => $country), $meta));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function document(int $id, array $meta): ContentItem
    {
        return $this->item($id, 'Document ' . $id, 'mcp_ai_reg_document', $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function requirement(
        int $id,
        string $title,
        string $requirementType,
        bool $mandatory = true,
        string $category = ''
    ): ContentItem {
        $meta = array(
            'country'          => 'LK',
            'requirement_type' => $requirementType,
            'is_mandatory'     => $mandatory,
        );

        if ('' !== $category) {
            $meta['product_category'] = $category;
        }

        return $this->item($id, $title, 'mcp_ai_requirement', $meta);
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
        array $taxonomy = array()
    ): ContentItem {
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
            taxonomy: $taxonomy,
        );
    }

    /**
     * A store that answers type and meta clauses, so paging is exercised.
     *
     * @param array<int, ContentItem> $items
     */
    private function store(array $items = array()): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items = array();

            private int $nextId = 1;

            /**
             * @param array<int, ContentItem> $items
             */
            public function __construct(array $items)
            {
                foreach ($items as $item) {
                    $this->add($item);
                }
            }

            public function add(ContentItem $item): void
            {
                $this->items[ $item->id ] = $item;
                $this->nextId             = max($this->nextId, $item->id + 1);
            }

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return $this->items[ $id ] ?? null;
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $matched = array();

                foreach ($this->items as $item) {
                    if (array() !== $query->types && ! in_array($item->type, $query->types, true)) {
                        continue;
                    }

                    if (! $this->matchesMeta($item, $query->metaQuery)) {
                        continue;
                    }

                    $matched[] = $item;
                }

                usort($matched, static fn (ContentItem $a, ContentItem $b): int => $a->id <=> $b->id);

                $perPage = $query->perPage > 0 ? $query->perPage : 20;
                $offset  = ( max(1, $query->page) - 1 ) * $perPage;
                $page    = array_slice($matched, $offset, $perPage);

                return new ContentCollection(
                    $page,
                    count($matched),
                    max(1, $query->page),
                    $perPage,
                    (int) ceil(count($matched) / $perPage)
                );
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                throw new \LogicException('not used here.');
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                throw new \LogicException('not used here.');
            }

            public function delete(int $id, int $userId): void
            {
                throw new \LogicException('not used here.');
            }

            public function getMeta(int $id): array
            {
                return $this->items[ $id ]->meta ?? array();
            }

            public function getTaxonomyTerms(int $id): array
            {
                return $this->items[ $id ]->taxonomy ?? array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return isset($this->items[ $id ]);
            }

            /**
             * WP_Query-style clause evaluation: AND groups, OR groups, equality
             * and NOT EXISTS.
             *
             * @param array<int|string, mixed> $clauses
             */
            private function matchesMeta(ContentItem $item, array $clauses): bool
            {
                if (array() === $clauses) {
                    return true;
                }

                $relation = 'OR' === strtoupper((string) ( $clauses['relation'] ?? 'AND' )) ? 'OR' : 'AND';

                foreach ($clauses as $name => $clause) {
                    if ('relation' === $name || ! is_array($clause)) {
                        continue;
                    }

                    $pass = $this->matchesClause($item, $clause);

                    if ('OR' === $relation && $pass) {
                        return true;
                    }

                    if ('AND' === $relation && ! $pass) {
                        return false;
                    }
                }

                return 'OR' === $relation ? false : true;
            }

            /**
             * @param array<string, mixed> $clause
             */
            private function matchesClause(ContentItem $item, array $clause): bool
            {
                if (isset($clause['relation'])) {
                    return $this->matchesMeta($item, $clause);
                }

                $key     = (string) ( $clause['key'] ?? '' );
                $compare = strtoupper((string) ( $clause['compare'] ?? '=' ));
                $value   = $clause['value'] ?? null;
                $stored  = $item->meta[ $key ] ?? null;

                if ('NOT EXISTS' === $compare) {
                    return null === $stored;
                }

                if ('EXISTS' === $compare) {
                    return null !== $stored;
                }

                return is_scalar($stored) && (string) $stored === (string) $value;
            }
        };
    }
}
