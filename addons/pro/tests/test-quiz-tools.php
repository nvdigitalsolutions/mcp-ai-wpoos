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
}
