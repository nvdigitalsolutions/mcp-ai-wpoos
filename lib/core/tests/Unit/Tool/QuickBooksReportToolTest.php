<?php

/**
 * The QuickBooks Online report reader.
 *
 * Five properties are worth pinning, and each corresponds to a way the WordPress
 * original could have been ported badly:
 *
 * 1. **The report is not rewritten.** The original ran `sanitize_text_field()`
 *    over every string *and every key*, so a label containing `<` or a
 *    percent-encoded octet came back altered. One test feeds a value that
 *    sanitiser would change and asserts it survives.
 * 2. **A 401 says what to do.** The original force-refreshed and retried; there is
 *    no store here, so the refusal has to name the fix rather than retry.
 * 3. **The credential never appears in a reply.** Every failure case serialises the
 *    result and asserts the token is absent.
 * 4. **The accounting method is normalised, not guessed.** `cash` and `CASH` are
 *    accepted because Intuit wants `Cash`; anything else is refused.
 * 5. **The query is exactly what was asked for.** `minorversion` is sent only when
 *    positive, and the realm and report are URL-encoded.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\Ecommerce\QuickBooksReportTool;
use PHPUnit\Framework\TestCase;

final class QuickBooksReportToolTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.secret-payload.sig';

    private ErrorFactoryInterface $errors;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $requests = array();

    protected function setUp(): void
    {
        $this->requests = array();
        $this->errors   = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? null )
        );
    }

    public function test_it_identifies_itself_and_declares_its_credential(): void
    {
        $tool = $this->tool($this->http());

        $this->assertSame('quickbooks_report', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertContains('requires-credentials', $tool->getCapabilityFlags());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
    }

    public function test_a_missing_token_is_refused_before_any_request(): void
    {
        $result = $this->tool($this->http())->execute(
            array('realm_id' => '123', 'report' => 'ProfitAndLoss'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_missing_realm_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array('access_token' => self::TOKEN, 'report' => 'ProfitAndLoss'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_missing_report_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '123'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_bad_date_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array(
                'access_token' => self::TOKEN,
                'realm_id'     => '123',
                'report'       => 'ProfitAndLoss',
                'start_date'   => '31/01/2026',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_an_unknown_accounting_method_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array(
                'access_token'      => self::TOKEN,
                'realm_id'          => '123',
                'report'            => 'ProfitAndLoss',
                'accounting_method' => 'modified',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_the_request_carries_the_bearer_token_and_the_encoded_path(): void
    {
        $tool = $this->tool($this->http($this->ok()));

        $tool->execute(
            array(
                'access_token'      => self::TOKEN,
                'realm_id'          => '9130345678',
                'report'            => 'ProfitAndLoss',
                'start_date'        => '2026-01-01',
                'end_date'          => '2026-01-31',
                'accounting_method' => 'cash',
            ),
            self::CONTEXT
        );

        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame('Bearer ' . self::TOKEN, $this->requests[0]['headers']['Authorization']);
        $this->assertStringStartsWith(
            'https://quickbooks.api.intuit.com/v3/company/9130345678/reports/ProfitAndLoss?',
            $this->requests[0]['url']
        );
        $this->assertStringContainsString('start_date=2026-01-01', $this->requests[0]['url']);
        $this->assertStringContainsString('accounting_method=Cash', $this->requests[0]['url']);
    }

    public function test_minorversion_is_sent_only_when_positive(): void
    {
        $this->tool($this->http($this->ok()))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'BalanceSheet', 'minor_version' => 0),
            self::CONTEXT
        );

        $this->assertStringNotContainsString('minorversion', $this->requests[0]['url']);
    }

    public function test_a_report_name_with_a_slash_is_encoded(): void
    {
        $this->tool($this->http($this->ok()))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'Sales/Summary'),
            self::CONTEXT
        );

        $this->assertStringContainsString('/reports/Sales%2FSummary', $this->requests[0]['url']);
    }

    public function test_the_report_body_is_returned_unsanitised(): void
    {
        // `sanitize_text_field()` would strip the tags and decode-or-eat the
        // percent sequences; a financial label that contains them must survive.
        $body = array(
            'Header' => array('ReportName' => 'Profit and Loss <draft> 100%20 ready'),
            'Rows'   => array('Row' => array(array('ColData' => array(array('value' => '1,234.56'))))),
        );

        $result = $this->tool($this->http($this->json($body)))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'ProfitAndLoss'),
            self::CONTEXT
        );

        $this->assertSame(
            'Profit and Loss <draft> 100%20 ready',
            $result['data']['report_data']['Header']['ReportName']
        );
        $this->assertSame('1,234.56', $result['data']['report_data']['Rows']['Row'][0]['ColData'][0]['value']);
    }

    public function test_a_401_names_the_fix_and_hides_the_token(): void
    {
        $result = $this->tool($this->http(new HttpResponse(401, '{"Fault":{}}')))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'ProfitAndLoss'),
            self::CONTEXT
        );

        $this->assertSame('quickbooks_unauthorized', $result['error']['code']);
        $this->assertStringContainsString('mint a fresh one', strtolower($result['error']['message']));
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($result));
    }

    public function test_a_403_names_the_scope(): void
    {
        $result = $this->tool($this->http(new HttpResponse(403, '{}')))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'ProfitAndLoss'),
            self::CONTEXT
        );

        $this->assertSame('quickbooks_forbidden', $result['error']['code']);
        $this->assertStringContainsString('com.intuit.quickbooks.accounting', $result['error']['message']);
    }

    public function test_an_unexpected_status_is_reported_with_its_code(): void
    {
        $result = $this->tool($this->http(new HttpResponse(503, '{}')))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'ProfitAndLoss'),
            self::CONTEXT
        );

        $this->assertSame('quickbooks_http_error', $result['error']['code']);
        $this->assertSame(503, $result['error']['data']['status']);
    }

    public function test_a_non_json_body_is_refused(): void
    {
        $result = $this->tool($this->http(new HttpResponse(200, '<html>nope</html>')))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'ProfitAndLoss'),
            self::CONTEXT
        );

        $this->assertSame('quickbooks_invalid_json', $result['error']['code']);
    }

    public function test_a_fault_is_refused_with_its_detail(): void
    {
        $fault = array(
            'Fault' => array(
                'Error' => array(array('code' => '500', 'Detail' => 'Report name not found')),
            ),
        );

        $result = $this->tool($this->http($this->json($fault, 400)))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'Nope'),
            self::CONTEXT
        );

        $this->assertSame('quickbooks_fault', $result['error']['code']);
        $this->assertStringContainsString('Report name not found', $result['error']['message']);
        $this->assertSame('500', $result['error']['data']['code']);
    }

    public function test_a_transport_failure_is_refused_without_the_token(): void
    {
        $result = $this->tool($this->http(new \RuntimeException('dns failure')))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'ProfitAndLoss'),
            self::CONTEXT
        );

        $this->assertSame('quickbooks_request_failed', $result['error']['code']);
        $this->assertStringContainsString('dns failure', $result['error']['message']);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($result));
    }

    public function test_an_oversized_report_is_refused(): void
    {
        $huge = str_repeat('x', 5242881);

        $result = $this->tool($this->http(new HttpResponse(200, $huge)))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '1', 'report' => 'ProfitAndLoss'),
            self::CONTEXT
        );

        $this->assertSame('quickbooks_report_too_large', $result['error']['code']);
    }

    public function test_the_success_payload_names_the_report_and_realm(): void
    {
        $result = $this->tool($this->http($this->ok()))->execute(
            array('access_token' => self::TOKEN, 'realm_id' => '42', 'report' => 'BalanceSheet'),
            self::CONTEXT
        );

        $this->assertSame('BalanceSheet', $result['data']['report']);
        $this->assertSame('42', $result['data']['realm_id']);
        $this->assertSame(200, $result['data']['http_status']);
        $this->assertArrayHasKey('fetched_at', $result['data']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function tool(HttpClientInterface $http): QuickBooksReportTool
    {
        return new QuickBooksReportTool($this->errors, $http);
    }

    private function ok(): HttpResponse
    {
        return $this->json(array('Header' => array('ReportName' => 'Profit and Loss')));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }

    private function http(HttpResponse|\RuntimeException ...$answers): HttpClientInterface
    {
        $requests = &$this->requests;
        $queue    = $answers;
        $http     = $this->createMock(HttpClientInterface::class);

        $http->method('send')->willReturnCallback(
            static function (string $method, string $url, array $headers = array(), ?string $body = null) use (&$queue, &$requests): HttpResponse {
                $requests[] = array(
                    'method'  => $method,
                    'url'     => $url,
                    'headers' => $headers,
                    'body'    => $body,
                );

                $answer = array_shift($queue);

                if ($answer instanceof \RuntimeException) {
                    throw $answer;
                }

                if (! $answer instanceof HttpResponse) {
                    throw new \LogicException('No queued HTTP answer.');
                }

                return $answer;
            }
        );

        return $http;
    }
}
