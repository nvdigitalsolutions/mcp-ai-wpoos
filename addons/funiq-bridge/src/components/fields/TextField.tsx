/**
 * Text field component.
 */
interface TextFieldProps {
  label: string;
  value: string;
  required?: boolean;
  onChange: (value: string) => void;
}

export function TextField({ label, value, required, onChange }: TextFieldProps) {
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
        onChange={(e) => onChange(e.target.value)}
        style={{ width: '100%', maxWidth: 400 }}
      />
    </div>
  );
}
