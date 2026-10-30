/**
 * Number field component.
 */
interface NumberFieldProps {
  label: string;
  value: string | number;
  required?: boolean;
  min?: number;
  max?: number;
  step?: number;
  /** Validation message from the form schema (renders + sets aria-invalid). */
  error?: string;
  onChange: (value: number | null) => void;
}

export function NumberField({ label, value, required, min, max, step, error, onChange }: NumberFieldProps) {
  return (
    <div style={{ marginBottom: 16 }}>
      <label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
        {label}{required && ' *'}
      </label>
      <input
        type="number"
        className="small-text"
        value={value}
        required={required}
        min={min}
        max={max}
        step={step}
        aria-invalid={error ? true : undefined}
        onChange={(e) => {
          const v = e.target.value === '' ? (required ? 0 : null) : parseFloat(e.target.value);
          onChange(isNaN(v as number) ? null : v);
        }}
        style={{ width: 200 }}
      />
      {error && <p style={{ color: '#b32d2e', margin: '4px 0 0' }}>{error}</p>}
    </div>
  );
}
