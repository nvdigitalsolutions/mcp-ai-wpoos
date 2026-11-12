<?php

namespace Tests\Unit;

use App\Http\Middleware\AcceptWpNonce;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class AcceptWpNonceTest extends TestCase
{
    public function test_matching_nonce_is_mapped_to_the_csrf_header(): void
    {
        $request = $this->requestWithSessionToken('session-token');
        $request->headers->set('X-WP-Nonce', 'session-token');

        (new AcceptWpNonce())->handle($request, fn (): Response => new Response('ok'));

        $this->assertSame('session-token', $request->headers->get('X-CSRF-TOKEN'));
    }

    public function test_mismatched_nonce_is_not_mapped(): void
    {
        $request = $this->requestWithSessionToken('session-token');
        $request->headers->set('X-WP-Nonce', 'attacker-token');

        (new AcceptWpNonce())->handle($request, fn (): Response => new Response('ok'));

        $this->assertNull($request->headers->get('X-CSRF-TOKEN'));
    }

    public function test_existing_csrf_header_is_preserved(): void
    {
        $request = $this->requestWithSessionToken('session-token');
        $request->headers->set('X-WP-Nonce', 'session-token');
        $request->headers->set('X-CSRF-TOKEN', 'explicit-token');

        (new AcceptWpNonce())->handle($request, fn (): Response => new Response('ok'));

        $this->assertSame('explicit-token', $request->headers->get('X-CSRF-TOKEN'));
    }

    public function test_request_without_a_session_is_untouched(): void
    {
        $request = Request::create('/', 'POST');
        $request->headers->set('X-WP-Nonce', 'session-token');

        (new AcceptWpNonce())->handle($request, fn (): Response => new Response('ok'));

        $this->assertNull($request->headers->get('X-CSRF-TOKEN'));
    }

    private function requestWithSessionToken(string $token): Request
    {
        $session = new Store('test', new ArraySessionHandler(60));
        $session->start();
        $session->put('_token', $token);

        $request = Request::create('/', 'POST');
        $request->setLaravelSession($session);

        return $request;
    }
}
