<?php

/**
 * Slash-command grammar.
 *
 * One parser, one grammar: `/name positional "quoted arg" --flag --key=value`.
 * The Laravel runtime executes a native command set, and the console's composer
 * forwards the raw line untouched, so the grammar is a contract rather than an
 * implementation detail.
 *
 * Deliberately not wired into the WordPress plugin: the wp.org base build strips
 * `lib/` (see `includes/bootstrap/oos-bridge.php`), so
 * `WP_MCP_AI_Slash_Command_Parser` keeps its standalone implementation. This is
 * the portable half; if the base build ever ships with `lib/`, that parser can
 * delegate here.
 *
 * One quirk is preserved rather than fixed: `--key=` with an empty value
 * matches neither flag form and lands in the positional arguments. Changing it
 * would silently alter what existing commands receive.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\SlashCommand;

final class SlashCommandParser
{
    /** Input is empty or does not start with a slash. */
    public const ERROR_SYNTAX = 'invalid_command_syntax';

    /** The slash is present but no legal command name follows. */
    public const ERROR_NAME = 'invalid_command_name';

    /**
     * Parse a raw slash-command line.
     *
     * Failure is reported in the `error` field rather than by throwing: callers
     * map the two codes onto their runtime's own error type, and a returned
     * shape is easier to replay from a data provider than an exception is.
     *
     * @param string $input Raw input, normally starting with `/`.
     *
     * @return array{
     *     command: string,
     *     raw_input: string,
     *     args: list<string>,
     *     flags: array<string, string|true>,
     *     raw_args: string,
     *     error: string|null
     * }
     */
    public static function parse(string $input): array
    {
        $input = trim($input);

        if ('' === $input || '/' !== $input[0]) {
            return self::failure(self::ERROR_SYNTAX);
        }

        // Drop the leading slash. `raw_input` re-adds it, so `/help   ` and
        // `/help` report the same command.
        $input = substr($input, 1);

        if (1 !== preg_match('/^([a-zA-Z0-9_-]+)(.*)$/s', $input, $matches)) {
            return self::failure(self::ERROR_NAME);
        }

        $args_string = trim($matches[2]);
        $parsed_args = self::parseArguments($args_string);

        return array(
            'command'   => $matches[1],
            'raw_input' => '/' . $input,
            'args'      => $parsed_args['positional'],
            'flags'     => $parsed_args['flags'],
            'raw_args'  => $args_string,
            'error'     => null,
        );
    }

    /**
     * Split an argument string into positional arguments and flags.
     *
     * Supported forms:
     *  - `--key=value` and `--key value` (the next token is the value)
     *  - `--key` alone (boolean true)
     *  - `-k value` and `-k` (single-character short flags)
     *  - `"quoted value"` and `'quoted value'`
     *
     * @param string $args_string Everything after the command name.
     *
     * @return array{positional: list<string>, flags: array<string, string|true>}
     */
    private static function parseArguments(string $args_string): array
    {
        $parsed = array(
            'positional' => array(),
            'flags'      => array(),
        );

        if ('' === $args_string) {
            return $parsed;
        }

        $tokens      = self::tokenize($args_string);
        $token_count = count($tokens);
        $index       = 0;

        while ($index < $token_count) {
            $token = $tokens[ $index ];

            // Long flag with an inline value: --key=value.
            if (preg_match('/^--([a-zA-Z0-9_-]+)=(.+)$/', $token, $matches)) {
                $parsed['flags'][ $matches[1] ] = self::unquote($matches[2]);
                ++$index;
                continue;
            }

            // Long flag, value in the next token or boolean true.
            if (preg_match('/^--([a-zA-Z0-9_-]+)$/', $token, $matches)) {
                $key = $matches[1];

                if (isset($tokens[ $index + 1 ]) && ! self::isFlag($tokens[ $index + 1 ])) {
                    $parsed['flags'][ $key ] = self::unquote($tokens[ $index + 1 ]);
                    $index                  += 2;
                } else {
                    $parsed['flags'][ $key ] = true;
                    ++$index;
                }
                continue;
            }

            // Short flag: -k value, or -k alone.
            if (preg_match('/^-([a-zA-Z0-9])$/', $token, $matches)) {
                $key = $matches[1];

                if (isset($tokens[ $index + 1 ]) && ! self::isFlag($tokens[ $index + 1 ])) {
                    $parsed['flags'][ $key ] = self::unquote($tokens[ $index + 1 ]);
                    $index                  += 2;
                } else {
                    $parsed['flags'][ $key ] = true;
                    ++$index;
                }
                continue;
            }

            $parsed['positional'][] = self::unquote($token);
            ++$index;
        }

        return $parsed;
    }

    /**
     * Split on whitespace, keeping quoted runs together.
     *
     * Quotes stay attached to the token; `unquote()` removes them afterwards,
     * which is what lets `--desc="two words"` reach the flag parser intact.
     *
     * @return list<string>
     */
    private static function tokenize(string $input): array
    {
        $tokens     = array();
        $length     = strlen($input);
        $current    = '';
        $in_quotes  = false;
        $quote_char = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $input[ $i ];

            if (! $in_quotes && ( '"' === $char || "'" === $char )) {
                $in_quotes  = true;
                $quote_char = $char;
                $current   .= $char;
                continue;
            }

            if ($in_quotes && $char === $quote_char) {
                $in_quotes = false;
                $current  .= $char;
                continue;
            }

            if (! $in_quotes && ( ' ' === $char || "\t" === $char )) {
                if ('' !== $current) {
                    $tokens[] = $current;
                    $current  = '';
                }
                continue;
            }

            $current .= $char;
        }

        if ('' !== $current) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Does this token look like a flag?
     */
    private static function isFlag(string $token): bool
    {
        return str_starts_with($token, '-');
    }

    /**
     * Strip one matching pair of surrounding quotes.
     *
     * A lone quote character has no pair to strip and reduces to an empty
     * string, matching the WordPress parser's behaviour.
     */
    private static function unquote(string $value): string
    {
        $value  = trim($value);
        $length = strlen($value);

        if (0 === $length) {
            return $value;
        }

        $first = $value[0];
        $last  = $value[ $length - 1 ];

        if (( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last )) {
            return $length > 1 ? substr($value, 1, -1) : '';
        }

        return $value;
    }

    /**
     * The shared shape of a failed parse.
     *
     * @param string $error One of the ERROR_* constants.
     *
     * @return array{command: string, raw_input: string, args: list<string>, flags: array<string, string|true>, raw_args: string, error: string}
     */
    private static function failure(string $error): array
    {
        return array(
            'command'   => '',
            'raw_input' => '',
            'args'      => array(),
            'flags'     => array(),
            'raw_args'  => '',
            'error'     => $error,
        );
    }
}
