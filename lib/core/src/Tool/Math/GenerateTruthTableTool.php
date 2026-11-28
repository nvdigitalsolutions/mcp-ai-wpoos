<?php

/**
 * Generate the truth table of a Boolean expression.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Truth_Table` (Proposal 164 section 164.166).
 * Tokenises an expression, parses it into a syntax tree, enumerates every
 * combination of the variables it references, and returns the rows plus
 * optional markdown, plain-text and LaTeX renderings.
 *
 * ## The second and last tool in this family that computes in PHP
 *
 * Seven of the nine `math` tools read from a Node math.js service through a
 * WordPress filter and refuse with `wp_mcp_ai_engine_missing` when nothing
 * implements it (section 164.165). This one and `evaluate_logic_gate` do their
 * own work, so both arrive as ordinary ports rather than as a worker contract.
 *
 * ## The expression `0` was refused as missing
 *
 * WordPress guarded the argument with `empty()`, and `empty('0')` is true in
 * PHP. The one-character expression `0` — a valid table with a single row and
 * no variables — therefore came back as "Expression is required.", while `1`,
 * which has the same shape and is not empty, worked. The asymmetry is the proof
 * that the guard was wrong. The port tests for the empty string.
 *
 * ## A variable named `result` lost its column
 *
 * The row builder wrote each variable's bit into the row and then overwrote
 * `$row_values['result']` with the evaluated answer. A variable named `result`
 * therefore vanished from every row and was replaced by the result of the row.
 * The port reserves the name, case-insensitively, because the renderers title
 * the column "Result".
 *
 * ## Three smaller corrections
 *
 * An unknown `format` was silently replaced with `both`; the port refuses it,
 * matching the treatment of the same argument on the gate evaluator. A
 * `variables` entry that was not a string was cast with `(string)`, so an array
 * raised an "Array to string conversion" warning before failing the identifier
 * test; the port refuses it by position. And the recursion guard counted
 * grammar layers — expression, or, xor, and, unary — so it rose four per
 * parenthesis level and refused at about seven nested pairs, a seventeen
 * character expression; the port counts the same way and sets the cap at 128,
 * which still bounds recursion and is reachable only well beyond the
 * 1,000-character input cap.
 *
 * Gated on `read`, matching both the WordPress declaration and its handler.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Math;

use InvalidArgumentException;
use LogicException;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\AbstractTool;

class GenerateTruthTableTool extends AbstractTool
{
    /** The largest number of variables one table may span. */
    public const MAX_VARIABLES = 8;

    /**
     * The recursion cap, counted in grammar layers.
     *
     * Each parenthesis level descends four layers, so this admits 31 levels.
     */
    public const MAX_PARSE_DEPTH = 128;

    /** The largest expression accepted, in bytes. */
    public const MAX_EXPRESSION_LENGTH = 1000;

    /** The formats the table is rendered in. */
    public const FORMATS = array('latex', 'markdown_table', 'text', 'both');

    /** The row key that carries the evaluated answer. */
    public const RESULT_KEY = 'result';

    /** The operators the expression grammar understands. */
    public const OPERATORS = array('AND', 'OR', 'NOT', 'NAND', 'NOR', 'XOR', 'XNOR');

    /** The symbolic operators, rewritten to their keyword form before scanning. */
    private const SYMBOLS = array(
        '⊕' => ' XOR ',
        '·' => ' AND ',
        '↑' => ' NAND ',
        '↓' => ' NOR ',
    );

    /** Single-character tokens: punctuation and the two literal digits. */
    private const PUNCTUATION = array(
        '(' => array('type' => 'LPAREN', 'value' => '('),
        ')' => array('type' => 'RPAREN', 'value' => ')'),
        '+' => array('type' => 'OP', 'value' => 'OR'),
        "'" => array('type' => 'OP', 'value' => 'POSTNOT'),
        '!' => array('type' => 'OP', 'value' => 'NOT'),
        '~' => array('type' => 'OP', 'value' => 'NOT'),
        '&' => array('type' => 'OP', 'value' => 'AND'),
        '|' => array('type' => 'OP', 'value' => 'OR'),
        '^' => array('type' => 'OP', 'value' => 'XOR'),
        '0' => array('type' => 'CONST', 'value' => '0'),
        '1' => array('type' => 'CONST', 'value' => '1'),
    );

    /** The literal keywords, as their constant value. */
    private const CONSTANTS = array(
        'TRUE'  => true,
        'FALSE' => false,
    );

    /** The shape a variable name must take. */
    private const IDENTIFIER = '/^[A-Za-z][A-Za-z0-9_]*$/';

    public function __construct(ErrorFactoryInterface $e)
    {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'generate_truth_table';
    }

    public function getName(): string
    {
        return 'Generate Truth Table';
    }

    public function getDescription(): string
    {
        return 'Parses a Boolean expression and returns its complete truth table over every combination of the variables it references. Supports the keyword operators AND, OR, NOT, NAND, NOR, XOR and XNOR, the symbolic operators ·, +, \', ⊕, ↑ and ↓, and the constants 0, 1, TRUE and FALSE. Limited to eight variables.';
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'expression' => array(
                    'type'        => 'string',
                    'description' => 'Boolean expression, e.g. "A NAND B", "(A AND B) OR NOT C", or "A↑B". At most 1,000 characters and eight variables.',
                ),
                'variables'  => array(
                    'type'        => 'array',
                    'description' => 'Optional explicit variable order. Variables the expression uses but this list omits are appended in alphabetical order.',
                    'items'       => array('type' => 'string'),
                    'maxItems'    => self::MAX_VARIABLES,
                ),
                'format'     => array(
                    'type'        => 'string',
                    'description' => 'Which renderings to return. Default both.',
                    'enum'        => self::FORMATS,
                    'default'     => 'both',
                ),
            ),
            'required'             => array('expression'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $expression = $this->expressionParam($arguments);
        $format     = $this->formatParam($arguments);
        $fields     = array();

        if ('' === $expression) {
            $fields['expression'] = array('A Boolean expression is required.');
        } elseif (strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            $fields['expression'] = array(
                sprintf('At most %d characters.', self::MAX_EXPRESSION_LENGTH),
            );
        }

        if (! in_array($format, self::FORMATS, true)) {
            $fields['format'] = array('One of: ' . implode(', ', self::FORMATS) . '.');
        }

        $explicit       = array();
        $rawVariables   = $arguments['variables'] ?? array();

        if (! is_array($rawVariables)) {
            $fields['variables'] = array('A list of variable names.');
            $rawVariables        = array();
        }

        foreach (array_values($rawVariables) as $index => $rawName) {
            if (! is_string($rawName)) {
                $fields['variables'] = array(
                    sprintf('Variable %d is not a string.', $index + 1),
                );

                break;
            }

            $name = trim($rawName);

            if (1 !== preg_match(self::IDENTIFIER, $name)) {
                $fields['variables'] = array(
                    sprintf(
                        'Variable %d is not an identifier: use letters, digits and underscores, starting with a letter.',
                        $index + 1
                    ),
                );

                break;
            }

            if (! in_array($name, $explicit, true)) {
                $explicit[] = $name;
            }
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The truth table cannot be generated.', $fields);
        }

        try {
            $tree = $this->parse($expression);
        } catch (InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                'The expression cannot be parsed.',
                array('expression' => array($e->getMessage()))
            );
        }

        $variables = $explicit;

        foreach ($this->collectVariables($tree) as $detected) {
            if (! in_array($detected, $variables, true)) {
                $variables[] = $detected;
            }
        }

        $variableFields = $this->variableProblems($variables);

        if (array() !== $variableFields) {
            return $this->errors->validationFailed('The variable list is not usable.', $variableFields);
        }

        $rows = $this->enumerate($tree, $variables);

        $data = array(
            'expression' => $expression,
            'variables'  => $variables,
            'rows'       => $rows,
        );

        if ('markdown_table' === $format || 'both' === $format) {
            $data['markdown_table'] = $this->renderMarkdownTable($variables, $rows);
        }

        if ('text' === $format || 'both' === $format) {
            $data['text'] = $this->renderTextTable($variables, $rows);
        }

        if ('latex' === $format || 'both' === $format) {
            $data['latex'] = $this->renderLatexTable($variables, $rows);
        }

        return $this->success(
            sprintf(
                'Truth table generated: %1$d %2$s over %3$d %4$s.',
                count($rows),
                1 === count($rows) ? 'row' : 'rows',
                count($variables),
                1 === count($variables) ? 'variable' : 'variables'
            ),
            $data
        );
    }

    /**
     * The expression, as a string.
     *
     * Deliberately not `stringParam()`: that helper strips tags, which would
     * silently rewrite a grammar the tokenizer is meant to judge. The raw string
     * is read, the tokenizer refuses characters it does not know, and the length
     * cap is applied before parsing, so nothing unvalidated reaches a renderer.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function expressionParam(array $arguments): string
    {
        $value = $arguments['expression'] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The requested format, lower-cased for a case-insensitive match.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function formatParam(array $arguments): string
    {
        $format = $this->stringParam($arguments, 'format');

        return '' === $format ? 'both' : strtolower($format);
    }

    /**
     * Names the table cannot use, then the variable cap.
     *
     * @param list<string> $variables Resolved variables.
     *
     * @return array<string, list<string>>
     */
    private function variableProblems(array $variables): array
    {
        foreach ($variables as $name) {
            if (self::RESULT_KEY === strtolower($name)) {
                return array(
                    'variables' => array(
                        sprintf('"%s" is reserved for the result column.', $name),
                    ),
                );
            }
        }

        if (count($variables) > self::MAX_VARIABLES) {
            return array(
                'variables' => array(
                    sprintf('Too many variables (limit %d).', self::MAX_VARIABLES),
                ),
            );
        }

        return array();
    }

    /**
     * Every assignment of the variables, most-significant bit first.
     *
     * The order is the canonical one a truth table is read in: the first
     * variable toggles slowest.
     *
     * @param array<string, mixed> $tree      Syntax tree.
     * @param list<string>         $variables Resolved variables.
     *
     * @return list<array<string, int>>
     */
    private function enumerate(array $tree, array $variables): array
    {
        $rows  = array();
        $count = count($variables);
        $total = 1 << $count;

        for ($mask = 0; $mask < $total; ++$mask) {
            $environment = array();

            foreach ($variables as $index => $name) {
                $environment[ $name ] = 0 !== (( $mask >> ( $count - 1 - $index ) ) & 1);
            }

            $row = array();

            foreach ($variables as $name) {
                $row[ $name ] = $environment[ $name ] ? 1 : 0;
            }

            $row[ self::RESULT_KEY ] = $this->evaluate($tree, $environment) ? 1 : 0;

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Parse an expression into a syntax tree.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When the expression is not well formed.
     */
    private function parse(string $expression): array
    {
        $tokens   = $this->tokenize($expression);
        $position = 0;
        $tree     = $this->parseOr($tokens, $position, 0);

        if ($position < count($tokens)) {
            throw new InvalidArgumentException('Unexpected token at the end of the expression.');
        }

        return $tree;
    }

    /**
     * Split an expression into tokens.
     *
     * Token shape: `array( 'type' => ..., 'value' => ... )`, where the type is
     * one of LPAREN, RPAREN, OP, VAR or CONST.
     *
     * @return list<array{type: string, value: string}>
     *
     * @throws InvalidArgumentException On a character the grammar does not know.
     */
    private function tokenize(string $expression): array
    {
        $expression = strtr($expression, self::SYMBOLS);
        $tokens     = array();
        $length     = strlen($expression);
        $index      = 0;

        while ($index < $length) {
            $character = $expression[ $index ];

            if ($this->isSpace($character)) {
                ++$index;

                continue;
            }

            if (isset(self::PUNCTUATION[ $character ])) {
                $tokens[] = self::PUNCTUATION[ $character ];
                ++$index;

                continue;
            }

            if ($this->isLetter($character)) {
                $start = $index;

                while (
                    $index < $length
                    && ( $this->isLetter($expression[ $index ]) || $this->isIdentifierTail($expression[ $index ]) )
                ) {
                    ++$index;
                }

                $word  = substr($expression, $start, $index - $start);
                $upper = strtoupper($word);

                if (isset(self::CONSTANTS[ $upper ])) {
                    $tokens[] = array(
                        'type'  => 'CONST',
                        'value' => self::CONSTANTS[ $upper ] ? '1' : '0',
                    );
                } elseif (in_array($upper, self::OPERATORS, true)) {
                    $tokens[] = array(
                        'type'  => 'OP',
                        'value' => $upper,
                    );
                } else {
                    $tokens[] = array(
                        'type'  => 'VAR',
                        'value' => $word,
                    );
                }

                continue;
            }

            throw new InvalidArgumentException(
                sprintf(
                    'Invalid character "%s" in the expression.',
                    $this->characterAt($expression, $index)
                )
            );
        }

        return $tokens;
    }

    /**
     * The lowest-precedence layer: OR and NOR.
     *
     * Precedence, tightest first: postfix complement and prefix NOT, then AND
     * and NAND, then XOR and XNOR, then OR and NOR. NAND sits with AND and NOR
     * with OR, which is how the operators are taught and written.
     *
     * @param list<array{type: string, value: string}> $tokens   Token list.
     * @param int                                      $position Cursor, by reference.
     * @param int                                      $depth    Grammar layers descended.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When the expression nests too deeply.
     */
    private function parseOr(array $tokens, int &$position, int $depth): array
    {
        $depth = $this->deeper($depth);
        $left  = $this->parseXor($tokens, $position, $depth);

        while ($this->peekOperator($tokens, $position, array('OR', 'NOR'))) {
            $operator = $tokens[ $position ]['value'];
            ++$position;

            $right = $this->parseXor($tokens, $position, $depth);
            $left  = array('type' => $operator, 'left' => $left, 'right' => $right);
        }

        return $left;
    }

    /**
     * XOR and XNOR.
     *
     * @param list<array{type: string, value: string}> $tokens   Token list.
     * @param int                                      $position Cursor, by reference.
     * @param int                                      $depth    Grammar layers descended.
     *
     * @return array<string, mixed>
     */
    private function parseXor(array $tokens, int &$position, int $depth): array
    {
        $depth = $this->deeper($depth);
        $left  = $this->parseAnd($tokens, $position, $depth);

        while ($this->peekOperator($tokens, $position, array('XOR', 'XNOR'))) {
            $operator = $tokens[ $position ]['value'];
            ++$position;

            $right = $this->parseAnd($tokens, $position, $depth);
            $left  = array('type' => $operator, 'left' => $left, 'right' => $right);
        }

        return $left;
    }

    /**
     * AND and NAND.
     *
     * @param list<array{type: string, value: string}> $tokens   Token list.
     * @param int                                      $position Cursor, by reference.
     * @param int                                      $depth    Grammar layers descended.
     *
     * @return array<string, mixed>
     */
    private function parseAnd(array $tokens, int &$position, int $depth): array
    {
        $depth = $this->deeper($depth);
        $left  = $this->parseUnary($tokens, $position, $depth);

        while ($this->peekOperator($tokens, $position, array('AND', 'NAND'))) {
            $operator = $tokens[ $position ]['value'];
            ++$position;

            $right = $this->parseUnary($tokens, $position, $depth);
            $left  = array('type' => $operator, 'left' => $left, 'right' => $right);
        }

        return $left;
    }

    /**
     * Prefix NOT, a primary, and any postfix complements.
     *
     * @param list<array{type: string, value: string}> $tokens   Token list.
     * @param int                                      $position Cursor, by reference.
     * @param int                                      $depth    Grammar layers descended.
     *
     * @return array<string, mixed>
     */
    private function parseUnary(array $tokens, int &$position, int $depth): array
    {
        $depth = $this->deeper($depth);

        if ($this->peekOperator($tokens, $position, array('NOT'))) {
            ++$position;

            $inner = $this->parseUnary($tokens, $position, $depth);

            return $this->postfix($tokens, $position, array('type' => 'NOT', 'right' => $inner));
        }

        return $this->postfix($tokens, $position, $this->parsePrimary($tokens, $position, $depth));
    }

    /**
     * Consume postfix complements, wrapping the node once per mark.
     *
     * @param list<array{type: string, value: string}> $tokens   Token list.
     * @param int                                      $position Cursor, by reference.
     * @param array<string, mixed>                     $node     Node the marks apply to.
     *
     * @return array<string, mixed>
     */
    private function postfix(array $tokens, int &$position, array $node): array
    {
        while ($this->peekOperator($tokens, $position, array('POSTNOT'))) {
            ++$position;
            $node = array('type' => 'NOT', 'right' => $node);
        }

        return $node;
    }

    /**
     * A variable, a constant, or a parenthesised expression.
     *
     * @param list<array{type: string, value: string}> $tokens   Token list.
     * @param int                                      $position Cursor, by reference.
     * @param int                                      $depth    Grammar layers descended.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException On a missing operand or a stray token.
     */
    private function parsePrimary(array $tokens, int &$position, int $depth): array
    {
        if ($position >= count($tokens)) {
            throw new InvalidArgumentException('Unexpected end of the expression.');
        }

        $token = $tokens[ $position ];

        if ('LPAREN' === $token['type']) {
            ++$position;

            $inner = $this->parseOr($tokens, $position, $depth);

            if ($position >= count($tokens) || 'RPAREN' !== $tokens[ $position ]['type']) {
                throw new InvalidArgumentException('Unbalanced parentheses.');
            }

            ++$position;

            return $inner;
        }

        if ('VAR' === $token['type']) {
            ++$position;

            return array('type' => 'VAR', 'name' => $token['value']);
        }

        if ('CONST' === $token['type']) {
            ++$position;

            return array('type' => 'CONST', 'value' => '1' === $token['value']);
        }

        throw new InvalidArgumentException(sprintf('Unexpected token "%s".', $token['value']));
    }

    /**
     * One more grammar layer, refusing past the cap.
     *
     * @throws InvalidArgumentException When the expression nests too deeply.
     */
    private function deeper(int $depth): int
    {
        if ($depth + 1 > self::MAX_PARSE_DEPTH) {
            throw new InvalidArgumentException('The expression nests too deeply.');
        }

        return $depth + 1;
    }

    /**
     * Whether the cursor sits on one of the listed operators.
     *
     * @param list<array{type: string, value: string}> $tokens    Token list.
     * @param int                                      $position  Cursor.
     * @param list<string>                             $operators Operator names to match.
     */
    private function peekOperator(array $tokens, int $position, array $operators): bool
    {
        if ($position >= count($tokens)) {
            return false;
        }

        $token = $tokens[ $position ];

        return 'OP' === $token['type'] && in_array($token['value'], $operators, true);
    }

    /**
     * The variables a syntax tree references, alphabetically.
     *
     * @param array<string, mixed> $tree Syntax tree.
     *
     * @return list<string>
     */
    private function collectVariables(array $tree): array
    {
        $found = array();

        $this->walk($tree, $found);

        ksort($found);

        return array_keys($found);
    }

    /**
     * @param array<string, mixed> $node  Syntax tree node.
     * @param array<string, true>  $found Names seen, by reference.
     */
    private function walk(array $node, array &$found): void
    {
        if ('VAR' === $node['type']) {
            $found[ (string) $node['name'] ] = true;

            return;
        }

        if (isset($node['left']) && is_array($node['left'])) {
            $this->walk($node['left'], $found);
        }

        if (isset($node['right']) && is_array($node['right'])) {
            $this->walk($node['right'], $found);
        }
    }

    /**
     * Evaluate a syntax tree under one assignment.
     *
     * @param array<string, mixed> $tree        Syntax tree.
     * @param array<string, bool>  $environment Assignment of each variable.
     */
    private function evaluate(array $tree, array $environment): bool
    {
        $type = (string) $tree['type'];

        if ('VAR' === $type) {
            return true === ( $environment[ (string) $tree['name'] ] ?? false );
        }

        if ('CONST' === $type) {
            return true === ( $tree['value'] ?? false );
        }

        if ('NOT' === $type) {
            return ! $this->evaluate(self::node($tree['right'] ?? null), $environment);
        }

        return $this->apply(
            $type,
            $this->evaluate(self::node($tree['left'] ?? null), $environment),
            $this->evaluate(self::node($tree['right'] ?? null), $environment)
        );
    }

    /**
     * A child node from an untyped syntax-tree slot.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException When the tree does not carry a node where one belongs.
     */
    private static function node(mixed $node): array
    {
        if (! is_array($node)) {
            throw new LogicException('Malformed syntax tree.');
        }

        return $node;
    }

    /**
     * A binary operator over two evaluated operands.
     *
     * @throws LogicException When the operator is one the parser cannot emit.
     */
    private function apply(string $operator, bool $left, bool $right): bool
    {
        switch ($operator) {
            case 'AND':
                return $left && $right;
            case 'OR':
                return $left || $right;
            case 'NAND':
                return ! ( $left && $right );
            case 'NOR':
                return ! ( $left || $right );
            case 'XOR':
                return $left !== $right;
            case 'XNOR':
                return $left === $right;
        }

        throw new LogicException(sprintf('Unknown operator "%s" in the syntax tree.', $operator));
    }

    /**
     * A markdown-style table.
     *
     * @param list<string>               $variables Column order.
     * @param list<array<string, int>>   $rows      Row data.
     */
    private function renderMarkdownTable(array $variables, array $rows): string
    {
        $headers = array_merge($variables, array('Result'));
        $lines   = array();
        $lines[] = '| ' . implode(' | ', $headers) . ' |';

        $separators = array();

        foreach ($headers as $header) {
            $separators[] = str_repeat('-', max(3, strlen($header)));
        }

        $lines[] = '| ' . implode(' | ', $separators) . ' |';

        foreach ($rows as $row) {
            $cells = array();

            foreach ($variables as $name) {
                $cells[] = (string) $row[ $name ];
            }

            $cells[] = (string) $row[ self::RESULT_KEY ];
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * A plain-text table.
     *
     * @param list<string>             $variables Column order.
     * @param list<array<string, int>> $rows      Row data.
     */
    private function renderTextTable(array $variables, array $rows): string
    {
        $lines   = array();
        $lines[] = implode(' ', $variables) . ' | Result';

        foreach ($rows as $row) {
            $cells = array();

            foreach ($variables as $name) {
                $cells[] = (string) $row[ $name ];
            }

            $lines[] = implode(' ', $cells) . ' | ' . (string) $row[ self::RESULT_KEY ];
        }

        return implode("\n", $lines);
    }

    /**
     * The table as a LaTeX `array` environment.
     *
     * @param list<string>             $variables Column order.
     * @param list<array<string, int>> $rows      Row data.
     */
    private function renderLatexTable(array $variables, array $rows): string
    {
        $columns = str_repeat('c', count($variables)) . '|c';
        $header  = implode(' & ', $variables) . ' & \\text{Result}';
        $body    = array();

        foreach ($rows as $row) {
            $cells = array();

            foreach ($variables as $name) {
                $cells[] = (string) $row[ $name ];
            }

            $cells[] = (string) $row[ self::RESULT_KEY ];
            $body[]  = implode(' & ', $cells) . ' \\\\';
        }

        return '\\begin{array}{' . $columns . '} ' . $header . ' \\\\ \\hline '
            . implode(' ', $body) . ' \\end{array}';
    }

    /** Whether a byte is whitespace the scanner skips. */
    private function isSpace(string $character): bool
    {
        return 1 === preg_match('/^[ \t\n\r\x0B\x0C]$/', $character);
    }

    /** Whether a byte is an ASCII letter, so an identifier may start. */
    private function isLetter(string $character): bool
    {
        return 1 === preg_match('/^[A-Za-z]$/', $character);
    }

    /** Whether a byte may continue an identifier. */
    private function isIdentifierTail(string $character): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_]$/', $character);
    }

    /**
     * The whole character beginning at a byte offset, for an error message.
     *
     * The scanner works in bytes, so an unknown multibyte character would
     * otherwise be reported as a single mojibake byte.
     */
    private function characterAt(string $expression, int $offset): string
    {
        $byte = ord($expression[ $offset ]);

        $length = match (true) {
            0xC0 === ( $byte & 0xE0 ) => 2,
            0xE0 === ( $byte & 0xF0 ) => 3,
            0xF0 === ( $byte & 0xF8 ) => 4,
            default                   => 1,
        };

        return substr($expression, $offset, $length);
    }
}
