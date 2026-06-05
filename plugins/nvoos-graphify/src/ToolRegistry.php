<?php
declare(strict_types=1);

namespace NvoosGraphify;

use NvoosGraphify\Contracts\Tool;

/**
 * Tool registry — a container for registered tool instances.
 *
 * Consumer addons retrieve the registry via
 * {@see nvoos_graphify_get_tool_registry()} and call {@see register()}
 * during the `nvoos_graphify/register_tools` action.
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
     * Register a tool instance.
     *
     * If a tool with the same slug is already registered,
     * it is silently replaced (last-registered wins).
     *
     * @param Tool $tool The tool instance to register.
     * @return void
     */
    public function register( Tool $tool ): void
    {
        $this->tools[ $tool->getSlug() ] = $tool;
    }

    /**
     * Retrieve a tool by its slug.
     *
     * @param string $slug The tool slug.
     * @return Tool|null The tool instance, or null if not found.
     */
    public function get( string $slug ): ?Tool
    {
        return $this->tools[ $slug ] ?? null;
    }

    /**
     * Return all registered tools.
     *
     * @return array<string,Tool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Check whether a tool with the given slug is registered.
     *
     * @param string $slug The tool slug.
     * @return bool
     */
    public function has( string $slug ): bool
    {
        return isset( $this->tools[ $slug ] );
    }

    /**
     * Return the total count of registered tools.
     *
     * @return int
     */
    public function count(): int
    {
        return count( $this->tools );
    }
}
