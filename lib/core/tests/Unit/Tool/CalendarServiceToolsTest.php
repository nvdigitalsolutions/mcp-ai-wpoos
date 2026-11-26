<?php

/**
 * The two service tools and the shared service rules.
 *
 * Pinned behaviours:
 *
 * - `create_service` writes the `_service_*` family, derives `_service_key` from
 *   the name and place, stores tags and media IDs, and exposes `source_url`.
 * - Duration, buffer, participants, price, place and URL bounds are enforced;
 *   remote image URLs are refused; the edit rule is enforced on update.
 * - `import_services` validates every row in the dry run, reports created and
 *   updated separately, dedupes by external id then derived key then legacy
 *   title, catches duplicates inside one payload, and pages visibly.
 * - The CSV reader strips a BOM, sniffs the delimiter, normalises header
 *   aliases and reports ignored columns and source row numbers.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Tests\Support\FakeCalendarStore;
use Nvoos\Core\Tool\Calendar\CreateServiceTool;
use Nvoos\Core\Tool\Calendar\ImportServicesTool;
use Nvoos\Core\Tool\Calendar\ServiceDefinition;
use PHPUnit\Framework\TestCase;

final class CalendarServiceToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ─── create_service ──────────────────────────────────────────────────

    public function test_create_writes_the_service_family_and_derives_the_key(): void
    {
        $store  = new FakeCalendarStore();
        $result = (new CreateServiceTool($this->errors, $store))->execute(
            array(
                'name'                => 'Deep Tissue Massage',
                'duration_minutes'    => 60,
                'price'               => 79.995,
                'buffer_time_minutes' => 15,
                'category'            => 'Massage',
                'place_id'            => 7,
                'max_participants'    => 4,
                'tags'                => array('relax', 'spa'),
                'media_ids'           => array(11, 12),
                'source_url'          => 'https://example.test/service',
                'external_id'         => 'svc-1',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['updated']);

        $command = $store->created;
        $this->assertNotNull($command);
        $this->assertSame(ServiceDefinition::TYPE, $command->type);
        $this->assertSame('publish', $command->status);

        $meta = $command->meta;
        $this->assertSame(60, $meta['_service_duration']);
        $this->assertSame(80.0, $meta['_service_price']);
        $this->assertSame(15, $meta['_service_buffer_time']);
        $this->assertSame(7, $meta['_service_place_id']);
        $this->assertSame(4, $meta['_service_max_participants']);
        $this->assertSame(array('relax', 'spa'), $meta['_service_tags']);
        $this->assertSame(array(11, 12), $meta['_service_gallery']);
        $this->assertSame('https://example.test/service', $meta['_service_source_url']);
        $this->assertSame('svc-1', $meta['_service_external_id']);
        $this->assertSame('deep-tissue-massage:7', $meta['_service_key']);
        $this->assertSame(array('mcp_service_category' => array('Massage')), $command->taxonomyInput);

        $service = $result['data']['service'];
        $this->assertSame('Deep Tissue Massage', $service['name']);
        $this->assertSame(60, $service['duration_minutes']);
        $this->assertSame(80.0, $service['price']);
        $this->assertSame('Massage', $service['category']);
        $this->assertSame(array(11, 12), $service['media_ids']);
        $this->assertSame(array('relax', 'spa'), $service['tags']);
    }

    public function test_create_refuses_out_of_range_values(): void
    {
        $store = new FakeCalendarStore();
        $tool  = new CreateServiceTool($this->errors, $store);

        $cases = array(
            'duration_minutes'    => 10,
            'price'               => -1,
            'buffer_time_minutes' => 241,
            'max_participants'    => 0,
            'place_id'            => -1,
            'source_url'          => 'ftp://example.test/x',
        );

        foreach ($cases as $field => $value) {
            $result = $tool->execute(array('name' => 'X', $field => $value), self::CONTEXT);

            $this->assertSame('validation_failed', $result['error']['code'], 'Expected ' . $field . ' to fail.');
            $this->assertSame($field, array_key_first($result['error']['data']));
        }

        $this->assertNull($store->created);
    }

    public function test_create_refuses_remote_images_but_accepts_media_ids(): void
    {
        $store = new FakeCalendarStore();
        $tool  = new CreateServiceTool($this->errors, $store);

        $refused = $tool->execute(
            array('name' => 'X', 'image_urls' => array('https://example.test/a.jpg')),
            self::CONTEXT
        );
        $this->assertSame('validation_failed', $refused['error']['code']);
        $this->assertSame('image_urls', array_key_first($refused['error']['data']));

        $allowed = $tool->execute(array('name' => 'X', 'image_urls' => array()), self::CONTEXT);
        $this->assertTrue($allowed['success']);
    }

    public function test_update_changes_only_supplied_fields_and_rewrites_the_key(): void
    {
        $store = new FakeCalendarStore();
        $store->seed(
            5,
            ServiceDefinition::TYPE,
            'Yoga',
            array(
                '_service_duration' => 60,
                '_service_place_id' => 7,
                '_service_price'    => 20.0,
                '_service_key'      => 'yoga:7',
            )
        );

        $tool   = new CreateServiceTool($this->errors, $store);
        $result = $tool->execute(array('service_id' => 5, 'name' => 'Yoga Flow'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['updated']);
        $this->assertSame('Yoga Flow', $store->items[5]->title);
        $this->assertSame(60, $store->items[5]->meta['_service_duration']);
        $this->assertSame(20.0, $store->items[5]->meta['_service_price']);
        $this->assertSame('yoga-flow:7', $store->items[5]->meta['_service_key']);

        $cleared = $tool->execute(array('service_id' => 5, 'name' => 'Yoga Flow', 'place_id' => 0), self::CONTEXT);
        $this->assertTrue($cleared['success']);
        $this->assertSame(0, $store->items[5]->meta['_service_place_id']);
        $this->assertSame('yoga-flow', $store->items[5]->meta['_service_key']);
    }

    public function test_update_clears_the_category_when_an_empty_string_is_sent(): void
    {
        $store = new FakeCalendarStore();
        $store->seed(
            5,
            ServiceDefinition::TYPE,
            'Yoga',
            array(),
            '',
            'publish',
            1,
            array('mcp_service_category' => array('Old'))
        );

        $tool   = new CreateServiceTool($this->errors, $store);
        $result = $tool->execute(array('service_id' => 5, 'name' => 'Yoga', 'category' => ''), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $store->items[5]->taxonomy['mcp_service_category']);
        $this->assertSame('', $result['data']['service']['category']);
    }

    public function test_update_honours_the_store_edit_rule(): void
    {
        $store = new FakeCalendarStore();
        $store->seed(5, ServiceDefinition::TYPE, 'Yoga', array());
        $store->access[5] = array('edit' => false);

        $result = (new CreateServiceTool($this->errors, $store))->execute(
            array('service_id' => 5, 'name' => 'Yoga'),
            self::CONTEXT
        );

        $this->assertSame('forbidden', $result['error']['code']);
    }

    public function test_create_requires_a_name(): void
    {
        $result = (new CreateServiceTool($this->errors, new FakeCalendarStore()))->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame('name', array_key_first($result['error']['data']));
    }

    // ─── import_services ─────────────────────────────────────────────────

    public function test_import_dry_run_validates_every_row_without_writing(): void
    {
        $store  = new FakeCalendarStore();
        $result = (new ImportServicesTool($this->errors, $store))->execute(
            array(
                'source'  => 'json_array',
                'dry_run' => true,
                'data'    => array(
                    array('name' => 'A', 'duration_minutes' => 60),
                    array('name' => 'B', 'duration_minutes' => 10),
                    array('duration_minutes' => 30),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(3, $result['data']['processed']);
        $this->assertSame(1, $result['data']['created']);
        $this->assertSame(2, $result['data']['failed']);
        $this->assertSame(array(1, 2), array_column($result['data']['errors'], 'index'));
        $this->assertStringContainsString('duration_minutes', $result['data']['errors'][0]['error']);
        $this->assertNull($store->created);
    }

    public function test_import_creates_rows_and_skips_them_on_a_rerun(): void
    {
        $store = new FakeCalendarStore();
        $tool  = new ImportServicesTool($this->errors, $store);

        $rows = array(
            array('name' => 'A', 'price' => 10),
            array('name' => 'B', 'price' => 20),
        );

        $first = $tool->execute(array('source' => 'json_array', 'data' => $rows), self::CONTEXT);
        $this->assertSame(2, $first['data']['created']);
        $this->assertCount(2, $first['data']['ids']);
        $this->assertCount(2, $store->items);

        $second = $tool->execute(array('source' => 'json_array', 'data' => $rows), self::CONTEXT);
        $this->assertSame(0, $second['data']['created']);
        $this->assertSame(2, $second['data']['skipped']);
        $this->assertCount(2, $store->items);
    }

    public function test_import_updates_an_existing_service_by_external_id(): void
    {
        $store = new FakeCalendarStore();
        $tool  = new ImportServicesTool($this->errors, $store);

        $tool->execute(
            array('source' => 'json_array', 'data' => array(array('name' => 'A', 'external_id' => 'x1', 'price' => 10))),
            self::CONTEXT
        );

        $id = array_key_first($store->items);

        $result = $tool->execute(
            array(
                'source'          => 'json_array',
                'update_existing' => true,
                'data'            => array(array('name' => 'A renamed', 'external_id' => 'x1', 'price' => 25)),
            ),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['updated']);
        $this->assertSame(0, $result['data']['created']);
        $this->assertCount(1, $store->items);
        $this->assertSame($id, array_key_first($store->items));
        $this->assertSame('A renamed', $store->items[ $id ]->title);
        $this->assertSame(25.0, $store->items[ $id ]->meta['_service_price']);
        $this->assertSame('a-renamed', $store->items[ $id ]->meta['_service_key']);
    }

    public function test_import_matches_a_legacy_title_only_under_the_name_strategy(): void
    {
        $store = new FakeCalendarStore();
        $store->seed(9, ServiceDefinition::TYPE, 'Legacy Service', array('_service_duration' => 30));

        $tool = new ImportServicesTool($this->errors, $store);

        $skipped = $tool->execute(
            array('source' => 'json_array', 'data' => array(array('name' => 'legacy service'))),
            self::CONTEXT
        );
        $this->assertSame(1, $skipped['data']['skipped']);

        $fresh = new FakeCalendarStore();
        $fresh->seed(9, ServiceDefinition::TYPE, 'Legacy Service', array('_service_duration' => 30));

        $created = (new ImportServicesTool($this->errors, $fresh))->execute(
            array(
                'source'         => 'json_array',
                'dedup_strategy' => 'name_and_place',
                'data'           => array(array('name' => 'legacy service')),
            ),
            self::CONTEXT
        );
        $this->assertSame(1, $created['data']['created']);
    }

    public function test_import_reads_csv_with_a_bom_semicolons_and_aliases(): void
    {
        $csv = "\xEF\xBB\xBFService;Duration;Cost;Capacity;Category;Notes\n"
            . "A;60;25;4;Massage;keep\n"
            . "\n"
            . "B;30;10;2;Yoga;skip\n";

        $store = new FakeCalendarStore();
        $tool  = new ImportServicesTool($this->errors, $store);

        $preview = $tool->execute(
            array('source' => 'csv', 'csv_string' => $csv, 'dry_run' => true),
            self::CONTEXT
        );

        $this->assertTrue($preview['success']);
        $this->assertSame(2, $preview['data']['total']);
        $this->assertSame(2, $preview['data']['created']);
        $this->assertSame(array('Notes'), $preview['data']['ignored_headers']);
        $this->assertSame('csv', $preview['data']['source']);

        $write = $tool->execute(array('source' => 'csv', 'csv_string' => $csv), self::CONTEXT);
        $this->assertSame(2, $write['data']['created']);

        $a = $this->item($store, 'A');
        $this->assertSame(60, $a->meta['_service_duration']);
        $this->assertSame(25.0, $a->meta['_service_price']);
        $this->assertSame(4, $a->meta['_service_max_participants']);
        $this->assertSame(array('Massage'), $a->taxonomy['mcp_service_category']);
        $this->assertSame('a', $a->meta['_service_key']);
    }

    public function test_import_reports_the_source_row_number_for_a_csv_failure(): void
    {
        $csv = "Name,Duration\nA,60\nB,10\n";

        $result = (new ImportServicesTool($this->errors, new FakeCalendarStore()))->execute(
            array('source' => 'csv', 'csv_string' => $csv, 'dry_run' => true),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['failed']);
        $this->assertSame(1, $result['data']['errors'][0]['index']);
        $this->assertSame(3, $result['data']['errors'][0]['row']);
    }

    public function test_import_paginates_visibly(): void
    {
        $rows = array();

        for ($i = 1; $i <= 5; ++$i) {
            $rows[] = array('name' => 'Service ' . $i);
        }

        $tool = new ImportServicesTool($this->errors, new FakeCalendarStore());

        $first = $tool->execute(
            array('source' => 'json_array', 'dry_run' => true, 'data' => $rows, 'offset' => 0, 'limit' => 2),
            self::CONTEXT
        );
        $this->assertSame(2, $first['data']['processed']);
        $this->assertSame(2, $first['data']['created']);
        $this->assertSame(2, $first['data']['next_offset']);
        $this->assertFalse($first['data']['complete']);
        $this->assertTrue($first['data']['has_more']);

        $last = $tool->execute(
            array('source' => 'json_array', 'dry_run' => true, 'data' => $rows, 'offset' => 4, 'limit' => 2),
            self::CONTEXT
        );
        $this->assertSame(1, $last['data']['processed']);
        $this->assertTrue($last['data']['complete']);

        $done = $tool->execute(
            array('source' => 'json_array', 'dry_run' => true, 'data' => $rows, 'offset' => 5),
            self::CONTEXT
        );
        $this->assertSame(0, $done['data']['processed']);
        $this->assertTrue($done['data']['complete']);
    }

    public function test_import_skips_a_duplicate_inside_one_payload(): void
    {
        $store = new FakeCalendarStore();
        $tool  = new ImportServicesTool($this->errors, $store);

        $rows = array(array('name' => 'A'), array('name' => 'A'));

        $preview = $tool->execute(array('source' => 'json_array', 'dry_run' => true, 'data' => $rows), self::CONTEXT);
        $this->assertSame(1, $preview['data']['created']);
        $this->assertSame(1, $preview['data']['skipped']);

        $write = $tool->execute(array('source' => 'json_array', 'data' => $rows), self::CONTEXT);
        $this->assertSame(1, $write['data']['created']);
        $this->assertSame(1, $write['data']['skipped']);
        $this->assertCount(1, $store->items);
    }

    public function test_import_rejects_an_unknown_category_when_auto_create_is_off(): void
    {
        $store = new FakeCalendarStore();
        $store->terms['mcp_service_category'] = array('Massage');

        $rows = array(
            array('name' => 'A', 'category' => 'Massage'),
            array('name' => 'B', 'category' => 'Unknown'),
        );

        $result = (new ImportServicesTool($this->errors, $store))->execute(
            array(
                'source'                 => 'json_array',
                'dry_run'                => true,
                'auto_create_categories' => false,
                'data'                   => $rows,
            ),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['created']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertStringContainsString('Unknown category', $result['data']['errors'][0]['error']);

        $auto = (new ImportServicesTool($this->errors, new FakeCalendarStore()))->execute(
            array('source' => 'json_array', 'dry_run' => true, 'data' => $rows),
            self::CONTEXT
        );
        $this->assertSame(2, $auto['data']['created']);
    }

    public function test_import_accepts_a_json_document_with_a_services_key(): void
    {
        $result = (new ImportServicesTool($this->errors, new FakeCalendarStore()))->execute(
            array(
                'source'      => 'json',
                'json_string' => '{"services":[{"name":"A"},{"name":"B"}]}',
                'dry_run'     => true,
            ),
            self::CONTEXT
        );

        $this->assertSame(2, $result['data']['created']);
    }

    public function test_import_refuses_invalid_input(): void
    {
        $tool = new ImportServicesTool($this->errors, new FakeCalendarStore());

        $source = $tool->execute(array('source' => 'xml'), self::CONTEXT);
        $this->assertSame('validation_failed', $source['error']['code']);

        $empty = $tool->execute(array('source' => 'json_array', 'data' => array()), self::CONTEXT);
        $this->assertSame('empty_data', $empty['error']['code']);

        $json = $tool->execute(
            array('source' => 'json', 'json_string' => '{"broken":'),
            self::CONTEXT
        );
        $this->assertSame('validation_failed', $json['error']['code']);
        $this->assertStringContainsString('Invalid JSON', $json['error']['message']);
    }

    public function test_import_refuses_remote_images_per_row(): void
    {
        $result = (new ImportServicesTool($this->errors, new FakeCalendarStore()))->execute(
            array(
                'source'  => 'json_array',
                'dry_run' => true,
                'data'    => array(
                    array('name' => 'A', 'image_urls' => array('https://example.test/a.jpg')),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['failed']);
        $this->assertStringContainsString('Remote image', $result['data']['errors'][0]['error']);
    }

    /**
     * Find a stored item by exact title.
     */
    private function item(FakeCalendarStore $store, string $title): ContentItem
    {
        foreach ($store->items as $item) {
            if ($item->title === $title) {
                return $item;
            }
        }

        throw new \RuntimeException('Item not found: ' . $title);
    }
}
