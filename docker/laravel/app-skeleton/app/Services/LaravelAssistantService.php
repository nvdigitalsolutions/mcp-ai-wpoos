<?php

namespace App\Services;

use App\Models\Assistant;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;

/**
 * Laravel implementation of the core assistant contract.
 *
 * The contract is intentionally narrow (validate/config/default/list) — it is
 * the runtime lookup used by the chat orchestrator. The admin CRUD lives in
 * AssistantController and is not part of the domain contract.
 */
class LaravelAssistantService implements AssistantServiceInterface
{
    public function validate(int $assistantId, int $userId = 0): array
    {
        $assistant = Assistant::find($assistantId);

        if ($assistant === null) {
            return ['valid' => false, 'error' => 'Assistant not found.'];
        }

        return [
            'valid' => true,
            'assistant' => [
                'id' => (int) $assistant->id,
                'title' => (string) $assistant->title,
                'status' => (string) $assistant->status,
            ],
        ];
    }

    public function getConfig(int $assistantId): array
    {
        $assistant = Assistant::find($assistantId);

        return $assistant === null
            ? ['found' => false]
            : ['found' => true, 'config' => $assistant->toRecord()];
    }

    public function getDefault(): array
    {
        $assistant = Assistant::query()->where('is_default', true)->first()
            ?? Assistant::query()->orderBy('id')->first();

        return $assistant === null
            ? ['found' => false]
            : [
                'found' => true,
                'assistant_id' => (int) $assistant->id,
                'config' => $assistant->toRecord(),
            ];
    }

    public function listForUser(int $userId = 0): array
    {
        return Assistant::query()
            ->orderBy('title')
            ->get()
            ->map(static fn (Assistant $assistant): array => [
                'id' => (int) $assistant->id,
                'title' => (string) $assistant->title,
                'status' => (string) $assistant->status,
                'capabilities' => $assistant->tools ?? [],
            ])
            ->all();
    }
}
