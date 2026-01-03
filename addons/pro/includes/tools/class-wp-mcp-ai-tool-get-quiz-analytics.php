<?php
/**
 * Tool for generating quiz analytics with Chart.js data.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates Chart.js visualization data for quiz analytics.
 */
class WP_MCP_AI_Tool_Get_Quiz_Analytics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_quiz_analytics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Quiz Analytics', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates Chart.js visualization data for quiz analytics including score distribution, pass/fail rates, completion times, and question performance.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'quiz_id'     => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the quiz to analyze.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'chart_types' => array(
					'type'        => 'array',
					'description' => __( 'Types of charts to generate. If not specified, all charts are generated.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array(
							'score_distribution',
							'pass_fail_rate',
							'completion_times',
							'question_performance',
							'submission_timeline',
						),
					),
				),
			),
			'required'             => array( 'quiz_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view quiz analytics.', 'wp-mcp-ai' ) );
		}

		$quiz_id     = isset( $arguments['quiz_id'] ) ? absint( $arguments['quiz_id'] ) : 0;
		$chart_types = isset( $arguments['chart_types'] ) && is_array( $arguments['chart_types'] ) ? $arguments['chart_types'] : array( 'score_distribution', 'pass_fail_rate', 'completion_times', 'question_performance', 'submission_timeline' );

		if ( ! $quiz_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_quiz_id', __( 'Quiz ID is required.', 'wp-mcp-ai' ) );
		}

		// Verify quiz exists.
		$quiz = get_post( $quiz_id );

		if ( ! $quiz || 'mcp_ai_quiz' !== $quiz->post_type ) {
			return new WP_Error( 'wp_mcp_ai_quiz_not_found', __( 'Quiz not found.', 'wp-mcp-ai' ) );
		}

		// Check permissions: must be quiz author or have edit_others_posts capability.
		$is_author       = absint( $quiz->post_author ) === $current_user_id;
		$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

		if ( ! $is_author && ! $can_edit_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view analytics for this quiz.', 'wp-mcp-ai' ) );
		}

		// Get all graded submissions for this quiz.
		$submissions = get_posts(
			array(
				'post_type'      => 'mcp_ai_submission',
				'post_status'    => 'publish',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_mcp_ai_submission_quiz_id',
						'value' => $quiz_id,
					),
					array(
						'key'   => '_mcp_ai_submission_status',
						'value' => 'graded',
					),
				),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $submissions ) ) {
			return new WP_Error( 'wp_mcp_ai_no_data', __( 'No graded submissions found for this quiz.', 'wp-mcp-ai' ) );
		}

		// Get quiz metadata.
		$quiz_title      = get_the_title( $quiz_id );
		$passing_score   = get_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', true );
		$total_points    = get_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', true );
		$questions       = get_post_meta( $quiz_id, '_mcp_ai_quiz_questions', true );
		$question_count  = is_array( $questions ) ? count( $questions ) : 0;

		// Collect analytics data.
		$analytics_data = $this->collect_analytics_data( $submissions, $questions );

		// Generate chart data based on requested types.
		$charts = array();

		foreach ( $chart_types as $chart_type ) {
			switch ( $chart_type ) {
				case 'score_distribution':
					$charts['score_distribution'] = $this->generate_score_distribution_chart( $analytics_data );
					break;

				case 'pass_fail_rate':
					$charts['pass_fail_rate'] = $this->generate_pass_fail_chart( $analytics_data, $passing_score );
					break;

				case 'completion_times':
					$charts['completion_times'] = $this->generate_completion_times_chart( $analytics_data );
					break;

				case 'question_performance':
					$charts['question_performance'] = $this->generate_question_performance_chart( $analytics_data, $questions );
					break;

				case 'submission_timeline':
					$charts['submission_timeline'] = $this->generate_submission_timeline_chart( $analytics_data );
					break;
			}
		}

		return array(
			'summary'        => sprintf(
				/* translators: 1: quiz title, 2: number of submissions */
				__( 'Generated analytics for %1$s with %2$d submissions', 'wp-mcp-ai' ),
				$quiz_title,
				count( $submissions )
			),
			'quiz_id'        => $quiz_id,
			'quiz_title'     => $quiz_title,
			'total_submissions' => count( $submissions ),
			'passing_score'  => absint( $passing_score ),
			'total_points'   => absint( $total_points ),
			'question_count' => $question_count,
			'charts'         => $charts,
			'stats'          => array(
				'average_score'      => $analytics_data['average_score'],
				'median_score'       => $analytics_data['median_score'],
				'pass_rate'          => $analytics_data['pass_rate'],
				'average_completion' => $analytics_data['average_completion'],
			),
		);
	}

	/**
	 * Collect analytics data from submissions.
	 *
	 * @param array $submission_ids Array of submission post IDs.
	 * @param array $questions      Quiz questions array.
	 * @return array Analytics data.
	 */
	private function collect_analytics_data( $submission_ids, $questions ) {
		$scores              = array();
		$percentages         = array();
		$passed_count        = 0;
		$completion_times    = array();
		$question_stats      = array();
		$submission_dates    = array();

		foreach ( $submission_ids as $submission_id ) {
			$percentage       = get_post_meta( $submission_id, '_mcp_ai_submission_percentage', true );
			$passed           = get_post_meta( $submission_id, '_mcp_ai_submission_passed', true );
			$earned_points    = get_post_meta( $submission_id, '_mcp_ai_submission_earned_points', true );
			$completion_time  = get_post_meta( $submission_id, '_mcp_ai_submission_completion_time', true );
			$grades           = get_post_meta( $submission_id, '_mcp_ai_submission_grades', true );
			$submitted_at     = get_post_meta( $submission_id, '_mcp_ai_submission_submitted_at', true );

			$percentages[] = floatval( $percentage );
			$scores[]      = floatval( $earned_points );

			if ( $passed ) {
				$passed_count++;
			}

			if ( $completion_time ) {
				$completion_times[] = floatval( $completion_time );
			}

			if ( $submitted_at ) {
				$submission_dates[] = $submitted_at;
			}

			// Collect question-level statistics.
			if ( is_array( $grades ) ) {
				foreach ( $grades as $grade ) {
					$q_index = isset( $grade['question_index'] ) ? absint( $grade['question_index'] ) : 0;
					$points  = isset( $grade['points_earned'] ) ? floatval( $grade['points_earned'] ) : 0;

					if ( ! isset( $question_stats[ $q_index ] ) ) {
						$question_stats[ $q_index ] = array(
							'total_points' => 0,
							'count'        => 0,
						);
					}

					$question_stats[ $q_index ]['total_points'] += $points;
					$question_stats[ $q_index ]['count']++;
				}
			}
		}

		// Calculate statistics.
		$average_score  = ! empty( $percentages ) ? array_sum( $percentages ) / count( $percentages ) : 0;
		$median_score   = $this->calculate_median( $percentages );
		$pass_rate      = ! empty( $submission_ids ) ? ( $passed_count / count( $submission_ids ) ) * 100 : 0;
		$avg_completion = ! empty( $completion_times ) ? array_sum( $completion_times ) / count( $completion_times ) : 0;

		return array(
			'scores'              => $scores,
			'percentages'         => $percentages,
			'passed_count'        => $passed_count,
			'failed_count'        => count( $submission_ids ) - $passed_count,
			'completion_times'    => $completion_times,
			'question_stats'      => $question_stats,
			'submission_dates'    => $submission_dates,
			'average_score'       => round( $average_score, 2 ),
			'median_score'        => round( $median_score, 2 ),
			'pass_rate'           => round( $pass_rate, 2 ),
			'average_completion'  => round( $avg_completion, 2 ),
		);
	}

	/**
	 * Generate score distribution chart data.
	 *
	 * @param array $analytics_data Analytics data.
	 * @return array Chart.js configuration.
	 */
	private function generate_score_distribution_chart( $analytics_data ) {
		$percentages = $analytics_data['percentages'];

		// Create bins: 0-10, 11-20, ..., 91-100.
		$bins = array_fill( 0, 10, 0 );

		foreach ( $percentages as $percentage ) {
			$bin_index = min( floor( $percentage / 10 ), 9 );
			$bins[ $bin_index ]++;
		}

		$labels = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$start    = $i * 10;
			$end      = $start + 10;
			$labels[] = "{$start}-{$end}%";
		}

		return array(
			'type' => 'bar',
			'data' => array(
				'labels'   => $labels,
				'datasets' => array(
					array(
						'label'           => __( 'Number of Students', 'wp-mcp-ai' ),
						'data'            => $bins,
						'backgroundColor' => 'rgba(54, 162, 235, 0.6)',
						'borderColor'     => 'rgba(54, 162, 235, 1)',
						'borderWidth'     => 1,
					),
				),
			),
			'options' => array(
				'responsive' => true,
				'plugins'    => array(
					'title' => array(
						'display' => true,
						'text'    => __( 'Score Distribution', 'wp-mcp-ai' ),
					),
					'legend' => array(
						'display' => true,
					),
				),
				'scales'     => array(
					'y' => array(
						'beginAtZero' => true,
						'title'       => array(
							'display' => true,
							'text'    => __( 'Number of Students', 'wp-mcp-ai' ),
						),
					),
					'x' => array(
						'title' => array(
							'display' => true,
							'text'    => __( 'Score Range', 'wp-mcp-ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Generate pass/fail rate chart data.
	 *
	 * @param array $analytics_data Analytics data.
	 * @param int   $passing_score  Passing score percentage.
	 * @return array Chart.js configuration.
	 */
	private function generate_pass_fail_chart( $analytics_data, $passing_score ) {
		return array(
			'type' => 'doughnut',
			'data' => array(
				'labels'   => array(
					__( 'Passed', 'wp-mcp-ai' ),
					__( 'Failed', 'wp-mcp-ai' ),
				),
				'datasets' => array(
					array(
						'data'            => array(
							$analytics_data['passed_count'],
							$analytics_data['failed_count'],
						),
						'backgroundColor' => array(
							'rgba(75, 192, 192, 0.6)',
							'rgba(255, 99, 132, 0.6)',
						),
						'borderColor'     => array(
							'rgba(75, 192, 192, 1)',
							'rgba(255, 99, 132, 1)',
						),
						'borderWidth'     => 1,
					),
				),
			),
			'options' => array(
				'responsive' => true,
				'plugins'    => array(
					'title' => array(
						'display' => true,
						'text'    => sprintf(
							/* translators: %d: passing score percentage */
							__( 'Pass/Fail Rate (Passing Score: %d%%)', 'wp-mcp-ai' ),
							$passing_score
						),
					),
					'legend' => array(
						'display'  => true,
						'position' => 'bottom',
					),
				),
			),
		);
	}

	/**
	 * Generate completion times chart data.
	 *
	 * @param array $analytics_data Analytics data.
	 * @return array Chart.js configuration.
	 */
	private function generate_completion_times_chart( $analytics_data ) {
		$completion_times = $analytics_data['completion_times'];

		if ( empty( $completion_times ) ) {
			return array(
				'type'    => 'bar',
				'data'    => array( 'labels' => array(), 'datasets' => array() ),
				'options' => array(
					'plugins' => array(
						'title' => array(
							'display' => true,
							'text'    => __( 'Completion Times (No Data Available)', 'wp-mcp-ai' ),
						),
					),
				),
			);
		}

		// Create time bins: 0-5min, 6-10min, etc.
		$max_time  = max( $completion_times );
		$bin_size  = 5; // 5 minute bins.
		$bin_count = ceil( $max_time / $bin_size );
		$bins      = array_fill( 0, $bin_count, 0 );

		foreach ( $completion_times as $time ) {
			$bin_index = min( floor( $time / $bin_size ), $bin_count - 1 );
			$bins[ $bin_index ]++;
		}

		$labels = array();
		for ( $i = 0; $i < $bin_count; $i++ ) {
			$start    = $i * $bin_size;
			$end      = $start + $bin_size;
			$labels[] = "{$start}-{$end} min";
		}

		return array(
			'type' => 'bar',
			'data' => array(
				'labels'   => $labels,
				'datasets' => array(
					array(
						'label'           => __( 'Number of Submissions', 'wp-mcp-ai' ),
						'data'            => $bins,
						'backgroundColor' => 'rgba(153, 102, 255, 0.6)',
						'borderColor'     => 'rgba(153, 102, 255, 1)',
						'borderWidth'     => 1,
					),
				),
			),
			'options' => array(
				'responsive' => true,
				'plugins'    => array(
					'title' => array(
						'display' => true,
						'text'    => __( 'Completion Times Distribution', 'wp-mcp-ai' ),
					),
					'legend' => array(
						'display' => true,
					),
				),
				'scales'     => array(
					'y' => array(
						'beginAtZero' => true,
						'title'       => array(
							'display' => true,
							'text'    => __( 'Number of Submissions', 'wp-mcp-ai' ),
						),
					),
					'x' => array(
						'title' => array(
							'display' => true,
							'text'    => __( 'Time Range (minutes)', 'wp-mcp-ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Generate question performance chart data.
	 *
	 * @param array $analytics_data Analytics data.
	 * @param array $questions      Quiz questions.
	 * @return array Chart.js configuration.
	 */
	private function generate_question_performance_chart( $analytics_data, $questions ) {
		$question_stats = $analytics_data['question_stats'];

		if ( empty( $question_stats ) || ! is_array( $questions ) ) {
			return array(
				'type'    => 'bar',
				'data'    => array( 'labels' => array(), 'datasets' => array() ),
				'options' => array(
					'plugins' => array(
						'title' => array(
							'display' => true,
							'text'    => __( 'Question Performance (No Data Available)', 'wp-mcp-ai' ),
						),
					),
				),
			);
		}

		$labels            = array();
		$average_scores    = array();
		$success_rates     = array();

		foreach ( $questions as $index => $question ) {
			$labels[] = sprintf( __( 'Q%d', 'wp-mcp-ai' ), $index + 1 );

			if ( isset( $question_stats[ $index ] ) ) {
				$stats        = $question_stats[ $index ];
				$avg_points   = $stats['total_points'] / $stats['count'];
				$max_points   = isset( $question['points'] ) ? $question['points'] : 1;
				$success_rate = ( $avg_points / $max_points ) * 100;

				$average_scores[]  = round( $avg_points, 2 );
				$success_rates[]   = round( $success_rate, 2 );
			} else {
				$average_scores[]  = 0;
				$success_rates[]   = 0;
			}
		}

		return array(
			'type' => 'bar',
			'data' => array(
				'labels'   => $labels,
				'datasets' => array(
					array(
						'label'           => __( 'Success Rate (%)', 'wp-mcp-ai' ),
						'data'            => $success_rates,
						'backgroundColor' => 'rgba(255, 206, 86, 0.6)',
						'borderColor'     => 'rgba(255, 206, 86, 1)',
						'borderWidth'     => 1,
					),
				),
			),
			'options' => array(
				'responsive' => true,
				'plugins'    => array(
					'title' => array(
						'display' => true,
						'text'    => __( 'Question Performance', 'wp-mcp-ai' ),
					),
					'legend' => array(
						'display' => true,
					),
				),
				'scales'     => array(
					'y' => array(
						'beginAtZero' => true,
						'max'         => 100,
						'title'       => array(
							'display' => true,
							'text'    => __( 'Success Rate (%)', 'wp-mcp-ai' ),
						),
					),
					'x' => array(
						'title' => array(
							'display' => true,
							'text'    => __( 'Question Number', 'wp-mcp-ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Generate submission timeline chart data.
	 *
	 * @param array $analytics_data Analytics data.
	 * @return array Chart.js configuration.
	 */
	private function generate_submission_timeline_chart( $analytics_data ) {
		$submission_dates = $analytics_data['submission_dates'];

		if ( empty( $submission_dates ) ) {
			return array(
				'type'    => 'line',
				'data'    => array( 'labels' => array(), 'datasets' => array() ),
				'options' => array(
					'plugins' => array(
						'title' => array(
							'display' => true,
							'text'    => __( 'Submission Timeline (No Data Available)', 'wp-mcp-ai' ),
						),
					),
				),
			);
		}

		// Group submissions by date.
		$dates_grouped = array();
		foreach ( $submission_dates as $datetime ) {
			$date = gmdate( 'Y-m-d', strtotime( $datetime ) );
			if ( ! isset( $dates_grouped[ $date ] ) ) {
				$dates_grouped[ $date ] = 0;
			}
			$dates_grouped[ $date ]++;
		}

		ksort( $dates_grouped );

		$labels = array();
		$data   = array();

		foreach ( $dates_grouped as $date => $count ) {
			$labels[] = gmdate( 'M d', strtotime( $date ) );
			$data[]   = $count;
		}

		return array(
			'type' => 'line',
			'data' => array(
				'labels'   => $labels,
				'datasets' => array(
					array(
						'label'           => __( 'Submissions', 'wp-mcp-ai' ),
						'data'            => $data,
						'borderColor'     => 'rgba(75, 192, 192, 1)',
						'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
						'borderWidth'     => 2,
						'fill'            => true,
						'tension'         => 0.4,
					),
				),
			),
			'options' => array(
				'responsive' => true,
				'plugins'    => array(
					'title' => array(
						'display' => true,
						'text'    => __( 'Submission Timeline', 'wp-mcp-ai' ),
					),
					'legend' => array(
						'display' => true,
					),
				),
				'scales'     => array(
					'y' => array(
						'beginAtZero' => true,
						'title'       => array(
							'display' => true,
							'text'    => __( 'Number of Submissions', 'wp-mcp-ai' ),
						),
					),
					'x' => array(
						'title' => array(
							'display' => true,
							'text'    => __( 'Date', 'wp-mcp-ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Calculate median of an array.
	 *
	 * @param array $values Array of numbers.
	 * @return float Median value.
	 */
	private function calculate_median( $values ) {
		if ( empty( $values ) ) {
			return 0;
		}

		sort( $values );
		$count  = count( $values );
		$middle = floor( $count / 2 );

		if ( 0 === $count % 2 ) {
			return ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;
		}

		return $values[ $middle ];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read',
			'local-only',
			'requires-capability',
		);
	}
}
