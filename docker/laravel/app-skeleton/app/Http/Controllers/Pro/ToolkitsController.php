<?php

namespace App\Http\Controllers\Pro;

use App\Support\Pro\ToolkitEngine;
use App\Support\Pro\ToolkitException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Application\Toolkit\ToolkitResearchService;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

/**
 * Generic Pro toolkit HTTP surface (Proposal 090-B §8; extracted for 149 §46/P4).
 *
 * This is a thin adapter. The manifest decides which settings, entities and
 * dashboard cards exist; {@see ToolkitEngine} owns the SQL, validation and
 * aggregation; this class owns only HTTP — route parsing, request validation,
 * entitlement and the response envelope.
 *
 * The extraction exists so a Filament renderer can call the same engine instead
 * of this controller, without a network hop and without becoming a second
 * writer. Behaviour, status codes and messages are unchanged; the console and
 * `ToolkitContractTest` are the proof.
 *
 * Entitlement is per-toolkit (`manifest.feature()`), not per-route: the list
 * filters out unentitled families, and direct access answers the typed 402. It
 * stays here because a panel hides an unlicensed toolkit instead of answering
 * 402 — one policy, two presentations.
 */
class ToolkitsController
{
    public function index(ToolkitEngine $engine, ProEntitlementInterface $entitlement): JsonResponse
    {
        $toolkits = [];

        foreach ($engine->manifests() as $manifest) {
            if (! $entitlement->allows($manifest->feature())) {
                continue;
            }

            $toolkits[] = $engine->summary($manifest);
        }

        return $this->ok([
            'toolkits' => $toolkits,
            'invalid' => $engine->invalid(),
        ]);
    }

    public function show(
        string $slug,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->ok(['toolkit' => $manifest->toArray()]);
    }

    /**
     * Draft one record from a natural-language query (Phase 4.5).
     *
     * The draft is returned for review, never written: the console saves it
     * through `storeEntity`, so the manifest's column validation stays the
     * single gate into the table.
     */
    public function research(
        Request $request,
        string $slug,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
        ToolkitResearchService $research,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        $data = $request->validate([
            'entity' => ['required', 'string'],
            'query' => ['required', 'string', 'max:2000'],
        ]);

        $config = $engine->entity($manifest, (string) $data['entity']);

        if ($config === null) {
            return $this->fail('entity_not_found', 'Unknown entity "'.$data['entity'].'".', 404);
        }

        $result = $research->draft($manifest, $config, (string) $data['query']);

        if ($result['error'] !== null) {
            $status = $result['error'] === 'ai_unavailable' ? 503 : 502;

            return $this->fail($result['error'], (string) $result['message'], $status);
        }

        return $this->ok([
            'entity' => $data['entity'],
            'draft' => $result['draft'],
            'provider' => $result['provider'],
        ]);
    }

    public function settings(
        string $slug,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->ok([
            'slug' => $manifest->slug(),
            'schema' => $manifest->settings(),
            'values' => $engine->settingsValues($manifest),
        ]);
    }

    public function updateSettings(
        Request $request,
        string $slug,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        $data = $request->validate([
            'values' => ['required', 'array'],
        ]);

        return $this->guard(function () use ($engine, $manifest, $data): array {
            return [
                'slug' => $manifest->slug(),
                'values' => $engine->writeSettings($manifest, (array) $data['values']),
            ];
        });
    }

    public function dashboard(
        string $slug,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->guard(fn (): array => [
            'slug' => $manifest->slug(),
            'cards' => $engine->dashboard($manifest),
        ]);
    }

    public function indexEntity(
        Request $request,
        string $slug,
        string $entity,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        $filter = $request->query('filter');

        return $this->guard(function () use ($engine, $manifest, $entity, $request, $filter): array {
            $page = $engine->listRecords(
                $manifest,
                $entity,
                (int) $request->query('per_page', 25),
                (int) $request->query('page', 1),
                (string) $request->query('search', ''),
                is_array($filter) ? $filter : null,
            );

            return ['entity' => $entity] + $page;
        });
    }

    public function showEntity(
        string $slug,
        string $entity,
        string $id,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->guard(function () use ($engine, $manifest, $entity, $id): array {
            $record = $engine->findRecord($manifest, $entity, $id);

            if ($record === null) {
                throw ToolkitException::recordNotFound();
            }

            return ['record' => $record];
        });
    }

    public function storeEntity(
        Request $request,
        string $slug,
        string $entity,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->guard(
            fn (): array => ['record' => $engine->createRecord($manifest, $entity, $request->input('data'))],
            201,
        );
    }

    public function updateEntity(
        Request $request,
        string $slug,
        string $entity,
        string $id,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->guard(
            fn (): array => [
                'record' => $engine->updateRecord($manifest, $entity, $id, $request->input('data')),
            ],
        );
    }

    public function destroyEntity(
        string $slug,
        string $entity,
        string $id,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->guard(function () use ($engine, $manifest, $entity, $id): array {
            if (! $engine->deleteRecord($manifest, $entity, $id)) {
                throw ToolkitException::recordNotFound();
            }

            return ['deleted' => true];
        });
    }

    public function duplicates(
        Request $request,
        string $slug,
        string $entity,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->guard(
            fn (): array => $engine->duplicates(
                $manifest,
                $entity,
                (string) $request->query('column', ''),
                (int) $request->query('limit', 25),
            ),
        );
    }

    public function importEntity(
        Request $request,
        string $slug,
        string $entity,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $engine, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->guard(
            fn (): array => $engine->import(
                $manifest,
                $entity,
                $request->input('rows'),
                $request->boolean('preview'),
                $request->boolean('skip_existing', true),
            ),
        );
    }

    /**
     * Resolve a slug and enforce entitlement.
     *
     * @return array{0: ToolkitManifest|null, 1: JsonResponse|null}
     */
    private function resolve(
        string $slug,
        ToolkitEngine $engine,
        ProEntitlementInterface $entitlement,
    ): array {
        $manifest = $engine->find($slug);

        if ($manifest === null) {
            return [null, $this->fail('toolkit_not_found', 'Unknown toolkit "'.$slug.'".', 404)];
        }

        if (! $entitlement->allows($manifest->feature())) {
            return [null, response()->json([
                'success' => false,
                'error' => [
                    'code' => 'pro_entitlement_required',
                    'message' => 'The '.$manifest->feature().' toolkit requires a Pro entitlement.',
                    'feature' => $manifest->feature(),
                ],
            ], 402)];
        }

        return [$manifest, null];
    }

    /**
     * Run an engine call and map its refusal onto the console's error envelope.
     *
     * `fields` is included only when the engine supplied them, so the existing
     * non-validation shapes (`invalid_payload`, `unknown_filter_column`,
     * `record_not_found`) do not gain a key they never had.
     *
     * @param callable(): array<string, mixed> $work
     */
    private function guard(callable $work, int $status = 200): JsonResponse
    {
        try {
            return $this->ok($work(), $status);
        } catch (ToolkitException $error) {
            $payload = [
                'code' => $error->errorCode,
                'message' => $error->getMessage(),
            ];

            if ($error->fields !== []) {
                $payload['fields'] = $error->fields;
            }

            return response()->json([
                'success' => false,
                'error' => $payload,
            ], $error->status);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
