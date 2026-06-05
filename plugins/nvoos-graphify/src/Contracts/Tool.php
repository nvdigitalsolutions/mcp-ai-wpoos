<?php
/**
 * Tool contract interface.
 *
 * Every tool (built-in or addon-registered) must implement this interface.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Contracts;

/**
 * Defines the contract for a graphify tool.
 *
 * @since 1.0.0
 */
interface Tool
{
    /**
     * Get the unique slug for this tool.
     *
     * @since 1.0.0
     * @return string Unique tool identifier (e.g. 'nvoos_graphify_get_node').
     */
    public function getSlug(): string;

    /**
     * Get the human-readable name of the tool.
     *
     * @since 1.0.0
     * @return string
     */
    public function getName(): string;

    /**
     * Get the tool description.
     *
     * @since 1.0.0
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get the JSON Schema for the tool's parameters.
     *
     * @since 1.0.0
     * @return array<string,mixed>
     */
    public function getParametersSchema(): array;

    /**
     * Get the WordPress capability required to execute this tool.
     *
     * @since 1.0.0
     * @return string
     */
    public function getRequiredCapability(): string;

    /**
     * Get capability flags for this tool.
     *
     * @since 1.0.0
     * @return array<string,bool>
     */
    public function getCapabilityFlags(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @since 1.0.0
     * @param array<string,mixed> $arguments Sanitized tool arguments.
     * @return array<string,mixed>|null Canonical envelope (success array or null on failure).
     */
    public function execute( array $arguments ): ?array;
}
