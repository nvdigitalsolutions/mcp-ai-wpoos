<?php
/**
 * Replays the project-management write corpus through the WordPress tools.
 *
 * 090-E step 6, write half. `tests/fixtures/pm/writes.json` was captured from
 * this same code path by `tests/manual/capture-pm-writes.php`, so this asserts
 * that the capture is reproducible and that the fixture has not drifted away
 * from the implementation it describes.
 *
 * Two normaliser quirks are shared with the Laravel half and are deliberate:
 *
 * - The create tools return `updated` as a boolean, but the key contains
 *   `date`, so `true` normalises to `<date>` while `false` (which casts to an
 *   empty string) falls through and stays `false`. The corpus records both.
 * - `assigned_to` is checked before the array recursion, so a list of user ids
 *   becomes a list of `<id>` placeholders instead of being walked as values.
 *
 * The Laravel half of the pair is
 * `docker/laravel/app-skeleton/tests/Feature/ProjectManagementWriteParityTest.php`.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.6.0
 * @license GPL-3.0-or-later
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Behaviour-preservation test for the project-management write tools.
 */
class Test_PM_Writes_Parity extends WP_UnitTestCase {

	/**
	 * Seeded ids and their symbolic names.
	 *
	 * @var array<string, int>
	 */
	private $ids = array();

	/**
	 * Posts created by the test.
	 *
	 * @var array<int, int>
	 */
	private $seeded = array();

	/**
	 * Enable the Pro feature and seed the family.
	 */
	public function set_up() {
		parent::set_up();

		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings                              = is_array( $settings ) ? $settings : array();
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->seed_family();
	}

	/**
	 * Remove the seeded posts.
	 */
	public function tear_down() {
		foreach ( $this->seeded as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		parent::tear_down();
	}

	/**
	 * Load the Pro tools.
	 */
	private static function load_tools() {
		$dir = WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/';

		foreach ( array(
			'class-wp-mcp-ai-tool-create-project.php',
			'class-wp-mcp-ai-tool-update-project.php',
			'class-wp-mcp-ai-tool-delete-project.php',
			'class-wp-mcp-ai-tool-create-task.php',
			'class-wp-mcp-ai-tool-update-task.php',
			'class-wp-mcp-ai-tool-delete-task.php',
		) as $file ) {
			require_once $dir . $file;
		}
	}

	/**
	 * Create the same three projects and two tasks the capture used.
	 *
	 * Explicit dates keep the rows ordered, so a future case that lists rather
	 * than reads a single row stays deterministic.
	 */
	private function seed_family() {
		$make = function ( $type, $title, array $meta, $date ) {
			$id = (int) wp_insert_post(
				array(
					'post_type'     => $type,
					'post_title'    => $title,
					'post_status'   => 'publish',
					'post_content'  => 'Seeded for the write corpus.',
					'post_date'     => $date,
					'post_date_gmt' => $date,
					'meta_input'    => $meta,
				)
			);

			$this->seeded[] = $id;

			return $id;
		};

		$this->ids['atlas'] = $make(
			'mcp_ai_project',
			'Atlas Migration',
			array(
				'_project_status'     => 'active',
				'_project_start_date' => '2026-01-05',
				'_project_end_date'   => '2026-03-31',
			),
			'2026-01-03 09:00:00'
		);

		$this->ids['beacon'] = $make(
			'mcp_ai_project',
			'Beacon Audit',
			array(
				'_project_status'     => 'planning',
				'_project_start_date' => '2026-02-01',
				'_project_end_date'   => '2026-04-30',
			),
			'2026-01-02 09:00:00'
		);

		$this->ids['cinder'] = $make(
			'mcp_ai_project',
			'Cinder Rebuild',
			array(
				'_project_status'     => 'completed',
				'_project_start_date' => '2025-10-01',
				'_project_end_date'   => '2025-12-20',
			),
			'2026-01-01 09:00:00'
		);

		$this->ids['plan'] = $make(
			'mcp_ai_task',
			'Draft the migration plan',
			array(
				'_task_project_id' => $this->ids['atlas'],
				'_task_status'     => 'in-progress',
				'_task_priority'   => 'high',
				'_task_due_date'   => '2026-01-20',
			),
			'2026-01-06 09:00:00'
		);

		$this->ids['contrast'] = $make(
			'mcp_ai_task',
			'Run the contrast sweep',
			array(
				'_task_project_id' => $this->ids['beacon'],
				'_task_status'     => 'pending',
				'_task_priority'   => 'low',
				'_task_due_date'   => '2026-02-10',
			),
			'2026-01-04 09:00:00'
		);
	}

	/**
	 * Replace ids and dates so the comparison is runtime-neutral.
	 *
	 * @param mixed $value Response fragment.
	 * @return mixed
	 */
	private function normalise( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();

		foreach ( $value as $key => $item ) {
			// User ids differ between runtimes for the same reason record ids
			// do. Checked before the array recursion so `assigned_to` lists are
			// handled as user ids rather than as indexed values.
			if ( 'assigned_to' === $key ) {
				$out[ $key ] = is_array( $item )
					? array_map( static fn ( $v ) => is_numeric( $v ) ? '<id>' : $v, $item )
					: ( is_numeric( $item ) ? '<id>' : $item );
				continue;
			}

			if ( is_array( $item ) ) {
				$out[ $key ] = $this->normalise( $item );
				continue;
			}

			if ( in_array( $key, array( 'id', 'project_id', 'task_id' ), true ) ) {
				$out[ $key ] = is_numeric( $item ) ? '<id>' : $item;
				continue;
			}

			if ( is_string( $key ) && ( str_contains( $key, 'date' ) || str_contains( $key, '_at' ) ) && '' !== (string) $item ) {
				$out[ $key ] = '<date>';
				continue;
			}

			$out[ $key ] = $item;
		}

		return $out;
	}

	/**
	 * Resolve the symbolic ids recorded in the corpus.
	 *
	 * Arguments are resolved too, not just scalars: `assigned_to` carries a
	 * list, and a list is what the create tools validate.
	 *
	 * @param array $args Recorded arguments.
	 * @return array
	 */
	private function resolve( array $args ) {
		$symbols = array(
			'<project:atlas>'  => $this->ids['atlas'],
			'<project:beacon>' => $this->ids['beacon'],
			'<project:cinder>' => $this->ids['cinder'],
			'<task:plan>'      => $this->ids['plan'],
			'<task:contrast>'  => $this->ids['contrast'],
			'<user>'           => get_current_user_id(),
		);

		foreach ( $args as $key => $value ) {
			if ( is_string( $value ) && isset( $symbols[ $value ] ) ) {
				$args[ $key ] = $symbols[ $value ];
				continue;
			}

			if ( is_array( $value ) ) {
				$args[ $key ] = array_map(
					static fn ( $v ) => is_string( $v ) && isset( $symbols[ $v ] ) ? $symbols[ $v ] : $v,
					$value
				);
			}
		}

		return $args;
	}

	/**
	 * Captured corpus.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function corpus(): array {
		$file = __DIR__ . '/fixtures/pm/writes.json';

		if ( ! is_file( $file ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * One case per captured name.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function corpus_cases(): array {
		$cases = array();

		foreach ( self::corpus() as $name => $case ) {
			$cases[ $name ] = array( $name, $case['tool'], $case['args'] );
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function test_the_corpus_is_present() {
		$this->assertGreaterThanOrEqual( 8, count( self::corpus() ) );
	}

	/**
	 * Every captured case reproduces its recorded response.
	 *
	 * @param string $case Case name.
	 * @param string $tool Tool slug.
	 * @param array  $args Recorded arguments.
	 */
	#[DataProvider( 'corpus_cases' )]
	public function test_the_write_tools_reproduce_the_corpus( $case, $tool, $args ) {
		self::load_tools();

		$map = array(
			'create_project' => 'WP_MCP_AI_Tool_Create_Project',
			'update_project' => 'WP_MCP_AI_Tool_Update_Project',
			'delete_project' => 'WP_MCP_AI_Tool_Delete_Project',
			'create_task'    => 'WP_MCP_AI_Tool_Create_Task',
			'update_task'    => 'WP_MCP_AI_Tool_Update_Task',
			'delete_task'    => 'WP_MCP_AI_Tool_Delete_Task',
		);

		$class  = $map[ $tool ];
		$tool_o = new $class();

		$result   = $tool_o->execute( $this->resolve( $args ) );
		$expected = self::corpus()[ $case ]['expected'];

		// A capture that recorded an error replays as that error.
		if ( isset( $expected['error'] ) ) {
			$this->assertWPError( $result );
			$this->assertSame( $expected['error'], $result->get_error_code() );

			return;
		}

		$this->assertIsArray( $result );
		$this->assertSame( $this->normalise( $expected ), $this->normalise( $result ) );
	}
}
