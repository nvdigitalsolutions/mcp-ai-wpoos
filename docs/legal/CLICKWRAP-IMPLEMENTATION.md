# Clickwrap Implementation Guide — Checkout Consent & Terms Versioning

**Last updated:** September 10, 2026

This guide covers how to make NV Digital Solutions' legal terms enforceable
at the point of sale. It implements the clickwrap best practices that US
courts consistently uphold (see "Why This Matters" below) and is the
operational companion to our
[Terms of Service](TERMS-OF-SERVICE.md), [Refund Policy](REFUND-POLICY.md),
[Acceptable Use Policy](ACCEPTABLE-USE-POLICY.md), and
[Privacy Policy](PRIVACY-POLICY.md).

---

## 1. Why Clickwrap Matters

Courts enforce clickwrap agreements when four conditions hold:

1. **Affirmative action** — the buyer actively checks a box or clicks
   "I agree" (never pre-checked).
2. **Conspicuous presentation** — the checkbox and the link to the terms are
   visible, readable, and adjacent to the action they gate.
3. **Accessibility of terms** — the full terms are one click away, and the
   exact version shown at acceptance remains retrievable later.
4. **Recorded consent** — we can prove *who* accepted *which version* of the
   terms *when*.

Browsewrap (terms only in a footer link) is routinely held unenforceable.
Everything below exists to satisfy the four conditions.

---

## 2. Setup Checklist

| # | Item | Where |
|---|---|---|
| 1 | Publish each policy as a real WordPress page with a stable canonical URL | `nvdigitalsolutions.com/terms-of-service`, `/refund-policy`, `/acceptable-use-policy`, `/privacy-policy` |
| 2 | Enable WooCommerce's Terms & Conditions checkbox and point it at the ToS page | WooCommerce → Settings → **Advanced** → Terms and conditions |
| 3 | Customize the checkbox label so it names the policies and links them (snippet below) | `functions.php` or a small mu-plugin |
| 4 | Record consent per order (version + timestamp + IP + user agent) | mu-plugin snippet below |
| 5 | Keep a dated, permanent copy of every published policy version | Versioning runbook, §4 |
| 6 | Surface the Privacy Policy at checkout | WooCommerce → Settings → **Accounts & Privacy** (privacy policy page) |
| 7 | Link all policies in the site footer (backup browsewrap — never the only mechanism) | Site theme footer |

---

## 3. Implementation Snippets

### 3.1. Checkout label naming every policy

WooCommerce's default label only references "terms and conditions". Replace
it so acceptance clearly covers all three binding documents:

```php
/**
 * Custom terms checkbox label for NV oOS checkout.
 */
add_filter(
	'woocommerce_get_terms_and_conditions_checkbox_text',
	function ( $text ) {
		$terms = get_permalink( wc_terms_and_conditions_page_id() );

		return sprintf(
			// Translators: %1$s = Terms of Service URL, %2$s = Refund Policy URL,
			// %3$s = Acceptable Use Policy URL.
			__( 'I have read and agree to the %1$s, %2$s, and %3$s, and I consent to the %4$s.', 'nvdigitalsolutions' ),
			'<a href="' . esc_url( $terms ) . '" target="_blank">' . esc_html__( 'Terms of Service', 'nvdigitalsolutions' ) . '</a>',
			'<a href="' . esc_url( home_url( '/refund-policy' ) ) . '" target="_blank">' . esc_html__( 'Refund Policy', 'nvdigitalsolutions' ) . '</a>',
			'<a href="' . esc_url( home_url( '/acceptable-use-policy' ) ) . '" target="_blank">' . esc_html__( 'Acceptable Use Policy', 'nvdigitalsolutions' ) . '</a>',
			'<a href="' . esc_url( home_url( '/privacy-policy' ) ) . '" target="_blank">' . esc_html__( 'Privacy Policy', 'nvdigitalsolutions' ) . '</a>'
		);
	},
	20
);
```

**Never pre-check the box.** WooCommerce does not pre-check by default; do
not add code that does.

### 3.2. Recording consent on the order

Save the accepted terms version, timestamp, IP, and user agent to order meta
so acceptance is provable in a chargeback dispute:

```php
/**
 * Record legal-terms consent on every order.
 *
 * Reads the current versions from options (see section 4) and stores them
 * against the order together with acceptance metadata.
 */
add_action(
	'woocommerce_checkout_update_order_meta',
	function ( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_nvds_terms_version', get_option( 'nvds_terms_version', '1.0' ) );
		$order->update_meta_data( '_nvds_refund_policy_version', get_option( 'nvds_refund_policy_version', '1.0' ) );
		$order->update_meta_data( '_nvds_aup_version', get_option( 'nvds_aup_version', '1.0' ) );
		$order->update_meta_data( '_nvds_privacy_policy_version', get_option( 'nvds_privacy_policy_version', '1.0' ) );
		$order->update_meta_data( '_nvds_terms_accepted_at', gmdate( 'c' ) );
		$order->update_meta_data( '_nvds_terms_accepted_ip', WC_Geolocation::get_ip_address() );
		$order->update_meta_data( '_nvds_terms_accepted_ua', substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 250 ) );
		$order->save();
	},
	20
);
```

Show the recorded consent on the admin order screen so support can verify it
during disputes:

```php
/**
 * Surface recorded consent in the order admin screen.
 */
add_action(
	'woocommerce_admin_order_data_after_billing_address',
	function ( $order ) {
		$version = $order->get_meta( '_nvds_terms_version' );
		if ( ! $version ) {
			return;
		}
		?>
		<div class="order_data_column" style="margin-top:1em;">
			<h4><?php esc_html_e( 'Legal terms acceptance', 'nvdigitalsolutions' ); ?></h4>
			<p>
				<?php
				echo esc_html(
					sprintf(
						// Translators: 1: terms version, 2: acceptance timestamp.
						__( 'Terms v%s accepted at %s', 'nvdigitalsolutions' ),
						$version,
						(string) $order->get_meta( '_nvds_terms_accepted_at' )
					)
				);
				?>
			</p>
		</div>
		<?php
	}
);
```

Install these as a small mu-plugin (`wp-content/mu-plugins/nvds-legal-consent.php`)
so they survive theme changes.

### 3.3. Alternative: plugin-based recording

If you prefer a maintained plugin, **WooCommerce Additional Terms (Lite or
Pro)** records which agreement each customer accepted per order, adds extra
required checkboxes (e.g., a separate "I understand the AI features may
generate inaccurate content" acknowledgment), and displays consent in order
details and customer emails.

---

## 4. Versioning Runbook

The Terms state that *the version in force at the time of purchase applies to
that purchase* — so every published version must remain retrievable forever.

1. **Keep versions in the repo:** `docs/legal/` is the source of truth.
   Each policy change is a git commit; the commit history is the audit log.
2. **Publish dated copies on the Site:** when a policy changes, create a
   dated page (e.g., `/terms-of-service/2026-09-10/`) and keep it published.
   The canonical URL always serves the current version.
3. **Bump the version option** so new orders record the new version:

   ```php
   update_option( 'nvds_terms_version', '1.1' ); // via wp-cli or a settings page
   ```

4. **Material vs. immaterial changes:** date-stamp every change; announce
   material changes on the Site and (for major terms changes) email existing
   customers — the Terms currently bind the checkout-time version, so no
   re-acceptance flow is required unless you adopt one later.

---

## 5. License Activation Gate (Secondary Acceptance Point)

Checkout consent is the primary acceptance. Optionally, record a second
acceptance at license activation:

- The checkout service (`addons/checkout-api/`) should store the
  `_nvds_terms_version` recorded at checkout inside the license record's
  customer metadata.
- When the plugin activates a license (`includes/admin/class-wp-mcp-ai-pro-license.php`),
  log the license server's response version locally — this creates a
  tamper-evident trail across both systems.

This is a defense-in-depth measure; do not make activation acceptance a
substitute for checkout clickwrap.

---

## 6. What NOT to Do

- Do not put terms only in a footer link (browsewrap — routinely
  unenforceable).
- Do not pre-check the consent box.
- Do not let a "Place Order" click stand in for consent without an explicit
  agreement statement next to the button.
- Do not silently change policies without bumping the version option and
  archiving the old text.
- Do not bury the AI-limitations disclosure in small print — US regulators
  (FTC) require AI limitations and experimental status to be disclosed
  clearly and conspicuously, not only in legal fine print.

---

## 7. Related Documents

- [Terms of Service](TERMS-OF-SERVICE.md)
- [Refund Policy](REFUND-POLICY.md)
- [Acceptable Use Policy](ACCEPTABLE-USE-POLICY.md)
- [Privacy Policy](PRIVACY-POLICY.md)
- [Compliance Checklist](COMPLIANCE-CHECKLIST.md)
