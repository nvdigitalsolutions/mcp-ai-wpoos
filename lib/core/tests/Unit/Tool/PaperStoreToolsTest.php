<?php

/**
 * Paper Store export and import — the pair that moves a collection.
 *
 * These run against the real `JsonPaperStore` in a temporary root rather than a
 * double, because the questions here are about *shape*: which records come back,
 * in what order, whether a page overlaps the one before it, and whether a batch
 * report adds up. A fake that answers with whatever the test wrote cannot fail
 * those questions.
 *
 * The two traps the suite exists for:
 *
 *  - `query()` answers an unusable collection name with an empty page, so an
 *    export that does not validate first would report "no records" for a
 *    call that could never have worked;
 *  - the WordPress import counts a record with no id as "skipped", which is the
 *    same counter it uses for "already exists". Those are different facts, and
 *    a caller reconciling a migration needs to tell them apart.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Infrastructure\PaperStore\JsonPaperStore;
use Nvoos\Core\Tool\PaperStoreExportTool;
use Nvoos\Core\Tool\PaperStoreImportTool;
use PHPUnit\Framework\TestCase;

final class PaperStoreToolsTest extends TestCase
{
    private string $root;

    private JsonPaperStore $store;

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/oos-paperstore-' . \bin2hex(\random_bytes(5));

        \mkdir($this->root, 0775, true);

        $this->store = new JsonPaperStore($this->root);

        $this->errors = new class () implements ErrorFactoryInterface {
            public function create(string $code, string $message, array $data = array()): mixed
            {
                return array('error' => array('code' => $code, 'message' => $message, 'data' => $data));
            }

            public function isError(mixed $value): bool
            {
                return \is_array($value) && isset($value['error']);
            }

            public function normalize(mixed $error): array
            {
                return (array) $error;
            }

            public function notFound(string $message = 'Resource not found.', array $data = array()): mixed
            {
                return $this->create('not_found', $message, $data);
            }

            public function forbidden(string $message = 'Access denied.', array $data = array()): mixed
            {
                return $this->create('forbidden', $message, $data);
            }

            public function validationFailed(string $message, array $errors = array()): mixed
            {
                return $this->create('validation_failed', $message, $errors);
            }

            public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed
            {
                return $this->create('rate_limited', $message);
            }
        };
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    // -- Export -------------------------------------------------------------

    public function test_the_export_declares_its_slug_capability_and_flags(): void
    {
        $tool = $this->export();

        $this->assertSame('paper_store_export', $tool->getSlug());
        $this->assertSame('Paper Store — Export Records', $tool->getName());
        $this->assertSame('manage_options', $tool->getRequiredCapability());

        // `cacheable` is deliberately absent: the store can change between calls.
        $this->assertSame(
            array('idempotent', 'local-only', 'read-only'),
            $tool->getCapabilityFlags(),
        );
    }

    public function test_the_export_schema_states_the_page_ceiling(): void
    {
        $schema = $this->export()->getParametersSchema();

        $this->assertSame(200, $schema['properties']['limit']['maximum']);
        $this->assertSame(200, $schema['properties']['limit']['default']);
        $this->assertSame(array('collection'), $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function test_the_export_returns_the_whole_collection_and_nothing_else(): void
    {
        $this->seed();
        $this->store->save('other', array('id' => 'zzz', 'title' => 'Not mine'));

        $result = $this->export()->execute(array('collection' => 'notes'));

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['count']);
        $this->assertSame(3, $result['data']['total']);
        $this->assertFalse($result['data']['truncated']);
        $this->assertNull($result['data']['next_offset']);

        $this->assertSame(
            array('alpha', 'bravo', 'charlie'),
            \array_column($result['data']['records'], 'id'),
            'Exports are ordered by id so paging is stable.',
        );
    }

    public function test_the_export_filters_by_tag(): void
    {
        $this->seed();

        $result = $this->export()->execute(array('collection' => 'notes', 'tags' => 'beta'));

        $this->assertSame(1, $result['data']['count']);
        $this->assertSame('bravo', $result['data']['records'][0]['id']);
        $this->assertSame(1, $result['data']['total'], 'The total is the filtered total, not the collection size.');
    }

    public function test_the_export_filters_by_status(): void
    {
        $this->seed();

        $result = $this->export()->execute(array('collection' => 'notes', 'status' => 'published'));

        $this->assertSame(array('alpha'), \array_column($result['data']['records'], 'id'));
    }

    public function test_the_export_filters_by_type(): void
    {
        $this->seed();

        $result = $this->export()->execute(array('collection' => 'notes', 'type' => 'note'));

        $this->assertSame(array('bravo'), \array_column($result['data']['records'], 'id'));
    }

    public function test_the_export_pages_without_overlap_or_gaps(): void
    {
        $this->seed();
        $this->store->save('notes', array('id' => 'delta', 'title' => 'Delta'));
        $this->store->save('notes', array('id' => 'echo', 'title' => 'Echo'));

        $seen = array();
        $offset = 0;

        for ($guard = 0; $guard < 10; ++$guard) {
            $result = $this->export()->execute(array(
                'collection' => 'notes',
                'limit' => 2,
                'offset' => $offset,
            ));

            $this->assertTrue($result['success']);
            $this->assertSame(5, $result['data']['total']);

            foreach ($result['data']['records'] as $record) {
                $seen[] = $record['id'];
            }

            $offset = $result['data']['next_offset'];

            if (null === $offset) {
                break;
            }
        }

        $this->assertSame(
            array('alpha', 'bravo', 'charlie', 'delta', 'echo'),
            $seen,
            'Every record exactly once, in order - no overlap and no gap.',
        );
    }

    public function test_an_unusable_collection_name_is_refused_rather_than_answered_empty(): void
    {
        $result = $this->export()->execute(array('collection' => '../secrets'));

        $this->assertArrayNotHasKey('success', $result, 'A refusal is an error envelope, not a success payload.');
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('not a usable collection name', $result['error']['message']);
    }

    public function test_an_empty_collection_is_an_empty_export_not_an_error(): void
    {
        $result = $this->export()->execute(array('collection' => 'never-used'));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['count']);
        $this->assertSame(0, $result['data']['total']);
        $this->assertSame(array(), $result['data']['records']);
    }

    public function test_a_limit_above_the_ceiling_is_clamped_rather_than_trusted(): void
    {
        $this->seed();

        $result = $this->export()->execute(array('collection' => 'notes', 'limit' => 5000));

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['data']['limit']);
    }

    // -- Import -------------------------------------------------------------

    public function test_the_import_declares_its_slug_capability_and_flags(): void
    {
        $tool = $this->import();

        $this->assertSame('paper_store_import', $tool->getSlug());
        $this->assertSame('Paper Store — Import Records', $tool->getName());
        $this->assertSame('manage_options', $tool->getRequiredCapability());

        // Not `idempotent`: the same payload reaches the same state, but every
        // write stamps a new updated_at.
        $this->assertSame(
            array('local-only', 'state-changing', 'write'),
            $tool->getCapabilityFlags(),
        );
    }

    public function test_the_import_writes_records_that_read_back(): void
    {
        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(
                array('id' => 'alpha', 'title' => 'Alpha', 'tags' => array('one'), 'body' => array('x' => 1)),
                array('id' => 'bravo', 'title' => 'Bravo'),
            ),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['imported']);
        $this->assertSame(0, $result['data']['skipped']);
        $this->assertSame(0, $result['data']['failed']);
        $this->assertFalse($result['data']['dry_run']);

        $stored = $this->store->find('notes', 'alpha');

        $this->assertNotNull($stored);
        $this->assertSame('Alpha', $stored['title']);
        $this->assertSame(array('one'), $stored['tags']);
        $this->assertSame(array('x' => 1), $stored['body']);
    }

    public function test_a_second_import_replaces_the_whole_record(): void
    {
        $this->store->save('notes', array('id' => 'alpha', 'title' => 'Old', 'tags' => array('keep-or-lose')));
        $created = $this->store->find('notes', 'alpha')['created_at'];

        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(array('id' => 'alpha', 'title' => 'New')),
        ));

        $this->assertSame(1, $result['data']['imported']);

        $stored = $this->store->find('notes', 'alpha');

        $this->assertSame('New', $stored['title']);
        $this->assertSame(array(), $stored['tags'], 'An import is a replace, not a merge.');
        $this->assertSame($created, $stored['created_at'], 'created_at survives a replace.');
    }

    public function test_overwrite_false_skips_an_existing_id(): void
    {
        $this->store->save('notes', array('id' => 'alpha', 'title' => 'Original'));

        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(array('id' => 'alpha', 'title' => 'Intruder')),
            'overwrite' => false,
        ));

        $this->assertSame(0, $result['data']['imported']);
        $this->assertSame(1, $result['data']['skipped']);
        $this->assertSame('Original', $this->store->find('notes', 'alpha')['title']);
    }

    public function test_a_record_without_an_id_is_a_failure_not_a_silent_skip(): void
    {
        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(array('title' => 'No key here')),
        ));

        $this->assertSame(0, $result['data']['imported']);
        $this->assertSame(0, $result['data']['skipped'], 'A malformed record is not "already exists".');
        $this->assertSame(1, $result['data']['failed']);
        $this->assertNull($result['data']['errors'][0]['id']);
        $this->assertSame(0, $result['data']['errors'][0]['index']);
        $this->assertStringContainsString('id is required', $result['data']['errors'][0]['error']);
    }

    public function test_a_record_without_a_title_is_a_failure(): void
    {
        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(array('id' => 'alpha')),
        ));

        $this->assertSame(1, $result['data']['failed']);
        $this->assertSame('alpha', $result['data']['errors'][0]['id']);
        $this->assertStringContainsString('title is required', $result['data']['errors'][0]['error']);
        $this->assertFalse($this->store->has('notes', 'alpha'), 'A refused record is not written.');
    }

    public function test_an_id_with_a_separator_is_refused_before_any_write(): void
    {
        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(array('id' => '../escape', 'title' => 'Nope')),
        ));

        $this->assertSame(1, $result['data']['failed']);
        $this->assertStringContainsString('single path segment', $result['data']['errors'][0]['error']);
        $this->assertSame(array(), $this->store->collections(), 'Nothing was written anywhere.');
    }

    public function test_a_dry_run_writes_nothing_and_reports_the_count(): void
    {
        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(
                array('id' => 'alpha', 'title' => 'Alpha'),
                array('id' => 'bravo', 'title' => 'Bravo'),
            ),
            'dry_run' => true,
        ));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(2, $result['data']['would_import']);
        $this->assertSame(2, $result['data']['imported'], 'The count a real run would report.');
        $this->assertFalse($this->store->has('notes', 'alpha'), 'A rehearsal writes nothing.');
    }

    public function test_a_dry_run_still_refuses_a_malformed_record(): void
    {
        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(array('title' => 'No key')),
            'dry_run' => true,
        ));

        $this->assertSame(0, $result['data']['would_import']);
        $this->assertSame(1, $result['data']['failed']);
    }

    public function test_a_batch_report_always_reconciles(): void
    {
        $this->store->save('notes', array('id' => 'existing', 'title' => 'Already here'));

        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(
                array('id' => 'fresh', 'title' => 'Fresh'),
                array('id' => 'existing', 'title' => 'Skipped'),
                array('title' => 'No key'),
                array('id' => 'no-title'),
                array('id' => 'bad/id', 'title' => 'Separator'),
                'not-an-object',
            ),
            'overwrite' => false,
        ));

        $data = $result['data'];

        $this->assertSame(1, $data['imported']);
        $this->assertSame(1, $data['skipped']);
        $this->assertSame(4, $data['failed']);
        $this->assertSame(
            $data['total'],
            $data['imported'] + $data['skipped'] + $data['failed'],
            'A batch report a caller cannot reconcile is worse than no report.',
        );
    }

    public function test_a_duplicate_id_inside_one_batch_is_written_once_when_overwrite_is_off(): void
    {
        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(
                array('id' => 'alpha', 'title' => 'First'),
                array('id' => 'alpha', 'title' => 'Second'),
            ),
            'overwrite' => false,
        ));

        $this->assertSame(1, $result['data']['imported']);
        $this->assertSame(1, $result['data']['skipped']);
        $this->assertSame('First', $this->store->find('notes', 'alpha')['title']);
    }

    public function test_a_map_instead_of_a_list_is_refused(): void
    {
        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array('alpha' => array('id' => 'alpha', 'title' => 'Alpha')),
        ));

        $this->assertArrayNotHasKey('success', $result, 'A refusal is an error envelope, not a success payload.');
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('list of objects', $result['error']['message']);
    }

    public function test_an_empty_record_list_is_refused(): void
    {
        $result = $this->import()->execute(array('collection' => 'notes', 'records' => array()));

        $this->assertArrayNotHasKey('success', $result, 'A refusal is an error envelope, not a success payload.');
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_batch_larger_than_the_ceiling_is_refused(): void
    {
        $records = array();

        for ($i = 0; $i < 501; ++$i) {
            $records[] = array('id' => 'r' . $i, 'title' => 'Record ' . $i);
        }

        $result = $this->import()->execute(array('collection' => 'notes', 'records' => $records));

        $this->assertArrayNotHasKey('success', $result, 'A refusal is an error envelope, not a success payload.');
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('at most 500', $result['error']['message']);
    }

    public function test_a_padded_id_is_written_with_the_value_that_was_validated(): void
    {
        $result = $this->import()->execute(array(
            'collection' => 'notes',
            'records' => array(array('id' => '  alpha  ', 'title' => '  Alpha  ')),
        ));

        $this->assertSame(1, $result['data']['imported'], 'A padded id is trimmed, not refused.');
        $this->assertTrue($this->store->has('notes', 'alpha'));

        $stored = $this->store->find('notes', 'alpha');

        $this->assertSame('alpha', $stored['id'], 'The validated id is the written id.');
        $this->assertSame('Alpha', $stored['title']);
    }

    public function test_an_unusable_collection_name_is_refused_by_the_import_too(): void
    {
        $result = $this->import()->execute(array(
            'collection' => '..',
            'records' => array(array('id' => 'alpha', 'title' => 'Alpha')),
        ));

        $this->assertArrayNotHasKey('success', $result, 'A refusal is an error envelope, not a success payload.');
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // -- Helpers ------------------------------------------------------------

    private function export(): PaperStoreExportTool
    {
        return new PaperStoreExportTool($this->errors, $this->store);
    }

    private function import(): PaperStoreImportTool
    {
        return new PaperStoreImportTool($this->errors, $this->store);
    }

    /**
     * Three records with distinct tags, statuses and types.
     */
    private function seed(): void
    {
        $this->store->save('notes', array(
            'id' => 'alpha',
            'title' => 'Alpha',
            'status' => 'published',
            'tags' => array('alpha-tag'),
        ));

        $this->store->save('notes', array(
            'id' => 'bravo',
            'title' => 'Bravo',
            'type' => 'note',
            'tags' => array('beta', 'alpha-tag'),
        ));

        $this->store->save('notes', array(
            'id' => 'charlie',
            'title' => 'Charlie',
            'status' => 'draft',
        ));
    }

    private function removeTree(string $dir): void
    {
        if (! \is_dir($dir)) {
            return;
        }

        foreach ((array) \scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry || ! \is_string($entry)) {
                continue;
            }

            $path = $dir . \DIRECTORY_SEPARATOR . $entry;

            if (\is_dir($path)) {
                $this->removeTree($path);
                continue;
            }

            @\unlink($path);
        }

        @\rmdir($dir);
    }
}
