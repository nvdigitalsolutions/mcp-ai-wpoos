<?php

/**
 * Evaluate a Boolean logic gate.
 *
 * Port of `WP_MCP_AI_Tool_Evaluate_Logic_Gate` (Proposal 164 section 164.165).
 * Evaluates AND, OR, NOT, NAND, NOR, XOR and XNOR over one or more inputs and,
 * on request, returns a NAND-only decomposition.
 *
 * ## One of only two tools in this family that is pure PHP
 *
 * Seven of the nine `math` tools delegate to a Node math.js service through a
 * WordPress filter (`wp_mcp_ai_mathjs_derivative` and friends) or to a graphing
 * filter, and refuse with `wp_mcp_ai_engine_missing` when nothing implements it.
 * This one and `generate_truth_table` do their own work: the gate evaluation, the
 * labels, the symbolic forms and the decompositions are all in PHP. WordPress
 * flagged it `local-only`, and here that flag is accurate.
 *
 * ## The n-ary NAND LaTeX dropped every input after the second
 *
 * The plain-text decomposition was right: `NAND(A, B, C)`. The LaTeX was built by
 * re-parsing that text with a hand-written converter that read exactly two
 * operands and then skipped to the closing parenthesis:
 *
 * ```php
 * $left  = $parse();   // A
 * $right = $parse();   // B
 * while ( ')' !== $expression_p[ $pos ] ) { ++$pos; }   // C discarded
 * ```
 *
 * So `NAND(A, B, C)` rendered as `(A \uparrow B)`, a **different function**:
 * two-input NAND, not the negation of a three-way conjunction. The port builds
 * the decomposition as a tree and renders it twice, so the two forms cannot
 * disagree, and an n-ary NAND renders as `\overline{A \cdot B \cdot C}`.
 *
 * n-ary NAND is not a fold of two-input NAND — `NAND(NAND(A,B),C)` is
 * `(A \wedge B) \vee \neg C` — so the tree keeps it as one n-ary node rather than
 * left-associating it into something else.
 *
 * ## Two smaller corrections
 *
 * `decompose_to_nand` was read with `! empty()`, so the string `'false'` switched
 * it **on**; it goes through `boolParam()`. An unknown `format` was silently
 * replaced with `both`; the port refuses it, because the schema declares three
 * values and a fourth is a caller mistake worth naming.
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

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\AbstractTool;

class EvaluateLogicGateTool extends AbstractTool
{
    /** The gates this tool evaluates. */
    public const GATES = array('AND', 'OR', 'NOT', 'NAND', 'NOR', 'XOR', 'XNOR');

    /** The formats the symbolic form is rendered in. */
    public const FORMATS = array('latex', 'text', 'both');

    /** The largest number of inputs one call accepts. */
    public const MAX_INPUTS = 16;

    public function __construct(ErrorFactoryInterface $e)
    {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'evaluate_logic_gate';
    }

    public function getName(): string
    {
        return 'Evaluate Logic Gate';
    }

    public function getDescription(): string
    {
        return 'Evaluates a Boolean gate (AND, OR, NOT, NAND, NOR, XOR, XNOR) over one to sixteen inputs and returns the result, the truth-table row, plain-text and LaTeX forms, and optionally a NAND-only decomposition in which every input is preserved.';
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
                'gate'              => array(
                    'type'        => 'string',
                    'description' => 'The gate to evaluate. NOT takes exactly one input; the rest take two or more and fold left.',
                    'enum'        => self::GATES,
                ),
                'inputs'            => array(
                    'type'        => 'array',
                    'description' => 'Boolean inputs, as true/false, 1/0 or "1"/"0".',
                    'items'       => array('type' => array('boolean', 'integer', 'string')),
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_INPUTS,
                ),
                'decompose_to_nand' => array(
                    'type'        => 'boolean',
                    'description' => 'Also return the equivalent expression built only from NAND. Default false.',
                    'default'     => false,
                ),
                'format'            => array(
                    'type'        => 'string',
                    'description' => 'Which symbolic forms to return. Default both.',
                    'enum'        => self::FORMATS,
                    'default'     => 'both',
                ),
            ),
            'required'             => array('gate', 'inputs'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $gate   = strtoupper(trim($this->stringParam($arguments, 'gate')));
        $format = trim($this->stringParam($arguments, 'format'));

        if ('' === $format) {
            $format = 'both';
        }

        $fields = array();

        if (! in_array($gate, self::GATES, true)) {
            $fields['gate'] = array('One of: ' . implode(', ', self::GATES) . '.');
        }

        if (! in_array($format, self::FORMATS, true)) {
            $fields['format'] = array('One of: ' . implode(', ', self::FORMATS) . '.');
        }

        $rawInputs = $arguments['inputs'] ?? null;

        if (! is_array($rawInputs)) {
            $fields['inputs'] = array('A list of boolean inputs.');
        } elseif (count($rawInputs) < 1 || count($rawInputs) > self::MAX_INPUTS) {
            $fields['inputs'] = array(sprintf('Between 1 and %d inputs.', self::MAX_INPUTS));
        }

        $bools = array();

        if (array() === $fields) {
            foreach (array_values($rawInputs) as $index => $value) {
                $coerced = $this->coerceBool($value);

                if (null === $coerced) {
                    $fields['inputs'] = array(
                        sprintf(
                            'Input %d is not unambiguously boolean: use true/false, 1/0 or "1"/"0".',
                            $index + 1
                        ),
                    );

                    break;
                }

                $bools[] = $coerced;
            }
        }

        if (array() === $fields) {
            if ('NOT' === $gate && 1 !== count($bools)) {
                $fields['inputs'] = array('NOT takes exactly one input.');
            } elseif ('NOT' !== $gate && count($bools) < 2) {
                $fields['inputs'] = array(sprintf('%s takes at least two inputs.', $gate));
            }
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The gate cannot be evaluated.', $fields);
        }

        $result = $this->evaluateGate($gate, $bools);
        $labels = $this->labels(count($bools));

        $row = array();

        foreach ($bools as $index => $bit) {
            $row[ $labels[ $index ] ] = $bit ? 1 : 0;
        }

        $row['result'] = $result ? 1 : 0;

        $data = array(
            'gate'      => $gate,
            'inputs'    => array_map(static fn (bool $b): int => $b ? 1 : 0, $bools),
            'result'    => $result,
            'truth_row' => $row,
        );

        if ('text' === $format || 'both' === $format) {
            $data['symbolic'] = $this->symbolicText($gate, $labels);
        }

        if ('latex' === $format || 'both' === $format) {
            $data['latex'] = $this->symbolicLatex($gate, $labels);
        }

        if ($this->boolParam($arguments, 'decompose_to_nand', false)) {
            $tree = $this->nandTree($gate, $labels);

            $data['nand_decomposition'] = array(
                'text'  => $this->renderText($tree),
                'latex' => $this->renderLatex($tree),
                'note'  => 'NAND is functionally complete: every gate can be built from NAND alone.',
            );
        }

        return $this->success(
            sprintf('Gate %1$s evaluated to %2$s.', $gate, $result ? '1' : '0'),
            $data
        );
    }

    /**
     * A value as a strict boolean, or null when it is ambiguous.
     */
    private function coerceBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if (0 === $value) {
                return false;
            }

            if (1 === $value) {
                return true;
            }

            return null;
        }

        if (is_string($value)) {
            $trimmed = strtolower(trim($value));

            if ('1' === $trimmed || 'true' === $trimmed) {
                return true;
            }

            if ('0' === $trimmed || 'false' === $trimmed) {
                return false;
            }
        }

        return null;
    }

    /**
     * The gate over the inputs.
     *
     * Multi-input AND, OR, NAND and NOR are the natural n-ary forms. XOR and XNOR
     * fold as parity, which is what repeated folding of the two-input form
     * computes, so three true inputs give true for XOR.
     *
     * @param list<bool> $bools
     */
    private function evaluateGate(string $gate, array $bools): bool
    {
        switch ($gate) {
            case 'NOT':
                return ! $bools[0];
            case 'AND':
                return ! in_array(false, $bools, true);
            case 'OR':
                return in_array(true, $bools, true);
            case 'NAND':
                return in_array(false, $bools, true);
            case 'NOR':
                return ! in_array(true, $bools, true);
            case 'XOR':
            case 'XNOR':
                $parity = false;

                foreach ($bools as $bit) {
                    $parity = $parity !== $bit;
                }

                return 'XNOR' === $gate ? ! $parity : $parity;
        }

        return false;
    }

    /**
     * Single-letter labels, A upward. The input cap keeps this inside the
     * alphabet.
     *
     * @return list<string>
     */
    private function labels(int $count): array
    {
        $labels = array();

        for ($i = 0; $i < $count; ++$i) {
            $labels[] = chr(ord('A') + $i);
        }

        return $labels;
    }

    /**
     * @param list<string> $labels
     */
    private function symbolicText(string $gate, array $labels): string
    {
        if ('NOT' === $gate) {
            return 'NOT ' . $labels[0];
        }

        return implode(' ' . $gate . ' ', $labels);
    }

    /**
     * @param list<string> $labels
     */
    private function symbolicLatex(string $gate, array $labels): string
    {
        switch ($gate) {
            case 'NOT':
                return '\\overline{' . $labels[0] . '}';
            case 'AND':
                return implode(' \\cdot ', $labels);
            case 'OR':
                return implode(' + ', $labels);
            case 'NAND':
                return '\\overline{' . implode(' \\cdot ', $labels) . '}';
            case 'NOR':
                return '\\overline{' . implode(' + ', $labels) . '}';
            case 'XOR':
                return implode(' \\oplus ', $labels);
            case 'XNOR':
                return '\\overline{' . implode(' \\oplus ', $labels) . '}';
        }

        return implode(',', $labels);
    }

    /**
     * The NAND-only decomposition as a tree.
     *
     * Leaves are labels. A node is `array( 'nand', a, b )` for a two-input NAND,
     * or `array( 'nand_n', args )` for an n-ary one, which is not a fold and so
     * is kept whole.
     *
     * @param list<string> $labels
     *
     * @return array<int|string, mixed>|string
     */
    private function nandTree(string $gate, array $labels): array|string
    {
        if ('NAND' === $gate) {
            return array('nand_n', $labels);
        }

        if ('NOT' === $gate) {
            return array('nand', $labels[0], $labels[0]);
        }

        $node = $labels[0];

        for ($i = 1; $i < count($labels); ++$i) {
            $node = $this->twoInputTree($gate, $node, $labels[ $i ]);
        }

        return $node;
    }

    /**
     * The NAND-only form of a two-input gate, as a tree.
     *
     * @param array<int|string, mixed>|string $a
     * @param array<int|string, mixed>|string $b
     *
     * @return array<int|string, mixed>
     */
    private function twoInputTree(string $gate, array|string $a, array|string $b): array
    {
        switch ($gate) {
            case 'AND':
                $nand = array('nand', $a, $b);

                return array('nand', $nand, $nand);
            case 'OR':
                return array('nand', array('nand', $a, $a), array('nand', $b, $b));
            case 'NOR':
                $or = array('nand', array('nand', $a, $a), array('nand', $b, $b));

                return array('nand', $or, $or);
            case 'XOR':
                $nab = array('nand', $a, $b);

                return array(
                    'nand',
                    array('nand', $a, $nab),
                    array('nand', $b, $nab),
                );
            case 'XNOR':
                $nab = array('nand', $a, $b);
                $xor = array(
                    'nand',
                    array('nand', $a, $nab),
                    array('nand', $b, $nab),
                );

                return array('nand', $xor, $xor);
        }

        return array('nand', $a, $b);
    }

    /**
     * @param array<int|string, mixed>|string $tree
     */
    private function renderText(array|string $tree): string
    {
        if (is_string($tree)) {
            return $tree;
        }

        if ('nand_n' === $tree[0]) {
            /** @var list<string> $args */
            $args = $tree[1];

            return 'NAND(' . implode(', ', $args) . ')';
        }

        return 'NAND(' . $this->renderText($tree[1]) . ', ' . $this->renderText($tree[2]) . ')';
    }

    /**
     * @param array<int|string, mixed>|string $tree
     */
    private function renderLatex(array|string $tree): string
    {
        if (is_string($tree)) {
            return $tree;
        }

        if ('nand_n' === $tree[0]) {
            /** @var list<string> $args */
            $args = $tree[1];

            return '\\overline{' . implode(' \\cdot ', $args) . '}';
        }

        return '(' . $this->renderLatex($tree[1]) . ' \\uparrow ' . $this->renderLatex($tree[2]) . ')';
    }
}
