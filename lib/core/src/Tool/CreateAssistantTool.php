<?php

/**
 * Tool: create_assistant — create a draft assistant, in the caller's estate.
 *
 * The REST controller and the `/admin` resource both write through
 * `App\Services\AssistantWriter`; this is the third transport onto that path,
 * through `AssistantWriteInterface`. A tool that saved the model directly would
 * re-implement estate assignment, per-estate slug uniqueness and the draft
 * default — the drift the writer exists to prevent.
 *
 * ## What is deliberately not offered
 *
 * - **`status`.** A created assistant is a draft. Publishing is an operator
 *   action; a model that can mint a live assistant can grant itself a capability
 *   in one call.
 * - **`is_default`.** Promoting an assistant to estate default clears the flag on
 *   every other assistant in the estate. That is an operator decision with a wide
 *   blast radius, not a tool argument.
 * - **`slug`.** The host derives and de-duplicates it per estate, because the
 *   uniqueness index is `(tenant_id, slug)`.
 * - **WordPress's `professions` / `regions` enums, knowledge-base attachments and
 *   `async`.** None has an equivalent here: professions are a validated
 *   catalogue (the REST transport checks them against it), attachments are a
 *   different feature, and there is no cron path for assistant creation.
 *
 * ## Defaults
 *
 * An omitted provider falls back to the site default, and an omitted model falls
 * back to the site default **only when the provider is also the default one** —
 * pairing the default model with a different provider is how a broken assistant
 * gets created silently. A supplied provider is checked against the router's
 * registered slugs when the host has one; a provider the runtime does not
 * register would fail at the assistant's first turn, which is a worse place to
 * learn it.
 *
 * ## Capability
 *
 * `edit_posts`. The app's `AssistantPolicy::create` allows any non-viewer estate
 * member, and the WordPress tool declares `edit_posts`; the tool declaration and
 * the policy agree.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\AssistantWriteInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class CreateAssistantTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const MAX_TOOLS = 100;

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly SettingsStoreInterface $settings,
        private readonly ?AssistantWriteInterface $assistants = null,
        private readonly ?ProviderRouter $providers = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'create_assistant';
    }

    public function getName(): string
    {
        return 'Create AI Assistant';
    }

    public function getDescription(): string
    {
        return 'Creates a new AI assistant in the current estate as a draft, with an optional system prompt, provider, model, temperature, token budget and tool list. Publishing and setting the estate default stay operator actions. Returns the new assistant id, slug and status.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'title'          => array(
                    'type'        => 'string',
                    'description' => 'The assistant\'s name.',
                    'minLength'   => 1,
                    'maxLength'   => 255,
                ),
                'description'    => array(
                    'type'        => 'string',
                    'description' => 'What the assistant is for. Stored as the assistant excerpt.',
                    'maxLength'   => 5000,
                ),
                'system_prompt'  => array(
                    'type'        => 'string',
                    'description' => 'The instructions the assistant runs under.',
                    'maxLength'   => 32000,
                ),
                'provider'       => array(
                    'type'        => 'string',
                    'description' => 'Provider slug (openai, gemini, ollama, …). Defaults to the site default.',
                    'maxLength'   => 40,
                ),
                'model'          => array(
                    'type'        => 'string',
                    'description' => 'Model id. Defaults to the site default when the provider is also the default.',
                    'maxLength'   => 120,
                ),
                'temperature'    => array(
                    'type'        => 'number',
                    'description' => 'Sampling temperature, 0 to 2.',
                    'minimum'     => 0,
                    'maximum'     => 2,
                ),
                'max_tokens'     => array(
                    'type'        => 'integer',
                    'description' => 'Response token budget, 1 to 200000.',
                    'minimum'     => 1,
                    'maximum'     => 200000,
                ),
                'tools'          => array(
                    'type'        => 'array',
                    'description' => 'Tool slugs the assistant may call. Omitted or empty means no tools — the least-privilege default.',
                    'items'       => array(
                        'type'      => 'string',
                        'maxLength' => 120,
                    ),
                    'maxItems'    => self::MAX_TOOLS,
                ),
            ),
            'required'             => array( 'title' ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getCapabilityFlags(): array
    {
        return array( 'write', 'state-changing', 'local-only', 'requires-capability' );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $title = \mb_substr($this->stringParam($arguments, 'title'), 0, 255);

        if ('' === $title) {
            return $this->errors->validationFailed(
                'A title is required.',
                array( 'title' => array( 'Required.' ) )
            );
        }

        $tools = $this->stringListParam($arguments, 'tools');
        $invalidTools = array();

        foreach ($tools as $tool) {
            if (1 !== \preg_match('/^[a-z0-9_]{1,120}$/', $tool)) {
                $invalidTools[] = $tool;
            }
        }

        if (array() !== $invalidTools) {
            return $this->errors->validationFailed(
                'Tool slugs must be lowercase snake_case.',
                array( 'tools' => $invalidTools )
            );
        }

        $provider = $this->resolveProvider($this->stringParam($arguments, 'provider'));

        if (\is_array($provider)) {
            return $provider;
        }

        $model = $this->resolveModel($this->stringParam($arguments, 'model'), $provider);

        if (null === $this->assistants) {
            // Honest answer rather than a draft that was never written.
            return $this->success(
                'This host does not accept assistant writes.',
                array( 'assistant' => array() )
            );
        }

        $draft = array( 'title' => $title );

        $description = \mb_substr($this->stringParam($arguments, 'description'), 0, 5000);
        if ('' !== $description) {
            $draft['description'] = $description;
        }

        $prompt = \mb_substr($this->stringParam($arguments, 'system_prompt'), 0, 32000);
        if ('' !== $prompt) {
            $draft['system_prompt'] = $prompt;
        }

        if ('' !== $provider) {
            $draft['provider'] = $provider;
        }

        if ('' !== $model) {
            $draft['model'] = $model;
        }

        if (\array_key_exists('temperature', $arguments)) {
            $draft['temperature'] = \max(0.0, \min(2.0, $this->floatParam($arguments, 'temperature', 0.7)));
        }

        if (\array_key_exists('max_tokens', $arguments)) {
            $draft['max_tokens'] = \max(1, \min(200000, $this->intParam($arguments, 'max_tokens', 0)));
        }

        $tools = \array_slice(\array_values(\array_unique($tools)), 0, self::MAX_TOOLS);
        if (array() !== $tools) {
            $draft['tools'] = $tools;
        }

        $result = $this->assistants->create($draft);

        if (empty($result['success'])) {
            return $this->errors->create(
                'assistant_create_failed',
                (string) ( $result['error'] ?? 'The assistant could not be created.' ),
                array( 'title' => $title )
            );
        }

        return $this->success(
            \sprintf('Created assistant "%s" as a draft.', $title),
            array(
                'assistant' => array(
                    'id'     => (int) ( $result['assistant_id'] ?? 0 ),
                    'slug'   => (string) ( $result['slug'] ?? '' ),
                    'status' => (string) ( $result['status'] ?? 'draft' ),
                ),
            )
        );
    }

    /**
     * The provider to store: the argument, else the site default, validated.
     *
     * Returns a string on success, or the error envelope to return to the caller.
     *
     * @return string|array<string, mixed>
     */
    private function resolveProvider(string $provider): string|array
    {
        $default = \trim($this->settings->getDefaultProvider());

        if ('' === $provider) {
            return \mb_substr($default, 0, 40);
        }

        $provider = \mb_substr($provider, 0, 40);

        if (null !== $this->providers) {
            $registered = $this->providers->getRegisteredSlugs();

            // An empty registry means provider registration has not run yet;
            // rejecting every slug then would make a fresh install unusable.
            if (array() !== $registered && ! $this->providers->has($provider)) {
                return $this->errors->validationFailed(
                    \sprintf('Unknown provider "%s".', $provider),
                    array( 'provider' => array( 'Registered providers: ' . \implode(', ', $registered) . '.' ) )
                );
            }
        }

        return $provider;
    }

    /**
     * The model to store: the argument, else the site default when the provider
     * is the default one, else nothing (the router resolves it per turn).
     */
    private function resolveModel(string $model, string $provider): string
    {
        if ('' !== $model) {
            return \mb_substr($model, 0, 120);
        }

        $defaultProvider = \trim($this->settings->getDefaultProvider());
        $defaultModel    = \trim($this->settings->getDefaultModel());

        if ('' !== $defaultModel && $provider === $defaultProvider) {
            return \mb_substr($defaultModel, 0, 120);
        }

        return '';
    }
}
