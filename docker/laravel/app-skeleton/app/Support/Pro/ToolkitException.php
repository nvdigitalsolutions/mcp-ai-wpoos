<?php

namespace App\Support\Pro;

use RuntimeException;

/**
 * A typed refusal from {@see ToolkitEngine}.
 *
 * The engine is shared by the HTTP controller and (from order item 10) the
 * Filament renderer. It cannot return a `JsonResponse` without becoming an HTTP
 * concern, and it must not return `false`/`null` for four different failures —
 * the console reads `error.code`, so the code has to survive the extraction.
 *
 * So the engine throws, and each caller maps once: the controller to the
 * existing `{success:false, error:{code,message,fields?}}` body, the panel to a
 * notification. `status` is carried on the exception because the codes are not
 * uniformly 404 — `pro_entitlement_required` stays 402 and lives in the
 * controller, not here.
 */
final class ToolkitException extends RuntimeException
{
    /**
     * @param array<string, string> $fields Field-keyed validation messages.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $fields = array(),
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function validation(array $fields, string $message = 'One or more fields are invalid.'): self
    {
        return new self('validation_failed', 422, $message, $fields);
    }

    public static function entityNotFound(string $entity): self
    {
        return new self('entity_not_found', 404, 'Unknown entity "'.$entity.'".');
    }

    public static function recordNotFound(): self
    {
        return new self('record_not_found', 404, 'Record not found.');
    }

    public static function invalidPayload(string $message): self
    {
        return new self('invalid_payload', 422, $message);
    }
}
