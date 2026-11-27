<?php

/**
 * Set or delete the Telegram bot webhook.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Manage_Telegram_Webhook` (Proposal 164 section
 * 164.102). Two actions over `setWebhook` and `deleteWebhook`.
 *
 * ## Corrections
 *
 * 1. **A chat-level secret is validated locally.** WordPress called a WordPress
 *    class (`WP_MCP_AI_Pro_Remote_Site_Manager`) for the check and fell back to
 *    the stored connection's secret when the argument was absent. The port
 *    validates the argument with the documented pattern and stores nothing: a
 *    credential belongs to the caller, not to a tool.
 * 2. **The webhook URL is validated as https with a host.** WordPress used
 *    `esc_url_raw()` with the https scheme, which is close but accepts a URL
 *    with no host in some shapes; the port requires scheme and host.
 *
 * ## Adaptations
 *
 * The automatic `setMyCommands` fan-out after a successful set is dropped. It
 * registered the WordPress plugin's own default command list, which lives in a
 * WordPress controller that has no core counterpart; register commands with
 * `manage_telegram_commands` instead.
 *
 * The fixed `allowed_updates` list is kept. A webhook that omits it does not
 * receive `chat_member` updates, and the WordPress tool asked for them
 * explicitly.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class ManageTelegramWebhookTool extends TelegramTool
{
    /**
     * The update types the WordPress tool subscribed to.
     *
     * @var list<string>
     */
    private const ALLOWED_UPDATES = array(
        'message',
        'edited_message',
        'channel_post',
        'edited_channel_post',
        'callback_query',
        'inline_query',
        'my_chat_member',
        'chat_member',
        'pre_checkout_query',
    );

    public function getSlug(): string
    {
        return 'manage_telegram_webhook';
    }

    public function getName(): string
    {
        return 'Manage Telegram Webhook';
    }

    public function getDescription(): string
    {
        return 'Set the https URL that receives this bot updates, or delete it. Setting a webhook stops get_telegram_updates from working until the webhook is deleted.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'           => array(
                    'type'        => 'string',
                    'description' => 'Telegram bot token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'action'          => array(
                    'type'        => 'string',
                    'description' => 'set to configure the webhook, delete to remove it.',
                    'enum'        => array('set', 'delete'),
                ),
                'url'             => array(
                    'type'        => 'string',
                    'description' => 'HTTPS URL that receives updates. Required for set.',
                ),
                'max_connections' => array(
                    'type'        => 'integer',
                    'description' => 'Simultaneous HTTPS connections Telegram may open, 1-100. Defaults to 40.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 40,
                ),
                'secret_token'    => array(
                    'type'        => 'string',
                    'description' => 'Secret Telegram echoes in the X-Telegram-Bot-Api-Secret-Token header, 1-256 characters from A-Z, a-z, 0-9, underscore and hyphen.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                    'maxLength'   => 256,
                    'pattern'     => '^[A-Za-z0-9_-]+$',
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

        if (! in_array($action, array('set', 'delete'), true)) {
            return $this->errors->validationFailed(
                'The action must be set or delete.',
                array('action' => array('Unsupported action.'))
            );
        }

        if ('delete' === $action) {
            try {
                $response = $this->apiPost($token, 'deleteWebhook', array());
            } catch (ChatChannelApiException $error) {
                return $this->failure($error);
            }

            return $this->success(
                'Telegram webhook deleted.',
                array(
                    'action'      => 'delete',
                    'description' => $this->text($response['description'] ?? ''),
                )
            );
        }

        $url   = $this->textArg($arguments, 'url');
        $parts = '' !== $url ? parse_url($url) : false;

        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host   = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if ('https' !== $scheme || '' === $host) {
            return $this->errors->validationFailed(
                'A webhook URL must be an absolute https URL.',
                array('url' => array('An absolute https URL is required.'))
            );
        }

        $connections = $this->limit($arguments['max_connections'] ?? null, 40, 100);
        $secret      = $this->credential($arguments, 'secret_token');

        if ('' !== $secret && 1 !== preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret)) {
            return $this->errors->validationFailed(
                'A secret token may contain only letters, digits, underscores and hyphens, up to 256 characters.',
                array('secret_token' => array('Invalid secret token.'))
            );
        }

        $payload = array(
            'url'             => $url,
            'max_connections' => $connections,
            'allowed_updates' => self::ALLOWED_UPDATES,
        );

        if ('' !== $secret) {
            $payload['secret_token'] = $secret;
        }

        try {
            $response = $this->apiPost($token, 'setWebhook', $payload);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Telegram webhook set to ' . $url . '.',
            array(
                'action'           => 'set',
                'url'              => $url,
                'max_connections'  => $connections,
                'secret_token_set' => '' !== $secret,
                'description'      => $this->text($response['description'] ?? ''),
            )
        );
    }
}
