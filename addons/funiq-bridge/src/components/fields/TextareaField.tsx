/**
 * Textarea field component.
 */
interface TextareaFieldProps {
  label: string;
  value: string;
  required?: boolean;
  onChange: (value: string) => void;
}

export function TextareaField({ label, value, required, onChange }: TextareaFieldProps) {
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
        onChange={(e) => onChange(e.target.value)}
        style={{ width: '100%', maxWidth: 640 }}
      />
    </div>
  );
}
