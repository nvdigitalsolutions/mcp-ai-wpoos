<?php

namespace App\Support\Assistants;

use App\Models\Assistant;
use App\Services\AssistantWriter;
use Nvoos\Core\Domain\Contract\AssistantWriteInterface;

/**
 * The assistant write path behind the core contract — Proposal 090-M §16.
 *
 * `App\Services\AssistantWriter` is the one writer: the REST controller and the
 * `/admin` resource both call it, and it owns estate assignment (from
 * `TenantContext`, never the payload), per-estate slug uniqueness, the draft
 * default, profession flattening and `is_default` exclusivity. This class adds no
 * rules; it adapts the writer's `Assistant` to the array shape a tool can carry
 * and keeps an exception from reaching a transcript.
 *
 * ## The failure message is deliberately generic
 *
 * The writer can throw a driver exception, whose message may carry column names,
 * SQL fragments or filesystem paths. `report()` keeps the detail in the log where
 * an operator can find it; the tool receives a sentence a model can relay.
 */
final class LaravelAssistantWriter implements AssistantWriteInterface
{
    public function __construct(private readonly AssistantWriter $writer)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $draft): array
    {
        try {
            $assistant = $this->writer->create($draft);
        } catch (\Throwable $error) {
            report($error);

            return array(
                'success' => false,
                'error'   => 'The assistant could not be created.',
            );
        }

        return array(
            'success'      => true,
            'assistant_id' => (int) $assistant->getKey(),
            'slug'         => (string) $assistant->slug,
            'status'       => (string) $assistant->status,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function duplicate(int $assistantId, string $title = ''): array
    {
        // The model's global scope pins the current estate, so a foreign id is
        // indistinguishable from a missing one — which is the point.
        $source = Assistant::query()->find($assistantId);

        if (null === $source) {
            return array(
                'success' => false,
                'code'    => 'assistant_not_found',
                'error'   => 'No assistant with that id exists in this estate.',
            );
        }

        $title = mb_substr(trim($title), 0, 255);

        // An explicit allowlist, not `$source->getAttributes()`: ids, slugs,
        // timestamps and `is_default` are not properties of a new assistant, and
        // a future column would otherwise be copied without anyone deciding it
        // should be.
        $draft = array(
            'title'         => '' !== $title ? $title : mb_substr($source->title . ' (Copy)', 0, 255),
            'description'   => $source->description,
            'system_prompt' => $source->system_prompt,
            'provider'      => $source->provider,
            'model'         => $source->model,
            'temperature'   => $source->temperature,
            'max_tokens'    => $source->max_tokens,
            'tools'         => is_array($source->tools) ? $source->tools : array(),
            'meta'          => is_array($source->meta) ? $source->meta : array(),
        );

        try {
            $copy = $this->writer->create($draft);
        } catch (\Throwable $error) {
            report($error);

            return array(
                'success' => false,
                'code'    => 'assistant_duplicate_failed',
                'error'   => 'The assistant could not be duplicated.',
            );
        }

        return array(
            'success'      => true,
            'assistant_id' => (int) $copy->getKey(),
            'slug'         => (string) $copy->slug,
            'status'       => (string) $copy->status,
        );
    }
}
