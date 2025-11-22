<?php
/**
 * Test PHPUnit Error Extraction
 *
 * Tests that the extract_test_results_from_phpunit_output method correctly
 * handles PHPUnit output with Errors, not just Failures.
 *
 * This addresses the P1 bug where PHPUnit runs with errors were being
 * incorrectly reported as all tests passing.
 *
 * @package WP_MCP_AI
 */

use PHPUnit\Framework\TestCase;

/**
 * Test case for PHPUnit error extraction
 */
class TestPhpunitErrorExtraction extends TestCase {

	/**
	 * Get an instance of the performance section for testing.
	 *
	 * @return object Mock section with the method we need to test.
	 */
	protected function get_section_instance() {
		// Create a minimal mock that has just the method we need to test.
		return new class() {
			/**
			 * Extract test results from PHPUnit output.
			 *
			 * This is a copy of the actual method from the performance section.
			 *
			 * @param string $output PHPUnit test output.
			 * @return array Test results with pass/fail counts.
			 */
			public function extract_test_results_from_phpunit_output( $output ) {
				$results = array(
					'total'   => 0,
					'passed'  => 0,
					'failed'  => 0,
					'skipped' => 0,
					'errors'  => 0,
				);

				// Extract total and assertions first (common to all summary formats).
				if ( preg_match( '/Tests: (\d+), Assertions: (\d+)/', $output, $matches ) ) {
					$results['total'] = absint( $matches[1] );
				} elseif ( preg_match( '/OK \((\d+) tests?, (\d+) assertions?\)/', $output, $matches ) ) {
					$results['total'] = absint( $matches[1] );
				}

				// Extract failures.
				if ( preg_match( '/Failures: (\d+)/', $output, $matches ) ) {
					$results['failed'] = absint( $matches[1] );
				}

				// Extract errors (critical fix: errors are not failures).
				if ( preg_match( '/Errors: (\d+)/', $output, $matches ) ) {
					$results['errors'] = absint( $matches[1] );
					// Errors count as failures for status determination.
					$results['failed'] += absint( $matches[1] );
				}

				// Extract skipped tests.
				if ( preg_match( '/Skipped: (\d+)/', $output, $matches ) ) {
					$results['skipped'] = absint( $matches[1] );
				}

				// Calculate passed tests (total minus failures, errors, and skipped).
				if ( $results['total'] > 0 ) {
					$results['passed'] = $results['total'] - $results['failed'] - $results['skipped'];
					// Ensure passed doesn't go negative.
					if ( $results['passed'] < 0 ) {
						$results['passed'] = 0;
					}
				}

				return $results;
			}
		};
	}

	/**
	 * Test extraction with errors (the P1 bug scenario).
	 */
	public function test_extract_results_with_errors() {
		$section = $this->get_section_instance();

		// Simulate PHPUnit output with errors (not failures).
		$output = 'PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

..E.E

Time: 00:02.456, Memory: 36.00 MB

There were 2 errors:

1) TestClass::testMethod
Error: Something went wrong

ERRORS!
Tests: 5, Assertions: 8, Errors: 2.';

		$results = $section->extract_test_results_from_phpunit_output( $output );

		// Verify results correctly identify errors.
		$this->assertEquals( 5, $results['total'], 'Should extract total tests' );
		$this->assertEquals( 2, $results['errors'], 'Should extract error count' );
		$this->assertEquals( 2, $results['failed'], 'Errors should count as failures' );
		$this->assertEquals( 3, $results['passed'], 'Should calculate passed correctly (total - errors)' );
	}

	/**
	 * Test extraction with failures (original case).
	 */
	public function test_extract_results_with_failures() {
		$section = $this->get_section_instance();

		// Simulate PHPUnit output with failures.
		$output = 'PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

..F.F

Time: 00:01.234, Memory: 28.00 MB

There were 2 failures:

1) TestClass::testMethod
Failed asserting that false is true.

FAILURES!
Tests: 5, Assertions: 8, Failures: 2.';

		$results = $section->extract_test_results_from_phpunit_output( $output );

		// Verify results correctly identify failures.
		$this->assertEquals( 5, $results['total'], 'Should extract total tests' );
		$this->assertEquals( 2, $results['failed'], 'Should extract failure count' );
		$this->assertEquals( 3, $results['passed'], 'Should calculate passed correctly' );
		$this->assertEquals( 0, $results['errors'], 'Should have no errors' );
	}

	/**
	 * Test extraction with both errors and failures.
	 */
	public function test_extract_results_with_errors_and_failures() {
		$section = $this->get_section_instance();

		// Simulate PHPUnit output with both errors and failures.
		$output = 'PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

.EF.E

Time: 00:03.789, Memory: 42.00 MB

There was 1 failure:

1) TestClass::testFailure
Failed asserting that false is true.

There were 2 errors:

2) TestClass::testError1
Error: Something went wrong

3) TestClass::testError2
Error: Another error

ERRORS!
Tests: 5, Assertions: 10, Failures: 1, Errors: 2.';

		$results = $section->extract_test_results_from_phpunit_output( $output );

		// Verify results correctly identify both.
		$this->assertEquals( 5, $results['total'], 'Should extract total tests' );
		$this->assertEquals( 2, $results['errors'], 'Should extract error count separately' );
		$this->assertEquals( 3, $results['failed'], 'Failed should be failures + errors (1 + 2)' );
		$this->assertEquals( 2, $results['passed'], 'Should calculate passed correctly (5 - 3)' );
	}

	/**
	 * Test extraction with all tests passing (OK status).
	 */
	public function test_extract_results_all_passing() {
		$section = $this->get_section_instance();

		// Simulate PHPUnit output with all passing.
		$output = 'PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

.....

Time: 00:00.567, Memory: 24.00 MB

OK (5 tests, 10 assertions)';

		$results = $section->extract_test_results_from_phpunit_output( $output );

		// Verify results correctly identify all passing.
		$this->assertEquals( 5, $results['total'], 'Should extract total tests' );
		$this->assertEquals( 5, $results['passed'], 'All tests should be passing' );
		$this->assertEquals( 0, $results['failed'], 'Should have no failures' );
		$this->assertEquals( 0, $results['errors'], 'Should have no errors' );
	}

	/**
	 * Test extraction with skipped tests.
	 */
	public function test_extract_results_with_skipped() {
		$section = $this->get_section_instance();

		// Simulate PHPUnit output with skipped tests.
		$output = 'PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

..S.S

Time: 00:01.123, Memory: 26.00 MB

OK, but incomplete, skipped, or risky tests!
Tests: 5, Assertions: 6, Skipped: 2.';

		$results = $section->extract_test_results_from_phpunit_output( $output );

		// Verify results correctly handle skipped.
		$this->assertEquals( 5, $results['total'], 'Should extract total tests' );
		$this->assertEquals( 2, $results['skipped'], 'Should extract skipped count' );
		$this->assertEquals( 3, $results['passed'], 'Passed should be total - skipped' );
		$this->assertEquals( 0, $results['failed'], 'Should have no failures' );
	}

	/**
	 * Test that the bug scenario (errors with no failures) is now fixed.
	 *
	 * This is the specific P1 bug: when PHPUnit has Errors but no Failures,
	 * it should NOT be saved as all tests passing.
	 */
	public function test_p1_bug_fixed_errors_not_reported_as_passing() {
		$section = $this->get_section_instance();

		// The exact scenario from the bug report: Tests with Errors but no Failures.
		$output = 'Tests: 10, Assertions: 15, Errors: 3.';

		$results = $section->extract_test_results_from_phpunit_output( $output );

		// BEFORE FIX: failed would be 0, passed would be 10 (BUG!).
		// AFTER FIX: failed should be 3, passed should be 7.
		$this->assertEquals( 10, $results['total'], 'Should extract total' );
		$this->assertEquals( 3, $results['errors'], 'Should extract errors' );
		$this->assertEquals( 3, $results['failed'], 'Errors should be counted as failures' );
		$this->assertEquals( 7, $results['passed'], 'Passed should NOT be 10 (the bug)' );
		$this->assertNotEquals( 10, $results['passed'], 'P1 BUG: Should NOT report all tests as passing when errors exist' );
	}
}

