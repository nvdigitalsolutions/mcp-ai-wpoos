<?php
/**
 * Elementor widget hub for the Content Graph AI addon.
 *
 * Registers the ecosystem's chat-family Elementor widgets (Wave D-UI-3) —
 * `nvoos_cg_chat` and `nvoos_cg_chat_bubble` — together with their
 * Elementor panel category, when Elementor is active. The hub itself is
 * loadable and hook-safe without Elementor: it only wires the
 * `elementor/*` actions, and every callback re-checks for the Elementor
 * runtime before touching its APIs.
 *
 * Widget names are ecosystem-specific and never collide with the base
 * plugin's `wp_mcp_ai_*` Elementor widgets in monolith installs.
 *
 * @package NvoosContentGraphAi\Elementor
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the chat-family Elementor widgets and their category.
 *
 * @since 1.1.0
 */
class ElementorHub {

	/**
	 * Elementor panel category slug.
	 */
	const CATEGORY = 'nvoos-content-graph-ai';

	/**
	 * Register the hub (hooked to `elementor/widgets/register`).
	 *
	 * Safe to call in both install modes; the callbacks no-op when the
	 * Elementor runtime is absent.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
	}

	/**
	 * Register the Elementor panel category.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 * @return void
	 */
	public function register_category( $elements_manager ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'NV oOS Content Graph AI', 'nvoos-content-graph-ai' ),
				'icon'  => 'eicon-code',
			)
		);
	}

	/**
	 * Register the chat-family widgets.
	 *
	 * The widget class files bail out early when `\Elementor\Widget_Base`
	 * is unavailable, so `class_exists()` below doubles as the Elementor
	 * runtime guard. This method therefore no-ops cleanly without
	 * Elementor and is safe to fire on either registration hook.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		foreach ( $this->get_widgets() as $widget_class ) {
			if ( class_exists( $widget_class ) ) {
				$widgets_manager->register( new $widget_class() );
			}
		}
	}

	/**
	 * The widget classes owned by this hub.
	 *
	 * @return string[] Fully-qualified widget class names.
	 */
	public function get_widgets(): array {
		return array(
			ChatWidget::class,
			ChatBubbleWidget::class,
		);
	}
}
