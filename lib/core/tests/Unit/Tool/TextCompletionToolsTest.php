<?php

/**
 * The five text-completion tools.
 *
 * They share one base and one failure surface, so the shared behaviours are
 * asserted once in a loop and the per-tool questions are asserted individually —
 * a data provider per tool would have multiplied the boilerplate without testing
 * anything extra.
 *
 * The single most important assertion here is
 * `test_code_is_not_tag_stripped`: `strip_tags('<?php echo 1;')` is an empty
 * string, and a code reviewer that silently receives nothing would report
 * confidently on code the caller never wrote.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Tool\AbstractCompletionTool;
use Nvoos\Core\Tool\AnalyzeCodeSequenceCompletionTool;
use Nvoos\Core\Tool\AnalyzeCommentContentCompletionTool;
use Nvoos\Core\Tool\GeneratePostExcerptCompletionTool;
use Nvoos\Core\Tool\SeoMetaOptimizerCompletionTool;
use Nvoos\Core\Tool\ValidateReasoningChainCompletionTool;
use PHPUnit\Framework\TestCase;

final class TextCompletionToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => $value instanceof \Throwable
                || (\is_array($value) && isset($value['error']))
        );
    }

    public function test_every_tool_declares_its_slug_capability_and_flags(): void
    {
        $expected = array(
            'generate_post_excerpt'    => array( GeneratePostExcerptCompletionTool::class, 'edit_posts' ),
            'analyze_code_sequence'    => array( AnalyzeCodeSequenceCompletionTool::class, 'edit_posts' ),
            'analyze_comment_content'  => array( AnalyzeCommentContentCompletionTool::class, 'edit_posts' ),
            'validate_reasoning_chain' => array( ValidateReasoningChainCompletionTool::class, 'read' ),
            'seo_meta_optimizer'       => array( SeoMetaOptimizerCompletionTool::class, 'edit_posts' ),
        );

        foreach ($expected as $slug => [$class, $capability]) {
            $tool = new $class($this->errors);

            $this->assertSame($slug, $tool->getSlug());
            $this->assertSame($capability, $tool->getRequiredCapability(), "Wrong capability for {$slug}");

            // Every one of them calls out and costs money, so every one of them
            // must say so — that is what the necessity gate and the read-only
            // profile read.
            $this->assertContains('external-api', $tool->getCapabilityFlags(), $slug);
            $this->assertContains('consumes-tokens', $tool->getCapabilityFlags(), $slug);
            $this->assertContains('read-only', $tool->getCapabilityFlags(), $slug);
            $this->assertNotContains('local-only', $tool->getCapabilityFlags(), $slug);
        }
    }

    public function test_a_host_without_a_router_says_so_for_every_tool(): void
    {
        $this->errors->expects($this->exactly(5))
            ->method('create')
            ->with('completion_unavailable', $this->anything())
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'completion_unavailable' ) ));

        $calls = array(
            array( new GeneratePostExcerptCompletionTool($this->errors), array( 'content' => 'Text.' ) ),
            array( new AnalyzeCodeSequenceCompletionTool($this->errors), array( 'code' => 'echo 1;' ) ),
            array( new AnalyzeCommentContentCompletionTool($this->errors), array( 'comment_content' => 'Text.' ) ),
            array( new ValidateReasoningChainCompletionTool($this->errors), array( 'reasoning_steps' => array( 'a' ) ) ),
            array( new SeoMetaOptimizerCompletionTool($this->errors), array( 'content' => 'x', 'focus_keyword' => 'y' ) ),
        );

        foreach ($calls as [$tool, $arguments]) {
            $result = $tool->execute($arguments);

            $this->assertSame('completion_unavailable', $result['error']['code']);
        }
    }

    public function test_the_excerpt_strips_the_wrappers_a_model_adds(): void
    {
        $captured = array();

        $result = $this->callTool(
            new GeneratePostExcerptCompletionTool($this->errors, $this->router($this->client("\"```text\n  A clean   summary. \n```\"", $captured))),
            array( 'content' => 'Long text.' )
        );

        $this->assertTrue($result['success']);
        $this->assertSame('A clean summary.', $result['data']['excerpt']);
        $this->assertSame(3, $result['data']['words']);
    }

    public function test_code_reaches_the_model_untouched(): void
    {
        $captured = array();

        $code = "<?php\nif (\$a < \$b) { echo '<b>x</b>'; }\n";

        $this->callTool(
            new AnalyzeCodeSequenceCompletionTool($this->errors, $this->router($this->client('Looks fine.', $captured))),
            array( 'code' => $code, 'language' => 'php' )
        );

        $prompt = $this->promptOf($captured);

        // strip_tags would have removed `<?php` and everything from `<` onward.
        $this->assertStringContainsString('<?php', $prompt);
        $this->assertStringContainsString('if ($a < $b)', $prompt);
        $this->assertStringContainsString("echo '<b>x</b>'", $prompt);
    }

    public function test_a_comment_verdict_is_normalised_to_the_wordpress_shape(): void
    {
        $answer = \json_encode(array(
            'is_spam'            => 1,
            'is_toxic'           => true,
            'toxicity_level'     => 'SEVERE',
            'spam_indicators'    => array( 'links', '  ', 7 ),
            'recommended_action' => 'delete',
            'confidence'         => 4.2,
            'reason'             => 'Promotional.',
        ));

        $result = $this->callTool(
            new AnalyzeCommentContentCompletionTool($this->errors, $this->router($this->client((string) $answer))),
            array( 'comment_content' => 'Buy now!', 'sensitivity' => 'high' )
        );

        $this->assertTrue($result['success']);

        $verdict = $result['data'];

        $this->assertTrue($verdict['is_spam']);
        $this->assertTrue($verdict['is_toxic']);

        // Unknown enum values are coerced to the safe member, as WordPress does.
        $this->assertSame('none', $verdict['toxicity_level']);
        $this->assertSame('hold', $verdict['recommended_action']);

        $this->assertSame(array( 'links' ), $verdict['spam_indicators']);
        $this->assertSame(1.0, $verdict['confidence']);
        $this->assertSame('high', $verdict['sensitivity_applied']);
    }

    public function test_an_unknown_sensitivity_is_refused_rather_than_defaulted(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('sensitivity must be one of'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->callTool(
            new AnalyzeCommentContentCompletionTool($this->errors, $this->router($this->client())),
            array( 'comment_content' => 'Text.', 'sensitivity' => 'paranoid' )
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_unreadable_verdict_is_an_error_not_a_default(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('unparseable_response', $this->stringContains('could not be read'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'unparseable_response' ) ));

        $result = $this->callTool(
            new AnalyzeCommentContentCompletionTool($this->errors, $this->router($this->client('I think it is probably fine.'))),
            array( 'comment_content' => 'Text.' )
        );

        $this->assertSame('unparseable_response', $result['error']['code']);
    }

    public function test_a_reasoning_verdict_must_carry_the_valid_field(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('unparseable_response', $this->stringContains('"valid"'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'unparseable_response' ) ));

        $result = $this->callTool(
            new ValidateReasoningChainCompletionTool(
                $this->errors,
                $this->router($this->client('{"confidence": 0.9}'))
            ),
            array( 'reasoning_steps' => array( 'All men are mortal.', 'Socrates is a man.' ) )
        );

        $this->assertSame('unparseable_response', $result['error']['code']);
    }

    public function test_a_reasoning_verdict_is_reported_with_its_step_index(): void
    {
        $answer = '{"valid": false, "confidence": 0.8, "broken_step": 2, "issues": ["does not follow"], "unsupported_claims": [], "suggestions": ["state the link"]}';

        $result = $this->callTool(
            new ValidateReasoningChainCompletionTool($this->errors, $this->router($this->client($answer))),
            array( 'reasoning_steps' => array( 'a', 'b' ), 'conclusion' => 'c' )
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['valid']);
        $this->assertSame(2, $result['data']['broken_step']);
        $this->assertSame(2, $result['data']['steps_checked']);
        $this->assertSame(array( 'does not follow' ), $result['data']['issues']);
    }

    public function test_seo_reports_the_lengths_instead_of_truncating(): void
    {
        $long = \str_repeat('a', 200);

        $answer = \json_encode(array(
            'meta_title'       => 'A perfectly fine title',
            'meta_description' => $long,
            'keywords'         => array( 'k1', 'k2' ),
        ));

        $result = $this->callTool(
            new SeoMetaOptimizerCompletionTool($this->errors, $this->router($this->client((string) $answer))),
            array( 'content' => 'Some content.', 'focus_keyword' => 'widgets' )
        );

        $this->assertTrue($result['success']);

        // Not shortened: the caller decides whether to ask again.
        $this->assertSame(200, $result['data']['meta_description_length']);
        $this->assertSame($long, $result['data']['meta_description']);
        $this->assertFalse($result['data']['within_limits']);
        $this->assertSame(22, $result['data']['meta_title_length']);
    }

    public function test_a_provider_refusal_is_surfaced_with_its_own_message(): void
    {
        $this->errors->method('normalize')->willReturn(array( 'message' => 'Model overloaded.' ));
        $this->errors->expects($this->once())
            ->method('create')
            ->with('completion_provider_error', 'Model overloaded.')
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'completion_provider_error' ) ));

        $result = $this->callTool(
            new GeneratePostExcerptCompletionTool(
                $this->errors,
                $this->router($this->client(new \RuntimeException('Model overloaded.')))
            ),
            array( 'content' => 'Text.' )
        );

        $this->assertSame('completion_provider_error', $result['error']['code']);
    }

    public function test_content_is_required_and_checked_before_any_call(): void
    {
        $this->errors->expects($this->exactly(2))
            ->method('validationFailed')
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $router = $this->createMock(ProviderRouter::class);
        $router->expects($this->never())->method('resolveForChat');

        $this->callTool(new GeneratePostExcerptCompletionTool($this->errors, $router), array( 'content' => '  ' ));
        $this->callTool(new AnalyzeCodeSequenceCompletionTool($this->errors, $router), array( 'code' => '' ));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callTool(AbstractCompletionTool $tool, array $arguments): mixed
    {
        return $tool->execute($arguments);
    }

    /**
     * @param list<array<int, mixed>> $captured
     */
    private function promptOf(array $captured): string
    {
        $this->assertNotEmpty($captured, 'The provider was never called.');

        return (string) ($captured[0][0]['content'][0]['text'] ?? '');
    }

    /**
     * @param list<string> $registered
     */
    private function router(?AbstractProviderClient $client, array $registered = array( 'openai' )): ProviderRouter
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('resolveForChat')->willReturn($client);
        $router->method('getRegisteredSlugs')->willReturn($registered);

        return $router;
    }

    /**
     * @param array<int, mixed> $captured Receives each message array sent.
     */
    private function client(mixed $response = null, array &$captured = array()): AbstractProviderClient
    {
        // A plain string is the model's answer; every provider client returns the
        // OpenAI envelope around it, so the fake does too.
        if (\is_string($response)) {
            $response = array(
                'choices' => array( array( 'message' => array( 'content' => $response ) ) ),
            );
        }

        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn('openai');
        $client->method('chat')->willReturnCallback(
            static function (array $messages) use ($response, &$captured): mixed {
                $captured[] = $messages;

                return $response ?? array(
                    'choices' => array( array( 'message' => array( 'content' => 'answer' ) ) ),
                );
            }
        );

        return $client;
    }
}
