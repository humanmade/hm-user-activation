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
| `Activation Link Resent` _(group variant)_ | Shown only after a new link was requested; holds the confirmation message |
| `Activation Errors` _(group variant)_ | Shown only on failure; inner paragraph bound to the error message |
| `Activation Success` _(group variant)_ | Shown only on success; inner paragraphs bound to username and password |

**Block bindings** — individual binding sources for use anywhere in the editor:

- `Activation: Error message`
- `Activation: Username` / `Activation: Username (formatted)`
- `Activation: Password` / `Activation: Password (formatted)`
- `Activation: Login URL` — the login URL, for a "Log in" link in the success message

## Link lifetime and resending

**Link lifetime** — set **Activation link lifetime** in the settings to a number of hours. Links older than that show an "expired" error instead of activating. `0` (the default) means links never expire. Sites can also use the `hm_user_activation_link_lifetime` filter. Expiry is only enforced by the plugin's activation page. On a site where the plugin isn't active, or when no activation page is set, core's `wp-activate.php` still accepts the key.

**Resend block** — the `Resend Activation Form` block (`hm-user-activation/resend-form`) asks for an email address, then redirects back to the page with `?hm_activation_resent=1`. The reply is the same whether or not a registration exists, and a page refresh doesn't send another email. The default activation page includes the block in the errors group, so expired links lead straight to it, and adds an `Activation Link Resent` group that shows "If there's a pending registration for that address, we've sent a new activation link." only after that redirect. The group only shows on the redirect, and not when an activation result is also showing. Existing pages need the block and the group added by hand.

**Resend function** — `HM\UserActivation\Resend\resend( string $email, bool $defer = false ): bool` sends the activation email again for the newest pending signup (user or site) for that address. It returns `true` if a signup was found and the email was handed to `wp_mail()` (or queued on `shutdown` when `$defer` is true), and `false` otherwise. The block gives the same reply either way.

Resend rules:

- While the link is still valid, the same link is sent again and the key and registered time are not changed, so a stranger can't invalidate someone else's link.
- Once the link has expired, a new key is made and the lifetime restarts. The old key is gone.
- Each signup can be resent 5 times (`hm_user_activation_resend_limit`, `0` for no limit), and not within 5 minutes of the last resend (`hm_user_activation_resend_interval`, seconds, `0` for none). The count is kept in the signup meta.
- The resend form allows 10 requests per hour from one IP address (`hm_user_activation_resend_ip_limit`, `0` for no limit). Over the limit the reply is the same and nothing is sent.
- The form sends the email after the redirect, so timing doesn't show whether an address has a pending signup.
- Return `false` from `hm_user_activation_can_resend` (`bool $can_resend, object $signup`) to block a resend.

The resend count is removed from the signup before the plugin's activation page activates it. A signup activated some other way, such as core's `wp-activate.php` on a site without the plugin, may keep a `hm_activation_resends` entry in its user meta or the new site's options.

Signups are network-wide, but the link lifetime and the email templates are per site. A resend from another site uses that site's settings.

## Hooks

- `hm_user_activation_link_lifetime` — filter the link lifetime in hours.
- `hm_user_activation_resend_interval` — filter the minimum seconds between resends for a signup (`int $seconds`).
- `hm_user_activation_resend_limit` — filter how many times a signup can be resent (`int $limit`).
- `hm_user_activation_resend_ip_limit` — filter resend requests per hour per IP address (`int $limit`).
- `hm_user_activation_can_resend` — filter whether a resend is allowed (`bool $can_resend, object $signup`).
- `hm_user_activation_needs_password_setup` — override whether the user is sent to set a password (`bool $needs, int $user_id, array $meta`). The default is `true` only while the user still has the password core generated during activation, so a password set by a hook on `wpmu_activate_user` skips the step automatically. Returning `false` for a user who only has the generated password leaves them unable to log in until they use "lost password". Returning `true` for a user who already has a password just sends them through a harmless reset. When `false`, no reset link is generated. If the welcome email is still sent, `{password_reset_link}` in it becomes the login URL, and if the welcome email body setting is empty a default body without a password step is used. On the default activation page the "Set your password" button is dropped when there is no reset link, and a "Log in" button is shown.
- `hm_user_activation_send_welcome_email` — filter whether the welcome email is sent; defaults to the setting (`bool $send, int $user_id, array $meta`).
- `hm_user_activation_login_url` — filter the login URL used for the `Activation: Login URL` binding and `{login_url}` in the welcome email. It is the **Log in page** setting if set, otherwise `wp_login_url()` (`string $url, int $user_id, array $meta`).
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
`{site_name}` `{site_url}` `{network_name}` `{username}` `{display_name}` `{first_name}` `{last_name}` `{nickname}` `{login_url}` `{password_reset_link}`
