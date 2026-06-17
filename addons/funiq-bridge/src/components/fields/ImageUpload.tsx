/**
 * Image upload field — integrates with WordPress Media Library via block-editor.
 */
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { Button, ResponsiveWrapper } from '@wordpress/components';
import { useSelect } from '@wordpress/data';

interface ImageUploadProps {
  label: string;
  /** Attachment ID or empty string / '' when no image. */
  value: number | string | null;
  onChange: (attachmentId: number | null) => void;
}

export function ImageUpload({ label, value, onChange }: ImageUploadProps) {
  const mediaId = typeof value === 'number' && value > 0 ? value : null;

  const media = useSelect(
    (select) => {
      if (!mediaId) return null;
      const { getMedia } = select('core') as any;
      return getMedia ? getMedia(mediaId) : null;
    },
    [mediaId]
  );

  return (
    <div style={{ marginBottom: 16 }}>
      <label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>{label}</label>
      <MediaUploadCheck>
        <MediaUpload
          onSelect={(m: any) => onChange(m.id)}
          allowedTypes={['image']}
          value={mediaId}
          render={({ open }) => (
            <div>
              {mediaId && media ? (
                <div style={{ marginBottom: 8 }}>
                  <img
                    src={media.source_url}
                    alt={media.alt_text || ''}
                    style={{ maxWidth: 200, maxHeight: 200, display: 'block', marginBottom: 8 }}
                  />
                  <div style={{ display: 'flex', gap: 8 }}>
                    <Button variant="secondary" onClick={open}>
                      Replace
                    </Button>
                    <Button variant="tertiary" isDestructive onClick={() => onChange(null)}>
                      Remove
                    </Button>
                  </div>
                </div>
              ) : (
                <Button variant="secondary" onClick={open}>
                  Select Image
                </Button>
              )}
            </div>
          )}
        />
      </MediaUploadCheck>
    </div>
  );
}
