# HM User Activation

Replaces WordPress Multisite's default `wp-activate.php` flow with a site-level activation page, customisable emails, and block-based templating.

## Requirements

- WordPress Multisite
- WordPress 6.5+
- PHP 8.1+

## Features

**Site-level activation page** — on plugin activation a draft page is created, pre-populated with the activation form block and conditional success/error blocks. Publish it and assign it in settings.

**Customisable activation email** — replaces the default network activation email with one that links to your site's own activation page. Subject, from name, from address, and body are all editable.

**Post-activation welcome email** — optionally send a follow-up email containing the user's credentials once their account is activated. Independently configurable from the activation email.

**Auto-login** — optionally log users in immediately after a successful activation (admin-controlled).

**Block editor support** — three block editor additions for building the activation page:

| Block / Variation | Purpose |
|---|---|
| `Activation Form` | Renders the activation key input and submit button |
| `Resend Activation Form` | Renders an email field so a user can request a new activation link |
| `Activation Errors` _(group variant)_ | Shown only on failure; inner paragraph bound to the error message |
| `Activation Success` _(group variant)_ | Shown only on success; inner paragraphs bound to username and password |

**Block bindings** — individual binding sources for use anywhere in the editor:

- `Activation: Error message`
- `Activation: Username` / `Activation: Username (formatted)`
- `Activation: Password` / `Activation: Password (formatted)`
- `Activation: Login URL` — the login URL, for a "Log in" link in the success message

## Link lifetime and resending

**Link lifetime** — set **Activation link lifetime** in the settings to a number of hours. Links older than that show an "expired" error instead of activating. `0` (the default) means links never expire. Sites can also use the `hm_user_activation_link_lifetime` filter.

**Resend block** — the `Resend Activation Form` block (`hm-user-activation/resend-form`) asks for an email address and always replies "If there's a pending registration for that address, we've sent a new activation link." The default activation page includes it in the errors group, so expired links lead straight to it. Existing pages need the block added by hand.

**Resend function** — `HM\UserActivation\Resend\resend( string $email ): bool` replaces the key on the newest pending user signup for that address, restarts its lifetime and sends the activation email again. The old key stops working. It returns `true` if a signup was found and the email sent. Return `false` from the `hm_user_activation_can_resend` filter (`bool $can_resend, object $signup`) to block a resend.

## Hooks

- `hm_user_activation_link_lifetime` — filter the link lifetime in hours.
- `hm_user_activation_can_resend` — filter whether a resend is allowed (`bool $can_resend, object $signup`).
- `hm_user_activation_needs_password_setup` — return `false` to skip generating the password reset link (`bool $needs, int $user_id, array $meta`).
- `hm_user_activation_send_welcome_email` — filter whether the welcome email is sent; defaults to the setting (`bool $send, int $user_id, array $meta`).
- `hm_user_activation_login_url` — filter the login URL in the activation result (`string $url, int $user_id, array $meta`).
- `hm_user_activation_activated` — action fired after a successful activation (`int $user_id, array $meta`).

## Setup

1. Activate the plugin on the target site.
2. Go to **Settings → User Activation** and configure:
   - **Activation page** — select the generated draft page (publish it when ready).
   - **Log in page URL** — used as `{login_url}` in the welcome email.
   - Email templates for both the activation and welcome emails.
3. Users who register will receive your custom activation email linking to the configured page.

## Email placeholders

### Activation email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{activation_link}`

### Welcome email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{password}` `{login_url}`
