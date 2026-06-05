<?php
/**
 * Composition root for the NV oOS Graphify plugin.
 *
 * Wires all services, registers WordPress hooks, and exposes
 * singletons for consumer addons via public API functions.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify;

/**
 * Plugin composition root.
 *
 * @since 1.0.0
 */
final class Plugin
{
    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Tool registry.
     *
     * @var ToolRegistry
     */
    private ToolRegistry $toolRegistry;

    /**
     * Private constructor — use Plugin::instance().
     */
    private function __construct()
    {
        $this->toolRegistry = new ToolRegistry();
    }

    /**
     * Get the singleton instance.
     *
     * @since 1.0.0
     * @return self
     */
    public static function instance(): self
    {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register all WordPress hooks and bootstrap the plugin.
     *
     * Called on `plugins_loaded` by the bootstrap.
     *
     * @since 1.0.0
     * @return void
     */
    public function register(): void
    {
        // Fire hook for addons to register their tools.
        add_action( 'plugins_loaded', function (): void {
            /**
             * Fires when NV oOS Graphify is ready for tool registration.
             *
             * Consumer addons hook into this to register their tools.
             *
             * @since 1.0.0
             * @param ToolRegistry $registry The tool registry instance.
             */
            do_action( Schema::ACTION_REGISTER_TOOLS, $this->toolRegistry );
        }, 20 );

        // TODO: Wire admin, REST, frontend, cron, and built-in tools
        // as classes are migrated from addons/graphify/includes/.
    }

    /**
     * Get the tool registry.
     *
     * @since 1.0.0
     * @return ToolRegistry
     */
    public function getToolRegistry(): ToolRegistry
    {
        return $this->toolRegistry;
    }
}
