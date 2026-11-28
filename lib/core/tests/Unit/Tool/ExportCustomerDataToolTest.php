<?php

/**
 * The subject-access export, and the four arguments it refuses.
 *
 * Proposal 164 section 164.185. Pinned here:
 *
 * - the account is resolved by id or by email, never both and never neither;
 * - an unknown id or email is a not-found, not an empty document;
 * - addresses, notes, CSV and upload are refused by name with the missing
 *   model in the message, rather than accepted and ignored;
 * - the order history is the runtime's order document, bounded and reported:
 *   `orders_total`, `orders_included` and `orders_truncated`;
 * - a refusal happens before any store is read.
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
use Nvoos\Core\Domain\Entity\Customer;
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use Nvoos\Core\Tests\Support\CustomerDirectoryFake;
use Nvoos\Core\Tests\Support\OrderStoreFake;
use Nvoos\Core\Tool\Ecommerce\ExportCustomerDataTool;
use PHPUnit\Framework\TestCase;

final class ExportCustomerDataToolTest extends TestCase
{
    private const USER = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    private CustomerDirectoryFake $directory;

    private OrderStoreFake $orders;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message, 'data' => $data),
            )
        );

        $this->directory = new CustomerDirectoryFake(
            new Customer(7, 'Ada Lovelace', 'ada@example.com', 'customer', new \DateTimeImmutable('2024-01-05'))
        );

        $this->orders = new OrderStoreFake();
    }

    private function tool(): ExportCustomerDataTool
    {
        return new ExportCustomerDataTool($this->errors, $this->directory, $this->orders);
    }

    // ── metadata and guards ──────────────────────────────────

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool();

        $this->assertSame('export_customer_data', $tool->getSlug());
        $this->assertSame('Export Customer Data', $tool->getName());
        $this->assertSame('manage_woocommerce', $tool->getRequiredCapability());
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool()->execute(array('customer_id' => 7));
    }

    public function test_an_unknown_argument_is_refused(): void
    {
        $result = $this->tool()->execute(array('customer_id' => 7, 'export_all' => true), self::USER);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('export_all', $result['error']['data']);
    }

    // ── the selector ─────────────────────────────────────────

    public function test_one_selector_is_required(): void
    {
        $result = $this->tool()->execute(array(), self::USER);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('customer_id', $result['error']['data']);
    }

    public function test_both_selectors_are_refused(): void
    {
        $result = $this->tool()->execute(
            array('customer_id' => 7, 'email' => 'ada@example.com'),
            self::USER
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('email', $result['error']['data']);
        $this->assertStringContainsString('not both', $result['error']['data']['email'][0]);
    }

    public function test_an_invalid_email_is_refused(): void
    {
        $result = $this->tool()->execute(array('email' => 'not-an-address'), self::USER);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('email', $result['error']['data']);
    }

    public function test_an_unknown_id_is_a_not_found(): void
    {
        $result = $this->tool()->execute(array('customer_id' => 99), self::USER);

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_an_unknown_email_is_a_not_found(): void
    {
        $result = $this->tool()->execute(array('email' => 'nobody@example.com'), self::USER);

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ── the refused arguments ────────────────────────────────

    public function test_addresses_are_refused_by_name(): void
    {
        $result = $this->tool()->execute(
            array('customer_id' => 7, 'include_addresses' => true),
            self::USER
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(
            'address model',
            $result['error']['data']['include_addresses'][0]
        );
    }

    public function test_notes_are_refused_by_name(): void
    {
        $result = $this->tool()->execute(
            array('customer_id' => 7, 'include_notes' => true),
            self::USER
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(
            'note',
            $result['error']['data']['include_notes'][0]
        );
    }

    public function test_csv_is_refused_by_name(): void
    {
        $result = $this->tool()->execute(
            array('customer_id' => 7, 'format' => 'csv'),
            self::USER
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('json', $result['error']['data']['format'][0]);
    }

    public function test_upload_is_refused_by_name(): void
    {
        $result = $this->tool()->execute(
            array('customer_id' => 7, 'upload' => true),
            self::USER
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(
            'media library',
            $result['error']['data']['upload'][0]
        );
    }

    public function test_a_refusal_reads_no_store(): void
    {
        $this->tool()->execute(array('customer_id' => 7, 'format' => 'csv'), self::USER);

        $this->assertSame(array(), $this->orders->queries);
    }

    // ── the document ─────────────────────────────────────────

    public function test_it_exports_one_account_by_id(): void
    {
        $result = $this->tool()->execute(array('customer_id' => 7), self::USER);

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['data']['customer']['id']);
        $this->assertSame('ada@example.com', $result['data']['customer']['email']);
        $this->assertSame('Ada Lovelace', $result['data']['customer']['name']);
        $this->assertArrayHasKey('exported_at', $result['data']);
    }

    public function test_it_finds_an_account_by_email_case_insensitively(): void
    {
        $result = $this->tool()->execute(array('email' => 'ADA@Example.COM'), self::USER);

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['data']['customer']['id']);
    }

    public function test_orders_are_included_by_default(): void
    {
        $this->orders = new OrderStoreFake(
            $this->order(1, 7, '2024-02-01'),
            $this->order(2, 7, '2024-03-01')
        );

        $result = $this->tool()->execute(array('customer_id' => 7), self::USER);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['orders_total']);
        $this->assertSame(2, $result['data']['orders_included']);
        $this->assertFalse($result['data']['orders_truncated']);
        $this->assertSame('#2', $result['data']['orders'][0]['number']);
    }

    public function test_orders_can_be_left_out(): void
    {
        $this->orders = new OrderStoreFake($this->order(1, 7, '2024-02-01'));

        $result = $this->tool()->execute(
            array('customer_id' => 7, 'include_orders' => false),
            self::USER
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('orders', $result['data']);
        $this->assertSame(array(), $this->orders->queries);
    }

    public function test_only_the_selected_account_orders_are_read(): void
    {
        $this->orders = new OrderStoreFake(
            $this->order(1, 7, '2024-02-01'),
            $this->order(2, 8, '2024-02-02')
        );

        $result = $this->tool()->execute(array('customer_id' => 7), self::USER);

        $this->assertSame(1, $result['data']['orders_total']);
        $this->assertSame(7, $this->orders->queries[0]->customerId);
    }

    public function test_a_long_history_is_paged(): void
    {
        $orders = array();

        for ($i = 1; $i <= 250; $i++) {
            $orders[] = $this->order($i, 7, '2024-01-01');
        }

        $this->orders = new OrderStoreFake(...$orders);

        $result = $this->tool()->execute(array('customer_id' => 7), self::USER);

        $this->assertSame(250, $result['data']['orders_total']);
        $this->assertSame(250, $result['data']['orders_included']);
        $this->assertFalse($result['data']['orders_truncated']);
        $this->assertCount(3, $this->orders->queries);
    }

    public function test_a_history_beyond_the_cap_says_it_was_clipped(): void
    {
        $orders = array();

        for ($i = 1; $i <= ExportCustomerDataTool::MAX_ORDERS + 1; $i++) {
            $orders[] = $this->order($i, 7, '2024-01-01');
        }

        $this->orders = new OrderStoreFake(...$orders);

        $result = $this->tool()->execute(array('customer_id' => 7), self::USER);

        $this->assertSame(ExportCustomerDataTool::MAX_ORDERS + 1, $result['data']['orders_total']);
        $this->assertSame(ExportCustomerDataTool::MAX_ORDERS, $result['data']['orders_included']);
        $this->assertTrue($result['data']['orders_truncated']);
    }

    private function order(int $id, int $customerId, string $created): Order
    {
        return new Order(
            id: $id,
            number: '#' . $id,
            status: OrderStatus::Completed,
            createdAt: new \DateTimeImmutable($created),
            total: Money::fromDecimal('100.00', 'USD'),
            lines: array(),
            customerId: $customerId,
        );
    }
}
