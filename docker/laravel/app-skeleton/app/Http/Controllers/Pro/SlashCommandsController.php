<?php

namespace App\Http\Controllers\Pro;

use App\Support\SlashCommands\SlashCommandExecutor;
use App\Support\SlashCommands\SlashCommandFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvoos\Core\Domain\SlashCommand\SlashCommandParser;

/**
 * Slash commands (`/api/v1/pro/slash-commands`).
 *
 * Mirrors the WordPress contract exactly, because the console's drawer and
 * composer are shared:
 *
 *   GET  /pro/slash-commands          → { commands: [...], categories: [...] }
 *   POST /pro/slash-commands/execute  → { success, command, result, correlation_id }
 *
 * Errors deliberately use WordPress's flat shape (`{code, message, data}`)
 * rather than this app's `{success, error}` envelope. `ChatPage` reads a
 * top-level `message` to show why a command failed; without it the composer
 * would fall back to "HTTP 400" on Laravel and the real reason would be lost.
 *
 * What is served is a native subset — see {@see \App\Support\SlashCommands\SlashCommandCatalogue}.
 *
 * Every execution is audited in `nvoos_slash_command_audit`, the counterpart of
 * WordPress's `wp_mcp_ai_slash_command_audit`: command, user, status, duration,
 * correlation id, result and IP. A command that failed is still a row — the
 * audit answers "who tried what", not "what succeeded".
 */
class SlashCommandsController
{
    /**
     * GET /slash-commands — the catalogue this account may run.
     */
    public function index(Request $request, SlashCommandExecutor $executor): JsonResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $commands = [];

        foreach ($executor->available($request->user()) as $name => $definition) {
            if ('' !== $search
                && ! str_contains(mb_strtolower($name.' '.$definition['description']), $search)
            ) {
                continue;
            }

            $commands[] = [
                'command' => $name,
                'description' => $definition['description'],
                'usage' => $definition['usage'],
                'parameters' => $definition['parameters'],
                'category' => $definition['category'],
            ];
        }

        $categories = array_values(array_unique(array_column($commands, 'category')));
        sort($categories);

        return response()->json([
            'commands' => $commands,
            'categories' => $categories,
        ]);
    }

    /**
     * POST /slash-commands/execute — parse, run, audit.
     */
    public function execute(Request $request, SlashCommandExecutor $executor): JsonResponse
    {
        $parsed = SlashCommandParser::parse((string) $request->input('command', ''));
        $correlationId = 'slash_'.time().'_'.Str::lower(Str::random(8));

        if (null !== $parsed['error']) {
            return $this->fail(
                $parsed['error'],
                SlashCommandParser::ERROR_SYNTAX === $parsed['error']
                    ? 'Commands must start with /'
                    : 'Invalid command name',
            );
        }

        $started = microtime(true);

        try {
            $result = $executor->run($parsed, $request->user());
        } catch (SlashCommandFailed $failure) {
            $this->audit($parsed['command'], 'failed', $failure->getMessage(), $started, $correlationId, $request);

            return $this->fail($failure->errorCode, $failure->getMessage());
        }

        $this->audit($parsed['command'], 'completed', 'success', $started, $correlationId, $request);

        return response()->json([
            'success' => true,
            'command' => $parsed['command'],
            'result' => $result,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * Append one audit row.
     *
     * Guarded on the table so a runtime that has not migrated yet answers the
     * command instead of failing on the audit. The guard is narrow on purpose:
     * it hides a missing migration only until the next request, and
     * `RuntimeSchemaTest` pins that the table exists.
     */
    private function audit(
        string $command,
        string $status,
        string $result,
        float $started,
        string $correlationId,
        Request $request,
    ): void {
        if (! Schema::hasTable('nvoos_slash_command_audit')) {
            return;
        }

        DB::table('nvoos_slash_command_audit')->insert([
            'command' => mb_substr($command, 0, 255),
            'user_id' => (int) ($request->user()?->id ?? 0),
            'status' => $status,
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'correlation_id' => $correlationId,
            'result' => mb_substr($result, 0, 500),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * WordPress's flat error shape, so the console reads one error vocabulary.
     */
    private function fail(string $code, string $message): JsonResponse
    {
        return response()->json(
            [
                'code' => $code,
                'message' => $message,
                'data' => ['status' => 400],
            ],
            400,
        );
    }
}
