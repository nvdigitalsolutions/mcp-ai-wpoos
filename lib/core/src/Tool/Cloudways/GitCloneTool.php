<?php

/**
 * Deploy a branch — `POST /app/{server}/{app}/git/clone`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Git_Clone` (Proposal 164 section 164.140).
 * The branch is required; the API replaces the application's web root with the
 * checkout, which is why the description says so and why the WordPress guidance
 * points at a backup first.
 *
 * **One correction.** WordPress built its message with
 * `esc_html( $branch )` inside a tool envelope. HTML-escaping is an output
 * concern for a rendered page, not for JSON: a branch named `release/1.0` is
 * fine, but a branch containing `&` or `"` would come back as `&amp;` or
 * `&quot;` and no longer match the ref a caller just deployed. The port returns
 * the branch as given.
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

class GitCloneTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_git_clone';
    }

    public function getName(): string
    {
        return 'Cloudways: Deploy Git Branch';
    }

    public function getDescription(): string
    {
        return 'Clones a branch into an application\'s web root, replacing what is there. Take a backup first.';
    }

    public function getParametersSchema(): array
    {
        $schema = $this->targetSchema();
        $schema['properties']['branch'] = array(
            'type'        => 'string',
            'description' => 'The branch to deploy.',
            'minLength'   => 1,
        );
        $schema['required'][] = 'branch';

        return $schema;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $pair = $this->appPair($arguments);

        if (! is_array($pair) || ! isset($pair['app_id'])) {
            return $pair;
        }

        $branch = trim($this->stringParam($arguments, 'branch'));

        if ('' === $branch) {
            return $this->errors->validationFailed(
                'A branch name is required.',
                array('branch' => array('The branch to deploy, e.g. main.'))
            );
        }

        try {
            $result = $this->client->post(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/git/clone',
                array('branch' => $branch)
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Git clone initiated for branch "%1$s" on app %2$d.', $branch, $pair['app_id']),
            array(
                'branch' => $branch,
                'result' => $result,
            )
        );
    }
}
