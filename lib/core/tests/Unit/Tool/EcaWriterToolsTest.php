<?php

/**
 * The five ECA writers: what they refuse, what they write, what they report.
 *
 * The assertions are mostly about the refusals and the report, not the call:
 * an overlap inside one payload is refused with both session numbers, a
 * duplicate code inside one CSV is skipped rather than applied twice, a term
 * transition says which scope its attendance reset covered, and a notification
 * names every address it did not reach. Those are the promises the WordPress
 * originals do not make, so they are the ones pinned here.
 *
 * The content store is stateful: `create` and `update` write through to the
 * in-memory rows exactly as the real store merges meta, so a test can read the
 * result back instead of asserting on a recorded call.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Classroom\ImportEcasCsvTool;
use Nvoos\Core\Tool\Classroom\ManageEcaTermTool;
use Nvoos\Core\Tool\Classroom\SendEcaNotificationTool;
use Nvoos\Core\Tool\Classroom\SendEcaParentReportTool;
use Nvoos\Core\Tool\Classroom\SetEcaScheduleTool;
use PHPUnit\Framework\TestCase;

final class EcaWriterToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => $code, 'message' => $message, 'data' => $data ),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => 'validation_failed', 'message' => $message, 'data' => $fields ),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message): array => array(
                'success' => false,
                'error'   => array( 'code' => 'not_found', 'message' => $message ),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message): array => array(
                'success' => false,
                'error'   => array( 'code' => 'forbidden', 'message' => $message ),
            )
        );
    }

    // ─── set_eca_schedule ───────────────────────────────────────────────────

    public function test_a_payload_whose_sessions_overlap_is_refused(): void
    {
        $store = $this->store(array( $this->eca(1) ));

        $result = $this->schedule($store)->execute(
            array(
                'eca_id'   => 1,
                'sessions' => array(
                    array( 'day' => 'Monday', 'start_time' => '09:00', 'end_time' => '10:00', 'venue' => 'Hall' ),
                    array( 'day' => 'Monday', 'start_time' => '09:30', 'end_time' => '10:30', 'venue' => 'Hall' ),
                ),
            ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('Sessions 1 and 2', $result['error']['message']);
        $this->assertSame(array(), $store->updates);
        $this->assertArrayNotHasKey('_eca_sessions', $store->items[1]->meta);
    }

    public function test_back_to_back_sessions_are_not_an_overlap(): void
    {
        $store = $this->store(array( $this->eca(1) ));

        $result = $this->schedule($store)->execute(
            array(
                'eca_id'   => 1,
                'sessions' => array(
                    array( 'day' => 'Monday', 'start_time' => '09:00', 'end_time' => '10:00' ),
                    array( 'day' => 'Monday', 'start_time' => '10:00', 'end_time' => '11:00' ),
                ),
            ),
            array( 'user_id' => 7 )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['sessions_saved']);
    }

    public function test_an_unknown_recurrence_is_refused_not_rewritten(): void
    {
        $store = $this->store(array( $this->eca(1) ));

        $result = $this->schedule($store)->execute(
            array(
                'eca_id'   => 1,
                'sessions' => array(
                    array( 'day' => 'Monday', 'start_time' => '09:00', 'end_time' => '10:00', 'recurrence' => 'fortnight' ),
                ),
            ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('sessions[0].recurrence', $result['error']['data']);
        $this->assertSame(array(), $store->updates);
    }

    public function test_the_first_session_is_mirrored_into_the_single_slot_fields(): void
    {
        $store = $this->store(array( $this->eca(1) ));

        $this->schedule($store)->execute(
            array(
                'eca_id'   => 1,
                'sessions' => array(
                    array( 'day' => 'Tuesday', 'start_time' => '13:00', 'end_time' => '14:00', 'venue' => 'Room 4', 'recurrence' => 'biweekly' ),
                    array( 'day' => 'Thursday', 'start_time' => '13:00', 'end_time' => '14:00', 'venue' => 'Room 9' ),
                ),
            ),
            array( 'user_id' => 7 )
        );

        $meta = $store->items[1]->meta;
        $this->assertCount(2, $meta['_eca_sessions']);
        $this->assertSame('Tuesday', $meta['_eca_day']);
        $this->assertSame('13:00', $meta['_eca_start_time']);
        $this->assertSame('14:00', $meta['_eca_end_time']);
        $this->assertSame('Room 4', $meta['_eca_venue']);
        $this->assertSame('biweekly', $meta['_eca_sessions'][0]['recurrence']);
    }

    public function test_a_venue_clash_with_another_activity_is_a_warning_not_a_refusal(): void
    {
        $store = $this->store(
            array(
                $this->eca(1),
                $this->eca(
                    2,
                    array(
                        '_eca_day'        => 'Monday',
                        '_eca_start_time' => '09:30',
                        '_eca_end_time'   => '10:30',
                        '_eca_venue'      => 'Hall',
                    ),
                    'Drama Club'
                ),
            )
        );

        $result = $this->schedule($store)->execute(
            array(
                'eca_id'   => 1,
                'sessions' => array(
                    array( 'day' => 'Monday', 'start_time' => '09:00', 'end_time' => '10:00', 'venue' => 'Hall' ),
                ),
            ),
            array( 'user_id' => 7 )
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['warnings']);
        $this->assertSame(2, $result['data']['conflicts'][0]['eca_id']);
        $this->assertStringContainsString('Drama Club', $result['data']['warnings'][0]);
    }

    // ─── manage_eca_term ────────────────────────────────────────────────────

    public function test_a_term_is_created_in_the_settings_store_and_listed(): void
    {
        $settings = $this->settings();

        $created = $this->terms($this->store(), $settings)->execute(
            array(
                'action'        => 'create',
                'term_name'     => 'Term 1 2026',
                'start_date'    => '2026-01-08',
                'end_date'      => '2026-04-02',
                'academic_year' => '2025-2026',
            ),
            array( 'user_id' => 7 )
        );

        $this->assertTrue($created['success']);
        $this->assertSame('upcoming', $created['data']['term']['status']);
        $this->assertSame('2026-01-08', $settings->data['eca_terms'][0]['start_date']);

        $listed = $this->terms($this->store(), $settings)->execute(
            array( 'action' => 'list' ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(1, $listed['data']['total']);
        $this->assertSame('Term 1 2026', $listed['data']['terms'][0]['name']);
    }

    public function test_a_duplicate_term_name_is_refused_because_the_name_is_the_join_key(): void
    {
        $settings = $this->settings(
            array(
                'eca_terms' => array(
                    array( 'id' => 'term_a', 'name' => 'Term 1 2026', 'status' => 'upcoming' ),
                ),
            )
        );

        $result = $this->terms($this->store(), $settings)->execute(
            array( 'action' => 'create', 'term_name' => 'term 1 2026' ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('term_name', $result['error']['data']);
        $this->assertCount(1, $settings->data['eca_terms']);
    }

    public function test_transition_completes_the_active_term_and_reports_the_reset_scope(): void
    {
        $settings = $this->settings(
            array(
                'eca_terms' => array(
                    array( 'id' => 'term_a', 'name' => 'Term 1 2026', 'status' => 'active' ),
                    array( 'id' => 'term_b', 'name' => 'Term 2 2026', 'status' => 'upcoming' ),
                ),
            )
        );

        $store = $this->store(
            array(
                $this->eca(
                    1,
                    array(
                        '_eca_term'           => 'Term 1 2026',
                        '_eca_attendance_log' => array( array( 'date' => '2026-01-10', 'attendees' => array() ) ),
                    )
                ),
                $this->eca(
                    2,
                    array(
                        '_eca_attendance_log' => array( array( 'date' => '2026-01-11', 'attendees' => array() ) ),
                    ),
                    'Untagged Club'
                ),
            )
        );

        $result = $this->terms($store, $settings)->execute(
            array( 'action' => 'transition', 'term_id' => 'term_b', 'rollover_enrollments' => true ),
            array( 'user_id' => 7 )
        );

        $this->assertTrue($result['success']);
        $this->assertSame('term_b', $result['data']['active_term']['id']);
        $this->assertSame('completed', $result['data']['previous_term']['status']);
        $this->assertSame(1, $result['data']['activities_retagged']);
        $this->assertSame(1, $result['data']['attendance_reset']);
        $this->assertSame('outgoing_term', $result['data']['attendance_scope']);

        $this->assertSame('Term 2 2026', $store->items[1]->meta['_eca_term']);
        $this->assertSame(array(), $store->items[1]->meta['_eca_attendance_log']);
        $this->assertNotEmpty($store->items[2]->meta['_eca_attendance_log']);
    }

    public function test_an_already_active_term_cannot_be_transitioned_to_itself(): void
    {
        $settings = $this->settings(
            array(
                'eca_terms' => array(
                    array( 'id' => 'term_a', 'name' => 'Term 1 2026', 'status' => 'active' ),
                ),
            )
        );

        $result = $this->terms($this->store(), $settings)->execute(
            array( 'action' => 'transition', 'term_id' => 'term_a' ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_close_deactivates_only_the_terms_activities(): void
    {
        $settings = $this->settings(
            array(
                'eca_terms' => array(
                    array( 'id' => 'term_a', 'name' => 'Term 1 2026', 'status' => 'active' ),
                ),
            )
        );

        $store = $this->store(
            array(
                $this->eca(1, array( '_eca_term' => 'Term 1 2026', '_eca_status' => 'active' )),
                $this->eca(2, array( '_eca_term' => 'Term 2 2026', '_eca_status' => 'active' ), 'Next Term Club'),
            )
        );

        $result = $this->terms($store, $settings)->execute(
            array( 'action' => 'close', 'term_id' => 'term_a' ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(1, $result['data']['ecas_deactivated']);
        $this->assertSame('inactive', $store->items[1]->meta['_eca_status']);
        $this->assertSame('active', $store->items[2]->meta['_eca_status']);
        $this->assertSame('completed', $settings->data['eca_terms'][0]['status']);
    }

    public function test_get_counts_the_activities_carrying_the_term_name(): void
    {
        $settings = $this->settings(
            array(
                'eca_terms' => array(
                    array( 'id' => 'term_a', 'name' => 'Term 1 2026', 'status' => 'active' ),
                ),
            )
        );

        $store = $this->store(
            array(
                $this->eca(1, array( '_eca_term' => 'Term 1 2026' )),
                $this->eca(2, array( '_eca_term' => 'Term 1 2026' ), 'Second Club'),
                $this->eca(3, array( '_eca_term' => 'Term 2 2026' ), 'Third Club'),
            )
        );

        $result = $this->terms($store, $settings)->execute(
            array( 'action' => 'get', 'term_id' => 'term_a' ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(2, $result['data']['term']['eca_count']);
    }

    // ─── import_ecas_csv ────────────────────────────────────────────────────

    public function test_a_duplicate_code_inside_one_file_is_skipped_and_reported(): void
    {
        $store = $this->store();

        $csv = "name,code,type\nChess Club,CH-1,club\nChess Club (copy),CH-1,club\n";

        $result = $this->importer($store)->execute(
            array( 'csv_content' => $csv ),
            array( 'user_id' => 7 )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['total_rows']);
        $this->assertSame(1, $result['data']['created_count']);
        $this->assertSame(1, $result['data']['skipped_count']);
        $this->assertStringContainsString('already appeared on row 2', $result['data']['errors'][0]);
        $this->assertCount(1, $store->created);
    }

    public function test_a_file_url_is_refused_and_file_id_is_named(): void
    {
        $store = $this->store();

        $result = $this->importer($store)->execute(
            array( 'file_url' => 'https://example.test/ecas.csv' ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('file_id', $result['error']['data']['file_url'][0]);
        $this->assertCount(0, $store->created);
    }

    public function test_dry_run_counts_without_writing(): void
    {
        $store = $this->store();

        $csv = "name,type\nChess Club,club\nDrama Club,society\n";

        $result = $this->importer($store)->execute(
            array( 'csv_content' => $csv, 'dry_run' => true ),
            array( 'user_id' => 7 )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['created_count']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertCount(0, $store->created);
    }

    public function test_update_existing_matches_by_code_and_updates_the_body(): void
    {
        $store = $this->store(
            array(
                $this->eca(5, array( '_eca_code' => 'CH-1' ), 'Old Chess Name'),
            )
        );

        $csv = "name,code,description\nChess Club,CH-1,Meets in the library.\n";

        $result = $this->importer($store)->execute(
            array( 'csv_content' => $csv, 'update_existing' => true ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(1, $result['data']['updated_count']);
        $this->assertSame(0, $result['data']['created_count']);
        $this->assertSame('Chess Club', $store->items[5]->title);
        $this->assertSame('Meets in the library.', $store->items[5]->content);
        $this->assertCount(0, $store->created);
    }

    public function test_the_same_code_creates_a_second_activity_when_update_existing_is_off(): void
    {
        $store = $this->store(
            array(
                $this->eca(5, array( '_eca_code' => 'CH-1' ), 'Existing Chess'),
            )
        );

        $result = $this->importer($store)->execute(
            array( 'csv_content' => "name,code\nChess Club,CH-1\n" ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(1, $result['data']['created_count']);
        $this->assertSame(0, $result['data']['updated_count']);
        $this->assertCount(1, $store->created);
    }

    public function test_an_unknown_type_falls_back_to_club_and_the_row_says_so(): void
    {
        $store = $this->store();

        $result = $this->importer($store)->execute(
            array( 'csv_content' => "name,type\nRobotics,technology\n" ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(1, $result['data']['created_count']);
        $this->assertStringContainsString('used club', $result['data']['errors'][0]);
        $this->assertSame('club', $store->created[0]['meta']['_eca_type']);
    }

    public function test_a_row_without_a_name_is_skipped(): void
    {
        $store = $this->store();

        $result = $this->importer($store)->execute(
            array( 'csv_content' => "name,type\n,club\nReal Club,club\n" ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(1, $result['data']['created_count']);
        $this->assertSame(1, $result['data']['skipped_count']);
        $this->assertStringContainsString('missing name', $result['data']['errors'][0]);
    }

    public function test_a_csv_is_read_from_the_file_registry_by_id(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eca-csv-');
        $this->assertIsString($path);
        file_put_contents($path, "name,type\nRegistry Club,club\n");

        $store = $this->store();
        $files = $this->files(42, $path);

        $result = $this->importer($store, $files)->execute(
            array( 'file_id' => 42 ),
            array( 'user_id' => 7 )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['created_count']);
        $this->assertSame('Registry Club', $store->created[0]['title']);

        unlink($path);
    }

    public function test_a_file_owned_by_someone_else_cannot_be_read(): void
    {
        $store = $this->store();
        $files = $this->files(42, 'C:/nowhere.csv', false);

        $result = $this->importer($store, $files)->execute(
            array( 'file_id' => 42 ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertSame('forbidden', $result['error']['code']);
    }

    // ─── send_eca_notification ──────────────────────────────────────────────

    public function test_every_address_is_reported_as_sent_or_failed(): void
    {
        $store = $this->store(
            array(
                $this->eca(
                    1,
                    array(
                        '_eca_student_enrollments' => array(
                            10 => array( 'enrollment_type' => 'confirmed' ),
                            11 => array( 'enrollment_type' => 'confirmed' ),
                        ),
                        '_eca_day'                 => 'Monday',
                        '_eca_start_time'          => '15:30',
                        '_eca_end_time'            => '16:30',
                    ),
                    'Chess Club'
                ),
                $this->student(10, array( '_student_email' => 'pupil@example.test' )),
                $this->student(11, array()),
            )
        );

        $mail = $this->mailer();

        $result = $this->notify($store, $mail)->execute(
            array( 'eca_id' => 1, 'notification_type' => 'reminder', 'recipients' => 'all_enrolled' ),
            array( 'user_id' => 7 )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['sent_count']);
        $this->assertSame(1, $result['data']['failed_count']);
        $this->assertSame('pupil@example.test', $result['data']['sent'][0]['email']);
        $this->assertSame('no_student_email', $result['data']['failed'][0]['error']);
        $this->assertCount(1, $mail->sent);
        $this->assertStringContainsString('Chess Club', $mail->sent[0]['subject']);
        $this->assertStringContainsString('15:30', $mail->sent[0]['body']);
    }

    public function test_include_parent_writes_a_second_message_to_the_parent(): void
    {
        $store = $this->store(
            array(
                $this->eca(1, array( '_eca_student_enrollments' => array( 10 => array( 'enrollment_type' => 'confirmed' ) ) )),
                $this->student(
                    10,
                    array(
                        '_student_email'        => 'pupil@example.test',
                        '_student_parent_email' => 'parent@example.test',
                    )
                ),
            )
        );

        $mail = $this->mailer();

        $result = $this->notify($store, $mail)->execute(
            array(
                'eca_id'            => 1,
                'notification_type' => 'schedule_change',
                'recipients'        => 'all_enrolled',
                'include_parent'    => true,
            ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(2, $result['data']['sent_count']);
        $this->assertSame(array( 'student', 'parent' ), array_column($result['data']['sent'], 'role'));
        $this->assertCount(2, $mail->sent);
    }

    public function test_an_untyped_roster_row_counts_as_confirmed(): void
    {
        $store = $this->store(
            array(
                $this->eca(1, array( '_eca_student_enrollments' => array( 10 => array( 'enrolled_at' => '2026-01-01' ) ) )),
                $this->student(10, array( '_student_email' => 'pupil@example.test' )),
            )
        );

        $result = $this->notify($store, $this->mailer())->execute(
            array( 'eca_id' => 1, 'notification_type' => 'reminder', 'recipients' => 'all_enrolled' ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(1, $result['data']['sent_count']);
    }

    public function test_the_waitlist_group_selects_only_waitlisted_rows(): void
    {
        $store = $this->store(
            array(
                $this->eca(
                    1,
                    array(
                        '_eca_student_enrollments' => array(
                            10 => array( 'enrollment_type' => 'confirmed' ),
                            11 => array( 'enrollment_type' => 'waitlist' ),
                        ),
                    )
                ),
                $this->student(10, array( '_student_email' => 'confirmed@example.test' )),
                $this->student(11, array( '_student_email' => 'waiting@example.test' )),
            )
        );

        $result = $this->notify($store, $this->mailer())->execute(
            array( 'eca_id' => 1, 'notification_type' => 'waitlist_update', 'recipients' => 'waitlisted' ),
            array( 'user_id' => 7 )
        );

        $this->assertSame(1, $result['data']['sent_count']);
        $this->assertSame(11, $result['data']['sent'][0]['student_id']);
    }

    public function test_the_log_row_carries_the_failure_count(): void
    {
        $store = $this->store(
            array(
                $this->eca(1, array( '_eca_student_enrollments' => array( 10 => array( 'enrollment_type' => 'confirmed' ) ) )),
                $this->student(10, array()),
            )
        );

        $this->notify($store, $this->mailer())->execute(
            array( 'eca_id' => 1, 'notification_type' => 'reminder', 'recipients' => 'all_enrolled' ),
            array( 'user_id' => 7 )
        );

        $log = $store->items[1]->meta['_eca_notification_log'];
        $this->assertCount(1, $log);
        $this->assertSame('reminder', $log[0]['type']);
        $this->assertSame(0, $log[0]['recipients_count']);
        $this->assertSame(1, $log[0]['failed_count']);
        $this->assertSame(7, $log[0]['sent_by']);
    }

    public function test_a_host_without_a_transport_says_so_before_sending_anything(): void
    {
        $store = $this->store(
            array(
                $this->eca(1, array( '_eca_student_enrollments' => array( 10 => array( 'enrollment_type' => 'confirmed' ) ) )),
                $this->student(10, array( '_student_email' => 'pupil@example.test' )),
            )
        );

        $mail = $this->mailer();
        $mail->available = false;

        $result = $this->notify($store, $mail)->execute(
            array( 'eca_id' => 1, 'notification_type' => 'reminder', 'recipients' => 'all_enrolled' ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertSame('email_unavailable', $result['error']['code']);
        $this->assertSame(array(), $store->updates);
    }

    // ─── send_eca_parent_report ─────────────────────────────────────────────

    public function test_the_parent_address_is_preferred_and_the_role_is_reported(): void
    {
        $store = $this->reportStore();

        $mail = $this->mailer();

        $result = $this->report($store, $mail)->execute(
            array( 'student_id' => 10 ),
            array( 'user_id' => 7 )
        );

        $this->assertTrue($result['success']);
        $this->assertSame('parent@example.test', $result['data']['recipient_email']);
        $this->assertSame('parent', $result['data']['recipient_role']);
        $this->assertSame(1, $result['data']['activities']);
        $this->assertStringContainsString('Chess Club', $mail->sent[0]['body']);
        $this->assertStringContainsString('Term 1 2026', $mail->sent[0]['body']);
    }

    public function test_the_student_address_is_used_when_no_parent_address_exists_and_the_result_says_so(): void
    {
        $store = $this->reportStore(array( '_student_parent_email' => '', '_student_email' => 'pupil@example.test' ));

        $result = $this->report($store, $this->mailer())->execute(
            array( 'student_id' => 10 ),
            array( 'user_id' => 7 )
        );

        $this->assertSame('pupil@example.test', $result['data']['recipient_email']);
        $this->assertSame('student', $result['data']['recipient_role']);
    }

    public function test_a_student_with_no_address_is_refused(): void
    {
        $store = $this->reportStore(array( '_student_parent_email' => '', '_student_email' => '' ));

        $result = $this->report($store, $this->mailer())->execute(
            array( 'student_id' => 10 ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertSame('no_recipient_email', $result['error']['code']);
    }

    public function test_a_student_without_enrollments_is_refused(): void
    {
        $store = $this->store(array( $this->student(10, array( '_student_email' => 'pupil@example.test' )) ));

        $result = $this->report($store, $this->mailer())->execute(
            array( 'student_id' => 10 ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertSame('no_enrollments', $result['error']['code']);
    }

    public function test_the_term_filter_narrows_the_report(): void
    {
        $store = $this->reportStore(
            array( '_student_parent_email' => 'parent@example.test' ),
            array(
                '_student_eca_enrollments' => array( 1 => array( 'enrollment_type' => 'confirmed' ) ),
            )
        );

        $result = $this->report($store, $this->mailer())->execute(
            array( 'student_id' => 10, 'term' => 'Term 2 2026' ),
            array( 'user_id' => 7 )
        );

        $this->assertFalse($result['success']);
        $this->assertSame('no_enrollments', $result['error']['code']);
        $this->assertStringContainsString('Term 2 2026', $result['error']['message']);
    }

    public function test_the_send_is_logged_with_the_recipient_role(): void
    {
        $store = $this->reportStore();

        $this->report($store, $this->mailer())->execute(
            array( 'student_id' => 10, 'term' => 'Term 1 2026' ),
            array( 'user_id' => 7 )
        );

        $log = $store->items[10]->meta['_student_reports_sent'];
        $this->assertCount(1, $log);
        $this->assertSame('Term 1 2026', $log[0]['term']);
        $this->assertSame('parent', $log[0]['recipient_role']);
        $this->assertSame('parent@example.test', $log[0]['sent_to']);
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    private function eca(int $id, array $meta = array(), ?string $title = null): ContentItem
    {
        return $this->item($id, 'mcp_ai_eca', $meta, $title);
    }

    private function student(int $id, array $meta = array()): ContentItem
    {
        return $this->item($id, 'mcp_ai_student', $meta, 'Student ' . $id);
    }

    private function item(int $id, string $type, array $meta, ?string $title): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title ?? ( 'mcp_ai_eca' === $type ? 'Chess Club ' . $id : 'Student ' . $id ),
            content: '',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
        );
    }

    /**
     * A student with a parent address and one enrolment in a Term 1 activity.
     *
     * @param array<string, mixed> $studentMeta Overrides merged over the defaults.
     * @param array<string, mixed>|null $enrollments
     */
    private function reportStore(array $studentMeta = array(), ?array $enrollments = null): ContentStoreInterface
    {
        $meta = array_merge(
            array( '_student_parent_email' => 'parent@example.test' ),
            $studentMeta
        );

        if (null !== $enrollments) {
            $meta = array_merge($meta, $enrollments);
        }

        return $this->store(
            array(
                $this->eca(
                    1,
                    array(
                        '_eca_term'       => 'Term 1 2026',
                        '_eca_status'     => 'active',
                        '_eca_day'        => 'Monday',
                        '_eca_start_time' => '15:30',
                        '_eca_end_time'   => '16:30',
                        '_eca_venue'      => 'Library',
                        '_eca_attendance_log' => array(
                            array(
                                'date'      => '2026-01-12',
                                'attendees' => array( 10 => 'present' ),
                            ),
                        ),
                    ),
                    'Chess Club'
                ),
                $this->student(
                    10,
                    array_merge(
                        $meta,
                        array(
                            '_student_eca_enrollments' => $meta['_student_eca_enrollments']
                                ?? array( 1 => array( 'enrollment_type' => 'confirmed' ) ),
                        )
                    )
                ),
            )
        );
    }

    private function schedule(ContentStoreInterface $store): SetEcaScheduleTool
    {
        return new SetEcaScheduleTool($this->errors, $store);
    }

    private function terms(ContentStoreInterface $store, SettingsStoreInterface $settings): ManageEcaTermTool
    {
        return new ManageEcaTermTool($this->errors, $store, $settings);
    }

    private function importer(ContentStoreInterface $store, ?FileStoreInterface $files = null): ImportEcasCsvTool
    {
        return new ImportEcasCsvTool($this->errors, $store, $files);
    }

    private function notify(ContentStoreInterface $store, EmailServiceInterface $mail): SendEcaNotificationTool
    {
        return new SendEcaNotificationTool($this->errors, $store, $mail);
    }

    private function report(ContentStoreInterface $store, EmailServiceInterface $mail): SendEcaParentReportTool
    {
        return new SendEcaParentReportTool($this->errors, $store, $mail);
    }

    private function settings(array $initial = array()): SettingsStoreInterface
    {
        return new class ($initial) implements SettingsStoreInterface {
            /** @var array<string, mixed> */
            public array $data;

            public function __construct(array $initial)
            {
                $this->data = $initial;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[ $key ] ?? $default;
            }

            public function all(): array
            {
                return $this->data;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[ $key ] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->data[ $key ]);
            }

            public function getDefaultProvider(): string
            {
                return 'openai';
            }

            public function getDefaultModel(): string
            {
                return 'gpt-test';
            }

            public function getApiKey(string $provider): ?string
            {
                return null;
            }

            public function getApiBaseUrl(string $provider): ?string
            {
                return null;
            }

            public function getRequestTimeout(): int
            {
                return 30;
            }

            public function isEnabled(string $feature): bool
            {
                return true;
            }
        };
    }

    private function mailer(): EmailServiceInterface
    {
        return new class implements EmailServiceInterface {
            /** @var list<array<string, mixed>> */
            public array $sent = array();

            public bool $available = true;

            public function send(array $message): array
            {
                $this->sent[] = $message;

                return array( 'success' => true, 'to' => $message['to'] ?? '' );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function validateRecipient(string $email): bool
            {
                return false !== filter_var($email, FILTER_VALIDATE_EMAIL);
            }
        };
    }

    private function files(int $id, string $path, bool $canAccess = true): FileStoreInterface
    {
        return new class ($id, $path, $canAccess) implements FileStoreInterface {
            public function __construct(
                private readonly int $id,
                private readonly string $path,
                private readonly bool $canAccess,
            ) {
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                throw new \LogicException('not used here.');
            }

            public function getPath(int $fileId): ?string
            {
                return $fileId === $this->id ? $this->path : null;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                if ($fileId !== $this->id) {
                    return null;
                }

                $size = is_file($this->path) ? (int) filesize($this->path) : 0;

                return new StoredFile(
                    id: $this->id,
                    filename: basename($this->path),
                    mimeType: 'text/csv',
                    sizeBytes: $size,
                    localPath: $this->path,
                    ownerId: 7,
                    createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
                );
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return $this->canAccess;
            }

            public function delete(int $fileId): void
            {
                throw new \LogicException('not used here.');
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                return array();
            }
        };
    }

    /**
     * A stateful store: writes land in `items` so a test can read them back.
     *
     * @param array<int, ContentItem> $items
     */
    private function store(array $items = array()): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items = array();

            /** @var list<array<string, mixed>> */
            public array $created = array();

            /** @var list<int> */
            public array $updates = array();

            private int $nextId = 1;

            /**
             * @param array<int, ContentItem> $items
             */
            public function __construct(array $items)
            {
                foreach ($items as $item) {
                    $this->items[ $item->id ] = $item;
                    $this->nextId             = max($this->nextId, $item->id + 1);
                }
            }

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return $this->items[ $id ] ?? null;
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $rows = array_values($this->items);

                if (array() !== $query->types) {
                    $rows = array_values(
                        array_filter($rows, static fn (ContentItem $item): bool => in_array($item->type, $query->types, true))
                    );
                }

                foreach ($query->metaQuery as $clause) {
                    if (! is_array($clause) || ! isset($clause['key'])) {
                        continue;
                    }

                    $key   = (string) $clause['key'];
                    $value = (string) ( $clause['value'] ?? '' );

                    $rows = array_values(
                        array_filter(
                            $rows,
                            static fn (ContentItem $item): bool => (string) ( $item->meta[ $key ] ?? '' ) === $value
                        )
                    );
                }

                $limit = max(1, $query->perPage);

                return new ContentCollection(
                    array_slice($rows, 0, $limit),
                    count($rows),
                    1,
                    $query->perPage,
                    (int) ceil(count($rows) / $limit)
                );
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                $id  = $this->nextId++;
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                $item = new ContentItem(
                    id: $id,
                    title: $command->title,
                    content: $command->content,
                    status: $command->status,
                    type: $command->type,
                    authorId: $command->authorId,
                    createdAt: $now,
                    updatedAt: $now,
                    meta: $command->meta,
                );

                $this->items[ $id ] = $item;
                $this->created[]    = array(
                    'id'      => $id,
                    'title'   => $command->title,
                    'content' => $command->content,
                    'meta'    => $command->meta,
                );

                return $item;
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                if (! isset($this->items[ $id ])) {
                    throw new \RuntimeException('missing item ' . $id);
                }

                $old  = $this->items[ $id ];
                $item = new ContentItem(
                    id: $id,
                    title: $command->title ?? $old->title,
                    content: $command->content ?? $old->content,
                    status: $command->status ?? $old->status,
                    type: $old->type,
                    authorId: $old->authorId,
                    createdAt: $old->createdAt,
                    updatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    meta: array_merge($old->meta, $command->meta),
                    taxonomy: $old->taxonomy,
                    excerpt: $old->excerpt,
                    slug: $old->slug,
                );

                $this->items[ $id ] = $item;
                $this->updates[]    = $id;

                return $item;
            }

            public function delete(int $id, int $userId): void
            {
                unset($this->items[ $id ]);
            }

            public function getMeta(int $id): array
            {
                return $this->items[ $id ]->meta ?? array();
            }

            public function getTaxonomyTerms(int $id): array
            {
                return array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return true;
            }
        };
    }
}
