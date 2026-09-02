<?php
/**
 * Assistant admin pages hub for the Content Graph AI addon (Wave D-UI-4).
 *
 * Wires the ported assistant builder pages (Build Assistant, Create
 * Assistant) into the assistant CPT's admin menu and registers their
 * AJAX create actions. Registered standalone-only by `Plugin.php` —
 * the base plugin owns the same admin surface in monolith installs and
 * registering here too would duplicate the menus.
 *
 * @package NvoosContentGraphAi\Admin
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Admin;

use NvoosContentGraphAi\Admin\AssistantPages\AddAssistantPage;
use NvoosContentGraphAi\Admin\AssistantPages\BuildAssistantPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the assistant admin pages and their AJAX handlers.
 *
 * @since 1.1.0
 */
class AssistantPages {

	/**
	 * Build Assistant page instance.
	 *
	 * @var BuildAssistantPage|null
	 */
	protected $build;

	/**
	 * Add Assistant page instance.
	 *
	 * @var AddAssistantPage|null
	 */
	protected $add;

	/**
	 * Register the pages and AJAX handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_' . BuildAssistantPage::CREATE_ACTION, array( $this, 'handle_ajax_create_assistant' ) );
		add_action( 'wp_ajax_' . AddAssistantPage::CREATE_ACTION, array( $this, 'handle_ajax_create_from_professional' ) );
	}

	/**
	 * Register the submenu pages under the assistant CPT menu.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		$this->build = new BuildAssistantPage();
		$this->build->register_page();

		$this->add = new AddAssistantPage();
		$this->add->register_page();
	}

	/**
	 * Dispatch asset enqueueing to the active page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ): void {
		if ( $this->build instanceof BuildAssistantPage ) {
			$this->build->enqueue_scripts( $hook );
		}

		if ( $this->add instanceof AddAssistantPage ) {
			$this->add->enqueue_scripts( $hook );
		}
	}

	/**
	 * Route the manual create AJAX request to the Build page.
	 *
	 * @return void
	 */
	public function handle_ajax_create_assistant(): void {
		if ( ! $this->build instanceof BuildAssistantPage ) {
			$this->build = new BuildAssistantPage();
		}

		$this->build->handle_ajax_create();
	}

	/**
	 * Route the create-from-template AJAX request to the Add page.
	 *
	 * @return void
	 */
	public function handle_ajax_create_from_professional(): void {
		if ( ! $this->add instanceof AddAssistantPage ) {
			$this->add = new AddAssistantPage();
		}

		$this->add->handle_ajax_create();
	}
}
