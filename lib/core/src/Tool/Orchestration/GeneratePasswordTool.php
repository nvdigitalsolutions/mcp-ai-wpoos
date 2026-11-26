<?php

/**
 * Generate cryptographically secure passwords.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Generate_Password` (Proposal 164 section 164.77).
 * The generation itself lives in WordPress' vault encryption service; this port
 * keeps its two behaviours that matter and states the one it does not promise:
 *
 * - **Every character is drawn from the combined charset with `random_int()`**,
 *   so a password is not guaranteed to contain one character from each selected
 *   set. A 12-character password with all four sets enabled can still be all
 *   lowercase. The strength score reports the consequence; it is not corrected.
 * - **`avoid_ambiguous` filters `0 O I l 1` from letters and digits only.** The
 *   symbol set is fixed and contains none of them, so it is never filtered.
 * - **Length outside 12–128 is refused by the tool.** The service it delegates
 *   to silently resets an out-of-range length to 20; the tool validates first,
 *   and the tool is the caller's contract.
 *
 * The strength score is the service's: +1 each for reaching 8, 12 and 16
 * characters, +1 for each of lower/upper/digit/symbol present, then halved and
 * capped at 4. A 12-character lowercase-only password therefore scores 2.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Tool\AbstractTool;

class GeneratePasswordTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The symbol set, fixed and unfiltered. */
    private const SYMBOLS = '!@#$%^&*()_+-=[]{}|;:,.<>?';

    public function __construct(ErrorFactoryInterface $e)
    {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'generate_password';
    }

    public function getName(): string
    {
        return 'Generate Password';
    }

    public function getDescription(): string
    {
        return 'Generate cryptographically secure random passwords with customizable strength and character sets. Returns each password with a strength score. Use when you need to create strong passwords for new accounts or password rotation.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'length'          => array(
                    'type'        => 'integer',
                    'default'     => 16,
                    'minimum'     => 12,
                    'maximum'     => 128,
                    'description' => 'Password length (12-128 characters, default: 16).',
                ),
                'uppercase'       => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'Include uppercase letters A-Z.',
                ),
                'lowercase'       => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'Include lowercase letters a-z.',
                ),
                'numbers'         => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'Include numbers 0-9.',
                ),
                'symbols'         => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'Include symbols ' . self::SYMBOLS . '.',
                ),
                'avoid_ambiguous' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Avoid ambiguous characters (0OIl1).',
                ),
                'count'           => array(
                    'type'        => 'integer',
                    'default'     => 1,
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'description' => 'Number of passwords to generate (1-10, default: 1).',
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * `read-only` and `local-only`; not idempotent and not cacheable, because
     * the same arguments deliberately produce a different secret each call.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'local-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $length          = abs($this->intParam($arguments, 'length', 16));
        $uppercase       = $this->boolParam($arguments, 'uppercase', true);
        $lowercase       = $this->boolParam($arguments, 'lowercase', true);
        $numbers         = $this->boolParam($arguments, 'numbers', true);
        $symbols         = $this->boolParam($arguments, 'symbols', true);
        $avoidAmbiguous  = $this->boolParam($arguments, 'avoid_ambiguous', false);
        $count           = abs($this->intParam($arguments, 'count', 1));

        if ($length < 12 || $length > 128) {
            return $this->errors->create(
                'invalid_password_length',
                'Password length must be between 12 and 128 characters.'
            );
        }

        if ($count < 1 || $count > 10) {
            return $this->errors->create(
                'invalid_password_count',
                'Count must be between 1 and 10.'
            );
        }

        if (! $uppercase && ! $lowercase && ! $numbers && ! $symbols) {
            return $this->errors->create(
                'no_character_sets',
                'At least one character set must be enabled (uppercase, lowercase, numbers, or symbols).'
            );
        }

        $options = array(
            'length'          => $length,
            'uppercase'       => $uppercase,
            'lowercase'       => $lowercase,
            'numbers'         => $numbers,
            'symbols'         => $symbols,
            'avoid_ambiguous' => $avoidAmbiguous,
        );

        $passwords = array();

        for ($i = 0; $i < $count; $i++) {
            $password = $this->generate($length, $uppercase, $lowercase, $numbers, $symbols, $avoidAmbiguous);

            if ('' === $password) {
                return $this->errors->create(
                    'password_generation_failed',
                    'Failed to generate a secure random password.'
                );
            }

            $passwords[] = array(
                'password' => $password,
                'strength' => $this->strength($password),
                'length'   => strlen($password),
            );
        }

        if (1 === $count) {
            return $this->success(
                sprintf('Generated a %d-character password.', $length),
                array_merge($passwords[0], array('options' => $options))
            );
        }

        return $this->success(
            sprintf('Generated %d passwords.', $count),
            array(
                'passwords' => $passwords,
                'count'     => count($passwords),
                'options'   => $options,
            )
        );
    }

    /**
     * Build the charset and draw every character from it.
     *
     * An empty charset or a failed RNG answers `''`; the caller converts that
     * to the WordPress error code. No character-class guarantee is imposed,
     * deliberately — see the class comment.
     */
    private function generate(
        int $length,
        bool $uppercase,
        bool $lowercase,
        bool $numbers,
        bool $symbols,
        bool $avoidAmbiguous,
    ): string {
        $charset = '';

        if ($uppercase) {
            $charset .= $avoidAmbiguous ? 'ABCDEFGHJKLMNPQRSTUVWXYZ' : 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }

        if ($lowercase) {
            $charset .= $avoidAmbiguous ? 'abcdefghjkmnpqrstuvwxyz' : 'abcdefghijklmnopqrstuvwxyz';
        }

        if ($numbers) {
            $charset .= $avoidAmbiguous ? '23456789' : '0123456789';
        }

        if ($symbols) {
            $charset .= self::SYMBOLS;
        }

        if ('' === $charset) {
            return '';
        }

        $password = '';
        $size     = strlen($charset);

        try {
            for ($i = 0; $i < $length; $i++) {
                $password .= $charset[ random_int(0, $size - 1) ];
            }
        } catch (\Throwable $error) {
            return '';
        }

        return $password;
    }

    /**
     * The vault service's 0–4 score.
     */
    private function strength(string $password): int
    {
        $score  = 0;
        $length = strlen($password);

        if ($length >= 8) {
            ++$score;
        }

        if ($length >= 12) {
            ++$score;
        }

        if ($length >= 16) {
            ++$score;
        }

        if (1 === preg_match('/[a-z]/', $password)) {
            ++$score;
        }

        if (1 === preg_match('/[A-Z]/', $password)) {
            ++$score;
        }

        if (1 === preg_match('/[0-9]/', $password)) {
            ++$score;
        }

        if (1 === preg_match('/[^a-zA-Z0-9]/', $password)) {
            ++$score;
        }

        return min(4, (int) ($score / 2));
    }
}
