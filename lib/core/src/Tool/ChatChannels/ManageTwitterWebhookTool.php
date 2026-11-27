<?php

/**
 * Register, delete, subscribe to, or list Twitter/X Account Activity webhooks.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Manage_Twitter_Webhook` (Proposal 164 section
 * 164.107). Four actions over the Account Activity API.
 *
 * ## Correction: there is no v2 webhook route
 *
 * WordPress chose its endpoint from `env_name`:
 *
 * ```
 * '' !== $env_name ? /1.1/account_activity/all/{env}/webhooks.json : /2/webhooks
 * ```
 *
 * The second branch does not exist. Webhook management is the Account Activity
 * API, which X documents only under v1.1 and only per environment
 * (`/1.1/account_activity/all/:env_name/webhooks.json`, `.../webhooks/:id.json`,
 * `.../subscriptions.json`); there is no `/2/webhooks` in the v2 reference. So
 * every WordPress call with the default empty `env_name` — which is the
 * documented default — asked for a route that 404s. The v2 surface is the DM
 * endpoints only.
 *
 * The port therefore requires `env_name`. A caller still needs a paid Account
 * Activity subscription to have an environment at all; that prerequisite is
 * named in the description rather than discovered from a 404.
 *
 * ## Correction: `subscribe` does not need the webhook id
 *
 * WordPress required `webhook_id` for `subscribe` and then did not use it on
 * the Premium route: `POST /1.1/account_activity/all/{env}/subscriptions.json`
 * subscribes the user whose tokens sign the request. The argument is not
 * required here, and passing one is not an error — some callers send it from a
 * previous `register` result.
 *
 * ## Correction: the webhook URL must be publicly resolvable
 *
 * WordPress used `esc_url_raw()` with the https scheme, which accepts
 * `https://127.0.0.1/hook` and `https://user:pass@host/hook`. X sends a CRC
 * challenge to that URL, so a private or credentialed URL is both useless and a
 * way to make the host issue an authenticated request inward. `publicUrl()`
 * refuses it.
 *
 * ## Adaptation
 *
 * The `list` action flattens the response. The Account Activity API answers
 * with `environments[] -> webhooks[]` on some routes and with a bare webhook
 * object on others; the projection returns one `webhooks` list either way.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

use Nvoos\Core\Support\Json;

class ManageTwitterWebhookTool extends TwitterTool
{
    /**
     * The actions, in the order the description lists them.
     *
     * @var list<string>
     */
    private const ACTIONS = array('register', 'delete', 'subscribe', 'list');

    public function getSlug(): string
    {
        return 'manage_twitter_webhook';
    }

    public function getName(): string
    {
        return 'Manage Twitter/X Webhook';
    }

    public function getDescription(): string
    {
        return 'Registers a webhook URL with the Twitter/X Account Activity API, deletes one, subscribes the token holder to account activity, or lists the registered webhooks. Requires a paid Account Activity environment; the environment name is required.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action'              => array(
                    'type'        => 'string',
                    'description' => 'register adds a URL, delete removes one, subscribe attaches the token holder to account activity, list reads the registered webhooks.',
                    'enum'        => self::ACTIONS,
                    'default'     => 'register',
                ),
                'env_name'            => array(
                    'type'        => 'string',
                    'description' => 'Account Activity environment name, as created in the developer portal.',
                    'pattern'     => '^[A-Za-z0-9_-]{1,32}$',
                ),
                'url'                 => array(
                    'type'        => 'string',
                    'description' => 'Public https URL that answers the CRC challenge. Required for register.',
                ),
                'webhook_id'          => array(
                    'type'        => 'string',
                    'description' => 'Webhook id from register or list. Required for delete.',
                    'pattern'     => '^[0-9]{1,32}$',
                ),
                'api_key'             => array(
                    'type'        => 'string',
                    'description' => 'OAuth 1.0a consumer key.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'api_secret'          => array(
                    'type'        => 'string',
                    'description' => 'OAuth 1.0a consumer secret.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'access_token'        => array(
                    'type'        => 'string',
                    'description' => 'OAuth 1.0a access token of the account that owns the webhook.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'access_token_secret' => array(
                    'type'        => 'string',
                    'description' => 'OAuth 1.0a access token secret.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('action', 'env_name', 'api_key', 'api_secret', 'access_token', 'access_token_secret'),
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

        $action = $this->textArg($arguments, 'action');

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->errors->validationFailed(
                'The action must be register, delete, subscribe or list.',
                array('action' => array('Unsupported action.'))
            );
        }

        $environment = $this->textArg($arguments, 'env_name');

        if (1 !== preg_match('/^[A-Za-z0-9_-]{1,32}$/', $environment)) {
            return $this->errors->validationFailed(
                'env_name is required and may contain only letters, digits, underscores and hyphens.',
                array('env_name' => array('Not an environment name.'))
            );
        }

        $credentials = $this->oauth1($arguments);

        if ($this->errors->isError($credentials) || ! is_array($credentials)) {
            return $credentials;
        }

        $route = self::API . '/1.1/account_activity/all/' . rawurlencode($environment);

        if ('register' === $action) {
            return $this->register($arguments, $environment, $route, $credentials);
        }

        if ('delete' === $action) {
            return $this->delete($arguments, $environment, $route, $credentials);
        }

        if ('subscribe' === $action) {
            return $this->subscribe($route, $environment, $credentials);
        }

        return $this->listWebhooks($route, $environment, $credentials);
    }

    /**
     * @param array{api_key: string, api_secret: string, access_token: string, access_token_secret: string} $credentials Credentials.
     */
    private function register(array $arguments, string $environment, string $route, array $credentials): mixed
    {
        $url = $this->publicUrl($arguments, 'url', 'webhook URL');

        if (! is_string($url)) {
            return $url;
        }

        $body = Json::encodeOr(array('url' => $url), '');

        if ('' === $body) {
            return $this->errors->validationFailed(
                'The webhook URL is not valid UTF-8.',
                array('url' => array('Not encodable.'))
            );
        }

        try {
            $payload = $this->signed(
                'POST',
                $route . '/webhooks.json',
                $credentials,
                $body
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Twitter/X webhook registered.',
            array(
                'action'      => 'register',
                'environment' => $environment,
                'webhook'     => $this->webhook($payload),
            )
        );
    }

    /**
     * @param array{api_key: string, api_secret: string, access_token: string, access_token_secret: string} $credentials Credentials.
     */
    private function delete(array $arguments, string $environment, string $route, array $credentials): mixed
    {
        $id = $this->textArg($arguments, 'webhook_id');

        if (1 !== preg_match('/^[0-9]{1,32}$/', $id)) {
            return $this->errors->validationFailed(
                'webhook_id is required to delete a webhook.',
                array('webhook_id' => array('Not a webhook id.'))
            );
        }

        try {
            $this->signed(
                'DELETE',
                $route . '/webhooks/' . rawurlencode($id) . '.json',
                $credentials
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Twitter/X webhook deleted.',
            array(
                'action'      => 'delete',
                'environment' => $environment,
                'webhook_id'  => $id,
                'deleted'     => true,
            )
        );
    }

    /**
     * @param array{api_key: string, api_secret: string, access_token: string, access_token_secret: string} $credentials Credentials.
     */
    private function subscribe(string $route, string $environment, array $credentials): mixed
    {
        try {
            $this->signed('POST', $route . '/subscriptions.json', $credentials);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'The token holder is subscribed to account activity for this environment.',
            array(
                'action'      => 'subscribe',
                'environment' => $environment,
                'subscribed'  => true,
            )
        );
    }

    /**
     * @param array{api_key: string, api_secret: string, access_token: string, access_token_secret: string} $credentials Credentials.
     */
    private function listWebhooks(string $route, string $environment, array $credentials): mixed
    {
        try {
            $payload = $this->signed('GET', $route . '/webhooks.json', $credentials);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $webhooks = array();

        foreach ($this->webhookContainers($payload) as $row) {
            $webhooks[] = $this->webhook($row);
        }

        return $this->success(
            sprintf('Retrieved %d Twitter/X webhook(s).', count($webhooks)),
            array(
                'action'      => 'list',
                'environment' => $environment,
                'webhooks'    => $webhooks,
                'count'       => count($webhooks),
            )
        );
    }

    /**
     * The webhook objects in a v1.1 payload.
     *
     * Three shapes answer this route: `environments[] -> webhooks[]` for the
     * environment listing, a bare list of webhook objects, and a single webhook
     * object of the login route. Each is reduced to the same list.
     *
     * @param array<array-key, mixed> $payload Decoded body.
     *
     * @return list<array<array-key, mixed>>
     */
    private function webhookContainers(array $payload): array
    {
        if (is_array($payload['environments'] ?? null)) {
            $rows = array();

            foreach ($payload['environments'] as $environment) {
                if (! is_array($environment) || ! is_array($environment['webhooks'] ?? null)) {
                    continue;
                }

                foreach ($environment['webhooks'] as $webhook) {
                    if (is_array($webhook)) {
                        $rows[] = $webhook;
                    }
                }
            }

            return $rows;
        }

        if (is_array($payload['webhooks'] ?? null)) {
            return array_values(array_filter($payload['webhooks'], 'is_array'));
        }

        if (isset($payload['id']) || isset($payload['url'])) {
            return array($payload);
        }

        return array();
    }

    /**
     * One webhook, projected.
     *
     * @param array<array-key, mixed> $webhook Decoded webhook object.
     *
     * @return array<string, mixed>
     */
    private function webhook(array $webhook): array
    {
        $valid = $webhook['valid'] ?? null;

        return array(
            'id'         => $this->scalarText($webhook['id'] ?? null),
            'url'        => $this->scalarText($webhook['url'] ?? null),
            'valid'      => is_bool($valid) ? $valid : null,
            'created_at' => $this->scalarText($webhook['created_timestamp'] ?? null),
        );
    }
}
