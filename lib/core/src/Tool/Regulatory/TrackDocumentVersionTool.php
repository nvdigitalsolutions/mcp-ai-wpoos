<?php

/**
 * Read or add a document version — `mcp_ai_reg_document`.
 *
 * Port of `WP_MCP_AI_Tool_Track_Document_Version` (Proposal 164 section
 * 164.149). Two actions behind one slug: `get_history` reads the version list,
 * `create_version` appends to it and moves the record to the new file.
 *
 * ## Two bugs in the WordPress writer, both fixed
 *
 * - **The new version's history entry was never saved.** The handler appended
 *   the old version, wrote the history to meta, *then* appended the new version
 *   to the in-memory array — after the save. The current version therefore never
 *   appeared in its own history, and the `change_notes` the caller supplied were
 *   discarded outright.
 * - **The old version was appended unconditionally.** A second `create_version`
 *   call added the same old entry again, so the history accumulated duplicates
 *   that made `total_versions` a lie. The port appends the outgoing version only
 *   when it differs from the most recent entry.
 *
 * Both are the kind of defect that only shows up when someone relies on the
 * record — which, for a regulatory document, is the audit.
 *
 * ## The history is one meta key
 *
 * `version_history` is a list of `{version, file_url, created_at, notes}` rows,
 * kept as a single meta value, exactly as WordPress stored it, so an export
 * round-trips. The current version is synthesised into the list when the history
 * does not already contain it, which is what `get_history` did and what makes a
 * freshly uploaded document report one version rather than none.
 *
 * Ordering is `version_compare` descending — `2.10` above `2.9`, which a string
 * sort would get wrong.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

class TrackDocumentVersionTool extends RegulatoryTool
{
    /** The actions this tool serves. */
    public const ACTIONS = array('get_history', 'create_version');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'track_document_version';
    }

    public function getName(): string
    {
        return 'Track Document Version';
    }

    public function getDescription(): string
    {
        return 'Reads a document version history, or records a new version with its file URL and change notes. A version list is stored on the record and survives export.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'document_id'  => array(
                    'type'        => 'integer',
                    'description' => 'The document id.',
                    'minimum'     => 1,
                ),
                'action'       => array(
                    'type'        => 'string',
                    'description' => 'get_history or create_version.',
                    'enum'        => self::ACTIONS,
                ),
                'new_version'  => array(
                    'type'        => 'string',
                    'description' => 'The new version number, for create_version.',
                    'maxLength'   => 50,
                ),
                'file_url'     => array(
                    'type'        => 'string',
                    'description' => 'The new file URL, for create_version. http or https.',
                    'maxLength'   => 2000,
                ),
                'change_notes' => array(
                    'type'        => 'string',
                    'description' => 'What changed in this version.',
                    'maxLength'   => 2000,
                ),
            ),
            'required'             => array('document_id', 'action'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $documentId = max(0, $this->intParam($arguments, 'document_id'));
        $action     = trim($this->stringParam($arguments, 'action'));

        if ($documentId <= 0) {
            return $this->errors->validationFailed(
                'A document id is required.',
                array('document_id' => array('The id shown by list_reg_documents.'))
            );
        }

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->errors->validationFailed(
                'A valid action is required.',
                array('action' => array('One of: ' . implode(', ', self::ACTIONS) . '.'))
            );
        }

        $item = $this->content->find($documentId);

        if (null === $item || self::DOCUMENT_TYPE !== $item->type) {
            return $this->errors->notFound('Document not found.');
        }

        $history = $this->history($item);

        if ('get_history' === $action) {
            return $this->historyResponse($documentId, $item, $history);
        }

        return $this->createVersion($item, $history, $arguments, (int) ($context['user_id'] ?? 0));
    }

    /**
     * The stored version list, whichever form it is in.
     *
     * @return list<array<string, mixed>>
     */
    private function history(ContentItem $item): array
    {
        $stored = $item->meta['version_history'] ?? array();

        if (! is_array($stored)) {
            return array();
        }

        $rows = array();

        foreach ($stored as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = array(
                'version'    => $this->text($row['version'] ?? null),
                'file_url'   => $this->text($row['file_url'] ?? null),
                'created_at' => $this->text($row['created_at'] ?? null),
                'notes'      => $this->text($row['notes'] ?? null),
            );
        }

        return $rows;
    }

    /**
     * The `get_history` response, with the current version ensured present.
     *
     * @param list<array<string, mixed>> $history
     *
     * @return array<string, mixed>
     */
    private function historyResponse(int $documentId, ContentItem $item, array $history): array
    {
        $current      = $this->text($item->meta['version'] ?? null);
        $currentUrl   = $this->text($item->meta['file_url'] ?? null);
        $alreadyThere = false;

        foreach ($history as $row) {
            if ($row['version'] === $current) {
                $alreadyThere = true;
                break;
            }
        }

        if (! $alreadyThere) {
            $history[] = array(
                'version'    => $current,
                'file_url'   => $currentUrl,
                'created_at' => $item->updatedAt->format('Y-m-d H:i:s'),
                'notes'      => '',
            );
        }

        usort($history, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $this->success(
            sprintf('Version history retrieved: %d %s.', count($history), 1 === count($history) ? 'version' : 'versions'),
            array(
                'document_id'     => $documentId,
                'current_version' => $current,
                'total_versions'  => count($history),
                'version_history' => $history,
            )
        );
    }

    /**
     * Append a version and move the record to it.
     *
     * @param array<string, mixed>       $arguments
     * @param list<array<string, mixed>> $history
     */
    private function createVersion(
        ContentItem $item,
        array $history,
        array $arguments,
        int $userId
    ): mixed {
        $newVersion = trim($this->stringParam($arguments, 'new_version'));
        $newUrl     = trim($this->stringParam($arguments, 'file_url'));
        $notes      = trim($this->stringParam($arguments, 'change_notes'));

        if ('' === $newVersion) {
            return $this->errors->validationFailed(
                'The version cannot be created.',
                array('new_version' => array('The new version number.'))
            );
        }

        if ('' === $newUrl) {
            return $this->errors->validationFailed(
                'The version cannot be created.',
                array('file_url' => array('The URL of the new file.'))
            );
        }

        if (1 !== preg_match('#^https?://#i', $newUrl)) {
            return $this->errors->validationFailed(
                'The version cannot be created.',
                array('file_url' => array('Only http and https URLs are accepted.'))
            );
        }

        $oldVersion = $this->text($item->meta['version'] ?? null);
        $oldUrl     = $this->text($item->meta['file_url'] ?? null);

        if ('' !== $oldVersion) {
            $last = array() !== $history ? $history[ count($history) - 1 ] : null;

            if (null === $last || $last['version'] !== $oldVersion || $last['file_url'] !== $oldUrl) {
                $history[] = array(
                    'version'    => $oldVersion,
                    'file_url'   => $oldUrl,
                    'created_at' => $item->updatedAt->format('Y-m-d H:i:s'),
                    'notes'      => '',
                );
            }
        }

        $history[] = array(
            'version'    => $newVersion,
            'file_url'   => $newUrl,
            'created_at' => $this->nowStamp(),
            'notes'      => $notes,
        );

        try {
            $this->content->update(
                $item->id,
                new UpdateContentCommand(
                    userId: $userId,
                    meta: array(
                        'version'         => $newVersion,
                        'file_url'        => $newUrl,
                        'version_history' => $history,
                    ),
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Document not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('track_document_version_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Document version updated from %1$s to %2$s.', $oldVersion, $newVersion),
            array(
                'document_id'  => $item->id,
                'old_version'  => $oldVersion,
                'new_version'  => $newVersion,
                'new_file_url' => $newUrl,
            )
        );
    }
}
