<?php

/**
 * Tests for the shared PII / secret filter.
 *
 * The filter is the only thing standing between a user's message and a durable
 * session log, so the test pins the pattern families and the two properties
 * that matter: everything that looks like a secret is replaced, and ordinary
 * prose is left alone.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\PiiFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PiiFilterTest extends TestCase
{
    #[DataProvider('redactions')]
    public function test_it_redacts_known_shapes(string $input, string $expectedToken, string $strippedFragment): void
    {
        $result = PiiFilter::scrub($input);

        $this->assertSame(1, $result['redactions']);
        $this->assertStringContainsString($expectedToken, $result['text']);
        $this->assertStringNotContainsString($strippedFragment, $result['text']);
    }

    public static function redactions(): array
    {
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'
            . '.eyJzdWIiOiIxMjM0NTY3ODkwIn0'
            . '.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $pem = "-----BEGIN RSA PRIVATE KEY-----\n"
            . "MIIEowIBAAKCAQEA1234567890123456789012345678901234567890\n"
            . "-----END RSA PRIVATE KEY-----";

        return array(
            'email' => array( 'mail jane.doe@example.com please', PiiFilter::REDACTED_EMAIL, 'jane.doe@example.com' ),
            'us phone' => array( 'call 555-123-4567 now', PiiFilter::REDACTED_PHONE, '555-123-4567' ),
            'ssn' => array( 'ssn 123-45-6789 on file', PiiFilter::REDACTED_SSN, '123-45-6789' ),
            'openai key' => array( 'key sk-abcdefghijklmnopqrstuvwx here', PiiFilter::REDACTED_KEY, 'sk-abcdefghijklmnopqrstuvwx' ),
            'github token' => array( 'token ghp_abcdefghijklmnopqrstuvwx end', PiiFilter::REDACTED_KEY, 'ghp_abcdefghijklmnopqrstuvwx' ),
            // The four families that used to reach storage unredacted.
            'aws key, numeric tail' => array( 'key AKIA1234567890123456 here', PiiFilter::REDACTED_KEY, 'AKIA1234567890123456' ),
            'aws key, lettered tail' => array( 'key AKIAIOSFODNN7EXAMPLE here', PiiFilter::REDACTED_KEY, 'AKIAIOSFODNN7EXAMPLE' ),
            'aws sts key' => array( 'key ASIAIOSFODNN7EXAMPLE here', PiiFilter::REDACTED_KEY, 'ASIAIOSFODNN7EXAMPLE' ),
            'jwt' => array( 'token ' . $jwt . ' end', PiiFilter::REDACTED_KEY, 'dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk' ),
            'private key block' => array( "here\n" . $pem . "\nthen", PiiFilter::REDACTED_KEY, 'MIIEowIBAAKCAQEA' ),
            'postgres connection string' => array( 'see postgres://user:pass@db.internal:5432/app now', PiiFilter::REDACTED_KEY, 'user:pass@db.internal' ),
            'mongodb+srv connection string' => array( 'at mongodb+srv://us:pw@cluster0.x.mongodb.net/db end', PiiFilter::REDACTED_KEY, 'cluster0.x.mongodb.net' ),
            'redis connection string' => array( 'redis://default:hunter2@cache:6379 now', PiiFilter::REDACTED_KEY, 'hunter2' ),
        );
    }

    public function test_a_card_number_is_redacted(): void
    {
        $result = PiiFilter::scrub('card 4111 1111 1111 1111 expires soon');

        $this->assertGreaterThanOrEqual(1, $result['redactions']);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $result['text']);
    }

    public function test_it_leaves_ordinary_prose_alone(): void
    {
        $input = 'Summarise the third quarter results for the design team.';
        $result = PiiFilter::scrub($input);

        $this->assertSame(0, $result['redactions']);
        $this->assertSame($input, $result['text']);
    }

    public function test_an_empty_string_is_a_no_op(): void
    {
        $this->assertSame(array( 'text' => '', 'redactions' => 0 ), PiiFilter::scrub(''));
    }

    public function test_the_pattern_table_can_be_replaced(): void
    {
        $patterns = array( array( '/ACME-\d+/', '[REDACTED_ACME]' ) );

        $result = PiiFilter::scrub('order ACME-42 shipped', $patterns);

        $this->assertSame(1, $result['redactions']);
        $this->assertStringContainsString('[REDACTED_ACME]', $result['text']);
    }

    public function test_an_invalid_pattern_is_skipped_without_a_warning(): void
    {
        $patterns = array(
            array( '/(unclosed/', '[NOPE]' ),
            array( '/keep/', '[YES]' ),
        );

        $result = PiiFilter::scrub('keep this', $patterns);

        $this->assertSame(1, $result['redactions']);
        $this->assertSame('[YES] this', $result['text']);
    }

    public function test_contains_secret_is_a_predicate_over_scrub(): void
    {
        $this->assertTrue(PiiFilter::containsSecret('reach me at jane@example.com'));
        $this->assertFalse(PiiFilter::containsSecret('nothing to see here'));
    }

    /**
     * The credential patterns run before the credit-card pattern, which would
     * otherwise claim the 16 trailing characters of an AWS key ID and leave the
     * identifying prefix behind as `AKIA[REDACTED_CC]`.
     */
    public function test_credential_shapes_are_not_mistaken_for_card_numbers(): void
    {
        $result = PiiFilter::scrub('key AKIA1234567890123456 here');

        $this->assertSame(1, $result['redactions']);
        $this->assertStringContainsString(PiiFilter::REDACTED_KEY, $result['text']);
        $this->assertStringNotContainsString(PiiFilter::REDACTED_CC, $result['text']);
        $this->assertSame('key ' . PiiFilter::REDACTED_KEY . ' here', $result['text']);
    }

    /**
     * Redacting only the BEGIN header would report success while leaving the key
     * material in the text, which is the failure mode that makes a guard worse
     * than none: it looks handled.
     */
    public function test_a_private_key_block_is_removed_in_full(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\n"
            . "MIIEowIBAAKCAQEA1234567890123456789012345678901234567890\n"
            . "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ\n"
            . "-----END RSA PRIVATE KEY-----";

        $result = PiiFilter::scrub("before\n" . $pem . "\nafter");

        $this->assertSame(1, $result['redactions']);
        $this->assertStringNotContainsString('MIIEowIBAAKCAQEA', $result['text']);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $result['text']);
        $this->assertStringNotContainsString('END RSA PRIVATE KEY', $result['text']);
        $this->assertStringContainsString("before\n", $result['text']);
        $this->assertStringContainsString("\nafter", $result['text']);
    }

    /**
     * A PEM whose END marker is missing cannot be closed by the block pattern, so
     * it is not redacted. This is a documented limitation, not a silent one, and
     * `OutputGuardrail` still detects the header on the output side.
     */
    public function test_a_truncated_private_key_block_is_not_redacted(): void
    {
        $truncated = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA";

        $result = PiiFilter::scrub($truncated);

        $this->assertSame(0, $result['redactions']);
        $this->assertSame($truncated, $result['text']);
    }

    /**
     * The credit-card pattern ends on a digit, so it stops before the separator
     * that follows the run. A pattern that allows a trailing separator eats the
     * space and glues the token to the next word.
     */
    public function test_redaction_keeps_the_separator_after_a_digit_run(): void
    {
        $this->assertSame(
            'card ' . PiiFilter::REDACTED_CC . ' expires soon',
            PiiFilter::scrub('card 4111 1111 1111 1111 expires soon')['text']
        );
        $this->assertSame(
            'order ' . PiiFilter::REDACTED_CC . ' confirmed',
            PiiFilter::scrub('order 1234567890123456 confirmed')['text']
        );
    }

    /**
     * The connection-string tail stops before sentence punctuation, so a URL in
     * prose does not take the full stop with it.
     */
    public function test_a_connection_string_keeps_its_trailing_punctuation(): void
    {
        $result = PiiFilter::scrub('see postgres://u:p@h/db.');

        $this->assertSame(1, $result['redactions']);
        $this->assertSame('see ' . PiiFilter::REDACTED_KEY . '.', $result['text']);
    }

    #[DataProvider('ordinaryProse')]
    public function test_ordinary_prose_is_never_redacted(string $input): void
    {
        $result = PiiFilter::scrub($input);

        $this->assertSame(0, $result['redactions'], 'Unexpected redaction in: ' . $input);
        $this->assertSame($input, $result['text']);
    }

    public static function ordinaryProse(): array
    {
        return array(
            'business prose' => array('Summarise the third quarter results for the design team.'),
            'private key, mentioned' => array('We rotate the private key every ninety days.'),
            'connection string, mentioned' => array('Use a connection string to reach the cluster.'),
            'bare scheme' => array('the postgres:// scheme is supported'),
            'token, mentioned' => array('The token refresh happens hourly.'),
            'base64 commentary' => array('the string eyJhcHAi appears in base64'),
            'aws prefix, mentioned' => array('The AKIA prefix identifies an access key.'),
            'date' => array('shipped 2026-11-15'),
            'aws docs example' => array('The database is reached over mongodb:// internally.'),
        );
    }

    /**
     * `scrub()` skips a pattern it cannot compile, so a typo in the table would
     * silently stop a family being redacted. Compile them all here instead.
     */
    public function test_every_default_pattern_is_a_valid_regex(): void
    {
        foreach (PiiFilter::patterns() as $index => $entry) {
            $this->assertNotFalse(
                @preg_match($entry[0], ''),
                'Pattern ' . $index . ' does not compile: ' . $entry[0]
            );
        }
    }
}
