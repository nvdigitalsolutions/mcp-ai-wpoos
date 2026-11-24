<?php

/**
 * List the social channels this platform can reach, and whether each is wired.
 *
 * Proposal 164 §164.4.6. The read half of the social toolkit, and the tool that
 * makes the diagnostics claim falsifiable: `Social: worker available, no tool
 * here` becomes `served (1 tool)` exactly when this lands.
 *
 * `connected` is a credential fact. It says a token is configured, not that the
 * token still works — a revoked token answers 401 at publish time, which is the
 * only place that answer can honestly come from.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SocialComposerInterface;

class GetSocialAccountsTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly SocialComposerInterface $social,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_social_accounts';
    }

    public function getName(): string
    {
        return 'Get Social Accounts';
    }

    public function getDescription(): string
    {
        return 'List the social platforms this platform knows about and whether each has credentials configured. A configured credential is not a verified one: a revoked token only fails when it is used.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('external-api', 'read-only', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (! $this->social->supports('accounts')) {
            return $this->errors->create(
                'social_unavailable',
                'No social channel reader is available on this host. Configure a media worker that advertises social_accounts.',
            );
        }

        try {
            $accounts = $this->social->accounts();
        } catch (\Throwable $e) {
            return $this->errors->create('social_accounts_failed', $e->getMessage());
        }

        $connected = \array_values(\array_filter(
            $accounts,
            static fn (array $row): bool => true === $row['connected'],
        ));

        return $this->success(
            \sprintf('%d platform(s) known, %d with credentials.', \count($accounts), \count($connected)),
            array(
                'platforms' => $accounts,
                'connected' => \array_column($connected, 'id'),
                'total' => \count($accounts),
            ),
        );
    }
}
