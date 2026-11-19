<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Support\Budgets\BudgetExceededException;
use App\Support\Budgets\BudgetGuard;
use App\Support\Budgets\BudgetUnverifiableException;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Domain\Contract\ModelRoutingCatalogueInterface;
use Nvoos\Core\Domain\ValueObject\CancellationToken;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat streaming — `/api/v1/chat/stream` and `/api/v1/chat-client`.
 *
 * The assistant is resolved from `assistant_id` **server-side**, and the body
 * can select it but never describe it.
 *
 * Why this exists. The route used to hand the request body's `assistant` blob
 * straight to the orchestrator, where it drives the provider, the model, the
 * system prompt and `max_agentic_iterations`; `assistant_id` was passed through
 * just as trustingly. Both are instructions to the runtime rather than
 * selections from it, so a caller could stream as any estate's assistant and
 * have token-usage and trace rows written against it (149 §88.5).
 *
 * The rule is the one `ResolveApiTenant` already applies to `X-Tenant-Id`: a
 * client-supplied identifier is a **selector**, and the server verifies the
 * principal may use it (OWASP Multi-Tenant Security Cheat Sheet, §1). A
 * non-zero id that names no assistant in the caller's estate answers 404, so an
 * estate the caller cannot enter is indistinguishable from one that does not
 * exist.
 *
 * `options` is still forwarded: the console's model picker uses it, and it is a
 * request-level *preference* within the caller's own estate rather than a
 * description of who they are. One thing about it changed in §90 — a pair the
 * deployment's catalogue has **retired** is refused, because routing to it buys
 * an upstream error and nothing else.
 */
class ChatStreamController
{
    public function __construct(
        private readonly ModelRoutingCatalogueInterface $catalogue,
        private readonly BudgetGuard $budgets,
    ) {
    }

    public function __invoke(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'messages' => ['required', 'array'],
            'assistant_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'options' => ['sometimes', 'array'],
        ]);

        $assistant = $this->resolveAssistant((int) ($validated['assistant_id'] ?? 0));
        $options = (array) ($validated['options'] ?? []);

        $this->refuseRetiredModel($options);

        // Before the provider is called, and before the stream is opened: a
        // limit applied afterwards is a report, and a 429 has to be an HTTP
        // response rather than an error frame inside a 200 stream (Proposal 154).
        try {
            $this->budgets->assertWithinBudget(null === $assistant ? 0 : (int) $assistant->id);
        } catch (BudgetExceededException $error) {
            return response()->json(array(
                'code' => 'budget_exceeded',
                'message' => $error->getMessage(),
                'window' => $error->window,
                'assistant_id' => $error->assistantId,
                'spend' => $error->spend,
                'limit' => $error->limit,
            ), 429);
        } catch (BudgetUnverifiableException $error) {
            return response()->json(array(
                'code' => 'budget_unverifiable',
                'message' => $error->getMessage(),
            ), 503);
        }

        // Resolved before the stream is opened: a 404 has to be an HTTP
        // response, and `response()->stream()` has already committed to 200 by
        // the time its callback runs.
        $messages = (array) $validated['messages'];
        $userId = (int) ($request->user()?->id ?? 0);
        $assistantId = null === $assistant ? 0 : (int) $assistant->id;
        $assistantConfig = null === $assistant ? array() : $assistant->toRecord();

        $headers = [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ];

        return response()->stream(function () use ($messages, $assistantConfig, $userId, $assistantId, $options): void {
            /** @var SseHandler $sse */
            $sse = app(SseHandler::class);
            $sse->markHeadersSent();

            $token = new CancellationToken(
                probe: static fn (): bool => connection_aborted() !== 0,
            );

            try {
                app(ChatOrchestrator::class)->handleChatStreaming(
                    messages: $messages,
                    assistantConfig: $assistantConfig,
                    userId: $userId,
                    assistantId: $assistantId,
                    options: $options,
                    cancellation: $token,
                );
            } catch (\Throwable $e) {
                $sse->sendEvent('error', [
                    'code' => 'stream_failed',
                    'message' => $e->getMessage(),
                ]);
                $sse->sendDone();
            }
        }, 200, $headers);
    }

    /**
     * The assistant this turn acts as, or null for an assistant-less turn.
     *
     * A non-zero id that names no assistant in the caller's estate is a 404
     * rather than a silent fall back to an assistant-less turn: a caller that
     * asked for a specific assistant and got a different one — or none — has to
     * be told, or the difference is invisible until the bill arrives.
     */
    private function resolveAssistant(int $assistantId): ?Assistant
    {
        if ($assistantId <= 0) {
            return null;
        }

        // `Assistant` carries no global tenant scope, so this lookup is the
        // scoping — not a convenience check on top of one.
        $assistant = Assistant::query()->find($assistantId);

        abort_if(null === $assistant, 404);
        abort_if($assistant->tenant_id !== TenantContext::current(), 404);

        return $assistant;
    }

    /**
     * Refuse a model the deployment has deliberately retired (§90).
     *
     * A `null` answer from the catalogue is "no opinion" and passes: a
     * deployment name, a fine-tune or an id the catalogue has never seen says
     * nothing about what a provider serves, and inventing a refusal there would
     * break models that work today.
     *
     * This is **not** a spend control. The catalogue is deployment reference
     * data with no tenant dimension (§90.2), so there is no per-estate allowlist
     * to validate against, and gating `options` on a capability would break
     * every non-admin chat — the console sends `options` on every message, set
     * from the selected assistant.
     *
     * @param array<string, mixed> $options
     */
    private function refuseRetiredModel(array $options): void
    {
        $provider = trim((string) ($options['provider'] ?? ''));
        $model = trim((string) ($options['model'] ?? ''));

        if ('' === $provider || '' === $model) {
            return;
        }

        if (false !== $this->catalogue->serves($provider, $model)) {
            return;
        }

        abort(422, sprintf(
            'The deployment catalogue does not serve the model "%s" from "%s".',
            $model,
            $provider
        ));
    }
}
