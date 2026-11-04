//! Document-model tier probe (Proposal 111): renders/loads one page and
//! sends it to the configured local DeepSeek-OCR-class server, printing the
//! returned Markdown. This is the live-verification gate for the tier.
//!
//! Run (requires a local server, e.g. `ollama pull deepseek-ocr` ~6.7 GB):
//!   cargo run --features document --example document_model_probe -- \
//!     <path.pdf|path.png> [--provider ollama|lm_studio] \
//!     [--model deepseek-ocr] [--base-url http://localhost:11434/v1]

use std::path::Path;

use nvoos_dictate_lib::error::AppResult;

/// The probe needs no persisted secrets — a no-op store satisfies the trait.
struct NoopSecretStore;

impl nvoos_dictate_lib::state::SecretStore for NoopSecretStore {
    fn get(&self, _key: &str) -> AppResult<Option<String>> {
        Ok(None)
    }
    fn set(&self, _key: &str, _value: &str) -> AppResult<()> {
        Ok(())
    }
    fn delete(&self, _key: &str) -> AppResult<()> {
        Ok(())
    }
}

fn main() -> Result<(), Box<dyn std::error::Error>> {
    let args: Vec<String> = std::env::args().skip(1).collect();
    let path = args.first().ok_or(
        "usage: document_model_probe <pdf|image> [--provider ollama|lm_studio] [--model deepseek-ocr] [--base-url http://localhost:11434/v1]",
    )?;
    let mut provider = "ollama".to_string();
    let mut model = "deepseek-ocr".to_string();
    let mut base_url = String::new();
    let mut it = args.iter().skip(1);
    while let Some(a) = it.next() {
        match a.as_str() {
            "--provider" => provider = it.next().cloned().unwrap_or_default(),
            "--model" => model = it.next().cloned().unwrap_or_default(),
            "--base-url" => base_url = it.next().cloned().unwrap_or_default(),
            _ => {}
        }
    }

    // Load one page RGB — RAW pixels, never the binarized scan profile.
    let (rgb, w, h): (Vec<u8>, u32, u32) = match path
        .rsplit('.')
        .next()
        .map(|e| e.to_lowercase())
        .unwrap_or_default()
        .as_str()
    {
        "pdf" => {
            let pdfium = nvoos_dictate_lib::document::pdf::bind()?;
            let doc = nvoos_dictate_lib::document::pdf::open(&pdfium, Path::new(path))?;
            let page = doc.pages().iter().next().ok_or("pdf has no pages")?;
            let img = nvoos_dictate_lib::document::pdf::render_page(&page, 300)?;
            let (w, h) = (img.width(), img.height());
            (img.as_raw().to_vec(), w, h)
        }
        _ => {
            let frame = nvoos_dictate_lib::document::images::page(Path::new(path))?;
            let (w, h) = (frame.width(), frame.height());
            (frame.as_raw().to_vec(), w, h)
        }
    };

    // Build the worker directly from an overridden default Settings (the
    // probe must not depend on the user's config.json).
    let mut settings = nvoos_dictate_lib::config::Settings::default();
    settings.document.model.enabled = true;
    settings.document.model.provider = provider.clone();
    settings.document.model.model = model.clone();
    if !base_url.is_empty() {
        match provider.as_str() {
            "lm_studio" => settings.lm_studio.base_url = base_url,
            _ => settings.ollama.base_url = base_url,
        }
    }
    let worker = nvoos_dictate_lib::document::model::build_document_model(&settings, &NoopSecretStore)
        .ok_or("could not build the document-model worker — is the tier enabled in the probe settings and the local server reachable?")?;

    let uri = nvoos_dictate_lib::document::model::page_to_png_data_uri(&rgb, w, h)?;
    let started = std::time::Instant::now();
    let out = worker.understand(&uri, "")?;
    println!(
        "provider: {provider}\nmodel:    {model}\npage:     {w}x{h} raw -> PNG data-URI\nlatency:  {} ms\n--- markdown ---\n{out}",
        started.elapsed().as_millis()
    );
    Ok(())
}
