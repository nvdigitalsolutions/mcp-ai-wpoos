<?php

/**
 * Read one iCloud Drive file's metadata through a gateway.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_iCloud_Drive_File` (Proposal 164 section
 * 164.108). One GET with `fileId` in the query.
 *
 * See `ICloudTool` for the shared corrections. The one specific to this tool is
 * that the file id is required and bounded: WordPress accepted any non-empty
 * string and put it into the query unencoded by length.
 *
 * The reply carries the decoded document under `response` and the fields the
 * gateway is most likely to name, each defaulting to null rather than to an
 * empty string, so a caller can tell "absent" from "empty".
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetICloudDriveFileTool extends ICloudTool
{
    public function getSlug(): string
    {
        return 'get_icloud_drive_file';
    }

    public function getName(): string
    {
        return 'Get iCloud Drive File';
    }

    public function getDescription(): string
    {
        return 'Reads the metadata of one iCloud Drive file through a gateway service, because Apple publishes no third-party drive API. File contents are not downloaded.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'gateway_url' => array(
                    'type'        => 'string',
                    'description' => 'Your iCloud gateway\'s public https file endpoint.',
                ),
                'api_key'     => array(
                    'type'        => 'string',
                    'description' => 'Bearer token for the gateway.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'file_id'     => array(
                    'type'        => 'string',
                    'description' => 'File identifier in iCloud Drive.',
                    'minLength'   => 1,
                    'maxLength'   => self::MAX_REF_LENGTH,
                ),
            ),
            'required'             => array('gateway_url', 'api_key', 'file_id'),
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

        $file = $this->ref($arguments, 'file_id');

        if ('' === $file) {
            return $this->errors->validationFailed(
                'A file_id is required.',
                array('file_id' => array('Required.'))
            );
        }

        $endpoint = $url . (false === strpos($url, '?') ? '?' : '&')
            . http_build_query(array('fileId' => $file), '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $this->gatewayGet($endpoint, $token);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Retrieved iCloud Drive file metadata.',
            array(
                'file_id'      => $file,
                'name'         => $this->nullable($response['name'] ?? $response['fileName'] ?? null),
                'size'         => $this->nullable($response['size'] ?? $response['sizeBytes'] ?? null),
                'content_type' => $this->nullable($response['contentType'] ?? $response['mimeType'] ?? null),
                'modified_at'  => $this->nullable($response['modifiedAt'] ?? $response['modifiedTime'] ?? null),
                'response'     => $response,
            )
        );
    }

    /**
     * A scalar field, or null when the gateway did not send one.
     */
    private function nullable(mixed $value): mixed
    {
        return is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }
}
