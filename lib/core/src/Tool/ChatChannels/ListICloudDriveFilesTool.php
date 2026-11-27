<?php

/**
 * List files and folders in an iCloud Drive account through a gateway.
 *
 * Port of `WP_MCP_AI_Pro_Tool_List_iCloud_Drive_Files` (Proposal 164 section
 * 164.108). One GET with `limit`, `folderId` and `offset` as query parameters.
 *
 * See `ICloudTool` for the platform model and the shared corrections. The one
 * specific to this tool is the page size: WordPress used `absint()`, so a
 * non-numeric value became zero and then the default, while a negative value
 * became its absolute value — `-50` asked for fifty items. The numeric clamp
 * treats both the same way.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class ListICloudDriveFilesTool extends ICloudTool
{
    public function getSlug(): string
    {
        return 'list_icloud_drive_files';
    }

    public function getName(): string
    {
        return 'List iCloud Drive Files';
    }

    public function getDescription(): string
    {
        return 'Lists files and folders in an iCloud Drive account through a gateway service, because Apple publishes no third-party drive API. Omit folder_id for the root.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'gateway_url' => array(
                    'type'        => 'string',
                    'description' => 'Your iCloud gateway\'s public https listing endpoint.',
                ),
                'api_key'     => array(
                    'type'        => 'string',
                    'description' => 'Bearer token for the gateway.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'folder_id'   => array(
                    'type'        => 'string',
                    'description' => 'Folder to list. Omit for the root.',
                    'maxLength'   => self::MAX_REF_LENGTH,
                ),
                'limit'       => array(
                    'type'        => 'integer',
                    'description' => 'Items to return, 1-100. Defaults to 25.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIST_LIMIT,
                    'default'     => self::DEFAULT_LIST_LIMIT,
                ),
                'offset'      => array(
                    'type'        => 'string',
                    'description' => 'Cursor from a previous response.',
                    'maxLength'   => self::MAX_REF_LENGTH,
                ),
            ),
            'required'             => array('gateway_url', 'api_key'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $url = $this->gateway($arguments);

        if (! is_string($url)) {
            return $url;
        }

        $token = $this->key($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $query = array(
            'limit' => $this->limit($arguments['limit'] ?? null, self::DEFAULT_LIST_LIMIT, self::MAX_LIST_LIMIT),
        );

        $folder = $this->ref($arguments, 'folder_id');

        if ('' !== $folder) {
            $query['folderId'] = $folder;
        }

        $offset = $this->ref($arguments, 'offset');

        if ('' !== $offset) {
            $query['offset'] = $offset;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $endpoint = $url . (false === strpos($url, '?') ? '?' : '&') . $queryString;

        try {
            $response = $this->gatewayGet($endpoint, $token);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $items = $this->items($response);

        return $this->success(
            sprintf('Retrieved %d iCloud Drive item(s).', count($items)),
            array(
                'folder_id' => $folder,
                'items'     => $items,
                'count'     => count($items),
                'response'  => $response,
            )
        );
    }
}
