<?php

namespace App\Support\SlashCommands;

/**
 * The slash commands this runtime serves (090-i).
 *
 * WordPress registers 24 base commands and nine Pro ones; most of them are
 * WordPress-development operations (`/ship`, `/clean-content`, `/sync-docs`,
 * `/markup-stats`) or Pro state mutations (`/schedule`, `/agent`, `/broadcast`)
 * with no meaning here. Reimplementing them would produce commands that parse
 * and then do nothing useful, which is worse than not listing them.
 *
 * This is therefore a **native subset**: every entry runs against a store this
 * runtime actually owns. The console's drawer and autocomplete read this list,
 * so an absent command is simply not offered.
 *
 * `capability` uses WordPress's vocabulary (see `App\Support\CapabilityMap`),
 * exactly as the WordPress commands do, so the catalogue filters the same way
 * on both runtimes.
 */
final class SlashCommandCatalogue
{
    public const CATEGORY_SYSTEM = 'System';
    public const CATEGORY_TOOLS = 'Tools';
    public const CATEGORY_ANALYTICS = 'Analytics';
    public const CATEGORY_WORKFLOWS = 'Workflows';

    /**
     * Command name => definition.
     *
     * `parameters` is documentation, not validation: the parser accepts any
     * flag, and each handler ignores what it does not use.
     */
    private const COMMANDS = [
        'help' => [
            'description' => 'List the commands this runtime supports, or describe one',
            'usage' => '/help [command]',
            'parameters' => ['command'],
            'category' => self::CATEGORY_SYSTEM,
            'capability' => 'edit_posts',
        ],
        'status' => [
            'description' => 'Runtime status: agents, approvals, queue, uptime and today\'s tokens',
            'usage' => '/status',
            'parameters' => [],
            'category' => self::CATEGORY_SYSTEM,
            'capability' => 'manage_options',
        ],
        'model' => [
            'description' => 'Show the configured default provider and model',
            'usage' => '/model',
            'parameters' => [],
            'category' => self::CATEGORY_SYSTEM,
            'capability' => 'manage_options',
        ],
        'jobs' => [
            'description' => 'Job counts by status, plus the most recent failures',
            'usage' => '/jobs',
            'parameters' => [],
            'category' => self::CATEGORY_SYSTEM,
            'capability' => 'manage_options',
        ],
        'tools' => [
            'description' => 'List the tools you can execute, optionally filtered',
            'usage' => '/tools [search]',
            'parameters' => ['search'],
            'category' => self::CATEGORY_TOOLS,
            'capability' => 'edit_posts',
        ],
        'cost' => [
            'description' => 'Token and cost totals for the recent window',
            'usage' => '/cost [--days=N]',
            'parameters' => ['--days'],
            'category' => self::CATEGORY_ANALYTICS,
            'capability' => 'manage_options',
        ],
        'workflow' => [
            'description' => 'List workflows, or show one by id',
            'usage' => '/workflow [--list | --show=ID]',
            'parameters' => ['--list', '--show'],
            'category' => self::CATEGORY_WORKFLOWS,
            'capability' => 'manage_options',
        ],
    ];

    /**
     * Every command definition, keyed by canonical name.
     *
     * @return array<string, array{description: string, usage: string, parameters: list<string>, category: string, capability: string}>
     */
    public static function all(): array
    {
        return self::COMMANDS;
    }

    /**
     * One command definition, or null when this runtime does not serve it.
     *
     * Lookup is case-insensitive because the parser preserves the case the user
     * typed; the canonical name is what the response reports.
     *
     * @return array{name: string, description: string, usage: string, parameters: list<string>, category: string, capability: string}|null
     */
    public static function find(string $name): ?array
    {
        $key = strtolower($name);

        if (! isset(self::COMMANDS[$key])) {
            return null;
        }

        return ['name' => $key] + self::COMMANDS[$key];
    }
}
