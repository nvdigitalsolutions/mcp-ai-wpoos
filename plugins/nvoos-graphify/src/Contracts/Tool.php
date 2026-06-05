<?php
declare(strict_types=1);

namespace NvoosGraphify\Contracts;

/**
 * Contract for every tool registered with NV oOS Graphify.
 *
 * Tools are the primary extensibility mechanism. Addon plugins
 * implement this interface and register instances via the
 * {@see \NvoosGraphify\ToolRegistry} during the
 * `nvoos_graphify/register_tools` action.
 *
 * @since 1.0.0
 */
interface Tool
{
    /**
     * Return the unique tool slug identifier.
     *
     * By convention, slug is prefixed `nvoos_graphify_`.
     *
     * @return string
     */
    public function getSlug(): string;

    /**
     * Return the human-readable tool name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Return the human-readable tool description.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Return the JSON Schema describing the tool's input parameters.
     *
     * @return array<string,mixed>
     */
    public function getParametersSchema(): array;

    /**
     * Return the WordPress capability required to execute this tool.
     *
     * @return string
     */
    public function getRequiredCapability(): string;

    /**
     * Return capability flags describing the tool's behavior.
     *
     * Typical flags: 'read-only', 'cacheable', 'destructive'.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array;

    /**
     * Execute the tool with given arguments and context.
     *
     * Must return a canonical success envelope (array with 'success'
     * and tool-specific keys) or a WP_Error on failure.
     *
     * @param array<string,mixed> $arguments Validated tool arguments.
     * @param array<string,mixed> $context   Execution context (user, locale, etc.).
     * @return array<string,mixed>|\WP_Error
     */
    public function execute( array $arguments = array(), array $context = array() );
}
