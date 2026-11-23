<?php

/**
 * Upload File tool — stores a file for use in AI workflows.
 *
 * Uses FileStoreInterface — framework-agnostic. Supports local file
 * uploads, base64-encoded data, or URL fetching.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

class UploadFileTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly FileStoreInterface $files,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'upload_file';
    }

    public function getName(): string
    {
        return 'Upload File';
    }

    public function getDescription(): string
    {
        return 'Stores a file for use in AI workflows. Accepts a local file path, base64-encoded content, or a URL to fetch. Returns file metadata for use with other tools.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'file_path' => array(
                    'type'        => 'string',
                    'description' => 'Local filesystem path to the file to upload.',
                ),
                'base64'    => array(
                    'type'        => 'string',
                    'description' => 'Base64-encoded file content. Requires filename and mime_type parameters.',
                ),
                'url'       => array(
                    'type'        => 'string',
                    'description' => 'URL to download the file from before storing.',
                ),
                'filename'  => array(
                    'type'        => 'string',
                    'description' => 'Desired filename. Required when using base64 or url. Auto-detected from path otherwise.',
                ),
                'mime_type' => array(
                    'type'        => 'string',
                    'description' => 'MIME type of the file. Default: application/octet-stream.',
                    'default'     => 'application/octet-stream',
                ),
                'visibility' => array(
                    'type'        => 'string',
                    'enum'        => array( 'private', 'public' ),
                    'description' => 'Who may read the stored file. "private" (the default) requires an authorised request to read it; "public" gives it a stable, unauthenticated URL. Choose public only for content the whole deployment may see.',
                    'default'     => 'private',
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $userId   = isset($context['user_id']) ? (int) $context['user_id'] : 0;
        $filePath = $this->stringParam($arguments, 'file_path');
        $base64   = $this->stringParam($arguments, 'base64');
        $url      = $this->stringParam($arguments, 'url');

        $count = ( '' !== $filePath ? 1 : 0 )
            + ( '' !== $base64 ? 1 : 0 )
            + ( '' !== $url ? 1 : 0 );

        if (0 === $count) {
            return $this->errors->validationFailed(
                'One of file_path, base64, or url is required.',
                array( 'source' => array( 'Provide file_path, base64, or url.' ) ),
            );
        }

        if ($count > 1) {
            return $this->errors->validationFailed(
                'Only one of file_path, base64, or url may be provided.',
                array( 'source' => array( 'Choose exactly one source.' ) ),
            );
        }

        $filename = $this->stringParam($arguments, 'filename');
        $mimeType = $this->stringParam($arguments, 'mime_type', 'application/octet-stream');
        $visibility = $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PRIVATE);

        if (!in_array($visibility, array(FileVisibilityStoreInterface::VISIBILITY_PUBLIC, FileVisibilityStoreInterface::VISIBILITY_PRIVATE), true)) {
            return $this->errors->validationFailed(
                'visibility must be "private" or "public".',
                array( 'visibility' => array( 'Use private unless the content is meant to be world-readable.' ) ),
            );
        }

        $tenantId = isset($context['tenant_id']) ? (string) $context['tenant_id'] : null;

        try {
            if ('' !== $filePath) {
                if ('' === $filename) {
                    $filename = basename($filePath);
                }
                $file = $this->storeFile($filePath, $filename, $mimeType, $userId, $visibility, $tenantId);
            } elseif ('' !== $base64) {
                if ('' === $filename) {
                    return $this->errors->validationFailed(
                        'filename is required when using base64.',
                        array( 'filename' => array( 'Specify a filename for the decoded content.' ) ),
                    );
                }
                $tmpFile = $this->saveBase64Temp($base64);
                $file    = $this->storeFile($tmpFile, $filename, $mimeType, $userId, $visibility, $tenantId);
                @unlink($tmpFile);
            } else {
                if ('' === $filename) {
                    $filename = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'download';
                }
                $content = @file_get_contents($url);
                if (false === $content) {
                    return $this->errors->create('download_failed', "Failed to download file from: $url");
                }
                $tmpFile = $this->saveTemp($content);
                $file    = $this->storeFile($tmpFile, $filename, $mimeType, $userId, $visibility, $tenantId);
                @unlink($tmpFile);
            }
        } catch (\Throwable $e) {
            return $this->errors->create('upload_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('File "%s" stored (ID: %d, %s).', $filename, $file->id, $visibility),
            $file->jsonSerialize(),
        );
    }

    /**
     * Store through the visibility capability when the host offers it.
     *
     * `FileVisibilityStoreInterface` is an optional capability, not a second
     * protocol (Proposal 162): a host that only implements `FileStoreInterface`
     * keeps working, and the file lands at that host's default visibility. The
     * probe is explicit so a missing capability is visible here rather than
     * silently dropping the argument.
     */
    private function storeFile(
        string $path,
        string $filename,
        string $mimeType,
        int $userId,
        string $visibility,
        ?string $tenantId,
    ): \Nvoos\Core\Domain\Entity\StoredFile {
        if ($this->files instanceof FileVisibilityStoreInterface) {
            return $this->files->storeWithVisibility(
                $path,
                $filename,
                $mimeType,
                $userId,
                $visibility,
                $tenantId,
            );
        }

        return $this->files->store($path, $filename, $mimeType, $userId);
    }

    private function saveBase64Temp(string $base64): string
    {
        $content = base64_decode($base64, true);
        if (false === $content) {
            throw new \RuntimeException('Invalid base64 data.');
        }
        return $this->saveTemp($content);
    }

    private function saveTemp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nvoos_upload_');
        if (false === $path) {
            throw new \RuntimeException('Could not create temporary file.');
        }
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Capability flags — Proposal 149 §154.
     *
     * Writes to a local store and persists information. Re-settable in
     * principle, but the previous value is gone, so not `reversible`.
     *
     * @return list<string>
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }
}
