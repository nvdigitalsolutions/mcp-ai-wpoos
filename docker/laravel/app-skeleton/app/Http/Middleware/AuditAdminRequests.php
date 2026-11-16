<?php

namespace App\Http\Middleware;

use App\Support\Admin\AdminAuditLogger;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Records every mutating admin request (Proposal 149 T-0.9).
 *
 * Registered on both panels' middleware stacks. Read requests are skipped —
 * the log answers "who changed what, and who tried", not "who looked".
 *
 * Exactly one row per mutating request, including failures: an exception
 * thrown inside the pipeline unwinds past this middleware before Laravel's
 * handler renders it, so the catch branch records the status the exception
 * will become and then rethrows. Without that, every rejected write would be
 * invisible — which is the half of an audit trail that matters during an
 * incident.
 */
final class AuditAdminRequests
{
    /** Methods that change state. */
    private const MUTATING = array('POST', 'PUT', 'PATCH', 'DELETE');

    /**
     * Livewire's own transport endpoint.
     *
     * Filament renders forms as Livewire components, so their traffic is POSTs
     * to `/livewire/update`. Those are UI mechanics, not operator actions; an
     * audit log drowned in them would hide the actions.
     */
    private const IGNORED_PATH_PREFIXES = array('livewire/update', 'livewire/message', 'livewire/upload-file');

    public function __construct(private readonly AdminAuditLogger $logger)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldAudit($request)) {
            return $next($request);
        }

        $panelId = $this->panelId();

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->logger->record($request, null, $panelId, $this->statusFor($exception));

            throw $exception;
        }

        $this->logger->record($request, $response, $panelId);

        return $response;
    }

    private function shouldAudit(Request $request): bool
    {
        if (! in_array(strtoupper($request->getMethod()), self::MUTATING, true)) {
            return false;
        }

        $path = trim($request->path(), '/');

        foreach (self::IGNORED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function panelId(): ?string
    {
        return Filament::getCurrentPanel()?->getId();
    }

    /**
     * The status the exception will be rendered as.
     */
    private function statusFor(\Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof ValidationException) {
            return 422;
        }

        return 500;
    }
}
