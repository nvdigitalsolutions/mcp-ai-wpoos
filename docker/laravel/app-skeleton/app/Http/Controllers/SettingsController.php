<?php

namespace App\Http\Controllers;

use App\Support\SettingsSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class SettingsController
{
    public function index(SettingsStoreInterface $store): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->maskedValues($store),
        ]);
    }

    public function schema(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'tabs' => SettingsSchema::tabs(),
                'fields' => SettingsSchema::fields(),
            ],
        ]);
    }

    public function values(SettingsStoreInterface $store): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['values' => $this->maskedValues($store)],
        ]);
    }

    public function updateValues(Request $request, SettingsStoreInterface $store): JsonResponse
    {
        $changes = $request->input('values', $request->all());

        if (! is_array($changes)) {
            abort(422, 'The values payload must be an object.');
        }

        $fields = collect(SettingsSchema::fields())->keyBy('key');

        foreach ($changes as $key => $value) {
            if (! is_string($key) || ! $fields->has($key)) {
                continue;
            }

            $field = $fields->get($key);

            // A masked secret or an empty secret field means "unchanged".
            if ($field['sensitive'] && ($value === SettingsSchema::MASKED_PLACEHOLDER || $value === '' || $value === null)) {
                continue;
            }

            $store->set($key, $this->coerce($field, $value));
        }

        return response()->json([
            'success' => true,
            'data' => ['values' => $this->maskedValues($store)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function maskedValues(SettingsStoreInterface $store): array
    {
        $values = [];

        foreach (SettingsSchema::fields() as $field) {
            $value = $store->get($field['key'], $field['default'] ?? null);

            if ($field['sensitive']) {
                $value = is_string($value) && $value !== '' ? SettingsSchema::MASKED_PLACEHOLDER : '';
            }

            $values[$field['key']] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $field
     * @return mixed
     */
    private function coerce(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'checkbox' => (bool) $value,
            'number' => $this->number($field, $value),
            'select' => $this->select($field, $value),
            'url' => $this->url($value),
            'email' => $this->email($value),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * @param array<string, mixed> $field
     */
    private function number(array $field, mixed $value): int|float
    {
        if (! is_numeric($value)) {
            abort(422, sprintf('%s must be numeric.', $field['label']));
        }

        $number = $field['type'] === 'number' && ($field['step'] ?? 1) < 1
            ? (float) $value
            : (int) $value;

        if (isset($field['min']) && $number < $field['min']) {
            abort(422, sprintf('%s must be at least %s.', $field['label'], $field['min']));
        }

        if (isset($field['max']) && $number > $field['max']) {
            abort(422, sprintf('%s must be at most %s.', $field['label'], $field['max']));
        }

        return $number;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function select(array $field, mixed $value): string
    {
        $value = (string) $value;
        $options = $field['options'] ?? [];

        if (! array_key_exists($value, $options)) {
            abort(422, sprintf('%s is not a supported value.', $field['label']));
        }

        return $value;
    }

    private function url(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            abort(422, 'The URL is not valid.');
        }

        return $value;
    }

    private function email(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'The email address is not valid.');
        }

        return $value;
    }
}
