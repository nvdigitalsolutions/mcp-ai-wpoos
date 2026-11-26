<?php

/**
 * Export a live assistant as a blueprint document.
 *
 * Port of `WP_MCP_AI_Tool_Export_Assistant_Blueprint` (Proposal 164 section
 * 164.81). The source is the canonical `nvoos-assistant` bundle — the same
 * engine `export_assistant` uses — and the output is the curated blueprint
 * dialect the WordPress Blueprint Installer consumes: `name`, `description`
 * and a `meta` map of configuration.
 *
 * ## One engine, not a second assembly
 *
 * `AssistantExportInterface` is where the bundle shape and the credential
 * denylist live. This tool reads one entry from that bundle and renames the
 * fields; it never queries assistants itself. A tool that built its own export
 * would drift from the REST controller and the admin page, and could emit a
 * credential key the engine strips.
 *
 * ## The mapping
 *
 * The canonical entry carries `title`, `excerpt`, `content` (the system
 * prompt) and `meta`; the blueprint wants `name`, `description`, and `meta`
 * with `instructions` / `available_tools` / `provider` / `model` /
 * `temperature`. The description falls back to the first 40 words of the
 * system prompt when the assistant has no excerpt, as WordPress did. Both the
 * canonical short keys and the WordPress-prefixed aliases are read, so a bundle
 * produced by either runtime exports cleanly.
 *
 * ## `save_to_media`
 *
 * Honoured through `FileStoreInterface::store()`, writing the JSON to a temp
 * file first. A host with no file store gets an explicit
 * `media_store_unavailable` refusal rather than a success with no file.
 *
 * ## Capability flags
 *
 * WordPress declared this tool `read-only` while offering a `save_to_media`
 * option that writes an attachment. Flags describe the tool, not the call, so
 * the port declares the write it can perform; a read-only profile is right to
 * gate it.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\AssistantExportInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class ExportAssistantBlueprintTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** Words taken from the system prompt for a missing description. */
    private const DESCRIPTION_WORDS = 40;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ?AssistantExportInterface $exporters = null,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'export_assistant_blueprint';
    }

    public function getName(): string
    {
        return 'Export Assistant Blueprint';
    }

    public function getDescription(): string
    {
        return 'Exports a live assistant as a curated blueprint JSON document in the format consumed by the Pro Blueprint Installer and the blueprint admin pages. Use it to freeze a working assistant into a shareable, re-importable blueprint.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'assistant_id'        => array(
                    'type'        => 'integer',
                    'description' => 'The assistant ID to export as a blueprint.',
                    'minimum'     => 1,
                ),
                'save_to_media'       => array(
                    'type'        => 'boolean',
                    'description' => 'Store the blueprint JSON as a file and return its id instead of only the raw JSON. Default false.',
                    'default'     => false,
                ),
                'confirm_destructive' => array(
                    'type'        => 'boolean',
                    'description' => 'Confirmation flag (unused by this tool; accepted for gate compatibility).',
                    'default'     => false,
                ),
            ),
            'required'             => array('assistant_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        if (null === $this->exporters) {
            return $this->errors->create(
                'assistant_export_unavailable',
                'This host has no assistant export engine.'
            );
        }

        $assistantId = max(0, $this->intParam($arguments, 'assistant_id', 0));

        if ($assistantId <= 0) {
            return $this->errors->validationFailed(
                'An assistant_id is required.',
                array('assistant_id' => array('A positive assistant id is required.'))
            );
        }

        $result = $this->exporters->export(array($assistantId));

        if (empty($result['success'])) {
            return $this->errors->create(
                (string) ( $result['code'] ?? 'assistant_export_failed' ),
                (string) ( $result['error'] ?? 'The assistant could not be exported.' )
            );
        }

        $entry = $result['bundle']['assistants'][0] ?? null;

        if (! is_array($entry)) {
            return $this->errors->create(
                'assistant_export_failed',
                'The export engine returned no assistant entry.'
            );
        }

        $blueprint = $this->blueprint($entry);
        $json      = json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            return $this->errors->create(
                'blueprint_encode_failed',
                'The blueprint payload could not be encoded as JSON.'
            );
        }

        if (! $this->boolParam($arguments, 'save_to_media', false)) {
            return $this->success(
                'Assistant blueprint generated.',
                array(
                    'json'      => $json,
                    'blueprint' => $blueprint,
                )
            );
        }

        $saved = $this->save($blueprint, $json, (int) ( $context['user_id'] ?? 0 ));

        if ($this->errors->isError($saved)) {
            return $saved;
        }

        return $this->success(
            'Assistant blueprint saved as a file.',
            $saved
        );
    }

    /**
     * Convert a canonical bundle entry into the blueprint dialect.
     *
     * @param array<string, mixed> $entry Bundle assistant entry.
     *
     * @return array<string, mixed>
     */
    private function blueprint(array $entry): array
    {
        $meta        = isset($entry['meta']) && is_array($entry['meta']) ? $entry['meta'] : array();
        $instructions = (string) ( $entry['content'] ?? '' );
        $excerpt      = (string) ( $entry['excerpt'] ?? '' );

        $description = '' !== $excerpt
            ? Text::singleLine($excerpt)
            : $this->excerpt($instructions);

        $output = array(
            'name'        => Text::singleLine((string) ( $entry['title'] ?? '' )),
            'description' => $description,
            'meta'        => array(),
        );

        if ('' !== $instructions) {
            $output['meta']['instructions'] = $instructions;
        }

        $tools = $meta['tools'] ?? ( $meta['available_tools'] ?? ( $meta['_wp_mcp_ai_tools'] ?? null ) );

        if (is_array($tools)) {
            $output['meta']['available_tools'] = array_values(array_map('strval', $tools));
        }

        foreach (array('provider', 'model', 'temperature') as $key) {
            if (isset($meta[ $key ])) {
                $output['meta'][ $key ] = $meta[ $key ];
            }
        }

        if (isset($meta['required_capability'])) {
            $output['meta']['required_capability'] = $meta['required_capability'];
        } elseif (isset($meta['mcp_ai_required_capability'])) {
            $output['meta']['required_capability'] = $meta['mcp_ai_required_capability'];
        }

        $memory = $meta['memory_files'] ?? ( $meta['_wp_mcp_ai_memory_files'] ?? null );

        if (is_array($memory)) {
            $output['meta']['memory_files'] = array_values($memory);
        }

        return $output;
    }

    /**
     * The description for an assistant with no excerpt: the first 40 words of
     * its system prompt.
     */
    private function excerpt(string $prompt): string
    {
        $words = preg_split('/\s+/', trim($prompt), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($words)) {
            return '';
        }

        $slice = array_slice($words, 0, self::DESCRIPTION_WORDS);

        return Text::singleLine(implode(' ', $slice));
    }

    /**
     * Persist the JSON through the bound file store.
     *
     * @return array<string, mixed>|mixed
     */
    private function save(array $blueprint, string $json, int $userId): mixed
    {
        if (null === $this->files) {
            return $this->errors->create(
                'media_store_unavailable',
                'This host has no file store, so the blueprint cannot be saved to media.'
            );
        }

        $slug     = Text::slug((string) ( $blueprint['name'] ?? 'assistant' ));
        $slug     = '' !== $slug ? $slug : 'assistant';
        $filename = sprintf('blueprint-%s-%s.json', $slug, gmdate('Ymd-His'));

        $temp = tempnam(sys_get_temp_dir(), 'nvoos-blueprint-');

        if (false === $temp) {
            return $this->errors->create(
                'blueprint_write_failed',
                'Could not create a temporary file for the blueprint.'
            );
        }

        try {
            if (false === file_put_contents($temp, $json)) {
                return $this->errors->create(
                    'blueprint_write_failed',
                    'Could not write the blueprint payload.'
                );
            }

            $file = $this->files->store($temp, $filename, 'application/json', $userId);
        } catch (\Throwable $error) {
            return $this->errors->create('blueprint_write_failed', $error->getMessage());
        } finally {
            @unlink($temp);
        }

        return array(
            'attachment_id' => $file->id,
            'filename'      => $file->filename,
            'blueprint'     => $blueprint,
        );
    }
}
