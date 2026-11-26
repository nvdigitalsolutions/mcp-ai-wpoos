<?php

/**
 * CRUD over toolkit contacts — `mcp_crm_contacts`.
 *
 * Ported from `WP_MCP_AI_Tool_Manage_CRM_Contact`: six actions (`create`,
 * `read`, `update`, `delete`, `list`, `search`), the same argument schema, and
 * the same data-store shape.
 *
 * ## Storage measured, not assumed
 *
 * Proposal 164 §164.63 said this tool "dispatches across both contact stores"
 * through a storage-type branch. It does not. Measured against the source, the
 * tool holds **one** store — `WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'contacts' )`
 * — and calls `create_item` / `get_item` / `update_item` / `delete_item` /
 * `query_items` on it. The dual store lives one level down, in the CPT store's
 * canonical slug map (`leads` → `mcp_ai_lead`, `contacts` → `mcp_crm_contacts`).
 * §164.65 records the correction.
 *
 * The port therefore reads and writes `mcp_crm_contacts` only. A legacy lead is
 * **not** reachable here — in WordPress it is not reachable here either. It is
 * reachable through `get_crm_handover`, `get_contact_interactions` and the lead
 * tools, and a test pins the separation so it cannot drift silently.
 *
 * ## The meta family
 *
 * The CPT store extracts `title` and `content` and stores every other key as
 * post meta under `sanitize_key( $key )` — **unprefixed**. So a contact carries
 * `first_name`, `last_name`, `email`, `phone`, `company`, `job_title` and
 * `tags` as plain meta keys, plus the store's `_toolkit_slug` / `_entity_type`
 * markers. An export round-trips.
 *
 * ## Two WordPress quirks, pinned
 *
 * 1. **A created contact has no title.** `create_item()` only sets a post title
 *    when the data carries a `title` key, and the declared `contact_data`
 *    schema has none. WordPress therefore creates an untitled record, which
 *    `search` (a title/content/excerpt query) cannot find by name, and which
 *    the dedupe scanner (a title comparison) cannot match. The port keeps the
 *    behaviour: a `title` supplied inside `contact_data` is honoured, and
 *    nothing is derived from `first_name` / `last_name`.
 * 2. **An update never changes the title.** `update_item()` is given
 *    `contact_data`, which carries no title, so renaming a contact through
 *    `first_name` / `last_name` leaves the stored title untouched.
 *
 * Both are upstream bug candidates; changing either would alter records the
 * WordPress tool writes.
 *
 * `storage_type` is kept in every payload with WordPress' `cpt` value. The core
 * has a single content store, so the key exists for shape compatibility rather
 * than to distinguish backends.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;

class ManageCrmContactTool extends CrmTool
{
    /** WordPress' CPT backend label, kept for shape compatibility. */
    public const STORAGE_TYPE = 'cpt';

    /** The six actions the tool dispatches. */
    public const ACTIONS = array('create', 'read', 'update', 'delete', 'list', 'search');

    /** The contact fields the declared schema names. */
    public const FIELDS = array('first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 'tags');

    /** The store's ceiling, mirroring the adapter's documented maximum. */
    private const MAX_PER_PAGE = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        ContentStoreInterface $content,
        ?SettingsStoreInterface $settings = null,
    ) {
        parent::__construct($e, $content, $settings);
    }

    public function getSlug(): string
    {
        return 'manage_crm_contact';
    }

    public function getName(): string
    {
        return 'Manage CRM Contact';
    }

    public function getDescription(): string
    {
        return 'Comprehensive CRM contact management. Create, read, update, delete, list, and search contacts. Includes email/phone validation and CCT/CPT storage support.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action'       => array(
                    'type'        => 'string',
                    'enum'        => self::ACTIONS,
                    'description' => 'Action to perform',
                ),
                'contact_id'   => array(
                    'type'        => 'integer',
                    'maximum'     => 9007199254740991,
                    'description' => 'Contact ID (required for read, update, delete)',
                ),
                'contact_data' => array(
                    'type'        => 'object',
                    'description' => 'Contact data (required for create, update)',
                    'properties'  => array(
                        'first_name' => array('type' => 'string'),
                        'last_name'  => array('type' => 'string'),
                        'email'      => array('type' => 'string'),
                        'phone'      => array('type' => 'string'),
                        'company'    => array('type' => 'string'),
                        'job_title'  => array('type' => 'string'),
                        'tags'       => array(
                            'type'  => 'array',
                            'items' => array('type' => 'string'),
                        ),
                    ),
                ),
                'search_query' => array(
                    'type'        => 'string',
                    'description' => 'Search query (for search action)',
                ),
                'per_page'     => array(
                    'type'        => 'integer',
                    'description' => 'Results per page (for list/search)',
                    'default'     => 20,
                ),
                'page'         => array(
                    'type'        => 'integer',
                    'description' => 'Page number (for list/search)',
                    'default'     => 1,
                ),
            ),
            'required'             => array('action'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $action = $this->key($this->stringParam($arguments, 'action'));

        switch ($action) {
            case 'create':
                return $this->createContact($arguments, $context);

            case 'read':
                return $this->readContact($arguments);

            case 'update':
                return $this->updateContact($arguments, $context);

            case 'delete':
                return $this->deleteContact($arguments, $context);

            case 'list':
                return $this->listContacts($arguments);

            case 'search':
                return $this->searchContacts($arguments);

            default:
                return $this->errors->validationFailed(
                    'Invalid action. Must be one of: create, read, update, delete, list, search.',
                    array('action' => array('Use one of the six actions.')),
                );
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function createContact(array $arguments, array $context): mixed
    {
        $data = $this->contactData($arguments);

        if (array() === $data) {
            return $this->errors->validationFailed(
                'Contact data is required.',
                array('contact_data' => array('Give the contact fields to create.')),
            );
        }

        if ('' === $this->text($data['email'] ?? '')) {
            return $this->errors->validationFailed(
                'Email is required.',
                array('email' => array('A contact needs an email address.')),
            );
        }

        $invalid = $this->invalidContactFields($data);

        if (null !== $invalid) {
            return $invalid;
        }

        $title = isset($data['title']) ? $this->text($data['title']) : '';
        $body  = isset($data['content']) ? $this->text($data['content']) : '';

        unset($data['title'], $data['content']);

        $meta = $this->metaFamily($data);
        $meta['_toolkit_slug'] = 'crm';
        $meta['_entity_type']  = 'contacts';

        try {
            $item = $this->content->create(
                new CreateContentCommand(
                    title: $title,
                    type: self::CONTACT_TYPE,
                    authorId: (int) ( $context['user_id'] ?? 0 ),
                    status: 'publish',
                    content: $body,
                    meta: $meta,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('contact_create_failed', $e->getMessage());
        }

        return $this->success(
            'Contact created successfully.',
            array(
                'contact_id'   => $item->id,
                'storage_type' => self::STORAGE_TYPE,
            )
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function readContact(array $arguments): mixed
    {
        $contactId = $this->intParam($arguments, 'contact_id');

        if ($contactId <= 0) {
            return $this->errors->validationFailed(
                'Contact ID is required.',
                array('contact_id' => array('Give the contact to read.')),
            );
        }

        $item = $this->content->find($contactId);

        if (! $item instanceof ContentItem || self::CONTACT_TYPE !== $item->type) {
            return $this->errors->notFound('Item not found', array('status' => 404));
        }

        return $this->success(
            'Contact loaded.',
            array(
                'contact'      => $this->contactRow($item),
                'storage_type' => self::STORAGE_TYPE,
            )
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function updateContact(array $arguments, array $context): mixed
    {
        $contactId = $this->intParam($arguments, 'contact_id');

        if ($contactId <= 0) {
            return $this->errors->validationFailed(
                'Contact ID is required.',
                array('contact_id' => array('Give the contact to update.')),
            );
        }

        $data = $this->contactData($arguments);

        if (array() === $data) {
            return $this->errors->validationFailed(
                'Contact data is required.',
                array('contact_data' => array('Give the contact fields to update.')),
            );
        }

        $invalid = $this->invalidContactFields($data);

        if (null !== $invalid) {
            return $invalid;
        }

        $item = $this->content->find($contactId);

        if (! $item instanceof ContentItem || self::CONTACT_TYPE !== $item->type) {
            return $this->errors->notFound('Item not found', array('status' => 404));
        }

        // Pinned: only a `title` key changes the title. Renaming through
        // first_name / last_name leaves it untouched, as WordPress does.
        $title = isset($data['title']) ? $this->text($data['title']) : null;
        $body  = isset($data['content']) ? $this->text($data['content']) : null;

        unset($data['title'], $data['content']);

        try {
            $this->content->update(
                $contactId,
                new UpdateContentCommand(
                    userId: (int) ( $context['user_id'] ?? 0 ),
                    title: $title,
                    content: $body,
                    meta: $this->metaFamily($data),
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('contact_update_failed', $e->getMessage());
        }

        return $this->success(
            'Contact updated successfully.',
            array('storage_type' => self::STORAGE_TYPE)
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function deleteContact(array $arguments, array $context): mixed
    {
        $contactId = $this->intParam($arguments, 'contact_id');

        if ($contactId <= 0) {
            return $this->errors->validationFailed(
                'Contact ID is required.',
                array('contact_id' => array('Give the contact to delete.')),
            );
        }

        $item = $this->content->find($contactId);

        if (! $item instanceof ContentItem || self::CONTACT_TYPE !== $item->type) {
            return $this->errors->notFound('Item not found', array('status' => 404));
        }

        try {
            $this->content->delete($contactId, (int) ( $context['user_id'] ?? 0 ));
        } catch (\Throwable $e) {
            return $this->errors->create('contact_delete_failed', $e->getMessage());
        }

        return $this->success(
            'Contact deleted successfully.',
            array('storage_type' => self::STORAGE_TYPE)
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function listContacts(array $arguments): mixed
    {
        return $this->queryContacts($arguments, null);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function searchContacts(array $arguments): mixed
    {
        $query = trim($this->stringParam($arguments, 'search_query'));

        if ('' === $query) {
            return $this->errors->validationFailed(
                'Search query is required.',
                array('search_query' => array('Give a term to search for.')),
            );
        }

        return $this->queryContacts($arguments, $query);
    }

    /**
     * The shared list/search query.
     *
     * WordPress passes `per_page` straight to `WP_Query`; the core clamps it to
     * the adapter's documented 1–200 range, with 20 as the default.
     *
     * @param array<string, mixed> $arguments
     */
    private function queryContacts(array $arguments, ?string $search): mixed
    {
        $perPage = min(max($this->intParam($arguments, 'per_page', 20), 1), self::MAX_PER_PAGE);
        $page    = max($this->intParam($arguments, 'page', 1), 1);

        try {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::CONTACT_TYPE),
                    statuses: array('publish'),
                    search: $search,
                    orderBy: 'date',
                    order: 'DESC',
                    page: $page,
                    perPage: $perPage,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('contact_query_failed', $e->getMessage());
        }

        $contacts = array();

        foreach ($result->items as $item) {
            if ($item instanceof ContentItem && self::CONTACT_TYPE === $item->type) {
                $contacts[] = $this->contactRow($item);
            }
        }

        $payload = array(
            'contacts'     => $contacts,
            'per_page'     => $perPage,
            'page'         => $page,
            'storage_type' => self::STORAGE_TYPE,
        );

        if (null !== $search) {
            $payload['search_query'] = $search;
        }

        return $this->success(
            sprintf('Found %d contacts.', $result->total),
            $payload
        );
    }

    /**
     * The store's item shape: id, title, content, then the unprefixed meta.
     *
     * @return array<string, mixed>
     */
    private function contactRow(ContentItem $item): array
    {
        $row = array(
            'id'      => $item->id,
            'title'   => $item->title,
            'content' => $item->content,
        );

        foreach ($item->meta as $key => $value) {
            if (! is_string($key) || str_starts_with($key, '_')) {
                continue;
            }

            $row[ $key ] = $value;
        }

        return $row;
    }

    /**
     * The contact payload, or an empty array when none was supplied.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function contactData(array $arguments): array
    {
        return isset($arguments['contact_data']) && is_array($arguments['contact_data'])
            ? $arguments['contact_data']
            : array();
    }

    /**
     * Store keys under their sanitized names, as `create_item()` does.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function metaFamily(array $data): array
    {
        $meta = array();

        foreach ($data as $key => $value) {
            $meta[ $this->key($key) ] = $value;
        }

        return $meta;
    }

    /**
     * Email and phone validation, or null when both are acceptable.
     *
     * WordPress delegates to `WP_MCP_AI_Validator_Service` (libphonenumber for
     * phones). The core has no phone library, so the phone gate is the
     * toolkit's own heuristic — at least seven digits, the rule the duplicate
     * scanner applies — applied to a supplied, non-empty value only.
     *
     * @param array<string, mixed> $data
     */
    private function invalidContactFields(array $data): mixed
    {
        $email = $this->text($data['email'] ?? '');

        if ('' !== $email && false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->errors->validationFailed(
                'The email address is not valid.',
                array('email' => array('Use a valid email address.')),
            );
        }

        $phone = $this->text($data['phone'] ?? '');

        if ('' !== $phone) {
            $digits = preg_replace('/\D/', '', $phone);

            if (strlen((string) $digits) < 7) {
                return $this->errors->validationFailed(
                    'The phone number is not valid.',
                    array('phone' => array('Use at least seven digits.')),
                );
            }
        }

        return null;
    }
}
