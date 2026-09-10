# AIMF Secure Forms

A lightweight, security-hardened WordPress form builder developed for AIMF Security.

## Features

- Reusable forms with text, email, paragraph, dropdown, radio, checkbox, and number fields
- Divi Code Module and Text Module compatibility
- WordPress nonce and capability validation
- Honeypot, per-IP and global rate limiting, minimum submission time, and signed render timestamps
- Interaction-gated, single-use anti-replay tokens
- Cloudflare Turnstile, Google reCAPTCHA v3, hCaptcha, and optional Akismet support
- Server-side Turnstile hostname and action validation
- Submission storage, status management, privacy export/erasure, and retention controls
- YubiKey PIV 9D encrypted configuration and submission exports

## Installation

1. Create a ZIP containing this repository with the files at the archive root.
2. In WordPress, open **Plugins > Add Plugin > Upload Plugin**.
3. Upload and activate the ZIP.
4. Open **AIMF Forms > Settings** and configure the security controls.
5. Build forms under **AIMF Forms > Form Builder**.

## Shortcodes

Default form:

```text
[aimf_form id="1"]
```

Custom form:

```text
[aimf_form id="2"]
```

Legacy compatibility:

```text
[aimf_contact_form]
```

## Setup guide

- [Cloudflare Turnstile and Bluehost setup](docs/setup-cloudflare-bluehost.html)
- [Service pages, funnels, updates, and configuration transfer](docs/service-pages-and-funnels.md)

## GitHub release updates

The plugin checks this repository's latest published GitHub Release and uses the standard WordPress plugin update interface. A release must use a version tag such as `v1.2.0` and include an installable asset named exactly `aimf-secure-forms.zip`.

This repository is public, so update checks work without authentication. If you fork it into a private repository, define a fine-grained token with repository-scoped, read-only Contents access in `wp-config.php`:

```php
define( 'AIMF_SCF_GITHUB_TOKEN', 'PASTE_READ_ONLY_TOKEN_HERE' );
```

Do not commit the token. Public repositories do not require it.

## Encrypted exports

Exports are encrypted with AES-256-GCM. Each one-time data key is wrapped to the bundled AIMF Security RSA-2048 public certificate. The corresponding private key remains in an authorized YubiKey PIV 9D slot.

The bundled certificate is organization-specific. Replace it and update the pinned fingerprint before deploying a fork whose operators must decrypt their own exports.

## Security notes

- CAPTCHA verification fails closed when a provider is configured.
- CAPTCHA secret keys are never included in configuration exports.
- No private keys, PINs, API credentials, user submissions, development metadata, or local paths are included in this repository.
- Do not use general web forms to collect passwords, private keys, recovery codes, payment-card data, or detailed incident evidence.

## Requirements

- WordPress 6.x or later
- PHP with OpenSSL support for encrypted exports
- HTTPS in production
- OpenSC, `pkcs11-tool`, Python 3, and Python `cryptography` for the included YubiKey decryption utility

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
