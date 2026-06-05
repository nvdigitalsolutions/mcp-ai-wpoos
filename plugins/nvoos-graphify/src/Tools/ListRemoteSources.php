<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

/**
 * Tool: nvoos_graphify_list_remote_sources
 *
 * Lists all configured remote source drivers and their current status.
 *
 * @since 1.0.0
 */
class ListRemoteSources extends AbstractTool
{
    /**
     * {@inheritdoc}
     */
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /** {@inheritdoc} */
    public function getSlug(): string
    {
        return 'nvoos_graphify_list_remote_sources';
    }

    /** {@inheritdoc} */
    public function getName(): string
    {
        return __( 'List Remote Sources', 'nvoos-graphify' );
    }

    /** {@inheritdoc} */
    public function getDescription(): string
    {
        return __( 'Returns all configured remote source drivers including their slug, driver type, human-readable label, enabled status, circuit-breaker state, last sync timestamp, last error message, and the capabilities supported by their driver. Use this to discover what remote sources are available before calling nvoos_graphify_sync_remote_source.', 'nvoos-graphify' );
    }

    /** {@inheritdoc} */
    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'enabled_only' => array(
                    'type'        => 'boolean',
                    'description' => __( 'If true, return only enabled sources.', 'nvoos-graphify' ),
                    'default'     => false,
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    /** {@inheritdoc} */
    public function getCapabilityFlags(): array
    {
        return array( 'read-only', 'cacheable' );
    }

    /**
     * Execute the tool.
     *
     * @param array<string,mixed> $arguments Tool arguments.
     * @param array<string,mixed> $context   Execution context.
     * @return array<string,mixed>|\WP_Error
     */
    public function execute( array $arguments = array(), array $context = array() )
    {
        // Remote sources require the Registry (Phase 7 feature).
        if ( ! class_exists( \NvoosGraphify\Remote\Registry::class ) ) {
            return array(
                'success'           => true,
                'sources'           => array(),
                'total'             => 0,
                'available_drivers' => array(),
            );
        }

        $enabled_only = ! empty( $arguments['enabled_only'] );
        $rows         = \NvoosGraphify\Graph\Db::listRemoteSources( $enabled_only ? array( 'enabled' => 1 ) : array() );
        $registry     = \NvoosGraphify\Plugin::instance()->getRemoteRegistry();

        $sources = array();
        foreach ( $rows as $row ) {
            $driver_instance = $registry->getDriver( $row->driver );
            $capabilities    = array();
            if ( $driver_instance ) {
                $capabilities = $driver_instance->getCapabilities();
            }

            $sources[] = array(
                'slug'          => $row->slug,
                'driver'        => $row->driver,
                'label'         => $row->label,
                'enabled'       => (bool) $row->enabled,
                'circuit_state' => $row->circuit_state,
                'last_sync_at'  => $row->last_sync_at,
                'last_error'    => $row->last_error,
                'rate_limit'    => $row->rate_limit,
                'capabilities'  => $capabilities,
            );
        }

        // Also list available driver types for reference.
        $available_drivers = array_keys( $registry->allDrivers() );

        return array(
            'success'           => true,
            'sources'           => $sources,
            'total'             => count( $sources ),
            'available_drivers' => $available_drivers,
        );
    }
}
