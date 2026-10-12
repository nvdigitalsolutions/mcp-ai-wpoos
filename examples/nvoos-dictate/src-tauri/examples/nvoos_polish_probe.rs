//! End-to-end probe of the NV oOS Stage-2 polish pipeline against the REAL
//! configured site and the REAL keychain credential — the exact code path
//! `finalize_inner` runs (minus the PTT keypress). Sends one clearly-marked
//! test transcript to the connected assistant and prints elapsed time plus
//! the polished reply.
//!
//! With `--archive` it also writes one archive record, leaving server-side
//! proof of reception in the NV oOS console. `--raw` dumps the verbatim chat
//! and archive responses; `--model <id>` overrides the polish model for both
//! the provider call and the raw chat request.
//!
//! Usage: cargo run --example nvoos_polish_probe [--archive] [--raw] [--model <id>]

use std::time::Instant;

use nvoos_dictate_lib::cleanup::{self, run_deterministic};
use nvoos_dictate_lib::config::{AppCategory, Settings};
use nvoos_dictate_lib::nvoos::NvoosClient;

#[cfg(not(windows))]
use nvoos_dictate_lib::state::OsKeyringStore;
use nvoos_dictate_lib::state::SecretStore;
#[cfg(windows)]
use nvoos_dictate_lib::storage::win_cred::WindowsCredentialStore;

#[tokio::main]
async fn main() -> nvoos_dictate_lib::error::AppResult<()> {
    let do_archive = std::env::args().any(|a| a == "--archive");
    let raw_dump = std::env::args().any(|a| a == "--raw");
    let model_override = std::env::args()
        .position(|a| a == "--model")
        .and_then(|i| std::env::args().nth(i + 1))
        .filter(|m| !m.is_empty());

    let mut settings = Settings::load()?;
    println!("site:         {}", settings.nvoos.site_url);
    println!("assistant_id: {:?}", settings.nvoos.assistant_id);
    if let Some(model) = &model_override {
        println!("model override: {model}");
        settings.nvoos.polish_model = Some(model.clone());
    }

    // Force the nvoos provider for the probe regardless of the saved setting.
    settings.cleanup.provider = "nvoos".to_string();

    #[cfg(windows)]
    let secrets: &dyn SecretStore = &WindowsCredentialStore;
    #[cfg(not(windows))]
    let secrets: &dyn SecretStore = &OsKeyringStore;

    let credential = match secrets.get(nvoos_dictate_lib::state::KEY_NVOOS_CREDENTIAL)? {
        Some(c) => {
            println!(
                "credential:   stored in the OS keychain ({} chars)",
                c.len()
            );
            c
        }
        None => {
            println!("credential:   NOT STORED — store it in Settings > NV oOS first");
            return Ok(());
        }
    };

    // Build the provider exactly like the dictation path does.
    let provider = match cleanup::create_provider(&settings, secrets)? {
        Some(p) => p,
        None => {
            println!("provider:     disabled — cannot test");
            return Ok(());
        }
    };
    println!("provider:     {}", provider.id());

    let raw = "hello this is an end to end test from the nvoos dictate app i want to see if the transcript reaches the assistant and how long the round trip takes";
    let stage1 = run_deterministic(raw, AppCategory::Default, &[], false);
    println!("stage1:       {stage1:?}");
    println!("sending polish request…");

    let started = Instant::now();
    match tokio::time::timeout(
        std::time::Duration::from_secs(60),
        provider.polish(&stage1, AppCategory::Default),
    )
    .await
    {
        Ok(Ok((polished, meta))) => {
            let ms = started.elapsed().as_millis();
            println!("polish:       ok in {ms} ms");
            println!("reply:        {polished}");
            println!(
                "meta:         assistant={:?} session={:?} model={:?} provider={:?}",
                meta.assistant_id, meta.session_key, meta.model, meta.provider
            );
        }
        Ok(Err(e)) => println!("polish:       FAILED: {e}"),
        Err(_) => println!(
            "polish:       TIMED OUT after 60 s (provider may still have processed it server-side)"
        ),
    }

    if do_archive || raw_dump {
        println!("\n--- raw diagnostic dump ---");
        let client = NvoosClient::new(settings.nvoos.site_url.clone(), credential.clone())?;

        // Raw chat request, body printed verbatim (no secrets in the body).
        let chat_body = serde_json::json!({
            "assistant_id": settings.nvoos.assistant_id,
            "messages": [
                {
                    "role": "system",
                    "content": [ { "type": "text", "text": settings.nvoos.cleanup_prompt } ]
                },
                {
                    "role": "user",
                    "content": [ { "type": "text", "text": raw } ]
                }
            ],
            "options": if let Some(model) = &model_override {
                serde_json::json!({ "model": model })
            } else {
                serde_json::Value::Null
            },
            "stream": false
        });
        let resp = reqwest::Client::new()
            .post(format!(
                "{}/wp-json/mcp-ai/v1/chat",
                settings.nvoos.site_url.trim_end_matches('/')
            ))
            .bearer_auth(&credential)
            .json(&chat_body)
            .send()
            .await?;
        let status = resp.status().as_u16();
        let body = resp.text().await.unwrap_or_default();
        println!("chat status: {status}");
        println!("chat body (full): {body}");

        // Thread the session metadata from the chat response into the
        // archive call, exactly like the fixed dictation path does.
        let chat_payload: serde_json::Value = serde_json::from_str(&body).unwrap_or_default();
        let meta = nvoos_dictate_lib::nvoos::types::extract_polish_meta(&chat_payload);

        // Raw archive request with its 400 body.
        let archive_body = serde_json::json!({
            "assistant_id": meta.assistant_id.clone().or(settings.nvoos.assistant_id.clone()),
            "session_key": meta.session_key,
            "title": "nvoos-dictate — e2e-test",
            "messages": [
                { "role": "user", "content": [ { "type": "text", "text": raw } ] },
                { "role": "assistant", "content": [ { "type": "text", "text": stage1 } ] }
            ],
            "meta": { "source": "nvoos-dictate", "target_app": "e2e-test", "engine": "probe", "e2e_ms": 0 }
        });
        let resp = reqwest::Client::new()
            .post(format!(
                "{}/wp-json/mcp-ai/v1/chat-transcripts",
                settings.nvoos.site_url.trim_end_matches('/')
            ))
            .bearer_auth(&credential)
            .json(&archive_body)
            .send()
            .await?;
        let status = resp.status().as_u16();
        let body = resp.text().await.unwrap_or_default();
        println!("archive status: {status}");
        println!(
            "archive body: {}",
            body.chars().take(500).collect::<String>()
        );
        let _ = client;
    }

    Ok(())
}
