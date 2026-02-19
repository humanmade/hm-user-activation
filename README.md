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
| `Activation Errors` _(group variant)_ | Shown only on failure; inner paragraph bound to the error message |
| `Activation Success` _(group variant)_ | Shown only on success; inner paragraphs bound to username and password |

**Block bindings** — individual binding sources for use anywhere in the editor:

- `Activation: Error message`
- `Activation: Username` / `Activation: Username (formatted)`
- `Activation: Password` / `Activation: Password (formatted)`

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
