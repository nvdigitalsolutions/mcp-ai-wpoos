<?php

/**
 * Unit tests for the shared slash-command parser.
 *
 * The console's composer forwards the raw line, so every case here is a line a
 * user can actually type. The quirks are pinned deliberately: an empty `--key=`
 * lands in the positional list, and a trailing space is trimmed out of
 * `raw_input`, because both are observable to a command handler.
 *
 * @package Nvoos\Core\Tests
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\SlashCommand;

use Nvoos\Core\Domain\SlashCommand\SlashCommandParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlashCommandParserTest extends TestCase
{
    public function testABareCommandParsesToNoArguments(): void
    {
        $parsed = SlashCommandParser::parse('/help');

        $this->assertNull($parsed['error']);
        $this->assertSame('help', $parsed['command']);
        $this->assertSame('/help', $parsed['raw_input']);
        $this->assertSame(array(), $parsed['args']);
        $this->assertSame(array(), $parsed['flags']);
        $this->assertSame('', $parsed['raw_args']);
    }

    public function testTrailingWhitespaceIsNormalisedOutOfRawInput(): void
    {
        $parsed = SlashCommandParser::parse('  /help   ');

        $this->assertSame('help', $parsed['command']);
        $this->assertSame('/help', $parsed['raw_input']);
    }

    public function testInputWithoutASlashIsASyntaxError(): void
    {
        $this->assertSame(SlashCommandParser::ERROR_SYNTAX, SlashCommandParser::parse('help')['error']);
    }

    public function testEmptyInputIsASyntaxError(): void
    {
        $this->assertSame(SlashCommandParser::ERROR_SYNTAX, SlashCommandParser::parse('   ')['error']);
    }

    public function testASlashAloneIsANameError(): void
    {
        $this->assertSame(SlashCommandParser::ERROR_NAME, SlashCommandParser::parse('/')['error']);
    }

    public function testAFailedParseCarriesNoCommandData(): void
    {
        $parsed = SlashCommandParser::parse('nope');

        $this->assertSame('', $parsed['command']);
        $this->assertSame('', $parsed['raw_input']);
        $this->assertSame(array(), $parsed['args']);
        $this->assertSame(array(), $parsed['flags']);
    }

    public function testHyphensAndUnderscoresAreAllowedInCommandNames(): void
    {
        $this->assertSame('optimize-perf', SlashCommandParser::parse('/optimize-perf')['command']);
        $this->assertSame('my_command', SlashCommandParser::parse('/my_command')['command']);
    }

    public function testPositionalArgumentsAreCollectedInOrder(): void
    {
        $parsed = SlashCommandParser::parse('/ship 123 456');

        $this->assertSame(array( '123', '456' ), $parsed['args']);
        $this->assertSame(array(), $parsed['flags']);
        $this->assertSame('123 456', $parsed['raw_args']);
    }

    public function testLongFlagsAcceptBothInlineAndSeparateValues(): void
    {
        $parsed = SlashCommandParser::parse('/ship --publish --date=2026-02-03');

        $this->assertSame(array( 'publish' => true, 'date' => '2026-02-03' ), $parsed['flags']);
    }

    public function testABareFlagFollowedByAnotherFlagStaysBoolean(): void
    {
        $parsed = SlashCommandParser::parse('/ship --publish --dry-run');

        $this->assertSame(array( 'publish' => true, 'dry-run' => true ), $parsed['flags']);
    }

    public function testShortFlagsFollowTheSameRules(): void
    {
        $parsed = SlashCommandParser::parse('/ship -p -d 2026-02-03');

        $this->assertSame(array( 'p' => true, 'd' => '2026-02-03' ), $parsed['flags']);
    }

    public function testQuotedValuesKeepTheirSpaces(): void
    {
        $parsed = SlashCommandParser::parse('/ship "My Post Title" --desc="A long description"');

        $this->assertSame(array( 'My Post Title' ), $parsed['args']);
        $this->assertSame(array( 'desc' => 'A long description' ), $parsed['flags']);
    }

    public function testSingleQuotesWorkToo(): void
    {
        $parsed = SlashCommandParser::parse("/workflow --show 'My Flow'");

        $this->assertSame(array( 'show' => 'My Flow' ), $parsed['flags']);
    }

    public function testTabsSeparateTokens(): void
    {
        $parsed = SlashCommandParser::parse("/ship\t--dry-run");

        $this->assertSame(array( 'dry-run' => true ), $parsed['flags']);
    }

    /**
     * The preserved quirk: `--key=` has no value, so it is not a flag.
     */
    public function testAnEmptyInlineValueBecomesAPositionalArgument(): void
    {
        $parsed = SlashCommandParser::parse('/ship --key=');

        $this->assertSame(array(), $parsed['flags']);
        $this->assertSame(array( '--key=' ), $parsed['args']);
    }

    /**
     * `-abc` is not a short flag (single character only), so it is positional.
     */
    public function testASingleDashMultiCharacterTokenIsPositional(): void
    {
        $parsed = SlashCommandParser::parse('/ship -abc');

        $this->assertSame(array( '-abc' ), $parsed['args']);
    }

    public function testAQuotedDashTokenIsPositionalAndUnquoted(): void
    {
        $parsed = SlashCommandParser::parse('/ship "-not-a-flag"');

        $this->assertSame(array( '-not-a-flag' ), $parsed['args']);
    }

    public function testAnUnclosedQuoteConsumesTheRestOfTheLine(): void
    {
        $parsed = SlashCommandParser::parse('/ship "unclosed');

        // The opening quote has no pair, so it is preserved rather than stripped.
        $this->assertSame(array( '"unclosed' ), $parsed['args']);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: array<string, string|true>}>
     */
    public static function grammarCases(): array
    {
        return array(
            'a plain argument list'    => array( '/workflow --list', array(), array( 'list' => true ) ),
            'a value with an equals'   => array( '/ship --title=a=b', array(), array( 'title' => 'a=b' ) ),
            'flags after positionals'  => array( '/ship 12 --publish', array( '12' ), array( 'publish' => true ) ),
            'an empty quoted argument' => array( '/say ""', array( '' ), array() ),
        );
    }

    /**
     * @param list<string>                $args  Expected positional arguments.
     * @param array<string, string|true>  $flags Expected flags.
     */
    #[DataProvider('grammarCases')]
    public function testTheGrammarMatchesTheRecordedCase(string $input, array $args, array $flags): void
    {
        $parsed = SlashCommandParser::parse($input);

        $this->assertNull($parsed['error'], $input);
        $this->assertSame($args, $parsed['args'], $input);
        $this->assertSame($flags, $parsed['flags'], $input);
    }
}
