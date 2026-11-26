<?php

/**
 * Drafts for the F&B toolkit, stored as Paper Store records.
 *
 * Port of `WP_MCP_AI_Fnb_Draft_Writer` (Proposal 164 section 164.75). The
 * behaviour that matters is preserved: a draft is written with **Draft** status
 * and nothing is sent, ordered or changed; the title must survive sanitising;
 * markup is stripped before storage; listing is newest-first.
 *
 * ## Two storage adaptations, both named
 *
 * - **A Paper Store record, not a `.md` file or a Google Doc.** WordPress writes
 *   under `csv_data_dir`/`drafts`, or uploads to a Drive folder when one is
 *   configured. This runtime has neither a Drive connection nor a drafts
 *   directory; it has {@see PaperStoreInterface}, which exists to hold exactly
 *   this kind of document and can list and page it. The collection is
 *   `fnb-drafts`, the record id is the title's slug (the same slug WordPress
 *   uses for the filename), and the body is `{ "markdown": "…" }`.
 * - **A title with no usable characters is refused.** WordPress would write
 *   `.md`; the store rejects an empty id. Refusing with a validation error is
 *   the honest outcome rather than inventing a name the caller never chose.
 *
 * The list payload keeps the WordPress keys `name`, `path` and `bytes`, where
 * `name` is `<id>.md` and `path` is `<collection>/<id>` — the record's locator
 * rather than a filesystem path, because there is no file.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Nvoos\Core\Support\Text;

final class FnbDraftWriter
{
    /** The Paper Store collection drafts live in. */
    public const COLLECTION = 'fnb-drafts';

    /** The store's own page cap, so the whole collection is read in one call. */
    public const MAX_LIST = 200;

    public function __construct(
        private readonly ErrorFactoryInterface $errors,
        private readonly PaperStoreInterface $store,
    ) {
    }

    /**
     * Save a draft and return its location.
     *
     * @param string $title   Draft title (the report convention).
     * @param string $content Markdown content.
     *
     * @return array<string, mixed>|mixed The draft payload, or the platform's error object.
     */
    public function save(string $title, string $content): mixed
    {
        $title   = Text::singleLine($title);
        $content = Text::multiline($content);

        if ('' === $title) {
            return $this->errors->validationFailed(
                'Draft title is required.',
                array('title' => array('Give the draft a title.'))
            );
        }

        $id = Text::slug($title);

        if ('' === $id) {
            return $this->errors->validationFailed(
                'The draft title has no characters usable in a record id.',
                array('title' => array('Use at least one letter or digit.'))
            );
        }

        try {
            $this->store->save(
                self::COLLECTION,
                array(
                    'id'     => $id,
                    'title'  => $title,
                    'status' => 'draft',
                    'type'   => 'fnb-draft',
                    'tags'   => array('fnb', 'fnb-draft'),
                    'body'   => array('markdown' => $content),
                )
            );
        } catch (\Throwable $error) {
            return $this->errors->create('draft_save_failed', $error->getMessage());
        }

        return array(
            'title'    => $title,
            'id'       => $id,
            'location' => self::COLLECTION . '/' . $id,
            'mode'     => 'paper_store',
            'status'   => 'Draft',
        );
    }

    /**
     * List drafts, newest first.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $page    = $this->store->query(
            self::COLLECTION,
            array(
                'limit'     => self::MAX_LIST,
                'order_by'  => 'updated_at',
                'direction' => 'desc',
            )
        );
        $drafts  = array();

        foreach ($page['records'] as $record) {
            $id   = (string) ($record['id'] ?? '');
            $body = $record['body'] ?? null;
            $text = is_array($body) && isset($body['markdown']) && is_string($body['markdown'])
                ? $body['markdown']
                : '';

            $drafts[] = array(
                'name'       => $id . '.md',
                'path'       => self::COLLECTION . '/' . $id,
                'bytes'      => strlen($text),
                'title'      => (string) ($record['title'] ?? ''),
                'updated_at' => (string) ($record['updated_at'] ?? ''),
            );
        }

        return array(
            'mode'   => 'paper_store',
            'drafts' => $drafts,
            'count'  => count($drafts),
            'total'  => (int) $page['total'],
        );
    }
}
