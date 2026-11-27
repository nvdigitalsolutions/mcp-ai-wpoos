<?php

/**
 * Register, remove or list a Telegram bot's slash commands.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Manage_Telegram_Commands` (Proposal 164 section
 * 164.102). Three actions over `setMyCommands`, `deleteMyCommands` and
 * `getMyCommands`, all scoped by Telegram's `BotCommandScope`.
 *
 * ## Corrections
 *
 * 1. **A chat-level scope without its id is refused.** WordPress built
 *    `{"type":"chat","chat_id":""}` and `{"type":"chat_member","user_id":0}`
 *    from missing arguments, so the request reached Telegram malformed instead
 *    of failing where the mistake was.
 * 2. **A command name is lowercased and then validated, not silently
 *    rewritten.** WordPress stripped every character outside `[a-zA-Z0-9_]`,
 *    so `hello-world` became `helloworld` and the caller could not tell which
 *    name was registered. Only case is normalised here.
 * 3. **An over-long description is refused.** WordPress truncated with
 *    byte-based `substr()`, which can cut a multi-byte character in half.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class ManageTelegramCommandsTool extends TelegramTool
{
    /**
     * The `BotCommandScope` types Telegram accepts.
     *
     * @var list<string>
     */
    private const SCOPES = array(
        'default',
        'all_private_chats',
        'all_group_chats',
        'all_chat_administrators',
        'chat',
        'chat_administrators',
        'chat_member',
    );

    /**
     * The scopes that name a chat.
     *
     * @var list<string>
     */
    private const CHAT_SCOPES = array('chat', 'chat_administrators', 'chat_member');

    public function getSlug(): string
    {
        return 'manage_telegram_commands';
    }

    public function getName(): string
    {
        return 'Manage Telegram Commands';
    }

    public function getDescription(): string
    {
        return 'Register, delete or list the bot slash commands for a Telegram scope (default, all private chats, all group chats, administrators, one chat or one member). Command names are lowercase letters, digits and underscores.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'         => array(
                    'type'        => 'string',
                    'description' => 'Telegram bot token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'action'        => array(
                    'type'        => 'string',
                    'description' => 'set to register commands, delete to remove them, get to list them.',
                    'enum'        => array('set', 'delete', 'get'),
                ),
                'scope'         => array(
                    'type'        => 'string',
                    'description' => 'BotCommandScope type. Defaults to default.',
                    'enum'        => array(
                        'default',
                        'all_private_chats',
                        'all_group_chats',
                        'all_chat_administrators',
                        'chat',
                        'chat_administrators',
                        'chat_member',
                    ),
                    'default'     => 'default',
                ),
                'chat_id'       => array(
                    'type'        => 'string',
                    'description' => 'Chat id, required for the chat, chat_administrators and chat_member scopes.',
                ),
                'user_id'       => array(
                    'type'        => 'integer',
                    'description' => 'User id, required for the chat_member scope.',
                    'minimum'     => 1,
                ),
                'language_code' => array(
                    'type'        => 'string',
                    'description' => 'Two-letter ISO 639-1 language code the commands apply to.',
                ),
                'commands'      => array(
                    'type'        => 'array',
                    'description' => 'Commands to register; required for set. Each entry needs command and description.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'command'     => array(
                                'type'        => 'string',
                                'description' => 'Command name without the slash.',
                            ),
                            'description' => array(
                                'type'        => 'string',
                                'description' => 'Short description shown in the command list.',
                            ),
                        ),
                        'required'   => array('command', 'description'),
                    ),
                ),
            ),
            'required'             => array('token', 'action'),
            'additionalProperties' => false,
        );
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'state-changing',
            'write',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->token($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $action = $this->textArg($arguments, 'action');

        if (! in_array($action, array('set', 'delete', 'get'), true)) {
            return $this->errors->validationFailed(
                'The action must be set, delete or get.',
                array('action' => array('Unsupported action.'))
            );
        }

        $scope = $this->textArg($arguments, 'scope');

        if ('' === $scope) {
            $scope = 'default';
        }

        if (! in_array($scope, self::SCOPES, true)) {
            return $this->errors->validationFailed(
                'Unsupported command scope.',
                array('scope' => array('Unsupported command scope.'))
            );
        }

        $chatId = $this->textArg($arguments, 'chat_id');
        $userId = array_key_exists('user_id', $arguments) && is_numeric($arguments['user_id'])
            ? (int) $arguments['user_id']
            : 0;

        if (in_array($scope, self::CHAT_SCOPES, true) && '' === $chatId) {
            return $this->errors->validationFailed(
                'A chat id is required for this scope.',
                array('chat_id' => array('A chat id is required for this scope.'))
            );
        }

        if ('chat_member' === $scope && $userId < 1) {
            return $this->errors->validationFailed(
                'A user id is required for the chat_member scope.',
                array('user_id' => array('A user id is required for the chat_member scope.'))
            );
        }

        $language = $this->textArg($arguments, 'language_code');

        $payload = array();

        if ('default' !== $scope) {
            $payload['scope'] = array('type' => $scope);

            if (in_array($scope, self::CHAT_SCOPES, true)) {
                $payload['scope']['chat_id'] = $chatId;
            }

            if ('chat_member' === $scope) {
                $payload['scope']['user_id'] = $userId;
            }
        }

        if ('' !== $language) {
            $payload['language_code'] = $language;
        }

        $report = array(
            'action'        => $action,
            'scope'         => $scope,
            'chat_id'       => in_array($scope, self::CHAT_SCOPES, true) ? $chatId : null,
            'user_id'       => 'chat_member' === $scope ? $userId : null,
            'language_code' => '' !== $language ? $language : null,
        );

        if ('set' === $action) {
            $commands = $this->commands($arguments);

            if ($this->errors->isError($commands)) {
                return $commands;
            }

            $payload['commands'] = $commands;

            try {
                $this->apiPost($token, 'setMyCommands', $payload);
            } catch (ChatChannelApiException $error) {
                return $this->failure($error);
            }

            return $this->success(
                sprintf('Registered %d Telegram command%s.', count($commands), 1 === count($commands) ? '' : 's'),
                $report + array(
                    'command_count' => count($commands),
                    'commands'      => $commands,
                )
            );
        }

        if ('delete' === $action) {
            try {
                $this->apiPost($token, 'deleteMyCommands', $payload);
            } catch (ChatChannelApiException $error) {
                return $this->failure($error);
            }

            return $this->success('Deleted the Telegram commands for this scope.', $report + array('deleted' => true));
        }

        try {
            $response = $this->apiPost($token, 'getMyCommands', $payload);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $rows   = array();
        $result = $this->result($response);

        foreach (is_array($result) ? $result : array() as $command) {
            if (! is_array($command)) {
                continue;
            }

            $rows[] = array(
                'command'     => $this->text($command['command'] ?? ''),
                'description' => $this->text($command['description'] ?? ''),
            );
        }

        return $this->success(
            sprintf('Found %d Telegram command%s.', count($rows), 1 === count($rows) ? '' : 's'),
            $report + array(
                'command_count' => count($rows),
                'commands'      => $rows,
            )
        );
    }

    /**
     * Validate and normalise the `commands` argument.
     *
     * Lowercasing is the only rewrite: a name the caller cannot predict is
     * refused rather than quietly changed.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<int, array<string, string>>|mixed The clean list, or the
     *                                                 validation error.
     */
    private function commands(array $arguments): mixed
    {
        $raw = $this->arrayParam($arguments, 'commands');

        if (array() === $raw) {
            return $this->errors->validationFailed(
                'A non-empty commands array is required for set.',
                array('commands' => array('At least one command is required.'))
            );
        }

        $clean = array();

        foreach ($raw as $index => $command) {
            if (! is_array($command)) {
                return $this->errors->validationFailed(
                    'Every command must be an object with command and description.',
                    array('commands' => array(sprintf('Entry %d is not an object.', (int) $index)))
                );
            }

            $name = strtolower(trim($this->text($command['command'] ?? '')));

            if (1 !== preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name)) {
                return $this->errors->validationFailed(
                    'A command name must start with a letter and contain only lowercase letters, digits and underscores, at most 32 characters.',
                    array('commands' => array(sprintf('Entry %d has an invalid command name.', (int) $index)))
                );
            }

            $description = $this->text($command['description'] ?? '');

            if ('' === $description || \mb_strlen($description) > 256) {
                return $this->errors->validationFailed(
                    'A command description is required and may not exceed 256 characters.',
                    array('commands' => array(sprintf('Entry %d has an invalid description.', (int) $index)))
                );
            }

            $clean[] = array(
                'command'     => $name,
                'description' => $description,
            );
        }

        return $clean;
    }
}
