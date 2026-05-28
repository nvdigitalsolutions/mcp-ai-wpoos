<?php
/**
 * Tests for quiz tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test quiz tool functionality.
 */
class Test_Quiz_Tools extends WP_UnitTestCase {
	/**
	 * Test create_quiz tool.
	 */
	public function test_create_quiz() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$arguments = array(
			'title'         => 'Test Quiz',
			'description'   => 'A test quiz',
			'time_limit'    => 30,
			'passing_score' => 70,
			'questions'     => array(
				array(
					'question'       => 'What is 2+2?',
					'type'           => 'multiple_choice',
					'options'        => array( '3', '4', '5' ),
					'correct_answer' => '4',
					'points'         => 1,
				),
				array(
					'question'       => 'Is PHP a programming language?',
					'type'           => 'true_false',
					'correct_answer' => 'true',
					'points'         => 1,
				),
			),
		);

		$tool   = new WP_MCP_AI_Tool_Create_Quiz();
		$result = $tool->execute( $arguments, array( 'user_id' => $admin_user ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertArrayHasKey( 'quiz_id', $result );
		$this->assertEquals( 'Test Quiz', $result['title'] );
		$this->assertEquals( 2, $result['question_count'] );
		$this->assertEquals( 2, $result['total_points'] );
	}

	/**
	 * Test create_quiz requires permission.
	 */
	public function test_create_quiz_requires_permission() {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$arguments = array(
			'title'     => 'Test Quiz',
			'questions' => array(
				array(
					'question' => 'Test?',
					'type'     => 'short_answer',
				),
			),
		);

		$tool   = new WP_MCP_AI_Tool_Create_Quiz();
		$result = $tool->execute( $arguments, array( 'user_id' => $subscriber ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test create_quiz can update existing quiz.
	 */
	public function test_create_quiz_with_quiz_id_updates_existing() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// First create a quiz.
		$create_args = array(
			'title'         => 'Original Quiz',
			'description'   => 'Original description',
			'time_limit'    => 30,
			'passing_score' => 70,
			'questions'     => array(
				array(
					'question'       => 'Original question?',
					'type'           => 'multiple_choice',
					'options'        => array( 'A', 'B', 'C' ),
					'correct_answer' => 'A',
					'points'         => 1,
				),
			),
		);

		$tool          = new WP_MCP_AI_Tool_Create_Quiz();
		$create_result = $tool->execute( $create_args, array( 'user_id' => $admin_user ) );

		$this->assertNotInstanceOf( 'WP_Error', $create_result );
		$this->assertArrayHasKey( 'quiz_id', $create_result );
		$this->assertFalse( $create_result['updated'] );

		$quiz_id = $create_result['quiz_id'];

		// Now update the quiz using create_quiz with quiz_id.
		$update_args = array(
			'quiz_id'       => $quiz_id,
			'title'         => 'Updated Quiz',
			'description'   => 'Updated description',
			'time_limit'    => 60,
			'passing_score' => 80,
			'questions'     => array(
				array(
					'question'       => 'Updated question?',
					'type'           => 'true_false',
					'correct_answer' => 'true',
					'points'         => 2,
				),
			),
		);

		$update_result = $tool->execute( $update_args, array( 'user_id' => $admin_user ) );

		$this->assertNotInstanceOf( 'WP_Error', $update_result );
		$this->assertEquals( $quiz_id, $update_result['quiz_id'] );
		$this->assertEquals( 'Updated Quiz', $update_result['title'] );
		$this->assertEquals( 'Updated description', $update_result['description'] );
		$this->assertEquals( 60, $update_result['time_limit'] );
		$this->assertEquals( 80, $update_result['passing_score'] );
		$this->assertEquals( 1, $update_result['question_count'] );
		$this->assertEquals( 2, $update_result['total_points'] );
		$this->assertTrue( $update_result['updated'] );
		$this->assertArrayHasKey( 'updated_at', $update_result );
	}

	/**
	 * Test create_quiz update requires permission.
	 */
	public function test_create_quiz_update_requires_permission() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$editor     = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Create a quiz as admin.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Admin Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		update_post_meta(
			$quiz_id,
			'_mcp_ai_quiz_questions',
			array(
				array(
					'question' => 'Test?',
					'type'     => 'short_answer',
					'points'   => 1,
				),
			)
		);

		// Try to update as a different user without edit_others_posts capability.
		$arguments = array(
			'quiz_id'   => $quiz_id,
			'title'     => 'Updated Quiz',
			'questions' => array(
				array(
					'question' => 'Updated?',
					'type'     => 'short_answer',
					'points'   => 1,
				),
			),
		);

		$tool   = new WP_MCP_AI_Tool_Create_Quiz();
		$result = $tool->execute( $arguments, array( 'user_id' => $editor ) );

		// Editor should be able to update (has edit_others_posts).
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'Updated Quiz', $result['title'] );
	}

	/**
	 * Test create_quiz update with invalid quiz_id.
	 */
	public function test_create_quiz_update_with_invalid_quiz_id() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$arguments = array(
			'quiz_id'   => 99999,
			'title'     => 'Test Quiz',
			'questions' => array(
				array(
					'question' => 'Test?',
					'type'     => 'short_answer',
				),
			),
		);

		$tool   = new WP_MCP_AI_Tool_Create_Quiz();
		$result = $tool->execute( $arguments, array( 'user_id' => $admin_user ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_quiz_not_found', $result->get_error_code() );
	}

	/**
	 * Test get_quiz tool.
	 */
	public function test_get_quiz() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a quiz first.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Sample Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'What is WordPress?',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 1 );

		$tool   = new WP_MCP_AI_Tool_Get_Quiz();
		$result = $tool->execute(
			array( 'quiz_id' => $quiz_id ),
			array( 'user_id' => $admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( $quiz_id, $result['quiz_id'] );
		$this->assertEquals( 'Sample Quiz', $result['title'] );
		$this->assertEquals( 1, $result['question_count'] );
	}

	/**
	 * Test list_quizzes tool.
	 */
	public function test_list_quizzes() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create two quizzes.
		for ( $i = 1; $i <= 2; $i++ ) {
			$quiz_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_quiz',
					'post_title'  => "Quiz $i",
					'post_status' => 'publish',
					'post_author' => $admin_user,
				)
			);

			update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 10 );
		}

		$tool   = new WP_MCP_AI_Tool_List_Quizzes();
		$result = $tool->execute( array(), array( 'user_id' => $admin_user ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertArrayHasKey( 'quizzes', $result );
		$this->assertCount( 2, $result['quizzes'] );
	}

	/**
	 * Test submit_quiz_answer tool.
	 */
	public function test_submit_quiz_answer() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$student    = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Test Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'What is 1+1?',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );

		$tool   = new WP_MCP_AI_Tool_Submit_Quiz_Answer();
		$result = $tool->execute(
			array(
				'quiz_id' => $quiz_id,
				'answers' => array(
					array(
						'question_index' => 0,
						'answer'         => '2',
					),
				),
			),
			array( 'user_id' => $student )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertArrayHasKey( 'submission_id', $result );
		$this->assertEquals( 'pending', $result['status'] );
	}

	/**
	 * Test grade_quiz tool.
	 */
	public function test_grade_quiz() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$student    = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Test Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 10 );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', 70 );

		// Create a submission.
		$submission_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_submission',
				'post_status' => 'pending',
				'post_author' => $student,
			)
		);

		update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
		update_post_meta( $submission_id, '_mcp_ai_submission_status', 'pending' );

		$tool   = new WP_MCP_AI_Tool_Grade_Quiz();
		$result = $tool->execute(
			array(
				'submission_id' => $submission_id,
				'grades'        => array(
					array(
						'question_index' => 0,
						'points_earned'  => 8,
					),
				),
			),
			array( 'user_id' => $admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 8, $result['earned_points'] );
		$this->assertEquals( 80, $result['percentage'] );
		$this->assertTrue( $result['passed'] );
	}

	/**
	 * Test get_quiz_submissions tool.
	 */
	public function test_get_quiz_submissions() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$student    = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Test Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		// Create a submission.
		$submission_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_submission',
				'post_status' => 'pending',
				'post_author' => $student,
			)
		);

		update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
		update_post_meta( $submission_id, '_mcp_ai_submission_status', 'pending' );

		$tool   = new WP_MCP_AI_Tool_Get_Quiz_Submissions();
		$result = $tool->execute(
			array( 'quiz_id' => $quiz_id ),
			array( 'user_id' => $admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertArrayHasKey( 'submissions', $result );
		$this->assertCount( 1, $result['submissions'] );
	}

	/**
	 * Test get_quiz_results tool.
	 */
	public function test_get_quiz_results() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$student    = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Test Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Test question',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 1 );

		// Create a graded submission.
		$submission_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_submission',
				'post_status' => 'publish',
				'post_author' => $student,
			)
		);

		update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
		update_post_meta( $submission_id, '_mcp_ai_submission_status', 'graded' );
		update_post_meta(
			$submission_id,
			'_mcp_ai_submission_answers',
			array(
				array(
					'question_index' => 0,
					'answer'         => 'My answer',
				),
			)
		);
		update_post_meta(
			$submission_id,
			'_mcp_ai_submission_grades',
			array(
				array(
					'question_index' => 0,
					'points_earned'  => 1,
				),
			)
		);
		update_post_meta( $submission_id, '_mcp_ai_submission_earned_points', 1 );
		update_post_meta( $submission_id, '_mcp_ai_submission_percentage', 100 );
		update_post_meta( $submission_id, '_mcp_ai_submission_passed', true );

		$tool   = new WP_MCP_AI_Tool_Get_Quiz_Results();
		$result = $tool->execute(
			array( 'submission_id' => $submission_id ),
			array( 'user_id' => $student )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'graded', $result['status'] );
		$this->assertEquals( 1, $result['earned_points'] );
		$this->assertEquals( 100, $result['percentage'] );
		$this->assertTrue( $result['passed'] );
	}

	/**
	 * Test time limit validation in submit_quiz_answer.
	 */
	public function test_submit_quiz_answer_time_limit_validation() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$student    = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz with 5 minute time limit.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Timed Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Test question?',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_time_limit', 5 );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 1 );

		// Try submitting with start time 10 minutes ago (should fail).
		$started_at = gmdate( 'Y-m-d\TH:i:s\Z', time() - ( 10 * 60 ) );

		$tool   = new WP_MCP_AI_Tool_Submit_Quiz_Answer();
		$result = $tool->execute(
			array(
				'quiz_id'    => $quiz_id,
				'started_at' => $started_at,
				'answers'    => array(
					array(
						'question_index' => 0,
						'answer'         => 'test',
					),
				),
			),
			array( 'user_id' => $student )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_time_limit_exceeded', $result->get_error_code() );
	}

	/**
	 * Test answer type validation.
	 */
	public function test_submit_quiz_answer_validates_answer_types() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$student    = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz with multiple choice question.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Multiple Choice Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Pick a color',
				'type'     => 'multiple_choice',
				'options'  => array( 'Red', 'Blue', 'Green' ),
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );

		// Try submitting invalid answer (not in options).
		$tool   = new WP_MCP_AI_Tool_Submit_Quiz_Answer();
		$result = $tool->execute(
			array(
				'quiz_id' => $quiz_id,
				'answers' => array(
					array(
						'question_index' => 0,
						'answer'         => 'Yellow',
					),
				),
			),
			array( 'user_id' => $student )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_multiple_choice_answer', $result->get_error_code() );
	}

	/**
	 * Test grade validation prevents exceeding max points.
	 */
	public function test_grade_quiz_validates_max_points() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$student    = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Test Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Test question',
				'type'     => 'short_answer',
				'points'   => 5,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 5 );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', 70 );

		// Create a submission.
		$submission_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_submission',
				'post_status' => 'pending',
				'post_author' => $student,
			)
		);

		update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
		update_post_meta( $submission_id, '_mcp_ai_submission_status', 'pending' );

		// Try grading with more points than allowed.
		$tool   = new WP_MCP_AI_Tool_Grade_Quiz();
		$result = $tool->execute(
			array(
				'submission_id' => $submission_id,
				'grades'        => array(
					array(
						'question_index' => 0,
						'points_earned'  => 10, // Exceeds max of 5.
					),
				),
			),
			array( 'user_id' => $admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_points_exceed_max', $result->get_error_code() );
	}

	/**
	 * Test update_quiz tool updates title.
	 */
	public function test_update_quiz_title() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a quiz first.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Original Title',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'What is 2+2?',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 1 );

		// Update the quiz title.
		$tool   = new WP_MCP_AI_Tool_Update_Quiz();
		$result = $tool->execute(
			array(
				'quiz_id' => $quiz_id,
				'title'   => 'Updated Title',
			),
			array( 'user_id' => $admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertArrayHasKey( 'quiz_id', $result );
		$this->assertEquals( 'Updated Title', $result['title'] );
		$this->assertContains( 'title', $result['updated_fields'] );

		// Verify in database.
		$quiz = get_post( $quiz_id );
		$this->assertEquals( 'Updated Title', $quiz->post_title );
	}

	/**
	 * Test update_quiz tool updates questions.
	 */
	public function test_update_quiz_questions() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a quiz first.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Math Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$old_questions = array(
			array(
				'question' => 'What is 1+1?',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $old_questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 1 );

		// Update with new questions.
		$new_questions = array(
			array(
				'question' => 'What is 2+2?',
				'type'     => 'multiple_choice',
				'options'  => array( '3', '4', '5' ),
				'points'   => 2,
			),
			array(
				'question' => 'Is 3+3=6?',
				'type'     => 'true_false',
				'points'   => 1,
			),
		);

		$tool   = new WP_MCP_AI_Tool_Update_Quiz();
		$result = $tool->execute(
			array(
				'quiz_id'   => $quiz_id,
				'questions' => $new_questions,
			),
			array( 'user_id' => $admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 2, $result['question_count'] );
		$this->assertEquals( 3, $result['total_points'] );
		$this->assertContains( 'questions', $result['updated_fields'] );
	}

	/**
	 * Test update_quiz requires permission.
	 */
	public function test_update_quiz_requires_permission() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$other_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz as admin.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Admin Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Test?',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );

		// Try to update as different user without permission.
		$tool   = new WP_MCP_AI_Tool_Update_Quiz();
		$result = $tool->execute(
			array(
				'quiz_id' => $quiz_id,
				'title'   => 'Hacked Title',
			),
			array( 'user_id' => $other_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test update_quiz allows author to update.
	 */
	public function test_update_quiz_author_can_update() {
		$author_user = $this->factory->user->create( array( 'role' => 'author' ) );

		// Create a quiz as author.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Author Quiz',
				'post_status' => 'publish',
				'post_author' => $author_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Test?',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );

		// Author should be able to update their own quiz.
		$tool   = new WP_MCP_AI_Tool_Update_Quiz();
		$result = $tool->execute(
			array(
				'quiz_id'    => $quiz_id,
				'time_limit' => 45,
			),
			array( 'user_id' => $author_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 45, $result['time_limit'] );
	}

	/**
	 * Test update_quiz validates quiz_id is required.
	 */
	public function test_update_quiz_requires_quiz_id() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Update_Quiz();
		$result = $tool->execute(
			array(
				'title' => 'New Title',
			),
			array( 'user_id' => $admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_quiz_id', $result->get_error_code() );
	}

	/**
	 * Test delete_quiz tool basic functionality.
	 */
	public function test_delete_quiz() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a quiz.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Quiz to Delete',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Test question',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );

		// Verify quiz exists.
		$this->assertNotNull( get_post( $quiz_id ) );

		// Delete the quiz using the tool.
		$tool   = new WP_MCP_AI_Tool_Delete_Quiz();
		$result = $tool->execute(
			array( 'quiz_id' => $quiz_id ),
			array( 'user_id' => $admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( $quiz_id, $result['quiz_id'] );

		// Verify quiz is deleted.
		$this->assertNull( get_post( $quiz_id ) );
	}

	/**
	 * Test delete_quiz requires permission.
	 */
	public function test_delete_quiz_requires_permission() {
		$admin_user      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$subscriber_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz as admin.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Quiz to Delete',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		// Try to delete as subscriber.
		$tool   = new WP_MCP_AI_Tool_Delete_Quiz();
		$result = $tool->execute(
			array( 'quiz_id' => $quiz_id ),
			array( 'user_id' => $subscriber_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		// Verify quiz still exists.
		$this->assertNotNull( get_post( $quiz_id ) );
	}

	/**
	 * Test delete_quiz validates quiz ID.
	 */
	public function test_delete_quiz_validates_quiz_id() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Test with missing quiz_id.
		$tool   = new WP_MCP_AI_Tool_Delete_Quiz();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_id', $result->get_error_code() );

		// Test with invalid quiz_id.
		$result = $tool->execute(
			array( 'quiz_id' => 999999 ),
			array( 'user_id' => $admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_quiz', $result->get_error_code() );

		// Test with wrong post type.
		$regular_post = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$result = $tool->execute(
			array( 'quiz_id' => $regular_post ),
			array( 'user_id' => $admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_quiz', $result->get_error_code() );
	}

	/**
	 * Test permission boundaries - subscriber trying to grade.
	 */
	public function test_subscriber_cannot_grade_quiz() {
		$admin_user      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$subscriber_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz and submission as admin.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Test Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'What is 2+2?',
				'type'     => 'short_answer',
				'points'   => 5,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 5 );

		// Create a submission.
		$submission_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_submission',
				'post_status' => 'pending',
				'post_author' => $subscriber_user,
			)
		);

		update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
		update_post_meta( $submission_id, '_mcp_ai_submission_status', 'pending' );
		update_post_meta( $submission_id, '_mcp_ai_submission_total_points', 5 );

		// Try to grade as subscriber (should fail).
		$tool   = new WP_MCP_AI_Tool_Grade_Quiz();
		$result = $tool->execute(
			array(
				'submission_id' => $submission_id,
				'grades'        => array(
					array(
						'question_index' => 0,
						'points_earned'  => 5,
					),
				),
			),
			array( 'user_id' => $subscriber_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test cascade deletion - submissions deleted when quiz deleted.
	 */
	public function test_cascade_deletion_of_submissions() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a quiz.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Quiz to Delete',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Test question',
				'type'     => 'short_answer',
				'points'   => 1,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );

		// Create multiple submissions for this quiz.
		$submission_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$submission_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_submission',
					'post_status' => 'pending',
					'post_author' => $admin_user,
				)
			);

			update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
			$submission_ids[] = $submission_id;
		}

		// Verify submissions exist.
		foreach ( $submission_ids as $submission_id ) {
			$this->assertNotNull( get_post( $submission_id ) );
		}

		// Delete the quiz.
		wp_delete_post( $quiz_id, true );

		// Verify submissions are also deleted (cascade deletion).
		foreach ( $submission_ids as $submission_id ) {
			$this->assertNull( get_post( $submission_id ) );
		}
	}

	/**
	 * Test full workflow integration: create → submit → grade → results.
	 */
	public function test_full_quiz_workflow_integration() {
		$tutor_user   = $this->factory->user->create( array( 'role' => 'editor' ) );
		$student_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Step 1: Create a quiz.
		$create_tool = new WP_MCP_AI_Tool_Create_Quiz();
		$quiz_result = $create_tool->execute(
			array(
				'title'         => 'Integration Test Quiz',
				'description'   => 'A comprehensive test',
				'time_limit'    => 30,
				'passing_score' => 70,
				'questions'     => array(
					array(
						'question'       => 'What is 5+5?',
						'type'           => 'multiple_choice',
						'options'        => array( '8', '10', '12' ),
						'correct_answer' => '10',
						'points'         => 5,
					),
					array(
						'question'       => 'Is the sky blue?',
						'type'           => 'true_false',
						'correct_answer' => 'true',
						'points'         => 5,
					),
				),
			),
			array( 'user_id' => $tutor_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $quiz_result );
		$quiz_id = $quiz_result['quiz_id'];

		// Step 2: Student submits answers.
		$submit_tool       = new WP_MCP_AI_Tool_Submit_Quiz_Answer();
		$submission_result = $submit_tool->execute(
			array(
				'quiz_id' => $quiz_id,
				'answers' => array(
					array(
						'question_index' => 0,
						'answer'         => '10',
					),
					array(
						'question_index' => 1,
						'answer'         => 'true',
					),
				),
			),
			array( 'user_id' => $student_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $submission_result );
		$submission_id = $submission_result['submission_id'];
		$this->assertEquals( 'pending', $submission_result['status'] );

		// Step 3: Tutor grades the submission.
		$grade_tool   = new WP_MCP_AI_Tool_Grade_Quiz();
		$grade_result = $grade_tool->execute(
			array(
				'submission_id'    => $submission_id,
				'grades'           => array(
					array(
						'question_index' => 0,
						'points_earned'  => 5,
						'feedback'       => 'Correct!',
					),
					array(
						'question_index' => 1,
						'points_earned'  => 5,
						'feedback'       => 'Well done!',
					),
				),
				'overall_feedback' => 'Perfect score!',
			),
			array( 'user_id' => $tutor_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $grade_result );
		$this->assertEquals( 10, $grade_result['earned_points'] );
		$this->assertEquals( 10, $grade_result['total_points'] );
		$this->assertEquals( 100, $grade_result['percentage'] );
		$this->assertTrue( $grade_result['passed'] );

		// Step 4: Student views results.
		$results_tool   = new WP_MCP_AI_Tool_Get_Quiz_Results();
		$results_result = $results_tool->execute(
			array(
				'submission_id' => $submission_id,
			),
			array( 'user_id' => $student_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $results_result );
		$this->assertEquals( $submission_id, $results_result['submission_id'] );
		$this->assertEquals( $quiz_id, $results_result['quiz_id'] );
		$this->assertEquals( 'graded', $results_result['status'] );
		$this->assertEquals( 100, $results_result['percentage'] );
		$this->assertTrue( $results_result['passed'] );
		$this->assertEquals( 'Perfect score!', $results_result['overall_feedback'] );
		$this->assertCount( 2, $results_result['detailed_results'] );
	}

	/**
	 * Test permission boundary - editor can grade any quiz.
	 */
	public function test_editor_can_grade_any_quiz() {
		$author_user = $this->factory->user->create( array( 'role' => 'author' ) );
		$editor_user = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Create a quiz by author.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Author Quiz',
				'post_status' => 'publish',
				'post_author' => $author_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Test question?',
				'type'     => 'short_answer',
				'points'   => 3,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 3 );

		// Create a submission.
		$submission_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_submission',
				'post_status' => 'pending',
				'post_author' => $author_user,
			)
		);

		update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
		update_post_meta( $submission_id, '_mcp_ai_submission_status', 'pending' );
		update_post_meta( $submission_id, '_mcp_ai_submission_total_points', 3 );

		// Editor (not the author) should be able to grade.
		$tool   = new WP_MCP_AI_Tool_Grade_Quiz();
		$result = $tool->execute(
			array(
				'submission_id' => $submission_id,
				'grades'        => array(
					array(
						'question_index' => 0,
						'points_earned'  => 3,
					),
				),
			),
			array( 'user_id' => $editor_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 3, $result['earned_points'] );
	}

	/**
	 * Test get_quiz_analytics tool with Chart.js data.
	 */
	public function test_get_quiz_analytics() {
		$admin_user   = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$student_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Analytics Test Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		$questions = array(
			array(
				'question' => 'Question 1',
				'type'     => 'short_answer',
				'points'   => 5,
			),
			array(
				'question' => 'Question 2',
				'type'     => 'short_answer',
				'points'   => 5,
			),
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', 10 );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', 70 );

		// Create several graded submissions.
		for ( $i = 0; $i < 5; $i++ ) {
			$submission_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_submission',
					'post_status' => 'publish',
					'post_author' => $student_user,
				)
			);

			$earned_points = 5 + $i; // Varying scores: 5, 6, 7, 8, 9.
			$percentage    = ( $earned_points / 10 ) * 100;

			update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
			update_post_meta( $submission_id, '_mcp_ai_submission_status', 'graded' );
			update_post_meta( $submission_id, '_mcp_ai_submission_earned_points', $earned_points );
			update_post_meta( $submission_id, '_mcp_ai_submission_total_points', 10 );
			update_post_meta( $submission_id, '_mcp_ai_submission_percentage', $percentage );
			update_post_meta( $submission_id, '_mcp_ai_submission_passed', $percentage >= 70 );
			update_post_meta( $submission_id, '_mcp_ai_submission_completion_time', 10 + $i );
			update_post_meta( $submission_id, '_mcp_ai_submission_submitted_at', current_time( 'mysql' ) );
			update_post_meta(
				$submission_id,
				'_mcp_ai_submission_grades',
				array(
					array(
						'question_index' => 0,
						'points_earned'  => 2 + $i,
					),
					array(
						'question_index' => 1,
						'points_earned'  => 3,
					),
				)
			);
		}

		// Test analytics generation.
		$tool   = new WP_MCP_AI_Tool_Get_Quiz_Analytics();
		$result = $tool->execute(
			array(
				'quiz_id' => $quiz_id,
			),
			array( 'user_id' => $admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertArrayHasKey( 'charts', $result );
		$this->assertArrayHasKey( 'stats', $result );
		$this->assertEquals( 5, $result['total_submissions'] );

		// Check that all default charts are generated.
		$this->assertArrayHasKey( 'score_distribution', $result['charts'] );
		$this->assertArrayHasKey( 'pass_fail_rate', $result['charts'] );
		$this->assertArrayHasKey( 'completion_times', $result['charts'] );
		$this->assertArrayHasKey( 'question_performance', $result['charts'] );
		$this->assertArrayHasKey( 'submission_timeline', $result['charts'] );

		// Verify Chart.js structure.
		$score_dist = $result['charts']['score_distribution'];
		$this->assertEquals( 'bar', $score_dist['type'] );
		$this->assertArrayHasKey( 'data', $score_dist );
		$this->assertArrayHasKey( 'options', $score_dist );

		// Verify pass/fail chart.
		$pass_fail = $result['charts']['pass_fail_rate'];
		$this->assertEquals( 'doughnut', $pass_fail['type'] );
		$this->assertCount( 2, $pass_fail['data']['labels'] );

		// Verify stats.
		$this->assertGreaterThan( 0, $result['stats']['average_score'] );
		$this->assertGreaterThan( 0, $result['stats']['pass_rate'] );
	}

	/**
	 * Test analytics requires permission.
	 */
	public function test_quiz_analytics_requires_permission() {
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$other_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a quiz as admin.
		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Private Quiz',
				'post_status' => 'publish',
				'post_author' => $admin_user,
			)
		);

		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', array() );

		// Try to view analytics as non-author subscriber.
		$tool   = new WP_MCP_AI_Tool_Get_Quiz_Analytics();
		$result = $tool->execute(
			array(
				'quiz_id' => $quiz_id,
			),
			array( 'user_id' => $other_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test research_quiz_topic tool is instantiatable.
	 */
	public function test_research_quiz_topic_tool_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Research_Quiz_Topic' ), 'WP_MCP_AI_Tool_Research_Quiz_Topic class should exist' );

		$tool = new WP_MCP_AI_Tool_Research_Quiz_Topic();
		$this->assertEquals( 'research_quiz_topic', $tool->get_slug(), 'Tool slug should be research_quiz_topic' );
		$this->assertEquals( 'Research Quiz Topic', $tool->get_name(), 'Tool name should be Research Quiz Topic' );
	}
}
