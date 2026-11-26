<?php

/**
 * View, update or reset the autonomous-loop circuit-breaker thresholds.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Configure_Circuit_Breaker` (Proposal 164 section
 * 164.78). The configuration lives in the settings store under
 * {@see AutonomousCircuitBreaker::CONFIG_KEY}; the breaker reads the same key.
 *
 * ## Pinned, and one correction
 *
 * - **`view` reports the values the breaker will actually use.** WordPress
 *   returns the raw stored value or the default, while its breaker writes the
 *   stored value through unclamped. This port normalises on the way out, so a
 *   hand-edited 0% threshold shows as the 10% floor rather than as a number the
 *   breaker will never honour.
 * - **`update` rejects unknown keys and out-of-range values by name**, keeps
 *   the rest of the stored configuration, and reports both lists. A rejected
 *   setting does not abort the accepted ones — that is the WordPress contract.
 * - **`reset` deletes the stored row**, so the defaults come from the code
 *   rather than from a copy that can drift.
 * - Values are read as `abs(int)`, matching WordPress's `absint`, so a negative
 *   input is considered at its magnitude rather than silently defaulted.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Application\Orchestration\AutonomousCircuitBreaker;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Tool\AbstractTool;

class ConfigureCircuitBreakerTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly SettingsStoreInterface $settings,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'configure_circuit_breaker';
    }

    public function getName(): string
    {
        return 'Configure Circuit Breaker';
    }

    public function getDescription(): string
    {
        return 'Configure circuit breaker sensitivity thresholds for autonomous orchestration sessions. Use "view" to read the effective settings, "update" to change them, "reset" to restore defaults.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action'   => array(
                    'type'        => 'string',
                    'enum'        => array('view', 'update', 'reset'),
                    'description' => 'View current settings, update specific settings, or reset to defaults (default: view).',
                ),
                'settings' => array(
                    'type'        => 'object',
                    'description' => 'Settings to update (required for "update"). Unknown keys and out-of-range values are rejected by name.',
                    'properties'  => array(
                        'error_threshold_pct'    => array(
                            'type'        => 'integer',
                            'description' => 'Error percentage threshold to open the circuit (10–100).',
                        ),
                        'volume_threshold'       => array(
                            'type'        => 'integer',
                            'description' => 'Minimum number of calls before evaluating the error rate (2–50).',
                        ),
                        'reset_timeout_sec'      => array(
                            'type'        => 'integer',
                            'description' => 'Seconds to wait before attempting a reset (60–3600).',
                        ),
                        'max_consecutive_errors' => array(
                            'type'        => 'integer',
                            'description' => 'Maximum consecutive errors before opening the circuit (1–20).',
                        ),
                        'no_progress_cycles'     => array(
                            'type'        => 'integer',
                            'description' => 'Number of identical-output cycles before detecting stagnation (2–10).',
                        ),
                    ),
                ),
            ),
            'required'             => array('action'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $action = $this->stringParam($arguments, 'action', 'view');

        return match ($action) {
            'view'   => $this->view(),
            'update' => $this->update($arguments),
            'reset'  => $this->reset(),
            default  => $this->errors->create('unknown_action', sprintf('Unknown action: %s', $action)),
        };
    }

    private function view(): array
    {
        return $this->success(
            'Current circuit breaker configuration retrieved.',
            array(
                'settings' => $this->effective(),
                'defaults' => $this->defaults(),
            )
        );
    }

    /**
     * @param array<string, mixed> $arguments Arguments.
     */
    private function update(array $arguments): mixed
    {
        $submitted = isset($arguments['settings']) && is_array($arguments['settings'])
            ? $arguments['settings']
            : array();

        if (array() === $submitted) {
            return $this->errors->create(
                'missing_settings',
                'Missing required argument: settings (object with threshold values).'
            );
        }

        $stored   = $this->stored();
        $updated  = array();
        $rejected = array();

        foreach ($submitted as $key => $value) {
            $key = (string) $key;

            if (! isset(AutonomousCircuitBreaker::BOUNDS[ $key ])) {
                $rejected[] = $key;
                continue;
            }

            $bounds = AutonomousCircuitBreaker::BOUNDS[ $key ];
            $int    = abs((int) $value);

            if ($int < $bounds['min'] || $int > $bounds['max']) {
                $rejected[] = sprintf('%s (must be %d–%d)', $key, $bounds['min'], $bounds['max']);
                continue;
            }

            $stored[ $key ] = $int;
            $updated[]      = $key;
        }

        $this->settings->set(AutonomousCircuitBreaker::CONFIG_KEY, $stored);

        return $this->success(
            sprintf('Updated %d setting(s). %d rejected.', count($updated), count($rejected)),
            array(
                'updated'  => $updated,
                'rejected' => $rejected,
                'settings' => $this->effective(),
                'defaults' => $this->defaults(),
            )
        );
    }

    private function reset(): array
    {
        $this->settings->delete(AutonomousCircuitBreaker::CONFIG_KEY);

        return $this->success(
            'Circuit breaker configuration reset to defaults.',
            array(
                'settings' => $this->defaults(),
                'defaults' => $this->defaults(),
            )
        );
    }

    /**
     * The stored map, without defaults.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $stored = $this->settings->get(AutonomousCircuitBreaker::CONFIG_KEY, array());

        return is_array($stored) ? $stored : array();
    }

    /**
     * The values the breaker will use.
     *
     * @return array<string, int>
     */
    private function effective(): array
    {
        return AutonomousCircuitBreaker::normalise($this->stored());
    }

    /**
     * @return array<string, int>
     */
    private function defaults(): array
    {
        $defaults = array();

        foreach (AutonomousCircuitBreaker::BOUNDS as $key => $bounds) {
            $defaults[ $key ] = $bounds['default'];
        }

        return $defaults;
    }
}
