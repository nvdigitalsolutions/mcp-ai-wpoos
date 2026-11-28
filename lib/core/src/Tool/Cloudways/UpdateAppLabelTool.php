<?php

/**
 * Rename an application — `PUT /app/{server_id}/{app_id}/label`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Update_App_Label` (Proposal 164 section
 * 164.142). The app-scoped sibling of `cloudways_update_server_label`: same
 * verb, same body, same trimming and emptiness rule, so a caller that can
 * rename a server can rename an app without learning a second convention.
 *
 * The accepted label range deliberately matches the server rename's
 * (`minLength` 1, `maxLength` 200). WordPress imposed only the lower bound and
 * let the API enforce the rest, and the API still does — the upper bound is the
 * same local guard the server rename already applies, kept identical so the two
 * operations cannot disagree about a borderline label.
 *
 * ## The message carries the raw label
 *
 * WordPress built the success message with `esc_html( $label )`. That belongs
 * in HTML, not in a JSON envelope: it would turn `Ben & Jerry` into
 * `Ben &amp; Jerry` for every caller. The label is returned as the caller
 * supplied it, trimmed.
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

class UpdateAppLabelTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_update_app_label';
    }

    public function getName(): string
    {
        return 'Cloudways: Update App Label';
    }

    public function getDescription(): string
    {
        return 'Renames a Cloudways application. The label is trimmed and must not be empty; the API decides whether the new name is acceptable and its answer is returned unchanged.';
    }

    public function getParametersSchema(): array
    {
        $schema = $this->targetSchema();

        $schema['properties']['label'] = array(
            'type'        => 'string',
            'description' => 'The new application label.',
            'minLength'   => 1,
            'maxLength'   => 200,
        );

        $schema['required'][] = 'label';

        return $schema;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $pair = $this->appPair($arguments);

        if (! is_array($pair) || ! isset($pair['app_id'])) {
            return $pair;
        }

        $label = $this->label($arguments);

        if ('' === $label) {
            return $this->errors->validationFailed(
                'A label is required.',
                array('label' => array('The new name for the application.'))
            );
        }

        try {
            $result = $this->client->put(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/label',
                array('label' => $label)
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('App renamed to "%s" (ID: %d).', $label, $pair['app_id']),
            $result
        );
    }
}
