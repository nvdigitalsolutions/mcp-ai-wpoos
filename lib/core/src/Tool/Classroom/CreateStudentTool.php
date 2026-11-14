<?php
/**
 * Create Student tool — create, or update in place when `student_id` is set.
 *
 * Mirrors `WP_MCP_AI_Tool_Create_Student` (addons/pro/includes/tools/
 * eca-management): same slug, parameters, validation and result shape.
 *
 * Behaviour carried over deliberately:
 * - Both names are required **on every call**, including an update. This tool
 *   is the "write the whole record" path; `update_student` is the patch path.
 * - Optional meta is only written when supplied and non-empty, so `house: ''`
 *   means "not provided" rather than "clear it".
 * - An invalid email is an error (`invalid_email`), not a silent drop.
 * - The create branch seeds `_student_eca_enrollments` with an empty list, so
 *   readers never have to distinguish "absent" from "no enrolments".
 * - The result carries `updated` so the caller can tell which branch ran.
 *
 * Divergences, all deliberate:
 * - **Permission is the store's.** WordPress compares the post author against
 *   the caller and lets `edit_others_posts` through; here `userCanAccess()`
 *   decides, which is the same rule the profile and content adapters enforce
 *   everywhere else. The error message is unchanged.
 * - **Timestamps are formatted** (`Y-m-d H:i:s`) from the record's
 *   `createdAt` / `updatedAt` rather than WordPress's `post_date` /
 *   `post_modified` strings. Same shape, same ordering.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CreateStudentTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'create_student';
	}

	public function getName(): string {
		return 'Create Student';
	}

	public function getDescription(): string {
		return 'Creates a new student record, or updates an existing one when student_id is supplied. Stores the name, year group, house, email and an external system id.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id' => array(
					'type'        => 'integer',
					'description' => 'Optional student ID. When supplied the existing record is updated instead of a new one created.',
					'minimum'     => 1,
				),
				'first_name' => array(
					'type'        => 'string',
					'description' => 'Student first name (required)',
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => 'Student last name (required)',
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'year_group' => array(
					'type'        => 'string',
					'description' => 'Year group (e.g. "Year 7")',
					'maxLength'   => 50,
				),
				'house'      => array(
					'type'        => 'string',
					'description' => 'House name',
					'maxLength'   => 100,
				),
				'email'      => array(
					'type'        => 'string',
					'description' => 'Student email address',
					'format'      => 'email',
				),
				'isams_id'   => array(
					'type'        => 'string',
					'description' => 'External (iSAMS) student id, stored for reference',
					'maxLength'   => 50,
				),
			),
			'required'             => array( 'first_name', 'last_name' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$user_id = (int) ( $context['user_id'] ?? 0 );

		$student_id = $this->intParam( $arguments, 'student_id' );
		$existing   = null;

		if ( $student_id > 0 ) {
			$existing = $this->content->find( $student_id );

			if ( ! $existing instanceof ContentItem || 'mcp_ai_student' !== $existing->type ) {
				return $this->errors->create( 'student_not_found', 'Student not found.' );
			}

			if ( ! $this->content->userCanAccess( $student_id, $user_id, 'edit' ) ) {
				return $this->errors->forbidden( 'You do not have permission to update this student.' );
			}
		}

		$first_name = Text::singleLine( $this->stringParam( $arguments, 'first_name' ) );
		$last_name  = Text::singleLine( $this->stringParam( $arguments, 'last_name' ) );

		if ( '' === $first_name || '' === $last_name ) {
			return $this->errors->validationFailed(
				'First name and last name are required.',
				array(
					'first_name' => array( 'This field is required.' ),
					'last_name'  => array( 'This field is required.' ),
				)
			);
		}

		$year_group = Text::singleLine( $this->stringParam( $arguments, 'year_group' ) );
		$house      = Text::singleLine( $this->stringParam( $arguments, 'house' ) );
		$email      = Text::singleLine( $this->stringParam( $arguments, 'email' ) );
		$isams_id   = Text::singleLine( $this->stringParam( $arguments, 'isams_id' ) );

		if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return $this->errors->create( 'invalid_email', 'Invalid email address.' );
		}

		$full_name = $first_name . ' ' . $last_name;

		$meta = array(
			'_student_first_name' => $first_name,
			'_student_last_name'  => $last_name,
		);

		// Optional meta is only written when supplied and non-empty.
		foreach ( array(
			'_student_year_group' => $year_group,
			'_student_house'      => $house,
			'_student_email'      => $email,
			'_student_isams_id'   => $isams_id,
		) as $key => $value ) {
			if ( '' !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		try {
			if ( $existing instanceof ContentItem ) {
				$this->content->update(
					$student_id,
					new UpdateContentCommand(
						userId: $user_id,
						title: $full_name,
						meta: $meta,
					)
				);
			} else {
				$meta['_student_eca_enrollments'] = array();

				$created = $this->content->create(
					new CreateContentCommand(
						title: $full_name,
						type: 'mcp_ai_student',
						authorId: $user_id,
						status: 'publish',
						content: '',
						meta: $meta,
					)
				);

				$student_id = $created->id;
			}
		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'student_write_failed', $e->getMessage() );
		}

		$saved = $this->content->find( $student_id );

		if ( ! $saved instanceof ContentItem ) {
			return $this->errors->notFound( 'Student not found.' );
		}

		$updated = $existing instanceof ContentItem;

		$student = array(
			'id'         => $student_id,
			'name'       => $saved->title,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'year_group' => $year_group,
			'house'      => $house,
			'email'      => $email,
			'isams_id'   => $isams_id,
		);

		// WordPress reports `created_at` on create and `updated_at` on update.
		$student[ $updated ? 'updated_at' : 'created_at' ] =
			( $updated ? $saved->updatedAt : $saved->createdAt )->format( 'Y-m-d H:i:s' );

		return $this->success(
			$updated ? 'Student updated successfully.' : 'Student created successfully.',
			array(
				'student_id' => $student_id,
				'student'    => $student,
				'updated'    => $updated,
			)
		);
	}
}
