# HM User Activation

Replaces WordPress Multisite's default `wp-signup.php` and `wp-activate.php` flow with site-level registration, activation, login and password reset pages, customisable emails, and block-based templating.

## Requirements

- WordPress Multisite
- WordPress 6.5+
- PHP 8.1+

## Features

**Site-level registration page** — registration happens on your own site instead of the network's `wp-signup.php`. Signups are created with core's multisite functions (`wpmu_validate_user_signup()` and `wpmu_signup_user()`), so all the usual validation — reserved and illegal names, banned email domains, existing users, pending signups — still applies. Requests to `wp-signup.php` and any `wp_registration_url()` links are redirected to the configured page.

**Site-level activation page** — on plugin activation a draft page is created, pre-populated with the activation form block and conditional success/error blocks. Publish it and assign it in settings.

**Customisable activation email** — replaces the default network activation email with one that links to your site's own activation page. Subject, from name, from address, and body are all editable.

**Post-activation welcome email** — optionally send a follow-up email once the account is activated. It never contains a password: users get a one-time password reset link to set their own instead. Independently configurable from the activation email.

**Password reset flow** — a site-level reset page handles both requesting a reset link and setting a new password, and `wp_lostpassword_url()` is filtered to point at it.

**Auto-login** — optionally log users in immediately after a successful activation (admin-controlled).

**Block editor support** — blocks and group variations for building each page:

| Block / Variation | Purpose |
|---|---|
| `Registration Form` | Renders the username and email fields and submit button |
| `Registration Errors` _(group variant)_ | Shown only on failure; inner paragraph bound to the error message |
| `Registration Success` _(group variant)_ | Shown only once a signup is created; tells the user to check their inbox |
| `Activation Form` | Renders the activation key input and submit button |
| `Activation Errors` _(group variant)_ | Shown only on failure; inner paragraph bound to the error message |
| `Activation Success` _(group variant)_ | Shown only on success; shows the username and a button to set a password |
| `Login Form` | Renders the WordPress login form, with "Forgot your password?" pointing at the reset page |
| `Password Reset Form` | Requests a reset link, or sets a new password when a key is present |
| `Password Reset Errors` / `Email Sent` / `Password Set` _(group variants)_ | Feedback for each reset outcome |

**Block bindings** — individual binding sources for use anywhere in the editor:

- `Registration: Error message`
- `Registration: Email address` / `Registration: Confirmation message`
- `Activation: Error message`
- `Activation: Username` / `Activation: Username (formatted)`
- `Activation: Password reset URL`
- `Password reset: Error message`

## Setup

1. Enable user registration for the network under **Network Admin → Settings → Registration Settings** (either "User accounts may be registered" or "Both sites and user accounts can be registered").
2. Activate the plugin on the target site.
3. Go to **Settings → User Activation** and configure:
   - **Registration page** — select the generated draft page (publish it when ready).
   - **Activation page** — select the generated draft page (publish it when ready).
   - **Password reset page** — select the generated draft page (publish it when ready).
   - **Log in page** — used as `{login_url}` in the welcome email.
   - Email templates for the activation, welcome and password reset emails.
4. Users who register will receive your custom activation email linking to the configured page.

## Security behaviour

The plugin replaces flows that core keeps on `wp-login.php`, `wp-signup.php` and `wp-activate.php`, so it applies the same protections to the pages it owns:

- **No passwords are ever emailed.** The welcome email carries a one-time reset link. The network's own `wpmu_welcome_user_notification()` email is suppressed, because `wpmu_activate_signup()` hands it a generated password and older networks' templates interpolate it in plain text. Filter `hm_user_activation_suppress_network_welcome_email` to change that.
- **Keys never stay in URLs.** An activation or reset key arriving in a query string is moved into a scoped, HTTP-only, `SameSite=Lax` cookie and the page reloads without it, keeping it out of browser history, `Referer` headers, proxy logs and copy-pasted links. Reset submissions cross-check the posted key against that cookie with `hash_equals()`. Keys are dropped as soon as they are spent.
- **These pages are never cached or indexed.** `nocache_headers()`, `DONOTCACHEPAGE` and core's sensitive-page robots and referrer meta are applied, so a page cache cannot store an activation success response — which contains a username and a live reset link — and replay it to the next visitor.
- **Submissions are rate limited** per client, by hashed `REMOTE_ADDR`: activation key attempts, reset requests and registrations. Tune with `hm_user_activation_rate_limit`, `hm_user_activation_rate_limit_window`, and `hm_user_activation_client_ip` for sites behind a trusted proxy.
- **Reset failures are indistinguishable.** Invalid, expired and mismatched keys share one message, and reset requests always report success, so neither can be used to enumerate accounts.
- **Core's hooks are honoured**, so policy plugins still apply: `allow_password_reset`, `lostpassword_post`, `validate_password_reset`, and `wp_login` when auto-login is enabled.

## Registration hooks

- `hm_user_activation_registration_enabled` — filter whether the form accepts submissions (`bool $enabled`, `string $network_setting`).
- `hm_user_activation_signup_meta` — filter the meta stored against the signup (`array $meta`, `string $user_name`, `string $user_email`). Core's `add_signup_meta` filter is applied first.
- `hm_user_activation_registered` — action fired after a signup is created (`string $user_name`, `string $user_email`, `array $meta`).

## Email placeholders

### Activation email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{activation_link}`

### Welcome email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{display_name}` `{first_name}` `{last_name}` `{nickname}` `{login_url}` `{password_reset_link}`

### Password reset email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{reset_link}`
