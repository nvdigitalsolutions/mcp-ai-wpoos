/**
 * Textarea field component.
 */
interface TextareaFieldProps {
  label: string;
  value: string;
  required?: boolean;
  /** Validation message from the form schema (renders + sets aria-invalid). */
  error?: string;
  onChange: (value: string) => void;
}

export function TextareaField({ label, value, required, error, onChange }: TextareaFieldProps) {
  return (
    <div style={{ marginBottom: 16 }}>
      <label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
        {label}{required && ' *'}
      </label>
      <textarea
        className="large-text"
        rows={5}
        value={value}
        required={required}
        aria-invalid={error ? true : undefined}
        onChange={(e) => onChange(e.target.value)}
        style={{ width: '100%', maxWidth: 640 }}
      />
      {error && <p style={{ color: '#b32d2e', margin: '4px 0 0' }}>{error}</p>}
    </div>
  );
}
