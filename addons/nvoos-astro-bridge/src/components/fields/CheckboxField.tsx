/**
 * Checkbox field component.
 */
interface CheckboxFieldProps {
  label: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
}

export function CheckboxField({ label, checked, onChange }: CheckboxFieldProps) {
  return (
    <div style={{ marginBottom: 16 }}>
      <label style={{ fontWeight: 600 }}>
        <input
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          style={{ marginRight: 8 }}
        />
        {label}
      </label>
    </div>
  );
}
