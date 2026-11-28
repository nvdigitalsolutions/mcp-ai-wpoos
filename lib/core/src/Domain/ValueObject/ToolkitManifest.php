<?php

/**
 * Toolkit manifest — the declarative contract for a Pro toolkit.
 *
 * A toolkit is a JSON document describing the settings, entities, columns and
 * dashboard cards that the generic Pro console screens render. Nothing in the
 * manifest is executable: the engine maps it onto one generic controller, so a
 * new family ships as data rather than bespoke PHP pages (Proposal 090-B, §8).
 *
 * Validation fails closed: unknown keys, unknown types, dangling references and
 * duplicate keys are all errors. A manifest that does not validate is never
 * served.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final class ToolkitManifest
{
    /**
     * Screens the console can render from a manifest.
     *
     * `console` is the toolkit's own operational surface: the shared engine
     * renders the frame and the tab, and a toolkit-specific component supplies
     * the panel (the database toolkit's connections, scopes, audit and health).
     * It is in the vocabulary rather than in a per-toolkit manifest key because
     * the engine has to know the id is legal before it can render the tab.
     */
    public const SCREENS = array( 'settings', 'entities', 'research', 'consolidate', 'dashboard', 'console' );

    /** Setting widget types. */
    public const SETTING_TYPES = array( 'text', 'textarea', 'number', 'boolean', 'select', 'secret' );

    /** Column value types the entity workbench knows how to render and sort. */
    public const COLUMN_TYPES = array( 'string', 'number', 'currency', 'boolean', 'date', 'enum' );

    /** Aggregates the dashboard card engine can compute. */
    public const AGGREGATES = array( 'count', 'sum', 'avg', 'min', 'max' );

    /** Filter operators accepted by dashboard cards. */
    public const FILTER_OPS = array( 'eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'like' );

    private const TOP_LEVEL_KEYS = array(
        'slug',
        'name',
        'version',
        'description',
        'icon',
        'feature',
        'screens',
        'settings',
        'tab_labels',
        'entities',
        'dashboard',
    );

    private const SLUG_PATTERN       = '/^[a-z][a-z0-9-]{1,63}$/';
    private const KEY_PATTERN        = '/^[a-z][a-z0-9_]{0,63}$/';
    private const VERSION_PATTERN    = '/^\d+\.\d+\.\d+$/';

    /**
     * @param array<string, mixed> $raw Validated manifest document.
     */
    private function __construct(private readonly array $raw)
    {
    }

    /**
     * Build a manifest from an already-validated document.
     *
     * @param array<string, mixed> $raw Manifest document.
     *
     * @throws \InvalidArgumentException When the document does not validate.
     */
    public static function fromArray(array $raw): self
    {
        $errors = self::validate($raw);

        if (array() !== $errors) {
            throw new \InvalidArgumentException('Invalid toolkit manifest: ' . \implode('; ', $errors));
        }

        return new self(self::normalize($raw));
    }

    /**
     * Validate a manifest document.
     *
     * @param array<string, mixed> $raw Manifest document.
     *
     * @return array<int, string> Human-readable errors; empty when valid.
     */
    public static function validate(array $raw): array
    {
        $errors = array();

        foreach (\array_keys($raw) as $key) {
            if (! \in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $errors[] = 'unknown top-level key "' . $key . '"';
            }
        }

        $slug = isset($raw['slug']) ? (string) $raw['slug'] : '';

        if (! \preg_match(self::SLUG_PATTERN, $slug)) {
            $errors[] = 'slug must match ' . self::SLUG_PATTERN;
        }

        foreach (array( 'name', 'feature' ) as $required) {
            if (! isset($raw[ $required ]) || '' === \trim((string) $raw[ $required ])) {
                $errors[] = $required . ' is required';
            }
        }

        if (isset($raw['feature']) && ! \preg_match(self::SLUG_PATTERN, (string) $raw['feature'])) {
            $errors[] = 'feature must match ' . self::SLUG_PATTERN;
        }

        if (! isset($raw['version']) || ! \preg_match(self::VERSION_PATTERN, (string) $raw['version'])) {
            $errors[] = 'version must be semantic (major.minor.patch)';
        }

        if (isset($raw['screens'])) {
            if (! \is_array($raw['screens'])) {
                $errors[] = 'screens must be a list';
            } else {
                foreach ($raw['screens'] as $screen) {
                    if (! \in_array($screen, self::SCREENS, true)) {
                        $label = \is_scalar($screen) ? (string) $screen : 'non-scalar';

                        $errors[] = 'screens: unknown screen "' . $label . '"';
                    }
                }

                if (\count($raw['screens']) !== \count(\array_unique($raw['screens']))) {
                    $errors[] = 'screens must be unique';
                }
            }
        }

        $entities = self::validateEntities($raw, $errors);

        self::validateSettings($raw, $errors);
        self::validateDashboard($raw, $errors, $entities, self::settingKeys($raw));

        return $errors;
    }

    public function slug(): string
    {
        return (string) $this->raw['slug'];
    }

    public function name(): string
    {
        return (string) $this->raw['name'];
    }

    public function feature(): string
    {
        return (string) $this->raw['feature'];
    }

    /**
     * @return array<int, string>
     */
    public function screens(): array
    {
        return \array_values((array) ( $this->raw['screens'] ?? array() ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function settings(): array
    {
        return \array_values((array) ( $this->raw['settings'] ?? array() ));
    }

    /**
     * Optional settings-screen tab labels, keyed by tab id.
     *
     * A manifest without tabs returns an empty map; {@see settingTabs()} then
     * presents every setting under one "General" tab so a renderer never
     * special-cases the untabbed shape.
     *
     * @return array<string, string>
     */
    public function tabLabels(): array
    {
        $labels = array();

        foreach ((array) ( $this->raw['tab_labels'] ?? array() ) as $key => $label) {
            $labels[ (string) $key ] = (string) $label;
        }

        return $labels;
    }

    /**
     * Settings grouped into render-ready tabs, in declared order.
     *
     * The grouping lives here rather than in each renderer so the panel and
     * the console cannot disagree about which tab a field belongs to.
     *
     * @return list<array{key: string, label: string, settings: list<array<string, mixed>>}>
     */
    public function settingTabs(): array
    {
        $settings = $this->settings();
        $labels   = $this->tabLabels();

        if (array() === $labels) {
            return array(
                array(
                    'key'      => 'general',
                    'label'    => 'General',
                    'settings' => $settings,
                ),
            );
        }

        $tabs  = array();
        $index = array();

        foreach ($labels as $key => $label) {
            $index[ $key ] = \count($tabs);
            $tabs[]        = array(
                'key'      => $key,
                'label'    => $label,
                'settings' => array(),
            );
        }

        foreach ($settings as $setting) {
            $tab = (string) ( $setting['tab'] ?? '' );

            if (isset($index[ $tab ])) {
                $tabs[ $index[ $tab ] ]['settings'][] = $setting;
            }
        }

        return $tabs;
    }

    /**
     * Declared entities; **empty for a settings-only toolkit**.
     *
     * CRM and chat channels own no engine records — their real entities live on
     * CPT/REST surfaces — so their manifests declare `screens: ["settings"]`
     * and omit `entities`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entities(): array
    {
        return \array_values((array) ( $this->raw['entities'] ?? array() ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entity(string $key): ?array
    {
        foreach ($this->entities() as $entity) {
            if ((string) ( $entity['key'] ?? '' ) === $key) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dashboardCards(): array
    {
        $dashboard = (array) ( $this->raw['dashboard'] ?? array() );

        return \array_values((array) ( $dashboard['cards'] ?? array() ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @param array<string, mixed>  $raw
     * @param array<int, string>    $errors Passed by reference.
     *
     * @return array<string, array<string, mixed>> Entity key → entity config.
     */
    private static function validateEntities(array $raw, array &$errors): array
    {
        // A **settings-only** toolkit omits `entities` entirely: its records
        // live on their own surfaces (CRM's deals/companies are CPTs), and the
        // engine owns no table for them. An explicit empty list is still
        // rejected — declaring `entities` and naming none is a mistake, not a
        // decision. Omission is the decision.
        if (! isset($raw['entities'])) {
            return array();
        }

        if (! \is_array($raw['entities'])) {
            $errors[] = 'entities must be a list';

            return array();
        }

        if (array() === $raw['entities']) {
            $errors[] = 'entities must be a non-empty list';

            return array();
        }

        $byKey = array();

        foreach (\array_values($raw['entities']) as $index => $entity) {
            $path = 'entities[' . $index . ']';

            if (! \is_array($entity)) {
                $errors[] = $path . ' must be an object';
                continue;
            }

            $key = (string) ( $entity['key'] ?? '' );

            if (! \preg_match(self::KEY_PATTERN, $key)) {
                $errors[] = $path . '.key must match ' . self::KEY_PATTERN;
            } elseif (isset($byKey[ $key ])) {
                $errors[] = $path . '.key "' . $key . '" is duplicated';
            }

            if ('' === \trim((string) ( $entity['label'] ?? '' ))) {
                $errors[] = $path . '.label is required';
            }

            $columns = $entity['columns'] ?? null;

            if (! \is_array($columns) || array() === $columns) {
                $errors[] = $path . '.columns must be a non-empty list';
                continue;
            }

            $columnKeys = array();

            foreach (\array_values($columns) as $columnIndex => $column) {
                $columnPath = $path . '.columns[' . $columnIndex . ']';

                if (! \is_array($column)) {
                    $errors[] = $columnPath . ' must be an object';
                    continue;
                }

                $columnKey = (string) ( $column['key'] ?? '' );

                if (! \preg_match(self::KEY_PATTERN, $columnKey)) {
                    $errors[] = $columnPath . '.key must match ' . self::KEY_PATTERN;
                } elseif (\in_array($columnKey, $columnKeys, true)) {
                    $errors[] = $columnPath . '.key "' . $columnKey . '" is duplicated';
                } else {
                    $columnKeys[] = $columnKey;
                }

                if ('' === \trim((string) ( $column['label'] ?? '' ))) {
                    $errors[] = $columnPath . '.label is required';
                }

                $type = (string) ( $column['type'] ?? '' );

                if (! \in_array($type, self::COLUMN_TYPES, true)) {
                    $errors[] = $columnPath . '.type "' . $type . '" is not one of ' . \implode('/', self::COLUMN_TYPES);
                }

                if ('enum' === $type) {
                    $values = $column['enum'] ?? null;

                    if (! \is_array($values) || array() === $values) {
                        $errors[] = $columnPath . '.enum must be a non-empty list for enum columns';
                    }
                }
            }

            $idField = (string) ( $entity['id_field'] ?? '' );

            if ('' === $idField) {
                $errors[] = $path . '.id_field is required';
            } elseif (! \in_array($idField, $columnKeys, true)) {
                $errors[] = $path . '.id_field "' . $idField . '" must name one of the columns';
            }

            $byKey[ $key ] = $entity;
        }

        return $byKey;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, string>   $errors Passed by reference.
     */
    private static function validateSettings(array $raw, array &$errors): void
    {
        $tabLabels = self::validateTabLabels($raw, $errors);

        if (! isset($raw['settings'])) {
            if (array() !== $tabLabels) {
                $errors[] = 'tab_labels requires settings';
            }

            return;
        }

        if (! \is_array($raw['settings'])) {
            $errors[] = 'settings must be a list';
            return;
        }

        $seen     = array();
        $usedTabs = array();

        foreach (\array_values($raw['settings']) as $index => $setting) {
            $path = 'settings[' . $index . ']';

            if (! \is_array($setting)) {
                $errors[] = $path . ' must be an object';
                continue;
            }

            $key = (string) ( $setting['key'] ?? '' );

            if (! \preg_match(self::KEY_PATTERN, $key)) {
                $errors[] = $path . '.key must match ' . self::KEY_PATTERN;
            } elseif (\in_array($key, $seen, true)) {
                $errors[] = $path . '.key "' . $key . '" is duplicated';
            } else {
                $seen[] = $key;
            }

            if ('' === \trim((string) ( $setting['label'] ?? '' ))) {
                $errors[] = $path . '.label is required';
            }

            $type = (string) ( $setting['type'] ?? '' );

            if (! \in_array($type, self::SETTING_TYPES, true)) {
                $errors[] = $path . '.type "' . $type . '" is not one of ' . \implode('/', self::SETTING_TYPES);
            }

            if ('select' === $type) {
                $options = $setting['options'] ?? null;

                if (! \is_array($options) || array() === $options) {
                    $errors[] = $path . '.options must be a non-empty list for select settings';
                }
            }

            // `rows` is a textarea hint and nothing else. A stray hint on
            // another type would be silently ignored by every renderer, which
            // is the kind of manifest lie the fail-closed rule exists to catch.
            if (isset($setting['rows'])) {
                if ('textarea' !== $type) {
                    $errors[] = $path . '.rows applies only to textarea settings';
                } elseif (! \is_int($setting['rows']) || $setting['rows'] < 2 || $setting['rows'] > 20) {
                    $errors[] = $path . '.rows must be an integer between 2 and 20';
                }
            }

            // Tabs are all-or-nothing: a manifest either declares a label for
            // every tab and assigns every setting to one, or it stays a single
            // General tab. A half-tabbed manifest would render an unlabelled
            // tab, so it is rejected rather than guessed at.
            $tab = $setting['tab'] ?? null;

            if (null !== $tab) {
                if (! \is_string($tab) || ! \preg_match(self::KEY_PATTERN, $tab)) {
                    $errors[] = $path . '.tab must match ' . self::KEY_PATTERN;
                } elseif (array() === $tabLabels) {
                    $errors[] = $path . '.tab requires a top-level tab_labels map';
                } elseif (! isset($tabLabels[ $tab ])) {
                    $errors[] = $path . '.tab "' . $tab . '" is not declared in tab_labels';
                } else {
                    $usedTabs[ $tab ] = true;
                }
            } elseif (array() !== $tabLabels) {
                $errors[] = $path . '.tab is required when tab_labels is declared';
            }
        }

        foreach (\array_keys($tabLabels) as $tab) {
            if (! isset($usedTabs[ $tab ])) {
                $errors[] = 'tab_labels.' . $tab . ' is not used by any setting';
            }
        }
    }

    /**
     * Validate the optional tab label map.
     *
     * @param array<string, mixed> $raw
     * @param array<int, string>   $errors Passed by reference.
     *
     * @return array<string, string> Declared labels; empty when the manifest has none.
     */
    private static function validateTabLabels(array $raw, array &$errors): array
    {
        if (! isset($raw['tab_labels'])) {
            return array();
        }

        if (! \is_array($raw['tab_labels']) || array() === $raw['tab_labels']) {
            $errors[] = 'tab_labels must be a non-empty object';
            return array();
        }

        $labels = array();

        foreach ($raw['tab_labels'] as $key => $label) {
            $key = (string) $key;

            if (! \preg_match(self::KEY_PATTERN, $key)) {
                $errors[] = 'tab_labels key "' . $key . '" must match ' . self::KEY_PATTERN;
                continue;
            }

            if (! \is_string($label) || '' === \trim($label)) {
                $errors[] = 'tab_labels.' . $key . ' must be a non-empty label';
                continue;
            }

            $labels[ $key ] = $label;
        }

        return $labels;
    }

    /**
     * @param array<string, mixed>                $raw
     * @param array<int, string>                  $errors   Passed by reference.
     * @param array<string, array<string, mixed>> $entities Validated entities by key.
     * @param array<string, true>                 $settings Declared setting keys.
     */
    private static function validateDashboard(array $raw, array &$errors, array $entities, array $settings): void
    {
        if (! isset($raw['dashboard'])) {
            return;
        }

        if (! \is_array($raw['dashboard'])) {
            $errors[] = 'dashboard must be an object';
            return;
        }

        $cards = $raw['dashboard']['cards'] ?? array();

        if (! \is_array($cards)) {
            $errors[] = 'dashboard.cards must be a list';
            return;
        }

        foreach (\array_values($cards) as $index => $card) {
            $path = 'dashboard.cards[' . $index . ']';

            if (! \is_array($card)) {
                $errors[] = $path . ' must be an object';
                continue;
            }

            if ('' === \trim((string) ( $card['key'] ?? '' ))) {
                $errors[] = $path . '.key is required';
            }

            if ('' === \trim((string) ( $card['label'] ?? '' ))) {
                $errors[] = $path . '.label is required';
            }

            $source  = (string) ( $card['source'] ?? '' );
            $entity  = null;

            if (\str_starts_with($source, 'entity:')) {
                $entityKey = \substr($source, 7);
                $entity    = $entities[ $entityKey ] ?? null;

                if (null === $entity) {
                    $errors[] = $path . '.source references unknown entity "' . $entityKey . '"';
                }
            } else {
                $errors[] = $path . '.source must be "entity:<key>"';
            }

            $aggregate = (string) ( $card['aggregate'] ?? '' );

            if (! \in_array($aggregate, self::AGGREGATES, true)) {
                $errors[] = $path . '.aggregate "' . $aggregate . '" is not one of ' . \implode('/', self::AGGREGATES);
                continue;
            }

            if (\in_array($aggregate, array( 'sum', 'avg', 'min', 'max' ), true)) {
                $column = (string) ( $card['column'] ?? '' );

                if ('' === $column) {
                    $errors[] = $path . '.column is required for ' . $aggregate;
                } elseif (null !== $entity && ! self::entityHasColumn($entity, $column)) {
                    $errors[] = $path . '.column "' . $column . '" is not a column of that entity';
                }
            }

            if (isset($card['filter'])) {
                $declared = $card['filter'];
                $isList   = \is_array($declared) && \array_is_list($declared);
                $filters  = $isList ? $declared : array( $declared );

                if ($isList && array() === $declared) {
                    $errors[] = $path . '.filter must declare at least one filter when given as a list';
                }

                foreach ($filters as $filterIndex => $filter) {
                    $filterPath = $isList ? $path . '.filter[' . $filterIndex . ']' : $path . '.filter';

                    if (! \is_array($filter)) {
                        $errors[] = $filterPath . ' must be an object';
                        continue;
                    }

                    $column = (string) ( $filter['column'] ?? '' );

                    if (null !== $entity && ! self::entityHasColumn($entity, $column)) {
                        $errors[] = $filterPath . '.column "' . $column . '" is not a column of that entity';
                    }

                    if (! \in_array((string) ( $filter['op'] ?? '' ), self::FILTER_OPS, true)) {
                        $errors[] = $filterPath . '.op must be one of ' . \implode('/', self::FILTER_OPS);
                    }

                    // A filter value is either a literal (value) or the runtime value of
                    // one of the toolkit's own settings (setting). Never both.
                    $hasValue   = \array_key_exists('value', $filter);
                    $hasSetting = \array_key_exists('setting', $filter);

                    if ($hasValue === $hasSetting) {
                        $errors[] = $filterPath . ' must declare exactly one of value or setting';
                    } elseif ($hasSetting) {
                        $setting = $filter['setting'];

                        if (! \is_string($setting) || ! isset($settings[ $setting ])) {
                            $errors[] = $filterPath . '.setting "' . (string) $setting . '" is not a declared setting';
                        }
                    }
                }
            }
        }
    }

    /**
     * Declared setting keys, so dashboard filters can bind to them.
     *
     * @param array<string, mixed> $raw
     * @return array<string, true>
     */
    private static function settingKeys(array $raw): array
    {
        $keys = array();

        foreach ((array) ( $raw['settings'] ?? array() ) as $setting) {
            $key = \is_array($setting) ? (string) ( $setting['key'] ?? '' ) : '';

            if ('' !== $key) {
                $keys[ $key ] = true;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $entity
     */
    private static function entityHasColumn(array $entity, string $column): bool
    {
        foreach ((array) ( $entity['columns'] ?? array() ) as $candidate) {
            if ((string) ( $candidate['key'] ?? '' ) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fill defaults so consumers never have to guess.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $raw): array
    {
        $raw['screens']  = \array_values((array) ( $raw['screens'] ?? array() ));
        $raw['settings'] = \array_values((array) ( $raw['settings'] ?? array() ));
        $raw['entities'] = \array_values((array) ( $raw['entities'] ?? array() ));

        foreach ($raw['entities'] as $index => $entity) {
            $raw['entities'][ $index ]['columns'] = \array_values((array) $entity['columns']);
            $raw['entities'][ $index ]['singular'] = (string) ( $entity['singular'] ?? $entity['label'] );
            $raw['entities'][ $index ]['icon']     = (string) ( $entity['icon'] ?? '' );
        }

        $raw['dashboard'] = array(
            'cards' => \array_values((array) ( $raw['dashboard']['cards'] ?? array() )),
        );

        return $raw;
    }
}
