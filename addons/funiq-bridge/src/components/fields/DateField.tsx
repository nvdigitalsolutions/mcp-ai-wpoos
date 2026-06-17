/**
 * Date field component.
 */
interface DateFieldProps {
  label: string;
  value: string;
  required?: boolean;
  onChange: (value: string) => void;
}

export function DateField({ label, value, required, onChange }: DateFieldProps) {
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
        onChange={(e) => onChange(e.target.value)}
        className="regular-text"
        style={{ width: 200 }}
      />
    </div>
  );
}
