<?php

/**
 * Activate an account add-on — `POST /addon/activate`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Addon_Activate` (Proposal 164 section
 * 164.141). One identifier, which is the slug `cloudways_addon_list` returns.
 *
 * **One correction.** WordPress built its message with `esc_html( $addon )`.
 * Escaping belongs at the render boundary; inside a JSON envelope it corrupts
 * the value, so an identifier containing `&` came back as an entity. The port
 * returns the identifier as given.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\CloudwaysApiException;
use Nvoos\Core\Support\Text;

class AddonActivateTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_addon_activate';
    }

    public function getName(): string
    {
        return 'Cloudways: Activate Add-on';
    }

    public function getDescription(): string
    {
        return 'Activates an account add-on by identifier. Use cloudways_addon_list for the valid values.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'addon' => array(
                    'type'        => 'string',
                    'description' => 'The add-on identifier, as returned by cloudways_addon_list.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('addon'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $addon = Text::sanitizeKey($this->stringParam($arguments, 'addon'));

        if ('' === $addon) {
            return $this->errors->validationFailed(
                'An add-on identifier is required.',
                array('addon' => array('The identifier shown by cloudways_addon_list.'))
            );
        }

        try {
            $result = $this->client->post('/addon/activate', array('addon' => $addon));
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Add-on "%s" activated successfully.', $addon),
            array(
                'addon'  => $addon,
                'result' => $result,
            )
        );
    }
}
