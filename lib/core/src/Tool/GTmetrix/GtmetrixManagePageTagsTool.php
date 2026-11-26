<?php

/**
 * Manage GTmetrix page tags.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Manage_Page_Tags` (Proposal 164 section
 * 164.98). One tool, five actions, because the API models tags as one resource
 * with two membership endpoints: `list` and `create` operate on the tag
 * collection, `update` on one tag, and `attach`/`detach` on the tag's page
 * membership. Deletion is deliberately not exposed: removing a tag from every
 * page it labels is an irreversible operation no caller of this tool asked for.
 *
 * ## Corrections
 *
 * 1. **`update` updates.** WordPress required a `tag_id`, validated it, and
 *    then called the create endpoint without the id, so an update created a
 *    second tag — or failed on the unique-name constraint — and never touched
 *    the tag named. The documented partial update is
 *    `POST /page_tags/{tag_id}`, which accepts any subset of the attributes.
 * 2. **A partial update may omit the name.** The docs say an omitted attribute
 *    keeps its value, and WordPress refused an update without a name. The port
 *    requires at least one of name, description or colour instead.
 * 3. **`create` requires a name.** WordPress sent empty attributes and let the
 *    API answer a 400; the declared unique name is now required before the
 *    call, and over 45 characters is refused by field.
 * 4. **Attach and detach are one request.** WordPress looped over the page ids
 *    against the page-side endpoint, discarded the per-page results, and
 *    answered success with a page count even when every call failed. The
 *    tag-side endpoint takes the whole list (up to 500) in one request, and a
 *    refusal is returned as the classified error it is.
 * 5. **Every page id is path-checked.** WordPress passed each value into a URL
 *    after `sanitize_text_field()`, which leaves `/` and `..` intact.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

use Nvoos\Core\Domain\Error\GtmetrixApiException;

class GtmetrixManagePageTagsTool extends GtmetrixTool
{
    public const ACTIONS = array('list', 'create', 'update', 'attach', 'detach');

    public const MAX_PAGE_IDS  = 500;
    public const MAX_NAME      = 45;
    public const MAX_DESCRIPTION = 255;

    public function getSlug(): string
    {
        return 'gtmetrix_manage_page_tags';
    }

    public function getName(): string
    {
        return 'GTmetrix Manage Page Tags';
    }

    public function getDescription(): string
    {
        return 'Manage GTmetrix page tags: list, create, update, attach or detach, to segment pages by environment, purpose or client. Tag names are unique and at most 45 characters; colours are 6-digit hex without the leading #. Deletion is not offered.';
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
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

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action'      => array(
                    'type'        => 'string',
                    'enum'        => self::ACTIONS,
                    'description' => 'The tag operation.',
                ),
                'tag_id'      => array(
                    'type'        => 'string',
                    'description' => 'The tag id (update, attach, detach).',
                ),
                'name'        => array(
                    'type'        => 'string',
                    'description' => 'Tag name (create, update); unique, at most 45 characters.',
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'Tag description, at most 255 characters.',
                ),
                'color'       => array(
                    'type'        => 'string',
                    'description' => 'Tag colour as 6-digit hex without #, for example FF8800.',
                ),
                'page_ids'    => array(
                    'type'        => 'array',
                    'description' => 'Page ids to attach or detach (1-500).',
                    'items'       => array('type' => 'string'),
                ),
            ),
            'required'             => array('action'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $guard = $this->guard();

        if (null !== $guard) {
            return $guard;
        }

        $action = array_key_exists('action', $arguments) && is_scalar($arguments['action'])
            ? strtolower(trim((string) $arguments['action']))
            : '';

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->errors->validationFailed(
                'action must be one of: ' . implode(', ', self::ACTIONS) . '.',
                array('action' => array('Unknown tag action.'))
            );
        }

        if ('list' === $action) {
            return $this->listTags();
        }

        if ('create' === $action || 'update' === $action) {
            return $this->writeTag($arguments, 'update' === $action);
        }

        return $this->movePages($arguments, 'detach' === $action);
    }

    /**
     * Every tag on the account.
     */
    private function listTags(): mixed
    {
        try {
            $response = $this->client->request('GET', 'page_tags');
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach ($this->resources($response['data']) as $tag) {
            $attrs  = $this->attrs($tag);
            $rows[] = array(
                'tag_id'      => $this->text($tag['id'] ?? ''),
                'name'        => $this->text($attrs['name'] ?? ''),
                'description' => $this->text($attrs['description'] ?? ''),
                'color'       => $this->text($attrs['color'] ?? ''),
            );
        }

        return $this->success(
            sprintf('%d tag(s).', count($rows)),
            array(
                'tags'  => $rows,
                'count' => count($rows),
            )
        );
    }

    /**
     * Create a tag, or update part of one.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function writeTag(array $arguments, bool $isUpdate): mixed
    {
        $tagId = $this->identifierArg($arguments, 'tag_id');
        $name  = $this->argumentText($arguments, 'name');
        $desc  = $this->argumentText($arguments, 'description');
        $color = $this->argumentText($arguments, 'color');
        $bad   = array();

        if ($isUpdate && '' === $tagId) {
            $bad['tag_id'] = array('A tag id is required for update.');
        }

        if (! $isUpdate && '' === $name) {
            $bad['name'] = array('A name is required to create a tag.');
        }

        if (mb_strlen($name) > self::MAX_NAME) {
            $bad['name'] = array('Tag names are limited to ' . self::MAX_NAME . ' characters.');
        }

        if (mb_strlen($desc) > self::MAX_DESCRIPTION) {
            $bad['description'] = array('Tag descriptions are limited to ' . self::MAX_DESCRIPTION . ' characters.');
        }

        if ('' !== $color && 1 !== preg_match('/^[0-9A-Fa-f]{6}$/', $color)) {
            $bad['color'] = array('Colours are 6-digit hex without the leading #.');
        }

        if ($isUpdate && '' === $name && '' === $desc && '' === $color) {
            $bad['name'] = array('At least one of name, description or colour is required.');
        }

        if (array() !== $bad) {
            return $this->errors->validationFailed(
                'The tag attributes are not valid; nothing was saved.',
                $bad
            );
        }

        $attributes = array();

        if ('' !== $name) {
            $attributes['name'] = $name;
        }

        if ('' !== $desc) {
            $attributes['description'] = $desc;
        }

        if ('' !== $color) {
            $attributes['color'] = $color;
        }

        $path = $isUpdate ? 'page_tags/' . rawurlencode($tagId) : 'page_tags';

        try {
            $response = $this->client->request(
                'POST',
                $path,
                array(
                    'data' => array(
                        'type'       => 'tag',
                        'attributes' => $attributes,
                    ),
                )
            );
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $tag   = is_array($response['data']) ? $response['data'] : array();
        $attrs = $this->attrs($tag);
        $id    = $this->text($tag['id'] ?? '');

        return $this->success(
            $isUpdate ? 'Tag updated.' : 'Tag created.',
            array(
                'tag_id'      => '' !== $id ? $id : $tagId,
                'name'        => '' !== $this->text($attrs['name'] ?? '') ? $this->text($attrs['name']) : $name,
                'description' => '' !== $this->text($attrs['description'] ?? '') ? $this->text($attrs['description']) : $desc,
                'color'       => '' !== $this->text($attrs['color'] ?? '') ? $this->text($attrs['color']) : $color,
            )
        );
    }

    /**
     * Attach or detach pages to or from a tag, in one request.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function movePages(array $arguments, bool $detach): mixed
    {
        $tagId   = $this->identifierArg($arguments, 'tag_id');
        $raw     = is_array($arguments['page_ids'] ?? null) ? $arguments['page_ids'] : array();
        $bad     = array();
        $pageIds = array();

        if ('' === $tagId) {
            $bad['tag_id'] = array('A tag id is required for attach and detach.');
        }

        if (array() === $raw) {
            $bad['page_ids'] = array('Provide 1-' . self::MAX_PAGE_IDS . ' page ids.');
        }

        if (count($raw) > self::MAX_PAGE_IDS) {
            $bad['page_ids'] = array('At most ' . self::MAX_PAGE_IDS . ' page ids are allowed.');
        }

        foreach (array_values($raw) as $index => $pageId) {
            $value = is_scalar($pageId) ? trim((string) $pageId) : '';

            if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
                $bad[ 'page_ids[' . $index . ']' ] = array('Invalid page id.');
                continue;
            }

            $pageIds[] = $value;
        }

        if (array() !== $bad) {
            return $this->errors->validationFailed(
                'A tag id and 1-' . self::MAX_PAGE_IDS . ' valid page ids are required; nothing was changed.',
                $bad
            );
        }

        $action = $detach ? 'detach' : 'attach';

        try {
            $this->client->request(
                'POST',
                'page_tags/' . rawurlencode($tagId) . '/' . $action,
                array('page_ids' => $pageIds)
            );
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            sprintf('%d page(s) %sed.', count($pageIds), $action),
            array(
                'tag_id'   => $tagId,
                'action'   => $action,
                'pages'    => count($pageIds),
                'page_ids' => $pageIds,
            )
        );
    }

    /**
     * A trimmed scalar argument, or ''.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function argumentText(array $arguments, string $key): string
    {
        return array_key_exists($key, $arguments) && is_scalar($arguments[$key])
            ? trim((string) $arguments[$key])
            : '';
    }
}
