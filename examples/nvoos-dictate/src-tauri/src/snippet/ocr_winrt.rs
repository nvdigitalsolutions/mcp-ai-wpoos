//! `Windows.Media.Ocr` engine — the built-in Windows OCR stack behind
//! PowerToys Text Extractor / Text Grab (Proposal 100, M2).
//!
//! Runs entirely on-device; recognized languages follow the Windows OCR
//! language packs (`Language.OCR*` capabilities). Recognition is async in
//! WinRT but this module blocks on the operation — callers must run it on
//! the snippet worker thread, never the UI thread.
//!
//! Compiled only on Windows with the `snippet` feature; the windows-crate
//! WinRT namespace features are declared on the target dependency itself
//! (namespace-derived names: `Media_Ocr` = Windows.Media.Ocr).

use crate::error::{AppError, AppResult};
use crate::snippet::{SnippetText, SnippetWord};

use windows::core::HSTRING;
use windows::Globalization::Language;
use windows::Graphics::Imaging::{BitmapPixelFormat, SoftwareBitmap};
use windows::Media::Ocr::OcrEngine;
use windows::Storage::Streams::{DataReader, DataWriter, InMemoryRandomAccessStream};

/// The first language WinRT reports when none is available (kept as the
/// fallback marker so callers can distinguish "no packs at all").
pub const NO_LANGUAGES_MARKER: &str = "__none__";

/// Build an `OcrEngine` for a BCP-47 `language` (e.g. "en-US"). An empty or
/// unknown tag falls back to the first available recognizer language.
/// Errors with a Text-Extractor-compatible message when no OCR language
/// pack is installed at all.
pub fn create_engine(language: &str) -> AppResult<OcrEngine> {
    if !language.trim().is_empty() {
        let tag = HSTRING::from(language.trim());
        if let Ok(lang) = Language::CreateLanguage(&tag) {
            if let Ok(engine) = OcrEngine::TryCreateFromLanguage(&lang) {
                return Ok(engine);
            }
        }
        tracing::warn!(language, "requested OCR language unavailable; falling back");
    }
    let languages = list_languages();
    let first = languages.first().ok_or_else(|| {
        AppError::NotConfigured(
            "no OCR languages are installed — install a Windows OCR language \
             pack (Settings > Time & Language) or download the RapidOCR engine \
             from the Screen OCR settings"
                .into(),
        )
    })?;
    let lang = Language::CreateLanguage(&HSTRING::from(first.as_str()))
        .map_err(|e| AppError::Engine(format!("language create failed: {e}")))?;
    OcrEngine::TryCreateFromLanguage(&lang)
        .map_err(|e| AppError::Engine(format!("OCR engine create failed: {e}")))
}

/// List the BCP-47 tags of installed OCR recognizer languages.
pub fn list_languages() -> Vec<String> {
    let mut out = Vec::new();
    if let Ok(langs) = OcrEngine::AvailableRecognizerLanguages() {
        for lang in langs {
            if let Ok(tag) = lang.LanguageTag() {
                out.push(tag.to_string_lossy());
            }
        }
    }
    out
}

/// Wrap raw BGRA8 pixels (`w`×`h`, `w*h*4` bytes) into a WinRT
/// `SoftwareBitmap` without any copy beyond the ones WinRT makes. The
/// sanctioned windows-rs path (the official OCR sample's): write the bytes
/// into an in-memory stream with `DataWriter`, read them back as an
/// `IBuffer` with `DataReader::ReadBuffer`, and hand that to
/// `CreateCopyFromBuffer`. (The alternative `IMemoryBufferByteAccess` QI
/// is compile-blocked by windows-rs's interface-hierarchy check, and a
/// direct `Buffer::cast` fails E_NOINTERFACE at runtime.)
fn bgra_to_bitmap(bgra: &[u8], w: u32, h: u32) -> AppResult<SoftwareBitmap> {
    let len = (w as usize) * (h as usize) * 4;
    if bgra.len() < len {
        return Err(AppError::Other("BGRA buffer undersized".into()));
    }
    let stream = InMemoryRandomAccessStream::new()
        .map_err(|e| AppError::Engine(format!("stream create failed: {e}")))?;
    {
        let writer = DataWriter::CreateDataWriter(&stream)
            .map_err(|e| AppError::Engine(format!("data writer create failed: {e}")))?;
        writer
            .WriteBytes(&bgra[..len])
            .map_err(|e| AppError::Engine(format!("buffer write failed: {e}")))?;
        writer
            .StoreAsync()
            .and_then(|op| op.get())
            .map_err(|e| AppError::Engine(format!("buffer store failed: {e}")))?;
        writer
            .DetachStream()
            .map_err(|e| AppError::Engine(format!("buffer detach failed: {e}")))?;
    }
    stream
        .Seek(0)
        .map_err(|e| AppError::Engine(format!("stream seek failed: {e}")))?;
    let reader = DataReader::CreateDataReader(&stream)
        .map_err(|e| AppError::Engine(format!("data reader create failed: {e}")))?;
    // The reader's internal buffer starts empty — LoadAsync pulls the
    // bytes from the stream before ReadBuffer can consume them.
    reader
        .LoadAsync(len as u32)
        .and_then(|op| op.get())
        .map_err(|e| AppError::Engine(format!("buffer load failed: {e}")))?;
    let buffer = reader
        .ReadBuffer(len as u32)
        .map_err(|e| AppError::Engine(format!("buffer read failed: {e}")))?;
    SoftwareBitmap::CreateCopyFromBuffer(&buffer, BitmapPixelFormat::Bgra8, w as i32, h as i32)
        .map_err(|e| AppError::Engine(format!("SoftwareBitmap create failed: {e}")))
}

/// Recognize a BGRA8 image (`w`×`h`) into engine-agnostic `SnippetText`.
/// WinRT gives per-word boxes; line text is rebuilt by joining words with
/// spaces (WinRT's `OcrLine::Text` omits inter-word spaces).
pub fn recognize_bgra(engine: &OcrEngine, bgra: &[u8], w: u32, h: u32) -> AppResult<SnippetText> {
    let bitmap = bgra_to_bitmap(bgra, w, h)?;
    let operation = engine
        .RecognizeAsync(&bitmap)
        .map_err(|e| AppError::Engine(format!("RecognizeAsync failed: {e}")))?;
    let result = operation
        .get()
        .map_err(|e| AppError::Engine(format!("recognition failed: {e}")))?;

    let mut lines: Vec<String> = Vec::new();
    let mut words: Vec<SnippetWord> = Vec::new();
    let mut total_conf: f32 = 0.0;
    let mut conf_count: u32 = 0;
    for line in result
        .Lines()
        .map_err(|e| AppError::Engine(format!("line enumeration failed: {e}")))?
    {
        let mut line_words: Vec<String> = Vec::new();
        for word in line
            .Words()
            .map_err(|e| AppError::Engine(format!("word enumeration failed: {e}")))?
        {
            let text = word.Text().map(|t| t.to_string_lossy()).unwrap_or_default();
            if !text.is_empty() {
                let rect = word.BoundingRect().unwrap_or_default();
                words.push(SnippetWord {
                    text: text.clone(),
                    x: rect.X.max(0.0) as u32,
                    y: rect.Y.max(0.0) as u32,
                    w: rect.Width.max(0.0) as u32,
                    h: rect.Height.max(0.0) as u32,
                });
                line_words.push(text);
                total_conf += 1.0; // WinRT exposes no per-word confidence
                conf_count += 1;
            }
        }
        if !line_words.is_empty() {
            lines.push(line_words.join(" "));
        }
    }
    Ok(SnippetText {
        text: lines.join("\n"),
        words,
        engine: "winrt".into(),
        confidence: if conf_count > 0 {
            Some(total_conf / conf_count as f32)
        } else {
            None
        },
    })
}

/// Convenience for the M0 spike: decode a PNG from memory (RGBA → straight
/// BGRA, alpha blended over white) and recognize it. Not on the app's hot
/// path (the live capture feeds `recognize_bgra` directly).
pub fn recognize_png(engine: &OcrEngine, png: &[u8]) -> AppResult<SnippetText> {
    let img = image::load_from_memory(png)
        .map_err(|e| AppError::Engine(format!("PNG decode failed: {e}")))?;
    let rgba = img.to_rgba8();
    let (w, h) = rgba.dimensions();
    let mut bgra = Vec::with_capacity((w * h * 4) as usize);
    for px in rgba.pixels() {
        let a = px[3] as f32 / 255.0;
        // Blend over white so anti-aliased glyph edges survive the BGR swap.
        bgra.push(((px[2] as f32 * a + 255.0 * (1.0 - a)).round()) as u8);
        bgra.push(((px[1] as f32 * a + 255.0 * (1.0 - a)).round()) as u8);
        bgra.push(((px[0] as f32 * a + 255.0 * (1.0 - a)).round()) as u8);
        bgra.push(255);
    }
    recognize_bgra(engine, &bgra, w, h)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn bgra_bitmap_roundtrip_size() {
        // 2x2 white BGRA.
        let bgra = vec![255u8; 16];
        let bitmap = bgra_to_bitmap(&bgra, 2, 2).unwrap();
        assert_eq!(bitmap.PixelWidth().unwrap(), 2);
        assert_eq!(bitmap.PixelHeight().unwrap(), 2);
    }

    #[test]
    fn bgra_bitmap_rejects_undersized_buffer() {
        assert!(bgra_to_bitmap(&[0u8; 8], 4, 4).is_err());
    }
}
