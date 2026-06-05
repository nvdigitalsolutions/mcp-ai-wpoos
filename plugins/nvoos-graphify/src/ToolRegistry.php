<?php
/**
 * Tool registry — container for tool instances.
 *
 * Addons register their tools via the `nvoos_graphify/register_tools` action.
 * Built-in tools are registered by the Plugin composition root.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify;

use NvoosGraphify\Contracts\Tool;

/**
 * Tool registry.
 *
 * @since 1.0.0
 */
final class ToolRegistry
{
    /**
     * Registered tools, keyed by slug.
     *
     * @var array<string,Tool>
     */
    private array $tools = array();

    /**
     * Register a tool.
     *
     * @since 1.0.0
     * @param Tool $tool The tool instance.
     * @return void
     */
    public function register( Tool $tool ): void
    {
        $this->tools[ $tool->getSlug() ] = $tool;
    }

    /**
     * Get a tool by slug.
     *
     * @since 1.0.0
     * @param string $slug Tool slug.
     * @return Tool|null The tool, or null if not found.
     */
    public function get( string $slug ): ?Tool
    {
        return $this->tools[ $slug ] ?? null;
    }

    /**
     * Get all registered tools.
     *
     * @since 1.0.0
     * @return array<string,Tool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Check if a tool is registered.
     *
     * @since 1.0.0
     * @param string $slug Tool slug.
     * @return bool
     */
    public function has( string $slug ): bool
    {
        return isset( $this->tools[ $slug ] );
    }

    /**
     * Get the number of registered tools.
     *
     * @since 1.0.0
     * @return int
     */
    public function count(): int
    {
        return count( $this->tools );
    }
}
