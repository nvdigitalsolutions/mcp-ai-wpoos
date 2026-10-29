/**
 * Date field component.
 */
interface DateFieldProps {
  label: string;
  value: string;
  required?: boolean;
  /** Validation message from the form schema (renders + sets aria-invalid). */
  error?: string;
  onChange: (value: string) => void;
}

export function DateField({ label, value, required, error, onChange }: DateFieldProps) {
  // Normalize date value to yyyy-mm-dd for the input.
  const formatted = value ? value.substring(0, 10) : '';

  return (
    <div style={{ marginBottom: 16 }}>
      <label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
        {label}{required && ' *'}
      </label>
      <input
        type="date"
        value={formatted}
        required={required}
        aria-invalid={error ? true : undefined}
        onChange={(e) => onChange(e.target.value)}
        className="regular-text"
        style={{ width: 200 }}
      />
      {error && <p style={{ color: '#b32d2e', margin: '4px 0 0' }}>{error}</p>}
    </div>
  );
}
