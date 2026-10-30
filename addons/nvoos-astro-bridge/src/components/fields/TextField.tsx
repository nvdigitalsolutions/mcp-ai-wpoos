/**
 * Text field component.
 */
interface TextFieldProps {
  label: string;
  value: string;
  required?: boolean;
  /** Validation message from the form schema (renders + sets aria-invalid). */
  error?: string;
  onChange: (value: string) => void;
}

export function TextField({ label, value, required, error, onChange }: TextFieldProps) {
  return (
    <div style={{ marginBottom: 16 }}>
      <label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
        {label}{required && ' *'}
      </label>
      <input
        type="text"
        className="regular-text"
        value={value}
        required={required}
        aria-invalid={error ? true : undefined}
        onChange={(e) => onChange(e.target.value)}
        style={{ width: '100%', maxWidth: 400 }}
      />
      {error && <p style={{ color: '#b32d2e', margin: '4px 0 0' }}>{error}</p>}
    </div>
  );
}
