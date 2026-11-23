<?php

namespace App\Support\Assistants;

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
}
