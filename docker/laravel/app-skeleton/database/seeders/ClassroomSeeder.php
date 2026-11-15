<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;

/**
 * Seeds the classroom family (090-d cluster 3) — local-mirror scope.
 *
 * Writes through ContentStoreInterface, so rows land in `nvoos_posts` here and
 * in the `mcp_ai_eca` / `mcp_ai_student` CPTs on WordPress. The Google
 * Classroom and ISAMS sync fields stay empty by design: only the local mirror
 * is ported, so the read tools have data to answer from without implying an
 * integration this runtime does not have.
 *
 * Enrolment is recorded on the activity (`_eca_student_enrollments`) in the
 * id-keyed map shape WordPress uses, so `get_eca` returns real enrolment
 * records. The reverse `_student_eca_enrollments` link is left empty:
 * back-filling it needs `ContentStore::update()`, which requires an editor
 * identity a seeder does not have, and inventing one would be worse than an
 * asymmetric seed. `enroll_student_eca` will maintain both sides once ported.
 */
class ClassroomSeeder extends Seeder
{
    /**
     * Seed one activity with two enrolled students, unless the family already
     * has rows.
     */
    public function run(): void
    {
        $store = app(ContentStoreInterface::class);

        $existing = $store->query(new ContentQuery(
            types: array('mcp_ai_eca'),
            perPage: 1,
        ));

        if ($existing->total > 0) {
            return;
        }

        $students = array();

        $roster = array(
            array('Amara', 'Okafor', '10', 'Falcon'),
            array('Leo', 'Marchetti', '9', 'Heron'),
        );

        foreach ( $roster as $row ) {
            list( $first, $last, $year, $house ) = $row;

            $students[] = $store->create(
                new CreateContentCommand(
                    title: $first . ' ' . $last,
                    type: 'mcp_ai_student',
                    authorId: 0,
                    status: 'publish',
                    content: '',
                    meta: array(
                        '_student_first_name'     => $first,
                        '_student_last_name'      => $last,
                        '_student_email'          => strtolower($first . '.' . $last) . '@example.org',
                        '_student_year_group'     => $year,
                        '_student_house'          => $house,
                        '_student_eca_enrollments' => array(),
                    ),
                )
            );
        }

        $student_ids = array_map(
            static fn ($student) => $student->id,
            $students
        );

        $enrollments = array();

        foreach ( $student_ids as $student_id ) {
            // WordPress stores this as an id-keyed map of records, not a list
            // of ids; every consumer reads `$enrollments[ $id ]`.
            $enrollments[ $student_id ] = array(
                'enrollment_type' => 'confirmed',
                'enrollment_date' => '2026-09-01 09:00:00',
                'payment_status'  => 'n/a',
                'amount_due'      => 0,
                'notes'           => '',
                'enrolled_by'     => 0,
            );
        }

        $eca = $store->create(
            new CreateContentCommand(
                title: 'Robotics Club',
                type: 'mcp_ai_eca',
                authorId: 0,
                status: 'publish',
                content: 'Build and program competition robots.',
                meta: array(
                    '_eca_status'              => 'active',
                    '_eca_type'                => 'club',
                    '_eca_code'                => 'ROB-01',
                    '_eca_venue'               => 'Design Lab',
                    '_eca_day'                 => 'Wednesday',
                    '_eca_start_time'          => '15:30',
                    '_eca_end_time'            => '17:00',
                    '_eca_booking_type'        => 'enrolment',
                    '_eca_cost'                => 45,
                    '_eca_cost_period'         => 'term',
                    '_eca_is_paid'             => 'yes',
                    '_eca_requires_audition'   => 'no',
                    '_eca_max_students'        => 16,
                    '_eca_current_enrollment'  => count($student_ids),
                    '_eca_teachers'            => array( 1 ),
                    '_eca_year_groups'         => array( '9', '10' ),
                    '_eca_student_enrollments' => $enrollments,
                ),
            )
        );
    }
}
