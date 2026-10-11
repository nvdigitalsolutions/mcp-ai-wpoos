//! NV oOS client validation tests (no network — construction rules only).

use nvoos_dictate_lib::error::AppError;
use nvoos_dictate_lib::nvoos::NvoosClient;

#[test]
fn accepts_https_origin() {
    assert!(NvoosClient::new("https://example.com/".into(), "cred_x.SECRET".into()).is_ok());
}

#[test]
fn accepts_localhost_dev_origin() {
    assert!(NvoosClient::new("http://localhost:8000".into(), "cred_x.SECRET".into()).is_ok());
    assert!(NvoosClient::new("http://127.0.0.1:8000".into(), "cred_x.SECRET".into()).is_ok());
}

#[test]
fn rejects_plaintext_remote_origin() {
    // `NvoosClient` deliberately has no `Debug` (it holds a credential), so
    // assert via pattern matching instead of `unwrap_err()`.
    let result = NvoosClient::new("http://example.com".into(), "cred_x.SECRET".into());
    assert!(matches!(result, Err(AppError::Auth(_))));
}

#[test]
fn rejects_empty_credential() {
    let result = NvoosClient::new("https://example.com".into(), "".into());
    assert!(matches!(result, Err(AppError::Auth(_))));
}

#[test]
fn strips_trailing_slash() {
    let client = NvoosClient::new("https://example.com/".into(), "cred_x.SECRET".into()).unwrap();
    assert_eq!(client.base_url(), "https://example.com");
}
