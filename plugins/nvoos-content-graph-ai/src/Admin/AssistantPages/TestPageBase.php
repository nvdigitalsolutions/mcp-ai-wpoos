<?php
/**
 * Base class for ecosystem admin test pages (Wave D-UI-4).
 *
 * Aligned port of the base plugin's admin test-page base
 * (`includes/admin/class-wp-mcp-ai-admin-test-page-base.php`): the same
 * submenu registration under a post-type menu, the same
 * `manage_options` capability gate, and the same enqueue dispatch
 * (page-specific assets only — the ecosystem chat widget ships its own
 * bundle via `[nvoos_content_graph_chat]`).
 *
 * @package NvoosContentGraphAi\Admin\AssistantPages
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\AssistantPages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for admin test pages.
 *
 * @since 1.1.0
 */
abstract class TestPageBase {

	/**
	 * Page hook suffix.
	 *
	 * @var string|false
	 */
	protected $page_hook;

	/**
	 * Get the post type for this test page.
	 *
	 * @return string
	 */
	abstract protected function get_post_type();

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	abstract protected function get_page_slug();

	/**
	 * Get the page title.
	 *
	 * @return string
	 */
	abstract protected function get_page_title();

	/**
	 * Get the menu title.
	 *
	 * @return string
	 */
	abstract protected function get_menu_title();

	/**
	 * Render the page content.
	 *
	 * @return void
	 */
	abstract public function render_page(): void;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the submenu page.
	 *
	 * @return void
	 */
	public function register_submenu_page(): void {
		$post_type = $this->get_post_type();

		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . $post_type,
			$this->get_page_title(),
			$this->get_menu_title(),
			'manage_options',
			$this->get_page_slug(),
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the test page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		$this->enqueue_page_assets();
	}

	/**
	 * Enqueue page-specific assets.
	 *
	 * Override in child classes to add page-specific JS/CSS.
	 *
	 * @return void
	 */
	protected function enqueue_page_assets(): void {
		// Override in child classes if needed.
	}

	/**
	 * Check if current user has permission to view this page.
	 *
	 * @return bool
	 */
	protected function check_permission(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-content-graph-ai' ) );
		}

		return true;
	}
}
