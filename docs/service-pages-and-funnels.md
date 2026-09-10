# AIMF Secure Forms: Service Pages, Funnels, Testing, Updates, and Configuration Transfer

## Purpose

AIMF Secure Forms is the preferred form system for AIMF Security service pages, landing pages, lead-generation funnels, contact pages, campaign pages, and conversion tests.

The plugin provides:

- A WordPress form builder managed under **AIMF Forms > Form Builder**.
- Reusable forms embedded with shortcodes.
- Divi Code Module and Text Module compatibility.
- Text, email, paragraph, dropdown, multiple-choice, checkbox, and number fields.
- Form-specific notification addresses, success messages, and button text.
- Secure submission storage in WordPress.
- CSRF protection, a honeypot, rate limiting, signed timing checks, interaction-gated tokens, anti-replay protection, CAPTCHA support, and optional Akismet filtering.
- Configuration export and import for backups, staging, and repeatable site setup.

The plugin intentionally excludes payment processing, file uploads, and high-risk integrations. Use a dedicated, reviewed system when a workflow requires those features.

---

## Core Shortcodes

### Default form

```text
[aimf_form id="1"]
```

The legacy shortcode remains supported:

```text
[aimf_contact_form]
```

Both shortcodes display the default form.

### Custom form

Replace `2` with the ID shown in **AIMF Forms > Form Builder**:

```text
[aimf_form id="2"]
```

Never place API keys, email credentials, CAPTCHA secrets, or other private data in shortcode attributes.

---

## Creating a Form

1. Sign in to WordPress as an administrator.
2. Open **AIMF Forms > Form Builder**.
3. Select **Add New Form**.
4. Enter a descriptive internal form title.
5. Add the required fields from the field palette.
6. Select each field and configure its label, placeholder, description, default value, required status, and options.
7. Configure the form-level settings:
   - Notification email.
   - Success message.
   - Submit button text.
8. Select **Save Form**.
9. Copy the generated shortcode.
10. Add the shortcode to the relevant page.
11. Test the complete submission and notification flow before publishing or sending traffic to the page.

### Recommended naming convention

Use names that identify the service, funnel stage, and purpose:

```text
Website Security Audit — Service Page
Cloudflare Setup — Consultation Funnel
Google Workspace Security — Lead Qualification
Quick Start Assessment — Campaign Landing Page
Incident Response — Urgent Contact
General Contact — Sitewide
```

Clear names make submissions and future configuration exports easier to understand.

---

## Available Field Types

### Single Line

Use for short values such as name, company, job title, domain, or organization.

### Email

Use for the primary contact address. Email values are sanitized and validated on the server.

### Paragraph

Use for project details, security concerns, goals, timelines, and free-form messages.

### Dropdown

Use when the visitor should select one option from a compact list.

Example service options:

```text
Quick Start Assessment
Website Security Audit
Marketing Channel Security Audit
Cloudflare Setup
Google Workspace Security
Device Hardening
Network Hardening
1:1 Security Training
Multiple Services
General Inquiry
Other
```

### Multiple Choice

Use when all options should remain visible and the visitor must select one.

### Checkboxes

Use when the visitor may select multiple applicable options.

### Number

Use for a budget, device count, employee count, location count, or another numeric qualification value. Configure minimum and maximum values where appropriate.

---

## Recommended Service-Page Form Pattern

Service-page forms should be concise and directly related to the offer. Avoid asking for information that is not needed to qualify or answer the inquiry.

### Recommended fields

```text
Name — Single Line — Required
Work Email — Email — Required
Company — Single Line — Optional
Primary Goal — Dropdown or Multiple Choice — Required
Project Details — Paragraph — Required
```

### Optional qualification fields

```text
Number of Employees — Number
Number of Devices — Number
Desired Start Time — Dropdown
Services Needed — Checkboxes
Current Security Concern — Dropdown
```

### Recommended button text

Use a service-specific action instead of a generic submit label:

```text
Request My Assessment
Schedule a Security Review
Get a Security Recommendation
Discuss My Project
Request a Consultation
Start My Audit
```

### Recommended success message

```text
Thank you. Your request was received securely. The AIMF Security team will review it and follow up using the email address provided.
```

Do not promise an exact response time unless the business can consistently meet it.

---

## Recommended Funnel Forms

### Top-of-funnel form

Goal: minimize friction and capture initial interest.

Recommended fields:

```text
Name
Email
Service Interest
```

### Middle-of-funnel qualification form

Goal: collect enough detail to route and prioritize a lead.

Recommended fields:

```text
Name
Work Email
Company
Service Interest
Organization Size
Primary Concern
Project Details
```

### Bottom-of-funnel consultation form

Goal: prepare for a sales or technical conversation.

Recommended fields:

```text
Name
Work Email
Company
Service Needed
Number of Users or Devices
Current Environment
Desired Timeline
Budget Range
Project Details
```

Do not collect passwords, authentication tokens, private keys, Social Security numbers, payment data, medical information, or detailed incident evidence through a general web form.

---

## Using a Form in Divi

### Code Module

1. Open the page in the Divi Builder.
2. Add a **Code** module where the form should appear.
3. Paste only the shortcode:

```text
[aimf_form id="2"]
```

4. Save the module and page.
5. Exit the Visual Builder and test the published page.

### Text Module

The shortcode may also be placed in a Divi Text Module. Use the text/code view if Divi attempts to wrap or modify the shortcode.

### Divi cache after an update

If an old layout or stylesheet remains visible:

1. Clear Divi static CSS/cache.
2. Clear the WordPress cache plugin, if present.
3. Purge the CDN or Cloudflare cache, if applicable.
4. Reload the page in a private browser window.

The plugin styles the form to fill the available Divi module width. Control the overall page width using the Divi row and column settings rather than adding a fixed width to the form.

---

## Service-Page Testing Workflow

Create and validate forms on a staging or test page before placing them on a production service page.

### Test page procedure

1. Create a private or unlinked WordPress page.
2. Add the form shortcode to a Divi Code Module.
3. Save and view the page outside the builder.
4. Submit a realistic test entry.
5. Confirm the success message.
6. Open **AIMF Forms > Submissions** and confirm the entry was stored under the expected form.
7. Confirm the notification email arrived at the expected address.
8. Test desktop and mobile layouts.
9. Test required-field errors and invalid email handling.
10. Test the CAPTCHA after configuring production keys.
11. Confirm that a CAPTCHA outage or failed verification blocks submission when a provider is configured.
12. Delete or clearly label test submissions after validation.

### Conversion-testing procedure

For a service-page or funnel test:

1. Duplicate the existing form in the Form Builder.
2. Give the duplicate a name identifying the test variation.
3. Change only the fields, button text, or success message being tested.
4. Embed each form ID on its corresponding page variation.
5. Keep form IDs and page URLs in the experiment notes.
6. Compare qualified submissions rather than raw submission volume alone.
7. Do not edit the control form after the test begins unless the test is restarted.

Example:

```text
Website Audit — Control — Form ID 4
Website Audit — Short Form Test — Form ID 5
```

---

## Global Settings and Form-Level Settings

### Global settings

Configure under **AIMF Forms > Settings**:

- CAPTCHA provider keys.
- Optional Akismet filtering.
- Minimum submission time.
- Data-retention period.
- Default notification email.

Recommended production baseline:

```text
CAPTCHA: Cloudflare Turnstile
Minimum Submit Time: 3 seconds
Data Retention: 90 days
Akismet: Enabled when a configured API key is available
```

### Form-level settings

Configure while editing an individual form:

- Notification email.
- Success message.
- Submit button text.

A form-specific notification email overrides the global notification destination for that form.

---

## Updating the Plugin Without Losing Configuration

Normal WordPress plugin updates and the **Replace current with uploaded** workflow preserve:

- Form definitions.
- Global plugin settings.
- Form-specific settings.
- Stored submissions.
- CAPTCHA keys.

These values are stored in WordPress options and the plugin submission table. Replacing plugin files does not delete them.

### Safe update procedure

1. Open **AIMF Forms > Settings**.
2. Select **Download Configuration** under **Configuration Backup & Transfer**.
3. Store the encrypted `.ascfenc` file in the project backup location.
4. Take a WordPress database backup before a production update.
5. Upload the new plugin ZIP.
6. Select **Replace current with uploaded** when WordPress prompts.
7. Do not delete the plugin before uploading the replacement.
8. Confirm the plugin remains active.
9. Open the Form Builder and verify the expected forms are present.
10. Confirm CAPTCHA settings remain configured.
11. Submit a live test form.
12. Confirm storage and notification delivery.
13. Clear Divi, WordPress, and CDN caches if styles appear stale.

### Important distinction

- **Deactivate/reactivate:** preserves settings, forms, and submissions.
- **Update/replace plugin files:** preserves settings, forms, and submissions.
- **Delete through WordPress Plugins:** runs the uninstall routine and removes plugin data. Do not delete the plugin when the goal is only to update it.

### WordPress update notifications from GitHub

The plugin checks the latest published GitHub Release. Pushing commits alone does not create a WordPress update notice. Each deployable update requires:

1. A plugin version increase in the header and `AIMF_SCF_VERSION`.
2. A matching Git tag, such as `v1.2.0`.
3. A published GitHub Release.
4. An attached installable asset named exactly `aimf-secure-forms.zip`.

The repository is private, so each WordPress installation must define a fine-grained, read-only GitHub token in `wp-config.php` above the stop-editing line:

```php
define( 'AIMF_SCF_GITHUB_TOKEN', 'PASTE_READ_ONLY_TOKEN_HERE' );
```

Restrict the token to the AIMF Secure Forms repository with read-only Contents access. Never store it in the plugin, WordPress database, form configuration, source control, screenshots, or support messages. If the repository becomes public, remove the constant; public release checks work without authentication.

WordPress checks releases through its normal update transient and displays the standard plugin update notice. A six-hour cache limits GitHub API traffic. Use **Dashboard > Updates > Check again** when an immediate refresh is needed.

---

## Exporting Configuration

1. Open **AIMF Forms > Settings**.
2. Scroll to **Configuration Backup & Transfer**.
3. Select **Download Configuration**.
4. Save the encrypted `.ascfenc` file securely.

All exports are encrypted automatically with AES-256-GCM. The one-time AES key is wrapped with the RSA-2048 public certificate corresponding to the AIMF Security YubiKey PIV `9D` private key. The WordPress server never receives the YubiKey PIN or private key.

The encrypted export contains:

- Form IDs and titles.
- Field definitions and ordering.
- Dropdown, radio, and checkbox choices.
- Required-field settings.
- Form notification addresses.
- Success messages and submit button text.
- Safe global settings.
- Configuration format and version metadata.

Configuration exports exclude CAPTCHA secret keys, stored submissions, rate-limit state, WordPress users, and credentials.

### Decrypt an export

Connect the authorized YubiKey and run:

```bash
/path/to/aimf-secure-contact-form/tools/decrypt-aimf-export.sh \
  /path/to/aimf-secure-forms-DATE.ascfenc
```

The utility prompts for the PIV PIN locally and writes a permissions-restricted JSON file beside the encrypted export. Never provide the PIV PIN to WordPress, a browser, a script argument, or a chat session.

The utility requires OpenSC (`pkcs11-tool`), the OpenSC PKCS#11 module, Python 3, and Python's `cryptography` package. It validates the encrypted envelope and expected certificate fingerprint before invoking the YubiKey.

---

## Importing Configuration

1. Connect the authorized YubiKey.
2. Decrypt the `.ascfenc` configuration export locally with `decrypt-aimf-export.sh`.
3. Install and activate AIMF Secure Forms on the destination site.
4. Open **AIMF Forms > Settings**.
5. Scroll to **Configuration Backup & Transfer**.
6. Choose the decrypted JSON file.
7. Select an import mode.
8. Select **Import Configuration**.
9. Confirm the import.
10. Review all imported forms.
11. Configure CAPTCHA secret keys manually on the destination site.
12. Submit a test entry for every imported production form.
13. Securely remove the decrypted JSON after the transfer is verified.

---

## Exporting Submission Data

Submission data can only be downloaded as a YubiKey-encrypted export.

1. Open **AIMF Forms > Submissions**.
2. Select **Download Encrypted Export**.
3. Store the resulting `.ascfenc` file securely.
4. Connect the authorized YubiKey when the data must be reviewed offline.
5. Run `decrypt-aimf-export.sh` against the encrypted file.
6. Securely remove the decrypted JSON when the approved task is complete.

The encrypted submission payload includes stored field data, form IDs and titles, timestamps, status, IP addresses, user agents, and CAPTCHA scores. Access and retain decrypted exports according to the applicable privacy policy and data-retention requirements.

Exports are limited to 5,000 submissions to avoid unbounded memory consumption. Reduce retained data or export more frequently if the site approaches that limit.

### Merge mode

Use **Merge with existing forms** for most transfers and updates.

- Imported form IDs overwrite matching form IDs.
- Forms with different IDs remain in place.
- Existing CAPTCHA secrets remain configured.
- Imported safe settings update their matching values.

### Replace mode

Use **Replace existing form definitions** only when the destination should exactly use the imported set of forms.

- Existing form definitions are removed before import.
- Stored submissions are not imported or deleted.
- Existing CAPTCHA secrets remain configured.
- The default Form ID 1 is recreated if the imported configuration does not contain it.

Export the destination configuration before using Replace mode.

---

## Moving Forms Between Staging and Production

Recommended workflow:

1. Export production configuration as an encrypted backup.
2. Build and test the new form on staging.
3. Export the encrypted staging configuration.
4. Decrypt the staging export locally with the authorized YubiKey.
5. Import the decrypted JSON into production using Merge mode.
6. Securely remove the decrypted JSON after verification.
7. Review form IDs before changing any production shortcode.
8. Configure or verify production CAPTCHA keys.
9. Add the selected form shortcode to the production Divi page.
10. Clear caches.
11. Submit a production test.
12. Confirm the correct notification inbox and stored submission.

When staging and production contain unrelated forms with the same numeric ID, an import can overwrite the production form with that ID. Review the JSON or use a controlled form-ID plan before transferring configuration between long-lived environments.

---

## Security Rules for Forms

- Configure a CAPTCHA provider on production.
- Use HTTPS for every page containing a form.
- Keep WordPress, Divi, this plugin, and all other plugins updated.
- Restrict WordPress administrator access.
- Never place CAPTCHA secret keys in Divi or page content.
- Never request passwords, API keys, recovery codes, or payment card data.
- Use the shortest practical retention period.
- Review spam and submission volume for anomalies.
- Use Cloudflare WAF or infrastructure-level rate controls for sustained attacks.
- Confirm that notification mail uses an authenticated and monitored delivery system.
- Export configuration and back up the database before production updates.

The JavaScript token, interaction gate, honeypot, timing check, CAPTCHA, Akismet, and rate limits are layered controls. No individual control should be treated as complete bot prevention.

---

## Troubleshooting

### The form does not appear

- Confirm the plugin is active.
- Confirm the shortcode uses an existing form ID.
- Use `[aimf_form id="1"]` to test the default form.
- Save the Divi module and page, then view the published page outside the builder.

### The form is narrow or styling is stale

- Confirm the latest plugin ZIP is installed.
- Clear Divi static CSS.
- Purge WordPress and CDN caches.
- Confirm the Divi row or column is not configured with an unintended custom width.

### Submissions fail after enabling CAPTCHA

- Confirm the site key and secret key belong to the same CAPTCHA widget.
- Confirm `aimfsecurity.com` is allowed in Turnstile Hostname Management without a scheme, port, path, or wildcard.
- Confirm outbound HTTPS requests from WordPress can reach the verification provider.
- Confirm the browser is not blocking `challenges.cloudflare.com`.
- Re-enter the secret key manually after importing configuration because exports intentionally exclude secrets.

### Turnstile Analytics shows no data

- Confirm both the Turnstile site key and secret key are saved.
- View the published page source and confirm the Turnstile API and widget container are present.
- Complete and submit the form; widget impressions and Siteverify validation are separate analytics measurements.
- In Turnstile Analytics, select the correct widget and a time range that includes the test.
- Look for the `aimf_secure_form` action after installing plugin version 1.1.0 or later.
- Clear Autoptimize, Divi, WordPress, and Cloudflare caches after updating the plugin.
- Allow time for analytics processing rather than expecting an immediate real-time event.
- Check browser developer tools for Turnstile errors such as an invalid site key or unauthorized domain.

### Notification email does not arrive

- Confirm the form-specific notification address.
- Confirm the global notification address.
- Check the submission was stored in WordPress.
- Check spam and quarantine folders.
- Verify the site’s transactional email or SMTP configuration.

### Imported forms overwrite unexpected forms

- Restore the configuration exported before import.
- Use Merge mode only when form IDs are coordinated.
- Use a staging-to-production form-ID plan for repeated deployments.

---

## Production Launch Checklist

- [ ] Form has a clear internal name.
- [ ] Only necessary fields are included.
- [ ] Required fields are correctly configured.
- [ ] Dropdown and choice values are complete.
- [ ] Notification email is monitored.
- [ ] Success message is accurate.
- [ ] Submit button matches the service-page CTA.
- [ ] Turnstile or another supported CAPTCHA is configured.
- [ ] Akismet is configured if enabled.
- [ ] Retention period is set.
- [ ] Form renders correctly in Divi on desktop and mobile.
- [ ] Test entry is stored under the correct form.
- [ ] Notification email is delivered.
- [ ] Configuration export is saved.
- [ ] WordPress database backup exists.
- [ ] Divi, WordPress, and CDN caches are cleared.
