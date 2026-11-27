<?php

/**
 * Upload a base64 file to an iCloud Drive folder through a gateway.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Upload_iCloud_Drive_File` (Proposal 164 section
 * 164.108). One POST of `{fileName, content, contentType, folderId}`.
 *
 * See `ICloudTool` for the shared corrections. Three are specific to this tool:
 *
 * 1. **A file name cannot be a path.** WordPress accepted any text, so
 *    `../../secrets` or `a/b` was forwarded and interpreted by the gateway.
 *    A separator, a dot-only name and a control character are all refused.
 * 2. **Base64 is normalised before the strict decode.** WordPress decoded the
 *    raw argument with `base64_decode($content, true)`, which fails on the
 *    line-wrapped form most encoders produce. Whitespace is stripped first and
 *    the result is still strictly decoded, so a malformed payload is still
 *    refused.
 * 3. **The content type must be a MIME type.** WordPress forwarded any string,
 *    including an empty one after trimming.
 *
 * The decoded size is reported so a caller can see what was actually sent. No
 * size ceiling is imposed here: the gateway owns that limit, and inventing one
 * would refuse uploads the caller's provider accepts.
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

class UploadICloudDriveFileTool extends ICloudTool
{
    /**
     * The content type used when none is given.
     */
    private const DEFAULT_CONTENT_TYPE = 'application/octet-stream';

    public function getSlug(): string
    {
        return 'upload_icloud_drive_file';
    }

    public function getName(): string
    {
        return 'Upload iCloud Drive File';
    }

    public function getDescription(): string
    {
        return 'Uploads a base64-encoded file to an iCloud Drive folder through a gateway service, because Apple publishes no third-party drive API. The file name may not contain a path.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'gateway_url'  => array(
                    'type'        => 'string',
                    'description' => 'Your iCloud gateway\'s public https upload endpoint.',
                ),
                'api_key'      => array(
                    'type'        => 'string',
                    'description' => 'Bearer token for the gateway.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'file_name'    => array(
                    'type'        => 'string',
                    'description' => 'Destination file name. No slashes and not a dot-only name.',
                    'minLength'   => 1,
                    'maxLength'   => 255,
                ),
                'content'      => array(
                    'type'        => 'string',
                    'description' => 'Base64-encoded file content.',
                    'minLength'   => 1,
                ),
                'folder_id'    => array(
                    'type'        => 'string',
                    'description' => 'Destination folder. Omit for the root.',
                    'maxLength'   => self::MAX_REF_LENGTH,
                ),
                'content_type' => array(
                    'type'        => 'string',
                    'description' => 'MIME type of the file. Defaults to application/octet-stream.',
                    'default'     => self::DEFAULT_CONTENT_TYPE,
                ),
            ),
            'required'             => array('gateway_url', 'api_key', 'file_name', 'content'),
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

        $url = $this->gateway($arguments);

        if (! is_string($url)) {
            return $url;
        }

        $token = $this->key($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $name = $this->name($arguments);

        if (! is_string($name)) {
            return $name;
        }

        $content = $this->content($arguments);

        if ($this->errors->isError($content) || ! is_array($content)) {
            return $content;
        }

        $type = $this->contentType($arguments);

        if (! is_string($type)) {
            return $type;
        }

        $payload = array(
            'fileName'    => $name,
            'content'     => $content['encoded'],
            'contentType' => $type,
        );

        $folder = $this->ref($arguments, 'folder_id');

        if ('' !== $folder) {
            $payload['folderId'] = $folder;
        }

        $body = Json::encodeOr($payload, '');

        if ('' === $body) {
            return $this->errors->validationFailed(
                'The upload could not be encoded as JSON.',
                array('content' => array('Not encodable.'))
            );
        }

        try {
            $response = $this->gatewayPost($url, $token, $body);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Uploaded ' . $name . ' to iCloud Drive.',
            array(
                'file_name'    => $name,
                'folder_id'    => $folder,
                'content_type' => $type,
                'bytes'        => $content['bytes'],
                'file_id'      => $this->stringValue($response['fileId'] ?? $response['id'] ?? null),
                'response'     => $response,
            )
        );
    }

    /**
     * The destination file name, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return string|mixed
     */
    private function name(array $arguments): mixed
    {
        $name = is_scalar($arguments['file_name'] ?? null) ? trim((string) $arguments['file_name']) : '';

        if ('' === $name) {
            return $this->errors->validationFailed(
                'A file_name is required.',
                array('file_name' => array('Required.'))
            );
        }

        if (255 < strlen($name)) {
            return $this->errors->validationFailed(
                'A file name is limited to 255 bytes.',
                array('file_name' => array('Too long.'))
            );
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || in_array($name, array('.', '..'), true)) {
            return $this->errors->validationFailed(
                'A file name may not contain a path separator or be a dot-only name.',
                array('file_name' => array('Not a file name.'))
            );
        }

        if (1 !== preg_match('/^[^\x00-\x1F\x7F]+$/', $name)) {
            return $this->errors->validationFailed(
                'A file name may not contain control characters.',
                array('file_name' => array('Not a file name.'))
            );
        }

        return $name;
    }

    /**
     * The decoded content, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array{encoded: string, bytes: int}|mixed
     */
    private function content(array $arguments): mixed
    {
        $raw = is_string($arguments['content'] ?? null) ? trim($arguments['content']) : '';

        if ('' === $raw) {
            return $this->errors->validationFailed(
                'Base64-encoded content is required.',
                array('content' => array('Required.'))
            );
        }

        $compact = preg_replace('/\s+/', '', $raw);
        $compact = is_string($compact) ? $compact : $raw;

        $decoded = base64_decode($compact, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding caller-supplied file bytes.

        if (false === $decoded || '' === $decoded) {
            return $this->errors->validationFailed(
                'The content is not valid base64.',
                array('content' => array('Not base64.'))
            );
        }

        return array(
            'encoded' => $compact,
            'bytes'   => strlen($decoded),
        );
    }

    /**
     * The MIME type, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return string|mixed
     */
    private function contentType(array $arguments): mixed
    {
        $type = is_scalar($arguments['content_type'] ?? null) ? trim((string) $arguments['content_type']) : '';

        if ('' === $type) {
            return self::DEFAULT_CONTENT_TYPE;
        }

        if (1 !== preg_match('#^[a-z0-9][a-z0-9.+-]{0,63}/[a-z0-9][a-z0-9.+-]{0,63}$#i', $type)) {
            return $this->errors->validationFailed(
                'content_type must be a MIME type such as text/plain.',
                array('content_type' => array('Not a MIME type.'))
            );
        }

        return $type;
    }
}
