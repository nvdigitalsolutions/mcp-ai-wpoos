/**
 * Select field — native <select> with an explicit first option when the
 * value is empty, so the control never claims a value the user did not
 * choose. Options come from the collection config (the same source that
 * builds the zod enum), keeping config, validation and UI in one place.
 * The label is programmatically associated (useId + htmlFor) so screen
 * readers name the control.
 */
import { useId } from '@wordpress/element';

interface SelectFieldProps {
  label: string;
  value: string;
  required?: boolean;
  /** Validation message from the form schema (renders + sets aria-invalid). */
  error?: string;
  options: Array<{ value: string; label: string }>;
  onChange: (value: string) => void;
}

export function SelectField({
  label,
  value,
  required,
  error,
  options,
  onChange,
}: SelectFieldProps) {
  const id = useId();

  return (
    <div style={{ marginBottom: 16 }}>
      <label htmlFor={id} style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
        {label}
        {required && ' *'}
      </label>
      <select
        id={id}
        value={value}
        required={required}
        aria-invalid={error ? true : undefined}
        onChange={(e) => onChange(e.target.value)}
        style={{ width: '100%', maxWidth: 400 }}
      >
        {!options.some((option) => option.value === value) && (
          <option value="" disabled>
            Select…
          </option>
        )}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      {error && <p style={{ color: '#b32d2e', margin: '4px 0 0' }}>{error}</p>}
    </div>
  );
}
