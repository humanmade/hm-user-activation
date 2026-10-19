# HM User Activation

Replaces WordPress Multisite's default `wp-signup.php` and `wp-activate.php` flow with site-level registration, activation, login and password reset pages, customisable emails, and block-based templating.

## Requirements

- WordPress Multisite
- WordPress 6.5+
- PHP 8.1+

## Features

**Site-level registration page** — registration happens on your own site instead of the network's `wp-signup.php`. Signups are created with core's multisite functions (`wpmu_validate_user_signup()` and `wpmu_signup_user()`), so all the usual validation — reserved and illegal names, banned email domains, existing users, pending signups — still applies. Core's `wpmu_active_signup` filter and the `signup_hidden_fields` and `signup_extra_fields` actions are honoured too. CAPTCHA and anti-spam plugins that add fields with those actions and check them in the `wpmu_validate_user_signup` filter work here. Plugins that only load on `wp-signup.php` need testing. Like `wp-signup.php`, the form tells visitors when a username or email address is already taken.

Once the page is published, `wp_registration_url()` and the `wp-login.php?action=register` link point at it for logged-out visitors. When the network allows user accounts only, requests to `wp-signup.php` are redirected there too. This needs the plugin to be active on the network's main site, because `wp-signup.php` always runs on the main site. When the network also allows sites, `wp-signup.php` stays with core so people can still sign up for a site, and logged-in users keep the "Add New Site" link in My Sites.

New accounts become members of the site they registered on, with the site's default role (usually Subscriber). Core's `wp-signup.php` adds new users to no site at all. Use `hm_user_activation_signup_meta` to change the site or role.

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
| `Resend Activation Form` | Renders an email field so a user can request a new activation link |
| `Activation Link Resent` _(group variant)_ | Shown only after a new link was requested; holds the confirmation message |
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
- `Activation: Login URL` — the login URL, for a "Log in" link in the success message
- `Password reset: Error message`

## Link lifetime and resending

**Link lifetime** — set **Activation link lifetime** in the settings to a number of hours. Links older than that show an "expired" error instead of activating. `0` (the default) means links never expire. Sites can also use the `hm_user_activation_link_lifetime` filter. Expiry is only enforced by the plugin's activation page. On a site where the plugin isn't active, or when no activation page is set, core's `wp-activate.php` still accepts the key.

**Resend block** — the `Resend Activation Form` block (`hm-user-activation/resend-form`) asks for an email address, then redirects back to the page with `?hm_activation_resent=1`. The reply is the same whether or not a registration exists, and a page refresh doesn't send another email. The default activation page includes the block in the errors group, so expired links lead straight to it, and adds an `Activation Link Resent` group that shows "If there's a pending registration for that address, we've sent a new activation link." only after that redirect. The group only shows on the redirect, and not when an activation result is also showing. The default registration page has the block and the group too, for someone whose earlier signup is still pending. Existing pages need the block and the group added by hand.

**Resend function** — `HM\UserActivation\Resend\resend( string $email, bool $defer = false ): bool` sends the activation email again for the newest pending signup (user or site) for that address. It returns `true` if a signup was found and the email was handed to `wp_mail()` (or queued on `shutdown` when `$defer` is true), and `false` otherwise. The block gives the same reply either way.

Resend rules:

- While the link is still valid, the same link is sent again and the key and registered time are not changed, so a stranger can't invalidate someone else's link.
- Once the link has expired, a new key is made and the lifetime restarts. The old key is gone.
- Each signup can be resent 5 times (`hm_user_activation_resend_limit`, `0` for no limit), and not within 5 minutes of the last resend (`hm_user_activation_resend_interval`, seconds, `0` for none). The count is kept in the signup meta.
- The resend form allows 10 requests per hour from one IP address (`hm_user_activation_resend_ip_limit`, `0` for no limit). Over the limit the reply is the same and nothing is sent. The address comes from `REMOTE_ADDR`; behind a proxy that doesn't restore the visitor's address, use `hm_user_activation_client_ip` to supply it, or every visitor shares one limit.
- If someone uses up a signup's resends while its link is still valid, every email still carries that valid link. With a link lifetime set, once the link expires and no resends are left, the person has to register again after core's two-day window.
- The form sends the email after the redirect, so timing doesn't show whether an address has a pending signup.
- Return `false` from `hm_user_activation_can_resend` (`bool $can_resend, object $signup`) to block a resend.

The resend count is removed from the signup before the plugin's activation page activates it. A signup activated some other way, such as core's `wp-activate.php` on a site without the plugin, may keep a `hm_activation_resends` entry in its user meta or the new site's options.

Signups are network-wide, but the link lifetime and the email templates are per site. A resend from another site uses that site's settings.

## Hooks

- `hm_user_activation_link_lifetime` — filter the link lifetime in hours.
- `hm_user_activation_resend_interval` — filter the minimum seconds between resends for a signup (`int $seconds`).
- `hm_user_activation_resend_limit` — filter how many times a signup can be resent (`int $limit`).
- `hm_user_activation_resend_ip_limit` — filter resend requests per hour per IP address (`int $limit`).
- `hm_user_activation_client_ip` — filter the client IP address used for the resend limit (`string $ip`).
- `hm_user_activation_can_resend` — filter whether a resend is allowed (`bool $can_resend, object $signup`).
- `hm_user_activation_needs_password_setup` — override whether the user is sent to set a password (`bool $needs, int $user_id, array $meta`). The default is `true` only while the user still has the password core generated during activation, so a password set by a hook on `wpmu_activate_user` skips the step automatically. Returning `false` for a user who only has the generated password leaves them unable to log in until they use "lost password". Returning `true` for a user who already has a password just sends them through a harmless reset. When `false`, no reset link is generated. If the welcome email is still sent, `{password_reset_link}` in it becomes the login URL, and if the welcome email body setting is empty a default body without a password step is used. On the default activation page the "Set your password" button is dropped when there is no reset link, and a "Log in" button is shown.
- `hm_user_activation_send_welcome_email` — filter whether the welcome email is sent; defaults to the setting (`bool $send, int $user_id, array $meta`).
- `hm_user_activation_login_url` — filter the login URL used for the `Activation: Login URL` binding and `{login_url}` in the welcome email. It is the **Log in page** setting if set, otherwise `wp_login_url()` (`string $url, int $user_id, array $meta`).
- `hm_user_activation_activated` — action fired after a successful activation (`int $user_id, array $meta`).

## Setup

1. Enable user registration for the network under **Network Admin → Settings → Registration Settings** (either "User accounts may be registered" or "Both sites and user accounts can be registered").
2. Activate the plugin on the target site.
3. Go to **Settings → User Activation** and configure:
   - **Registration page** — the generated draft page is selected for you. Registration links only point at it once it is published.
   - **Activation page** — the generated draft page is selected for you. Publish it before you save this screen, because this list only shows published pages.
   - **Password reset page** — the generated draft page is selected for you. Publish it before you save this screen, because this list only shows published pages.
   - **Log in page** — used as `{login_url}` in the welcome email.
   - Email templates for the activation, welcome and password reset emails.
4. Users who register will receive your custom activation email linking to the configured page.

## Registration hooks

- `hm_user_activation_registration_enabled` — filter whether the form accepts submissions (`bool $enabled`, `string $network_setting`).
- `hm_user_activation_signup_meta` — filter the meta stored against the signup (`array $meta`, `string $user_name`, `string $user_email`). The meta starts with `add_to_blog` (this site) and `new_role` (the default role). Core's `add_signup_meta` filter is applied first.
- `hm_user_activation_registered` — action fired after a signup is created (`string $user_name`, `string $user_email`, `array $meta`).

## Email placeholders

### Activation email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{activation_link}`

### Welcome email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{display_name}` `{first_name}` `{last_name}` `{nickname}` `{login_url}` `{password_reset_link}`

### Password reset email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{reset_link}`

## Testing

The plugin has end-to-end tests that use Playwright, `@wordpress/scripts` and `@wordpress/env`. The tests run on a subdirectory multisite network with the plugin network-activated. Network activation matters because WordPress only loads network-active plugins on `wp-activate.php`.

### Running the tests

You need Node.js and Docker.

1. Install dependencies and the browser:
   ```bash
   npm install
   npx playwright install chromium
   ```

2. Start the WordPress environment. The tests site runs on port 8889.
   ```bash
   npm run wp-env start
   ```

3. Convert the site to a multisite network, activate the plugin and publish the pages the tests use. It is safe to run again.
   ```bash
   npm run env:setup
   ```

4. Run the tests:
   ```bash
   npm run test:e2e
   ```

If port 8888 is already in use, create a git-ignored `.wp-env.override.json` containing `{ "port": 8890 }`. The tests only use port 8889.

### Other commands

- `npm run test:e2e:debug` runs the tests in debug mode.
- `npm run test:e2e:watch` reruns the tests when files change.
- `npm run wp-env stop` stops the environment.
- `WP_ENV_CORE=WordPress/WordPress#6.9.7 npm run wp-env start` runs against another WordPress version.

### Test helpers

`tests/mu-plugins/hmua-test-helpers.php` is only active when the `HMUA_TESTS` constant is set, which `.wp-env.json` does for the tests environment. It stores every outgoing email instead of sending it, and adds REST routes under `hmua-tests/v1` for reading mail, creating signups and users, and setting plugin options. `tests/e2e/fixtures.js` wraps these as the `hmua` fixture.

### Coding standards

```bash
composer install
composer lint
```
