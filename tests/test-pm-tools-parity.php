<?php
/**
 * Replays the project-management parity corpus through the WordPress tools.
 *
 * 090-E step 6: the family's read tools ship with a parity fixture. The corpus
 * at `tests/fixtures/pm/tools.json` was captured from this same code path, so
 * this asserts the capture is reproducible and the fixture has not drifted
 * away from the implementation it describes.
 *
 * The Laravel half of the pair is
 * `docker/laravel/app-skeleton/tests/Feature/ProjectManagementToolParityTest.php`.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.6.0
 * @license GPL-3.0-or-later
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Behaviour-preservation test for the project-management read tools.
 */
class Test_PM_Tools_Parity extends WP_UnitTestCase {

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
			'class-wp-mcp-ai-tool-list-projects.php',
			'class-wp-mcp-ai-tool-list-tasks.php',
			'class-wp-mcp-ai-tool-get-task-dependencies.php',
		) as $file ) {
			require_once $dir . $file;
		}
	}

	/**
	 * Create the same three projects and three tasks the capture used.
	 */
	private function seed_family() {
		$project = function ( $title, $body, array $meta, $date ) {
			$id = (int) wp_insert_post(
				array(
					'post_type'     => 'mcp_ai_project',
					'post_title'    => $title,
					'post_status'   => 'publish',
					'post_content'  => $body,
					'post_date'     => $date,
					'post_date_gmt' => $date,
					'meta_input'    => $meta,
				)
			);

			$this->seeded[] = $id;

			return $id;
		};

		$task = function ( $title, $body, array $meta, $date ) {
			$id = (int) wp_insert_post(
				array(
					'post_type'     => 'mcp_ai_task',
					'post_title'    => $title,
					'post_status'   => 'publish',
					'post_content'  => $body,
					'post_date'     => $date,
					'post_date_gmt' => $date,
					'meta_input'    => $meta,
				)
			);

			$this->seeded[] = $id;

			return $id;
		};

		$this->ids['atlas'] = $project(
			'Atlas Migration',
			'Move the estate to the new platform.',
			array(
				'_project_status'      => 'active',
				'_project_start_date'  => '2026-01-05',
				'_project_end_date'    => '2026-03-31',
				'_project_assigned_to' => array( (string) get_current_user_id() ),
			),
			'2026-01-03 09:00:00'
		);

		$this->ids['beacon'] = $project(
			'Beacon Audit',
			'Accessibility and performance review.',
			array(
				'_project_status'      => 'planning',
				'_project_start_date'  => '2026-02-01',
				'_project_end_date'    => '2026-04-30',
				'_project_assigned_to' => array(),
			),
			'2026-01-02 09:00:00'
		);

		$this->ids['cinder'] = $project(
			'Cinder Rebuild',
			'Rebuild the storefront.',
			array(
				'_project_status'      => 'completed',
				'_project_start_date'  => '2025-10-01',
				'_project_end_date'    => '2025-12-20',
				'_project_assigned_to' => array(),
			),
			'2026-01-01 09:00:00'
		);

		$this->ids['plan'] = $task(
			'Draft the migration plan',
			'Write the cutover plan.',
			array(
				'_task_project_id'  => $this->ids['atlas'],
				'_task_status'      => 'in-progress',
				'_task_priority'    => 'high',
				'_task_due_date'    => '2026-01-20',
				'_task_assigned_to' => get_current_user_id(),
			),
			'2026-01-06 09:00:00'
		);

		$this->ids['freeze'] = $task(
			'Freeze plugin updates',
			'Announce and freeze.',
			array(
				'_task_project_id'  => $this->ids['atlas'],
				'_task_status'      => 'pending',
				'_task_priority'    => 'medium',
				'_task_due_date'    => '2026-01-25',
				'_task_assigned_to' => 0,
				'_task_depends_on'  => array( $this->ids['plan'] ),
			),
			'2026-01-05 09:00:00'
		);

		$this->ids['contrast'] = $task(
			'Run the contrast sweep',
			'WCAG AA across the top 20 pages.',
			array(
				'_task_project_id'  => $this->ids['beacon'],
				'_task_status'      => 'completed',
				'_task_priority'    => 'low',
				'_task_due_date'    => '2026-02-10',
				'_task_assigned_to' => 0,
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
			// User ids differ between runtimes for the same reason record ids do.
			// Checked before the array recursion so `assigned_to` lists are
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

			if ( in_array( $key, array( 'id', 'project_id', 'task_id', 'depends_on' ), true ) ) {
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
	 * Resolve a symbolic id.
	 *
	 * @param array $args Recorded arguments.
	 * @return array
	 */
	private function resolve( array $args ) {
		$symbols = array(
			'<project:atlas>' => $this->ids['atlas'],
			'<task:plan>'     => $this->ids['plan'],
			'<task:freeze>'   => $this->ids['freeze'],
		);

		foreach ( $args as $key => $value ) {
			if ( is_string( $value ) && isset( $symbols[ $value ] ) ) {
				$args[ $key ] = $symbols[ $value ];
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
		$file = __DIR__ . '/fixtures/pm/tools.json';

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
		$this->assertGreaterThanOrEqual( 5, count( self::corpus() ) );
	}

	/**
	 * Every captured case reproduces its recorded response.
	 *
	 * @param string $case Case name.
	 * @param string $tool Tool slug.
	 * @param array  $args Recorded arguments.
	 */
	#[DataProvider( 'corpus_cases' )]
	public function test_the_read_tools_reproduce_the_corpus( $case, $tool, $args ) {
		self::load_tools();

		$map = array(
			'list_projects'         => 'WP_MCP_AI_Tool_List_Projects',
			'list_tasks'            => 'WP_MCP_AI_Tool_List_Tasks',
			'get_task_dependencies' => 'WP_MCP_AI_Tool_Get_Task_Dependencies',
		);

		$class  = $map[ $tool ];
		$tool_o = new $class();

		$result = $tool_o->execute( $this->resolve( $args ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->normalise( self::corpus()[ $case ]['expected'] ), $this->normalise( $result ) );
	}
}
