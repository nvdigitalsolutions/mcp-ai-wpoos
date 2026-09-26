<?php
/**
 * Tests for the web-search fallback of the Search Upwork Jobs tool.
 *
 * Covers:
 * - is_upwork_category_page(): category landing pages vs. job post URLs.
 * - filter_upwork_job_results(): dropping non-job entries from fallback lists.
 * - extract_snippet_metadata(): job type / budget / recency parsing.
 * - execute() fallback: category pages excluded from the delivered jobs,
 *   snippet metadata mapped onto the standard job envelope, guidance notice
 *   when nothing usable remains.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   GPL-3.0-or-later
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-search.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-upwork-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php';

/**
 * Test suite for the Upwork job search web-search fallback.
 */
class Test_Upwork_Job_Search_Fallback extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Search_Upwork_Jobs
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
		$this->tool = new WP_MCP_AI_Tool_Search_Upwork_Jobs();
	}

	/**
	 * Clean up after each test run.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_private( $method, array $args ) {
		$reflection = new ReflectionMethod( $this->tool, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $this->tool, $args );
	}

	// -------------------------------------------------------------------------
	// Category page detection
	// -------------------------------------------------------------------------

	/**
	 * Bare /freelance-jobs/{slug}/, /freelance-jobs/apply/{category}/, and
	 * /hire/{slug}/ paths are category pages; job post URLs carry a ~jobId
	 * suffix (or live under /jobs/).
	 */
	public function test_is_upwork_category_page_classifies_urls() {
		$category_pages = array(
			'https://www.upwork.com/freelance-jobs/administrative-support/',
			'https://www.upwork.com/freelance-jobs/level-design',
			'https://www.upwork.com/freelance-jobs/apply/web-development/',
			'https://www.upwork.com/hire/virtual-assistants/',
			'https://upwork.com/hire/mobile-app-developers',
		);
		foreach ( $category_pages as $url ) {
			$this->assertTrue( $this->invoke_private( 'is_upwork_category_page', array( $url ) ), "Expected category page: $url" );
		}

		$job_postings = array(
			'https://www.upwork.com/freelance-jobs/WordPress-Developer_~01d7d03bb39cc7daec/',
			'https://www.upwork.com/freelance-jobs/apply/WordPress-Developer-for-Elementor-Website_~022091870001775728249/',
			'https://www.upwork.com/jobs/wordpress-dev_~0123456789abcdef/',
			'https://www.upwork.com/nx/find-work/best-matches',
		);
		foreach ( $job_postings as $url ) {
			$this->assertFalse( $this->invoke_private( 'is_upwork_category_page', array( $url ) ), "Expected job posting: $url" );
		}

		// Non-Upwork hosts and empty URLs are never category pages.
		$this->assertFalse( $this->invoke_private( 'is_upwork_category_page', array( 'https://example.com/freelance-jobs/foo/' ) ) );
		$this->assertFalse( $this->invoke_private( 'is_upwork_category_page', array( '' ) ) );
	}

	// -------------------------------------------------------------------------
	// Result filtering
	// -------------------------------------------------------------------------

	/**
	 * The fallback filter keeps job postings and non-Upwork sources, and drops
	 * category pages plus entries without a title or URL.
	 */
	public function test_filter_upwork_job_results_drops_category_pages() {
		$results = array(
			array(
				'title'   => 'WordPress Developer needed for agency',
				'url'     => 'https://www.upwork.com/freelance-jobs/WordPress-Developer_~01d7d03bb39cc7daec/',
				'snippet' => 'Fixed-price · Posted 2 days ago · $120 Fixed-price',
			),
			array(
				'title'   => 'Administrative Support Freelance Jobs: Work Remote & Earn Online',
				'url'     => 'https://www.upwork.com/freelance-jobs/administrative-support/',
				'snippet' => 'Freelance administrative and virtual assistants may support anyone…',
			),
			array(
				'title'   => 'Remote designer openings this week',
				'url'     => 'https://remoteok.com/remote-design-jobs',
				'snippet' => 'A curated board of remote design roles.',
			),
			array(
				'title' => 'Missing URL',
				'url'   => '',
			),
			array(
				'title' => '',
				'url'   => 'https://www.upwork.com/jobs/foo_~01abc/',
			),
			'not-an-array',
		);

		$filtered = $this->invoke_private( 'filter_upwork_job_results', array( $results ) );

		$this->assertCount( 2, $filtered );
		$this->assertSame( 'WordPress Developer needed for agency', $filtered[0]['title'] );
		$this->assertSame( 'Remote designer openings this week', $filtered[1]['title'] );
	}

	// -------------------------------------------------------------------------
	// Snippet metadata extraction
	// -------------------------------------------------------------------------

	/**
	 * Fixed-price snippets yield type, budget, and recency fields.
	 */
	public function test_extract_snippet_metadata_parses_fixed_price() {
		$snippet = 'Intermediate Experience level · I need to get an excel file/csv created… · Fixed-price ‐ Posted 2 days ago · $120 Fixed-price';

		$meta = $this->invoke_private( 'extract_snippet_metadata', array( $snippet ) );

		$this->assertSame( 'fixed', $meta['job_type'] );
		$this->assertSame( 120.0, $meta['budget'] );
		$this->assertSame( '2 days ago', $meta['published'] );
	}

	/**
	 * Hourly snippets yield the hourly type and the first rate figure.
	 */
	public function test_extract_snippet_metadata_parses_hourly() {
		$snippet = 'Hourly: $25.00-$45.00 · Posted 1 hour ago';

		$meta = $this->invoke_private( 'extract_snippet_metadata', array( $snippet ) );

		$this->assertSame( 'hourly', $meta['job_type'] );
		$this->assertSame( 25.0, $meta['budget'] );
		$this->assertSame( '1 hour ago', $meta['published'] );
	}

	/**
	 * Snippets without job signals yield empty metadata.
	 */
	public function test_extract_snippet_metadata_handles_plain_snippets() {
		$meta = $this->invoke_private( 'extract_snippet_metadata', array( 'A generic description with no job signals.' ) );

		$this->assertSame( '', $meta['job_type'] );
		$this->assertNull( $meta['budget'] );
		$this->assertSame( '', $meta['published'] );
	}

	/**
	 * Snippets with an hourly range and an experience tier yield budget_max
	 * and tier fields.
	 */
	public function test_extract_snippet_metadata_parses_tier_and_budget_range() {
		$meta = $this->invoke_private( 'extract_snippet_metadata', array( 'Intermediate Experience level · Hourly: $25.00-$45.00 · Posted 1 hour ago' ) );

		$this->assertSame( 'hourly', $meta['job_type'] );
		$this->assertSame( 25.0, $meta['budget'] );
		$this->assertSame( 45.0, $meta['budget_max'] );
		$this->assertSame( 'Intermediate', $meta['tier'] );
		$this->assertSame( '1 hour ago', $meta['published'] );

		// Fixed-price range with an explicit second dollar sign.
		$meta = $this->invoke_private( 'extract_snippet_metadata', array( 'Entry level · Fixed-price · $500-$1,000 · Posted 3 hours ago' ) );

		$this->assertSame( 'fixed', $meta['job_type'] );
		$this->assertSame( 500.0, $meta['budget'] );
		$this->assertSame( 1000.0, $meta['budget_max'] );
		$this->assertSame( 'Entry level', $meta['tier'] );
	}

	/**
	 * A year-like number after a dash is never taken as a budget upper bound,
	 * and a bare "expert" phrase without level wording never sets the tier.
	 */
	public function test_extract_snippet_metadata_guards_year_like_and_bare_expert() {
		$meta = $this->invoke_private( 'extract_snippet_metadata', array( 'Fixed-price · Budget $500 - 2024 forecast · We need an expert' ) );

		$this->assertSame( 500.0, $meta['budget'] );
		$this->assertNull( $meta['budget_max'] );
		$this->assertSame( '', $meta['tier'] );
	}

	// -------------------------------------------------------------------------
	// execute() fallback integration
	// -------------------------------------------------------------------------

	/**
	 * Build a DuckDuckGo-shaped JSON body: a category-page abstract plus one
	 * real job posting and one category page in the related topics.
	 *
	 * @return string JSON.
	 */
	private function duckduckgo_body() {
		return wp_json_encode(
			array(
				'AbstractText'  => 'Administrative Support Freelance Jobs: Work Remote & Earn Online',
				'AbstractURL'   => 'https://www.upwork.com/freelance-jobs/administrative-support/',
				'Heading'       => 'Administrative Support Freelance Jobs: Work Remote & Earn Online',
				'RelatedTopics' => array(
					array(
						'Text'     => 'WordPress Developer needed for agency - Upwork',
						'FirstURL' => 'https://www.upwork.com/freelance-jobs/WordPress-Developer_~01d7d03bb39cc7daec/',
						'Result'   => 'Entry level · Build a WordPress site for our agency… · Fixed-price ‐ Posted 2 days ago · $120 Fixed-price',
					),
					array(
						'Text'     => 'Typing Freelance Jobs: Work Remote & Earn Online',
						'FirstURL' => 'https://www.upwork.com/freelance-jobs/typing/',
						'Result'   => 'Entry Experience level · We are looking for a detail-oriented freelancer…',
					),
				),
			)
		);
	}

	/**
	 * Broad-pass body: repeats the first pass's job posting (dedupe proof), a
	 * category page, and one aggregator listing.
	 *
	 * @return string JSON.
	 */
	private function duckduckgo_body_broad() {
		return wp_json_encode(
			array(
				'AbstractText'  => 'SEO Freelance Jobs: Work Remote & Earn Online',
				'AbstractURL'   => 'https://www.upwork.com/freelance-jobs/seo/',
				'Heading'       => 'SEO Freelance Jobs: Work Remote & Earn Online',
				'RelatedTopics' => array(
					array(
						'Text'     => 'WordPress Developer needed for agency - Upwork',
						'FirstURL' => 'https://www.upwork.com/freelance-jobs/WordPress-Developer_~01d7d03bb39cc7daec/',
						'Result'   => 'Entry level · Build a WordPress site for our agency…',
					),
					array(
						'Text'     => 'Senior WordPress developer openings this week',
						'FirstURL' => 'https://remoteok.com/remote-wordpress-jobs',
						'Result'   => 'A curated board of remote WordPress roles. Hourly: $30.00-$50.00 · Posted 1 hour ago',
					),
				),
			)
		);
	}

	/**
	 * The execute() fallback must exclude category pages, keep the job posting,
	 * and map snippet-derived metadata onto the standard job envelope.
	 */
	public function test_execute_fallback_filters_category_pages_and_maps_metadata() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Register the base web_search tool so the fallback can resolve it.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Tool_Web_Search() );

		$body       = $this->duckduckgo_body();
		$body_broad = $this->duckduckgo_body_broad();

		// Differentiate the two passes by the requested query: the site-restricted
		// first pass carries "site:upwork.com"; the broad second pass does not.
		// The colon and slashes are percent-encoded inside the request URL, so
		// decode before matching.
		$http_stub = static function ( $preempt, $args, $url ) use ( $body, $body_broad ) {
			if ( false !== strpos( $url, 'duckduckgo.com' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => false !== strpos( urldecode( $url ), 'site:upwork.com' ) ? $body : $body_broad,
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $this->tool->execute(
			array(
				'query' => 'wordpress developer',
				'limit' => 5,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'fallback', $result['mode'] );
		$this->assertSame( 'web_search', $result['source'] );

		// Category pages excluded across both passes: 2 in the first pass
		// (abstract + typing) and 1 in the broad pass (SEO abstract).
		$this->assertSame( 3, $result['filtered_out'] );

		// The first-pass job posting plus the broad-pass aggregator listing —
		// the duplicated job posting is deduped, not repeated.
		$this->assertCount( 2, $result['jobs'] );

		$urls = wp_list_pluck( $result['jobs'], 'url' );
		$this->assertContains( 'https://www.upwork.com/freelance-jobs/WordPress-Developer_~01d7d03bb39cc7daec/', $urls );
		$this->assertContains( 'https://remoteok.com/remote-wordpress-jobs', $urls );

		$job = $result['jobs'][0];
		$this->assertSame( 'WordPress Developer needed for agency - Upwork', $job['title'] );
		$this->assertStringContainsString( '~01d7d03bb39cc7daec', $job['url'] );
		$this->assertSame( 'fixed', $job['job_type'] );
		$this->assertSame( 120.0, $job['budget'] );
		$this->assertSame( '2 days ago', $job['published'] );

		$this->assertStringContainsString( 'category page', $result['notice'] );
		$this->assertStringContainsString( '3 results were excluded', $result['notice'] );
		$this->assertStringContainsString( 'expanded second search', $result['notice'] );
	}

	/**
	 * The broad second pass must merge aggregator listings and rank direct
	 * Upwork job postings above them regardless of arrival order.
	 */
	public function test_execute_fallback_ranks_direct_postings_first() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Tool_Web_Search() );

		$body       = $this->duckduckgo_body();
		$body_broad = $this->duckduckgo_body_broad();

		$http_stub = static function ( $preempt, $args, $url ) use ( $body, $body_broad ) {
			if ( false !== strpos( $url, 'duckduckgo.com' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => false !== strpos( urldecode( $url ), 'site:upwork.com' ) ? $body : $body_broad,
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $this->tool->execute(
			array(
				'query' => 'wordpress developer',
				'limit' => 5,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// The direct Upwork posting (with the ~jobId suffix) outranks the
		// aggregator listing even though the listing arrived in the second pass.
		$this->assertStringContainsString( '~01d7d03bb39cc7daec', $result['jobs'][0]['url'] );
		$this->assertSame( 'https://remoteok.com/remote-wordpress-jobs', $result['jobs'][1]['url'] );
	}

	/**
	 * The broad second pass must run even when the call carries no keywords
	 * (the discovery-scan preset passes an empty query) — otherwise a
	 * category-page-dominated first pass leaves the schedule with no jobs.
	 */
	public function test_execute_fallback_runs_broad_pass_without_keywords() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Tool_Web_Search() );

		// Pass 1 (site-restricted) surfaces only category pages; pass 2
		// (broad) surfaces one real posting plus an aggregator listing.
		$category_only = wp_json_encode(
			array(
				'AbstractText'  => 'WordPress Freelance Jobs: Work Remote & Earn Online',
				'AbstractURL'   => 'https://www.upwork.com/freelance-jobs/wordpress/',
				'Heading'       => 'WordPress Freelance Jobs: Work Remote & Earn Online',
				'RelatedTopics' => array(
					array(
						'Text'     => 'Typing Freelance Jobs: Work Remote & Earn Online',
						'FirstURL' => 'https://www.upwork.com/freelance-jobs/typing/',
						'Result'   => 'Entry Experience level · We are looking for a detail-oriented freelancer…',
					),
				),
			)
		);
		$broad_body    = wp_json_encode(
			array(
				'RelatedTopics' => array(
					array(
						'Text'     => 'WordPress Developer for Block-Based Theme - Upwork',
						'FirstURL' => 'https://www.upwork.com/freelance-jobs/apply/WordPress-Developer-for-Block-Based-Theme_~022048801956531499628/',
						'Result'   => 'Hourly: $25.00-$45.00 · Posted 1 hour ago',
					),
					array(
						'Text'     => 'Senior WordPress developer openings this week',
						'FirstURL' => 'https://remoteok.com/remote-wordpress-jobs',
						'Result'   => 'A curated board of remote WordPress roles.',
					),
				),
			)
		);

		$http_stub = static function ( $preempt, $args, $url ) use ( $category_only, $broad_body ) {
			if ( false !== strpos( $url, 'duckduckgo.com' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => false !== strpos( urldecode( $url ), 'site:upwork.com' ) ? $category_only : $broad_body,
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// No query, no skills, no category — the preset's default call shape.
		$result = $this->tool->execute(
			array( 'limit' => 10 ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['jobs'] );
		$this->assertStringContainsString( 'expanded second search', $result['notice'] );
		$urls = wp_list_pluck( $result['jobs'], 'url' );
		$this->assertContains( 'https://www.upwork.com/freelance-jobs/apply/WordPress-Developer-for-Block-Based-Theme_~022048801956531499628/', $urls );
		$this->assertContains( 'https://remoteok.com/remote-wordpress-jobs', $urls );
	}

	/**
	 * When every fallback hit is a category page, the tool returns an empty
	 * list with actionable guidance instead of junk leads.
	 */
	public function test_execute_fallback_empty_with_guidance_notice() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Tool_Web_Search() );

		$http_stub = static function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, 'duckduckgo.com' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'AbstractText' => 'SEO Freelance Jobs: Work Remote & Earn Online',
							'AbstractURL'  => 'https://www.upwork.com/freelance-jobs/seo/',
							'Heading'      => 'SEO Freelance Jobs: Work Remote & Earn Online',
						)
					),
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $this->tool->execute(
			array( 'query' => 'seo specialist' ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( array(), $result['jobs'] );
		$this->assertStringContainsString( 'No individual job postings were found', $result['notice'] );
	}

	/**
	 * The fallback envelope echoes the effective criteria so callers can tell
	 * weak filters from a missing Upwork connection, and flags unfiltered
	 * searches in the notice.
	 */
	public function test_execute_fallback_echoes_criteria_and_flags_missing_filters() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Tool_Web_Search() );

		$body = wp_json_encode(
			array(
				'RelatedTopics' => array(
					array(
						'Text'     => 'WordPress Developer for Block-Based Theme - Upwork',
						'FirstURL' => 'https://www.upwork.com/freelance-jobs/apply/WordPress-Developer-for-Block-Based-Theme_~022048801956531499628/',
						'Result'   => 'Hourly: $25.00-$45.00 · Posted 1 hour ago',
					),
				),
			)
		);

		$http_stub = static function ( $preempt, $args, $url ) use ( $body ) {
			if ( false !== strpos( $url, 'duckduckgo.com' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => $body,
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// With criteria: the criteria echo carries them and the noise warning stays away.
		$result = $this->tool->execute(
			array(
				'query'  => 'wordpress developer',
				'skills' => array( 'Elementor' ),
				'limit'  => 5,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertTrue( $result['criteria_provided'] );
		$this->assertSame( 'wordpress developer', $result['criteria']['query'] );
		$this->assertSame( array( 'Elementor' ), $result['criteria']['skills'] );
		$this->assertSame( 5, $result['criteria']['limit'] );
		$this->assertStringNotContainsString( 'unfiltered marketplace noise', $result['notice'] );

		// Without criteria: criteria_provided is false and the notice explains the noise.
		$result = $this->tool->execute(
			array( 'limit' => 10 ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertFalse( $result['criteria_provided'] );
		$this->assertSame( '', $result['criteria']['query'] );
		$this->assertStringContainsString( 'unfiltered marketplace noise', $result['notice'] );
		$this->assertSame( 10, $result['criteria']['limit'] );
	}

	// -------------------------------------------------------------------------
	// Query building + ranking helpers
	// -------------------------------------------------------------------------

	/**
	 * The primary query is site-restricted; the broad query drops the
	 * restriction, phrases multi-word keywords, and includes the location.
	 */
	public function test_build_fallback_query_primary_and_broad_modes() {
		$args = array(
			'query'    => 'WordPress developer',
			'location' => 'Remote',
			'job_type' => 'hourly',
		);

		$primary = $this->invoke_private( 'build_fallback_query', array( $args ) );
		$this->assertStringContainsString( 'site:upwork.com/freelance-jobs/apply', $primary );
		$this->assertStringContainsString( '"WordPress developer"', $primary );
		$this->assertStringContainsString( 'Remote', $primary );
		$this->assertStringContainsString( 'hourly', $primary );

		$broad = $this->invoke_private( 'build_fallback_query', array( $args, true ) );
		$this->assertStringNotContainsString( 'site:', $broad );
		$this->assertStringContainsString( '"WordPress developer"', $broad );

		// Without any filters the broad query still seeds generic job terms
		// plus the recency default — the second pass must work even when the
		// schedule preset carries no keywords.
		$broad_plain = $this->invoke_private( 'build_fallback_query', array( array(), true ) );
		$this->assertStringContainsString( 'upwork', $broad_plain );
		$this->assertStringContainsString( 'recently posted freelance job openings', $broad_plain );
	}

	/**
	 * Skills group into a quoted OR expression, exclusions become minus
	 * operators, and "all" sentinel filters never leak into the query.
	 */
	public function test_build_fallback_query_groups_skills_and_applies_exclusions() {
		$args = array(
			'query'            => 'WordPress developer',
			'skills'           => array( 'Elementor', 'WooCommerce' ),
			'exclude_keywords' => array( 'homework', 'essay' ),
			'job_type'         => 'all',
			'experience_level' => 'all',
		);

		$query = $this->invoke_private( 'build_fallback_query', array( $args ) );

		$this->assertStringContainsString( 'site:upwork.com/freelance-jobs/apply', $query );
		$this->assertStringContainsString( '"WordPress developer"', $query );
		$this->assertStringContainsString( '("Elementor" OR "WooCommerce")', $query );
		$this->assertStringContainsString( '-"homework"', $query );
		$this->assertStringContainsString( '-"essay"', $query );
		$this->assertStringNotContainsString( 'all', $query );
	}

	/**
	 * The fallback ranker puts direct Upwork job postings first, then keyword
	 * matches, and strips its internal scoring key.
	 */
	public function test_rank_fallback_jobs_prefers_direct_postings_and_keyword_matches() {
		$jobs = array(
			array(
				'title'       => 'Generic freelance board',
				'url'         => 'https://remoteok.com/remote-wordpress-jobs',
				'description' => 'A curated board of remote roles.',
				'job_type'    => '',
			),
			array(
				'title'       => 'WordPress Developer needed for agency - Upwork',
				'url'         => 'https://www.upwork.com/freelance-jobs/WordPress-Developer_~01d7d03bb39cc7daec/',
				'description' => 'Build a WordPress site for our agency…',
				'job_type'    => 'fixed',
			),
			array(
				'title'       => 'Unrelated listing',
				'url'         => 'https://remoteok.com/other',
				'description' => 'No match here.',
				'job_type'    => '',
			),
		);

		$ranked = $this->invoke_private(
			'rank_fallback_jobs',
			array( $jobs, array( 'query' => 'wordpress developer' ) )
		);

		$this->assertSame( 'https://www.upwork.com/freelance-jobs/WordPress-Developer_~01d7d03bb39cc7daec/', $ranked[0]['url'] );
		$this->assertSame( 'https://remoteok.com/remote-wordpress-jobs', $ranked[1]['url'] );
		$this->assertSame( 'https://remoteok.com/other', $ranked[2]['url'] );

		// The internal ranking key never leaks into the returned jobs.
		foreach ( $ranked as $job ) {
			$this->assertArrayNotHasKey( '_rank', $job );
		}
	}

	/**
	 * Job URLs are derived from the GraphQL node id + title slug.
	 */
	public function test_build_job_url_derives_url_from_id_and_title() {
		$node = array(
			'id'    => '~01d7d03bb39cc7daec',
			'title' => 'WordPress Developer needed for agency',
		);

		$this->assertSame(
			'https://www.upwork.com/jobs/wordpress-developer-needed-for-agency_~01d7d03bb39cc7daec/',
			$this->invoke_private( 'build_job_url', array( $node ) )
		);

		$this->assertSame(
			'',
			$this->invoke_private(
				'build_job_url',
				array(
					array(
						'id'    => '',
						'title' => 'No id',
					),
				)
			)
		);
		$this->assertSame(
			'',
			$this->invoke_private(
				'build_job_url',
				array(
					array(
						'id'    => '~01abc',
						'title' => '',
					),
				)
			)
		);
	}

	// -------------------------------------------------------------------------
	// execute() API-mode integration
	// -------------------------------------------------------------------------

	/**
	 * The API path must send recency sort attributes, fold the location into
	 * the search expression, and derive a human-facing job URL per result.
	 */
	public function test_execute_api_mode_wires_sort_attributes_and_location() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Upwork Test',
				'connection_type' => 'upwork',
				'url'             => 'https://www.upwork.com',
				'enabled'         => 1,
				'client_id'       => 'client-id-123',
				'client_secret'   => 'client-secret-123',
				'refresh_token'   => 'refresh-token-123',
			)
		);

		$captured_graphql = null;

		$http_stub = static function ( $preempt, $args, $url ) use ( &$captured_graphql ) {
			if ( false !== strpos( $url, 'api/v3/oauth2/token' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'access_token' => 'tkn-123',
							'expires_in'   => 3600,
						)
					),
				);
			}
			if ( false !== strpos( $url, 'api.upwork.com/graphql' ) ) {
				$captured_graphql = json_decode( isset( $args['body'] ) ? $args['body'] : '{}', true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'data' => array(
								'marketplaceJobPostingsSearch' => array(
									'totalCount' => 1,
									'edges'      => array(
										array(
											'node'   => array(
												'id'       => '~01d7d03bb39cc7daec',
												'title'    => 'WordPress Developer needed',
												'description' => 'Build a WordPress site for our agency.',
												'createdDateTime' => '2026-09-19T00:00:00Z',
												'publishedDateTime' => '2026-09-19T00:00:00Z',
												'jobType'  => 'FIXED',
												'engagement' => '',
												'duration' => '',
												'skills'   => array( array( 'prettyName' => 'WordPress' ) ),
												'client'   => array(
													'totalFeedback'            => 4.9,
													'totalHires'               => 12,
													'totalJobsPosted'          => 20,
													'totalSpent'               => array(
														'amount'   => 5000,
														'currency' => 'USD',
													),
													'paymentVerificationStatus' => 'VERIFIED',
													'location'                 => array( 'country' => 'United States' ),
												),
												'category' => array( 'name' => 'Web, Mobile & Software Dev' ),
												'subcategory' => array( 'name' => 'Web & Mobile Design' ),
												'totalApplicants' => 5,
												'tierText' => 'Intermediate',
											),
											'cursor' => 'cursor-1',
										),
									),
									'pageInfo'   => array(
										'endCursor'   => 'cursor-1',
										'hasNextPage' => false,
									),
								),
							),
						)
					),
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $this->tool->execute(
			array(
				'connection_id' => $connection_id,
				'query'         => 'WordPress developer',
				'location'      => 'Remote',
				'sort'          => 'recency',
				'limit'         => 10,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Cleanup: connection option + cached access token.
		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		unset( $connections[ $connection_id ] );
		update_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME, $connections );
		delete_transient( 'wp_mcp_ai_upwork_at_' . md5( $connection_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'api', $result['mode'] );
		$this->assertCount( 1, $result['jobs'] );

		// Recency sort attributes were sent.
		$this->assertIsArray( $captured_graphql );
		$this->assertSame(
			array( array( 'field' => 'RECENCY' ) ),
			$captured_graphql['variables']['sortAttributes']
		);

		// Location folded into the search expression.
		$this->assertSame(
			'WordPress developer (Remote)',
			$captured_graphql['variables']['marketPlaceJobFilter']['searchExpression']
		);

		// Human-facing job URL derived from id + title.
		$job = $result['jobs'][0];
		$this->assertSame(
			'https://www.upwork.com/jobs/wordpress-developer-needed_~01d7d03bb39cc7daec/',
			$job['url']
		);
		$this->assertSame( array( 'WordPress' ), $job['skills'] );
		$this->assertSame( 'United States', $job['client']['country'] );
	}

	/**
	 * "all" sentinel filters must never leak into the GraphQL filter, and
	 * exclude_keywords must fold into the searchExpression as boolean NOT.
	 */
	public function test_execute_api_mode_normalises_all_sentinels_and_applies_exclusions() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Upwork Test',
				'connection_type' => 'upwork',
				'url'             => 'https://www.upwork.com',
				'enabled'         => 1,
				'client_id'       => 'client-id-123',
				'client_secret'   => 'client-secret-123',
				'refresh_token'   => 'refresh-token-123',
			)
		);

		$captured_graphql = null;

		$http_stub = static function ( $preempt, $args, $url ) use ( &$captured_graphql ) {
			if ( false !== strpos( $url, 'api/v3/oauth2/token' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'access_token' => 'tkn-123',
							'expires_in'   => 3600,
						)
					),
				);
			}
			if ( false !== strpos( $url, 'api.upwork.com/graphql' ) ) {
				$captured_graphql = json_decode( isset( $args['body'] ) ? $args['body'] : '{}', true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'data' => array(
								'marketplaceJobPostingsSearch' => array(
									'totalCount' => 0,
									'edges'      => array(),
									'pageInfo'   => array(
										'endCursor'   => null,
										'hasNextPage' => false,
									),
								),
							),
						)
					),
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $this->tool->execute(
			array(
				'connection_id'    => $connection_id,
				'query'            => 'WordPress developer',
				'job_type'         => 'all',
				'experience_level' => 'all',
				'exclude_keywords' => array( 'homework', 'essay writing' ),
				'limit'            => 10,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Cleanup: connection option + cached access token.
		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		unset( $connections[ $connection_id ] );
		update_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME, $connections );
		delete_transient( 'wp_mcp_ai_upwork_at_' . md5( $connection_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'api', $result['mode'] );

		$filter = isset( $captured_graphql['variables']['marketPlaceJobFilter'] )
			? $captured_graphql['variables']['marketPlaceJobFilter']
			: array();

		// The "all" sentinels are normalised away — no jobType/contractorTier keys.
		$this->assertArrayNotHasKey( 'jobType', $filter );
		$this->assertArrayNotHasKey( 'contractorTier', $filter );

		// Exclusions fold into the searchExpression as uppercase boolean NOT.
		$this->assertSame(
			'WordPress developer NOT (homework OR "essay writing")',
			$filter['searchExpression']
		);

		// The criteria echo reports the normalised filters.
		$this->assertTrue( $result['criteria_provided'] );
		$this->assertSame( '', $result['criteria']['job_type'] );
		$this->assertSame( array( 'homework', 'essay writing' ), $result['criteria']['exclude_keywords'] );
	}
}
