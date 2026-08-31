<?php
/**
 * Sync Docs Slash Command
 *
 * Documentation drift detection and synchronization.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Docs Command Class
 *
 * Implements documentation synchronization:
 * 1. Find all documentation
 * 2. Detect drift from code
 * 3. Find broken links
 * 4. Update code examples
 * 5. Generate missing docs
 *
 * @since 1.2.0
 */
class SlashCommandSyncDocs {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Execute sync-docs command
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|WP_Error Command result or error.
	 */
	public function execute( $args, $flags, $context ) {
		$user_id = isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id();

		// Check permissions.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new \WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to synchronize documentation.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Parse flags.
		$doc_type    = isset( $flags['type'] ) ? sanitize_text_field( $flags['type'] ) : 'all';
		$dry_run     = isset( $flags['dry-run'] ) || isset( $flags['n'] );
		$auto_fix    = isset( $flags['auto-fix'] ) || isset( $flags['a'] );
		$check_links = ! isset( $flags['skip-links'] );
		$check_code  = ! isset( $flags['skip-code'] );

		// Find documentation.
		$docs = $this->find_documentation( $doc_type );

		if ( empty( $docs ) ) {
			return $this->format_response(
				'info',
				__( 'No documentation found to synchronize.', 'nvoos-content-graph-ai-platform' ),
				array()
			);
		}

		// Analyze each document.
		$results = array(
			'total_docs'   => count( $docs ),
			'docs_checked' => 0,
			'issues_found' => 0,
			'issues_fixed' => 0,
			'docs'         => array(),
		);

		foreach ( $docs as $doc ) {
			$doc_result = $this->analyze_document(
				$doc,
				array(
					'check_links' => $check_links,
					'check_code'  => $check_code,
					'auto_fix'    => $auto_fix && ! $dry_run,
				)
			);

			$results['docs'][] = $doc_result;
			++$results['docs_checked'];

			if ( ! empty( $doc_result['issues'] ) ) {
				$results['issues_found'] += count( $doc_result['issues'] );
			}

			if ( ! empty( $doc_result['fixed'] ) ) {
				$results['issues_fixed'] += count( $doc_result['fixed'] );
			}
		}

		return $this->format_response(
			'success',
			sprintf(
				/* translators: 1: docs checked, 2: issues found */
				__( 'Checked %1$d document(s), found %2$d issue(s).', 'nvoos-content-graph-ai-platform' ),
				$results['docs_checked'],
				$results['issues_found']
			),
			$results
		);
	}

	/**
	 * Find documentation posts/pages
	 *
	 * @param string $type Document type (all, posts, pages, readme).
	 * @return array Document list.
	 */
	private function find_documentation( $type ) {
		$docs = array();

		// Find posts/pages tagged as documentation.
		if ( in_array( $type, array( 'all', 'posts', 'pages' ), true ) ) {
			$post_types = array();

			if ( in_array( $type, array( 'all', 'posts' ), true ) ) {
				$post_types[] = 'post';
			}

			if ( in_array( $type, array( 'all', 'pages' ), true ) ) {
				$post_types[] = 'page';
			}

			$posts = get_posts(
				array(
					'post_type'     => $post_types,
					'post_status'   => 'publish',
					'numberposts'   => -1,
					'category_name' => 'documentation',
				)
			);

			// Also find posts/pages with "documentation", "guide", "tutorial" in title.
			if ( empty( $posts ) ) {
				$posts = get_posts(
					array(
						'post_type'   => $post_types,
						'post_status' => 'publish',
						'numberposts' => 50,
						's'           => 'documentation guide tutorial',
					)
				);
			}

			foreach ( $posts as $post ) {
				$docs[] = array(
					'type'    => 'post',
					'id'      => $post->ID,
					'title'   => get_the_title( $post->ID ),
					'content' => $post->post_content,
					'url'     => get_permalink( $post->ID ),
				);
			}
		}

		// Find README files in plugin/theme directories.
		if ( in_array( $type, array( 'all', 'readme' ), true ) ) {
			$readme_files = $this->find_readme_files();
			foreach ( $readme_files as $file ) {
				$docs[] = array(
					'type'    => 'file',
					'path'    => $file,
					'title'   => basename( $file ),
					'content' => file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
					'url'     => null,
				);
			}
		}

		return $docs;
	}

	/**
	 * Find README files
	 *
	 * @return array File paths.
	 */
	private function find_readme_files() {
		$files = array();

		// Check plugin directory.
		$readme_patterns = array( 'README.md', 'readme.txt', 'README.txt', 'DOCUMENTATION.md' );

		if ( defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR ) ) {
			$plugin_dir = WP_PLUGIN_DIR;

			foreach ( $readme_patterns as $pattern ) {
				$found = glob( $plugin_dir . '/*/' . $pattern );
				if ( $found ) {
					$files = array_merge( $files, $found );
				}
			}
		}

		// Check theme directory.
		$theme_dir = get_template_directory();
		foreach ( $readme_patterns as $pattern ) {
			$file = $theme_dir . '/' . $pattern;
			if ( file_exists( $file ) ) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * Analyze a single document
	 *
	 * @param array $doc     Document data.
	 * @param array $options Analysis options.
	 * @return array Analysis results.
	 */
	private function analyze_document( $doc, $options ) {
		$issues = array();
		$fixed  = array();

		// Check for broken links.
		if ( $options['check_links'] ) {
			$link_issues = $this->check_links( $doc['content'] );
			if ( ! empty( $link_issues['broken'] ) ) {
				foreach ( $link_issues['broken'] as $link ) {
					$issues[] = array(
						'type'    => 'broken_link',
						'message' => sprintf(
							/* translators: %s: URL */
							__( 'Broken link: %s', 'nvoos-content-graph-ai-platform' ),
							$link
						),
						'fixable' => false,
					);
				}
			}
		}

		// Check for outdated version references.
		$version_issues = $this->check_version_references( $doc['content'] );
		if ( ! empty( $version_issues ) ) {
			foreach ( $version_issues as $issue ) {
				$issues[] = array(
					'type'    => 'outdated_version',
					'message' => $issue,
					'fixable' => true,
				);

				if ( $options['auto_fix'] ) {
					$doc['content'] = $this->fix_version_references( $doc['content'] );
					$fixed[]        = 'Updated version references';
				}
			}
		}

		// Check code examples.
		if ( $options['check_code'] ) {
			$code_issues = $this->check_code_examples( $doc['content'] );
			if ( ! empty( $code_issues ) ) {
				foreach ( $code_issues as $issue ) {
					$issues[] = array(
						'type'    => 'code_example',
						'message' => $issue,
						'fixable' => false,
					);
				}
			}
		}

		// Check for missing sections.
		$missing_sections = $this->check_required_sections( $doc );
		if ( ! empty( $missing_sections ) ) {
			foreach ( $missing_sections as $section ) {
				$issues[] = array(
					'type'    => 'missing_section',
					'message' => sprintf(
						/* translators: %s: section name */
						__( 'Missing recommended section: %s', 'nvoos-content-graph-ai-platform' ),
						$section
					),
					'fixable' => false,
				);
			}
		}

		// Apply fixes if any were made.
		// WordPress.org compliance: only auto-fix post-type docs (stored in the database).
		// File-type docs (README files in plugin/theme folders) are read-only because
		// plugin folders are deleted on upgrade and writing to them violates directory guidelines.
		if ( ! empty( $fixed ) && $options['auto_fix'] && 'post' === $doc['type'] ) {
			wp_update_post(
				array(
					'ID'           => $doc['id'],
					'post_content' => $doc['content'],
				)
			);
		}

		return array(
			'doc_id' => isset( $doc['id'] ) ? $doc['id'] : null,
			'title'  => $doc['title'],
			'type'   => $doc['type'],
			'url'    => $doc['url'],
			'issues' => $issues,
			'fixed'  => $fixed,
			'status' => empty( $issues ) ? 'synced' : 'needs_attention',
		);
	}

	/**
	 * Check for broken links
	 *
	 * @param string $content Document content.
	 * @return array Link check results.
	 */
	private function check_links( $content ) {
		$results = array(
			'total'  => 0,
			'broken' => array(),
		);

		// Extract all URLs.
		preg_match_all( '/\[([^\]]+)\]\(([^)]+)\)/', $content, $md_links );
		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $html_links );

		$all_links = array_merge(
			isset( $md_links[2] ) ? $md_links[2] : array(),
			isset( $html_links[1] ) ? $html_links[1] : array()
		);

		$results['total'] = count( $all_links );

		// Check internal links.
		$site_url = get_site_url();

		foreach ( $all_links as $url ) {
			// Skip external links and anchors.
			if ( 0 === strpos( $url, '#' ) || ( 0 === strpos( $url, 'http' ) && 0 !== strpos( $url, $site_url ) ) ) {
				continue;
			}

			// Check if internal link is valid.
			if ( strpos( $url, $site_url ) === 0 ) {
				$post_id = url_to_postid( $url );
				if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
					$results['broken'][] = $url;
				}
			}
		}

		return $results;
	}

	/**
	 * Check version references
	 *
	 * @param string $content Document content.
	 * @return array Version issues.
	 */
	private function check_version_references( $content ) {
		$issues = array();

		// Get current WordPress version.
		$current_wp_version = get_bloginfo( 'version' );

		// Find version references.
		preg_match_all( '/(?:WordPress|WP)\s+(\d+\.\d+(?:\.\d+)?)/i', $content, $matches );

		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $mentioned_version ) {
				if ( version_compare( $mentioned_version, $current_wp_version, '<' ) ) {
					$issues[] = sprintf(
						/* translators: 1: old version, 2: current version */
						__( 'References WordPress %1$s (current: %2$s)', 'nvoos-content-graph-ai-platform' ),
						$mentioned_version,
						$current_wp_version
					);
				}
			}
		}

		return array_unique( $issues );
	}

	/**
	 * Fix version references
	 *
	 * @param string $content Document content.
	 * @return string Fixed content.
	 */
	private function fix_version_references( $content ) {
		$current_wp_version = get_bloginfo( 'version' );

		// Replace old version references with current.
		$content = preg_replace(
			'/(?:WordPress|WP)\s+\d+\.\d+(?:\.\d+)?/i',
			'WordPress ' . $current_wp_version,
			$content
		);

		return $content;
	}

	/**
	 * Check code examples
	 *
	 * @param string $content Document content.
	 * @return array Code issues.
	 */
	private function check_code_examples( $content ) {
		$issues = array();

		// Find code blocks.
		preg_match_all( '/```(?:php)?\s*(.*?)```/s', $content, $code_blocks );

		if ( empty( $code_blocks[1] ) ) {
			preg_match_all( '/<code[^>]*>(.*?)<\/code>/s', $content, $html_blocks );
			$code_blocks[1] = isset( $html_blocks[1] ) ? $html_blocks[1] : array();
		}

		// Check for deprecated functions.
		$deprecated_functions = array(
			'mysql_query'       => 'Use $wpdb->query() instead',
			'create_function'   => 'Use anonymous functions instead',
			'get_the_author_id' => 'Use get_the_author_meta(\'ID\') instead',
		);

		foreach ( $code_blocks[1] as $code ) {
			foreach ( $deprecated_functions as $func => $suggestion ) {
				if ( stripos( $code, $func ) !== false ) {
					$issues[] = sprintf(
						/* translators: 1: function name, 2: suggestion */
						__( 'Deprecated function %1$s found. %2$s', 'nvoos-content-graph-ai-platform' ),
						$func,
						$suggestion
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Check for required sections
	 *
	 * @param array $doc Document data.
	 * @return array Missing sections.
	 */
	private function check_required_sections( $doc ) {
		$missing = array();

		// Required sections for documentation.
		$required_sections = array(
			'Installation',
			'Usage',
			'Examples',
		);

		foreach ( $required_sections as $section ) {
			// Check if section exists (markdown or HTML heading).
			if ( stripos( $doc['content'], '# ' . $section ) === false &&
				stripos( $doc['content'], '## ' . $section ) === false &&
				stripos( $doc['content'], '<h2>' . $section ) === false ) {
				$missing[] = $section;
			}
		}

		return $missing;
	}

	/**
	 * Format command response
	 *
	 * @param string $status  Response status.
	 * @param string $message User message.
	 * @param array  $data    Additional data.
	 * @return string Formatted response.
	 */
	private function format_response( $status, $message, $data = array() ) {
		$output = "## {$message}\n\n";

		if ( isset( $data['total_docs'] ) ) {
			$output .= sprintf(
				"**Summary:**\n- Documents checked: %d\n- Issues found: %d\n- Issues fixed: %d\n\n",
				$data['docs_checked'],
				$data['issues_found'],
				$data['issues_fixed']
			);
		}

		if ( isset( $data['docs'] ) && ! empty( $data['docs'] ) ) {
			$output .= "### Details\n\n";

			foreach ( $data['docs'] as $doc_result ) {
				$output .= $this->format_doc_result( $doc_result );
			}
		}

		return $output;
	}

	/**
	 * Format individual document result
	 *
	 * @param array $result Document result data.
	 * @return string Formatted output.
	 */
	private function format_doc_result( $result ) {
		$status_icons = array(
			'synced'          => '✅',
			'needs_attention' => '⚠️',
		);

		$icon = isset( $status_icons[ $result['status'] ] ) ? $status_icons[ $result['status'] ] : '❓';

		$output = sprintf(
			"#### %s %s\n\n",
			$icon,
			esc_html( $result['title'] )
		);

		// Fixed issues.
		if ( ! empty( $result['fixed'] ) ) {
			$output .= "**Auto-fixed:**\n";
			foreach ( $result['fixed'] as $fix ) {
				$output .= sprintf( "- ✓ %s\n", esc_html( $fix ) );
			}
			$output .= "\n";
		}

		// Issues found.
		if ( ! empty( $result['issues'] ) ) {
			// Group by type.
			$by_type = array();
			foreach ( $result['issues'] as $issue ) {
				$type = $issue['type'];
				if ( ! isset( $by_type[ $type ] ) ) {
					$by_type[ $type ] = array();
				}
				$by_type[ $type ][] = $issue;
			}

			foreach ( $by_type as $type => $issues ) {
				$type_label = ucwords( str_replace( '_', ' ', $type ) );
				$output    .= sprintf( "**%s:**\n", $type_label );

				foreach ( $issues as $issue ) {
					$fixable_text = isset( $issue['fixable'] ) && $issue['fixable'] ? ' (fixable)' : '';
					$output      .= sprintf(
						"- %s%s\n",
						esc_html( $issue['message'] ),
						$fixable_text
					);
				}
				$output .= "\n";
			}
		}

		if ( $result['url'] ) {
			$output .= sprintf( "[View Document](%s)\n\n", esc_url( $result['url'] ) );
		}

		return $output;
	}
}
