# HM User Activation

Replaces WordPress Multisite's default `wp-signup.php` and `wp-activate.php` flow with site-level registration, activation, login and password reset pages, customisable emails, and block-based templating.

## Requirements

- WordPress Multisite
- WordPress 6.5+
- PHP 8.1+

## Features

**Site-level registration page** — registration happens on your own site instead of the network's `wp-signup.php`. Signups are created with core's multisite functions (`wpmu_validate_user_signup()` and `wpmu_signup_user()`), so all the usual validation — reserved and illegal names, banned email domains, existing users, pending signups — still applies. Core's sign-up hooks are honoured too, so CAPTCHA, anti-spam and consent plugins that add fields to `wp-signup.php` work here. See [Extending the forms](#extending-the-forms). Plugins that only load on `wp-signup.php` need testing. Like `wp-signup.php`, the form tells visitors when a username or email address is already taken.

Once the page is published, `wp_registration_url()` and the `wp-login.php?action=register` link point at it for logged-out visitors. When the network allows user accounts only, requests to `wp-signup.php` are redirected there too. This needs the plugin to be active on the network's main site, because `wp-signup.php` always runs on the main site. When the network also allows sites, `wp-signup.php` stays with core so people can still sign up for a site, and logged-in users keep the "Add New Site" link in My Sites.

New accounts become members of the site they registered on, with the site's default role (usually Subscriber). Core's `wp-signup.php` adds new users to no site at all. Use `hm_user_activation_signup_meta` to change the site or role.

**Site-level activation page** — on plugin activation a draft page is created, pre-populated with the activation form block and conditional success/error blocks. Publish it and assign it in settings.

**Customisable activation email** — replaces the default network activation email with one that links to your site's own activation page. Subject, from name, from address, and body are all editable.

**Post-activation welcome email** — optionally send a follow-up email once the account is activated. It never contains a password: users get a one-time password reset link to set their own instead. Independently configurable from the activation email.

**Password reset flow** — a site-level reset page handles both requesting a reset link and setting a new password, and once it is published `wp_lostpassword_url()` is filtered to point at it.

**Auto-login** — optionally log users in immediately after a successful activation (admin-controlled).

**Block editor support** — blocks and group variations for building each page:

| Block / Variation | Purpose |
|---|---|
| `Registration Form` | Renders the username and email fields and submit button. Turn off **Ask for a username** to ask only for an email address |
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

**Email-only registration** — the Registration Form block has an **Ask for a username** setting (the `showUsername` attribute, on by default). When it is off, the form has no Username field and the username is made from the email address. See [Registration without a username](#registration-without-a-username).

**Block bindings** — individual binding sources for use anywhere in the editor:

- `Registration: Error message`
- `Registration: Email address` / `Registration: Confirmation message`
- `Activation: Error message`
- `Activation: Username` / `Activation: Username (formatted)`
- `Activation: Password reset URL`
- `Activation: Login URL` — the login URL, for a "Log in" link in the success message
- `Password reset: Error message`

## Link lifetime and resending

**Link lifetime** — set **Activation link lifetime** in the settings to a number of hours. Links older than that show an "expired" error instead of activating. `0` (the default) means links never expire. Sites can also use the `hm_user_activation_link_lifetime` filter, which also gets the signup row. Expiry is only enforced by the plugin's activation page. On a site where the plugin isn't active, or when no activation page is set, core's `wp-activate.php` still accepts the key.

**Resend block** — the `Resend Activation Form` block (`hm-user-activation/resend-form`) asks for an email address, then redirects back to the page with `?hm_activation_resent=1`. The reply is the same whether or not a registration exists, and a page refresh doesn't send another email. The default activation page includes the block in the errors group, so expired links lead straight to it, and adds an `Activation Link Resent` group that shows "If there's a pending registration for that address, we've sent a new activation link." only after that redirect. The group only shows on the redirect, and not when an activation result is also showing. The default registration page has the block and the group too, for someone whose earlier signup is still pending. Existing pages need the block and the group added by hand.

**Resend function** — `HM\UserActivation\Resend\resend( string $email, bool $defer = false ): bool` sends the activation email again for the newest pending signup (user or site) for that address. It returns `true` if a signup was found and the email was handed to `wp_mail()` (or queued on `shutdown` when `$defer` is true), and `false` otherwise. The block gives the same reply either way.

Resend rules:

- While the link is still valid, the same link is sent again and the key and registered time are not changed, so a stranger can't invalidate someone else's link.
- Once the link has expired, a new key is made and the lifetime restarts. The old key is gone.
- Each signup can be resent 5 times (`hm_user_activation_resend_limit`, `0` for no limit), and not within 5 minutes of the last resend (`hm_user_activation_resend_interval`, seconds, `0` for none). The count is kept in the signup meta.
- Each signup can also be resent 3 times an hour (`hm_user_activation_resend_hourly_limit`, `0` for no limit). The hour is a fixed window. It starts with the first resend in it and lasts one hour. The first resend after the window ends starts a new one. The window start and count are kept in the signup meta.
- The resend form has no per-IP limit by default (`hm_user_activation_resend_ip_limit` is `0`). A limit kept in PHP costs a write on every request and blocks offices that share one address, so rate limit per IP at the web server instead. See the [nginx rules](#nginx) in Rate limiting. A site that wants the PHP limit can return a number of requests per hour from the filter. Over the limit the reply is the same and nothing is sent. The address comes from `REMOTE_ADDR`; behind a proxy that doesn't restore the visitor's address, use `hm_user_activation_client_ip` to supply it, or every visitor shares one limit.
- If someone uses up a signup's resends while its link is still valid, every email still carries that valid link. With a link lifetime set, once the link expires and no resends are left, the person has to register again after core's two-day window.
- The form sends the email after the redirect, so timing doesn't show whether an address has a pending signup.
- Return `false` from `hm_user_activation_can_resend` (`bool $can_resend, object $signup`) to block a resend.

The resend count is removed from the signup before the plugin's activation page activates it. A signup activated some other way, such as core's `wp-activate.php` on a site without the plugin, may keep a `hm_activation_resends` entry in its user meta or the new site's options.

Signups are network-wide, but the link lifetime and the email templates are per site. A resend from another site uses that site's settings.

## Complete your account mode

By default, opening an activation link activates the account straight away, and the user then sets a password from a link. Turn on **Complete your account** in the settings to change this. It is off by default.

With it on:

- An activation link shows a form instead of activating. The form shows the email address from the signup, which can't be changed, and asks for a password and its confirmation.
- The account is only activated when the user submits the form and everything is valid. Opening or reloading the link never activates it. A mail link scanner that follows the link only sees the form.
- The password is checked as on the reset form and `wp-login.php`, so `check_passwords` and `validate_password_reset` run and password policy plugins apply. The account does not exist yet, so `validate_password_reset` gets a user with ID `0` that only has the login and email from the signup.
- An unknown or already used link shows the error group with the resend form. Both get the same message. An expired link says it has expired.
- After activation the user is sent to the log in URL with `?hm_activation_complete=1`. The `Login Form` block and `wp-login.php` then show "Your account is ready. Log in."
- The user is **not** logged in automatically, and the **Auto-login on activation** setting is ignored. Logging in through the log in form means anything a site runs at login, such as two-factor enrolment, still runs.
- There is no set-password step. The welcome email, if it is on, uses the log in URL for `{password_reset_link}`, and the default body has no password step. `hm_user_activation_needs_password_setup` is not used. The password the user chose replaces any password that a `wpmu_activate_user` hook set.
- A signup whose username already belongs to an account with another email address can't be completed. It gets the same error as an unknown link. An account with the signup's own email address can be completed, for example after core created the user but failed to create the site. So until the link is used, it works like a password reset link for that account. Activation links never expire unless you set **Activation link lifetime**.

The form is the `Activation Form` block, so existing activation pages keep working in both modes, and the default activation page needs no change.

Sites can add their own fields, such as names or consent checkboxes. The form fires core's `wp-login.php` registration hooks, so the portable way is a plugin that uses `register_form`, `registration_errors` and `user_register`. See [Extending the forms](#extending-the-forms). The plugin's own hooks below still work and are kept for existing sites:

```php
add_action( 'hm_user_activation_complete_account_fields', function ( $signup, $meta, $fields ) {
	printf(
		'<p><label for="first-name">First name</label> <input id="first-name" name="first_name" value="%s"></p>',
		esc_attr( $fields['first_name'] ?? '' )
	);
}, 10, 3 );

add_filter( 'hm_user_activation_complete_account_errors', function ( $errors, $fields ) {
	if ( trim( $fields['first_name'] ?? '' ) === '' ) {
		$errors->add( 'first_name', 'Please enter your first name.' );
	}
	return $errors;
}, 10, 2 );

add_action( 'hm_user_activation_complete_account', function ( $user_id, $meta, $fields ) {
	update_user_meta( $user_id, 'first_name', sanitize_text_field( $fields['first_name'] ) );
}, 10, 3 );
```

`$fields` is the posted form, unslashed and not sanitised, without the password fields and the form's own fields. Sanitise and escape your fields yourself. When a form has errors, it is shown again with the messages, and `$fields` holds the posted values so the fields can be filled in again. After a failed security check, `$fields` is empty.

## Hooks

- `hm_user_activation_link_lifetime` — filter the link lifetime in hours (`int $hours, object|null $signup`). The signup row lets a site limit expiry to some signups. It is `null` when unknown.
- `hm_user_activation_resend_interval` — filter the minimum seconds between resends for a signup (`int $seconds`).
- `hm_user_activation_resend_limit` — filter how many times a signup can be resent (`int $limit`).
- `hm_user_activation_resend_hourly_limit` — filter how many times a signup can be resent in an hour (`int $limit`, `object $signup`). The default is 3 and `0` turns it off.
- `hm_user_activation_resend_ip_limit` — filter resend requests per hour per IP address (`int $limit`). The default is `0`, which turns it off.
- `hm_user_activation_client_ip` — filter the client IP address used for the resend limit (`string $ip`).
- `hm_user_activation_can_resend` — filter whether a resend is allowed (`bool $can_resend, object $signup`).
- `hm_user_activation_needs_password_setup` — override whether the user is sent to set a password (`bool $needs, int $user_id, array $meta`). The default is `true` only while the user still has the password core generated during activation, so a password set by a hook on `wpmu_activate_user` skips the step automatically. Returning `false` for a user who only has the generated password leaves them unable to log in until they use "lost password". Returning `true` for a user who already has a password just sends them through a harmless reset. When `false`, no reset link is generated. If the welcome email is still sent, `{password_reset_link}` in it becomes the login URL, and if the welcome email body setting is empty a default body without a password step is used. On the default activation page the "Set your password" button is dropped when there is no reset link, and a "Log in" button is shown.
- `hm_user_activation_send_welcome_email` — filter whether the welcome email is sent; defaults to the setting (`bool $send, int $user_id, array $meta`).
- `hm_user_activation_login_url` — filter the login URL used for the `Activation: Login URL` binding and `{login_url}` in the welcome email. It is the **Log in page** setting if set, then a 1.0.0 log in URL that the upgrade could not turn into a page, then `wp_login_url()` (`string $url, int $user_id, array $meta`).
- `hm_user_activation_reset_url_fallback` — filter the base URL of set-password links when there is no published password reset page. The default is core's `wp-login.php?action=rp` (`string $url, string $key, string $login`).
- `hm_user_activation_password_strength_meter` — filter whether the Complete your account and Password Reset forms show core's password strength meter and a show/hide button (`bool $enabled`, default `true`). The server's password checks run either way.
- `hm_user_activation_filter_lostpassword_url` — when a published password reset page is set, the plugin points every `wp_lostpassword_url()` link on the site at it. Return `false` to turn that off (`bool $filter`, default `true`).
- `hm_user_activation_complete_account_fields` — action inside the Complete your account form, before the submit button (`object $signup, array $meta, array $fields`).
- `hm_user_activation_complete_account_errors` — filter the errors for the Complete your account form (`WP_Error $errors, array $fields, object $signup, array $meta`).
- `hm_user_activation_complete_account` — action after an account is activated from the Complete your account form, before the welcome email (`int $user_id, array $meta, array $fields`).
- `hm_user_activation_activated` — action fired after a successful activation (`int $user_id, array $meta`). It fires before auto-login, so the new user is not yet the current user.

## Installation

The plugin needs its compiled `build/` directory. Every release tag and release ZIP from 1.2.0 on includes it, so install a release. Earlier releases need no build.

- **Composer** — `composer require humanmade/hm-user-activation`. Composer installs from the release tags, which contain `build/`. To try the latest unreleased code, require `dev-release` instead. The `release` branch is `main` plus a fresh build after every merge.
- **ZIP** — download `hm-user-activation-X.Y.Z.zip` from the GitHub release and upload it as a plugin.

`main` and `dev-main` hold only the sources. They need `npm install` and `npm run build` before the blocks work. See [Development](#development).

## Setup

1. Enable user registration for the network under **Network Admin → Settings → Registration Settings** (either "User accounts may be registered" or "Both sites and user accounts can be registered").
2. Activate the plugin on the target site.
3. Go to **Settings → User Activation** and configure:
   - **Registration page** — the generated draft page is selected for you. Registration links only point at it once it is published.
   - **Activation page** — the generated draft page is selected for you. Publish it before you save this screen, because this list only shows published pages.
   - **Password reset page** — the generated draft page is selected for you. Publish it before you save this screen, because this list only shows published pages. Until it is published, set-password links go to core's `wp-login.php`.
   - **Log in page** — used as `{login_url}` in the welcome email.
   - Email templates for the activation, welcome and password reset emails.
4. Users who register will receive your custom activation email linking to the configured page.

## Upgrading to 1.4.0

The per-IP resend limit is now off by default. Earlier versions allowed 10 resend requests an hour from one IP address. To get the old behaviour back, return `10` from `hm_user_activation_resend_ip_limit`:

```php
add_filter( 'hm_user_activation_resend_ip_limit', fn() => 10 );
```

Each signup is now also limited to 3 resends an hour. Return a different number from `hm_user_activation_resend_hourly_limit`, or `0` to turn it off.

## Upgrading from 1.0.0

Version 1.1 renamed some settings and removed the password bindings. The plugin upgrades this data once on each site. The upgrade runs when the plugin is activated (on the main site only when it is network-activated), and the first time someone who can manage options opens the site's dashboard. It never runs on front-end or admin-ajax requests. Sites that went from 1.0.0 to 1.1.x get their 1.0.0 sender and log in URL back, because 1.1.x ignored them. The `hm_activation_db_version` option records that it has run. Version 1.0.0 stored no network options, so nothing changes at network level.

The upgrade does these things:

- It moves the activation email sender (`hm_activation_email_from_name`, `hm_activation_email_from`) to the **From name** and **From email** settings. If there was no activation email sender, it uses the welcome email sender (`hm_activation_welcome_email_from_name`, `hm_activation_welcome_email_from`). All emails now share one sender, so a different welcome email sender is lost. A sender that is already set is kept. The old options are deleted.
- It turns the 1.0.0 log in URL (`hm_activation_login_url`) into the **Log in page** setting when the URL is a page on the site. A URL that is not a page, for example on another domain, is kept and still used when no log in page is selected. The settings screen shows this URL. Select a log in page to replace it, or delete the `hm_activation_login_url` option to go back to the WordPress login URL.
- It replaces `{password}` in the welcome email subject and body with `{password_reset_link}`. The 1.0.0 line `Password: {password}` becomes `Set your password: {password_reset_link}`.
- It updates pages and synced patterns that use the removed `Activation: Password` and `Activation: Password (formatted)` bindings. The first of these blocks becomes the "Set your password" and "Log in" buttons from the default activation page. The others are removed. The rest of the page stays the same. When revisions are enabled, the old version is saved as a revision.
- If the site has an activation page but no password reset page, it creates a draft one. Set-password links go to core's `wp-login.php` until you publish it.

It does not add the resend form or the `Activation Link Resent` group to an existing activation page. Add them by hand if you want them.

To upgrade every site at once, without opening each dashboard, use WP-CLI:

```bash
wp site list --field=url | xargs -I {} wp --url={} eval 'if ( function_exists( "HM\\UserActivation\\Upgrade\\upgrade" ) ) { HM\UserActivation\Upgrade\upgrade(); }'
```

Code cannot be upgraded this way, so these stay:

- The 1.0.0 classes `Activation`, `Emails`, `Block_Bindings`, `Page_Setup` and `Admin_Settings` in the `HM\UserActivation` namespace still exist with their public static methods. They are deprecated and call the new functions. `Activation::get_password()` always returns an empty string, `Activation::get_result()` leaves out `reset_url`, and `Emails::send_welcome_email()` ignores its `$password` argument.
- Hooks now use namespaced functions, so a 1.0.0-style `remove_filter( 'wpmu_signup_user_notification', [ 'HM\UserActivation\Emails', 'intercept_user_notification' ] )` no longer matches. Use the function name instead, for example `remove_filter( 'wpmu_signup_user_notification', 'HM\UserActivation\Emails\intercept_user_notification' )`. The functions that 1.0.0 hooked as class methods are now:
  - `HM\UserActivation\Emails\intercept_user_notification` and `HM\UserActivation\Emails\intercept_blog_notification` (`wpmu_signup_user_notification`, `wpmu_signup_blog_notification`)
  - `HM\UserActivation\Activation\maybe_redirect_wp_activate` (`init`, priority 1), `HM\UserActivation\Activation\maybe_process_activation` (`template_redirect`) and `HM\UserActivation\Activation\filter_block_visibility` (`render_block`)
  - `HM\UserActivation\BlockBindings\register_sources` (`init`)
  - `HM\UserActivation\Admin\add_settings_page` (`admin_menu`) and `HM\UserActivation\Admin\register_settings` (`admin_init`)

**Mail link scanners** — by default, an account is activated when its activation link is opened. A mail link scanner that follows links can activate the account before the user does. The user then sees the "already active" error with the resend form. If auto-login is on, the scanner gets the login cookie, not the user. [Complete your account mode](#complete-your-account-mode) avoids this, because opening the link only shows a form.

## Security behaviour

The plugin replaces flows that core keeps on `wp-login.php`, `wp-signup.php` and `wp-activate.php`, so it applies the same protections to the pages it owns:

- **No passwords are emailed.** `wpmu_activate_signup()` gives every new account a generated password, and core's default welcome templates print it. The plugin's own welcome email carries a one-time reset link instead.
  - The network's user welcome email (`wpmu_welcome_user_notification()`) is suppressed. Filter `hm_user_activation_suppress_network_welcome_email` to change that. If you also turn off the plugin's welcome email, users get no email after activation.
  - The new site welcome email for site signups (`wpmu_welcome_notification()`) is kept, because it is the only email with the new site's address. The password in it is replaced with a link to the reset page.
- **Keys never stay in URLs.** An activation or reset key arriving in a query string is moved into a scoped, HTTP-only, `SameSite=Lax` cookie and the page reloads without it. This keeps it out of browser history, `Referer` headers, proxy logs and copy-pasted links. The cookies use a `wp-` prefix like core's own, so page caches and hosts that only pass `wp-` cookies through still work. The activation cookie is read once and then dropped, whether the key worked or not. Reset submissions cross-check the posted key against the cookie with `hash_equals()`, and the reset cookie is dropped once the key is spent or found invalid.
- **These pages are never cached or indexed.** `nocache_headers()`, `batcache_cancel()` where Batcache is present, `DONOTCACHEPAGE` and core's sensitive-page robots and referrer meta are applied. A page cache therefore cannot store an activation success response, which contains a username and a live reset link, and replay it to the next visitor.
- **Reset failures look the same.** Invalid, expired and mismatched keys share one message, and reset requests report success whether or not the account exists. The reset email is sent after the response, so on PHP-FPM the response time does not reveal it either.
- **Core's hooks are honoured**, so captcha and password policy plugins still apply: `allow_password_reset` (through `get_password_reset_key()`), `lostpassword_form`, `lostpassword_post`, `resetpass_form`, `validate_password_reset`, and `wp_login` when auto-login is on.
- **Auto-login never replaces a session.** If the visitor is already logged in, following an activation link activates the account but does not log them out of their own.
- **Email templates cannot capture keys.** Site admins can edit the email templates, but the emails can go to any user on the network. A link that carries a key is always kept apart from the template text around it, so a template cannot join its own URL to the front of the link.

## Rate limiting

These pages want limiting. Activation keys can be guessed given enough attempts, and the registration, reset and resend forms send mail to an address the caller supplies. Apart from the resend form's own caps (see [Link lifetime and resending](#link-lifetime-and-resending)), the plugin deliberately does **not** do this itself.

PHP only sees `REMOTE_ADDR`. On most hosting that is the load balancer or CDN, not the visitor. So a PHP limiter either trusts a header the caller controls, or treats everyone behind one address as a single client. Per-client counters also have to be stored somewhere, and an attack is exactly the traffic that fills that store: thousands of transient rows in `wp_options`, or churn through the object cache.

The web server already does this with fixed-size storage and eviction. Configure it there, in front of PHP.

Two things shape the rules:

- Limit form `POST`s by method, not by page path. WordPress serves the same page at several URLs (`/register`, `/?page_id=12`, `/index.php?page_id=12`), and it does not redirect `POST`s to the canonical one, so a path match is easy to step around.
- Activation keys also arrive by `GET`. Those requests are redirected to the page's canonical URL, so limiting that one path covers them.

### nginx

Recover the real client address first, or every request will share one key:

```nginx
# In http {}. Use the CIDRs of your load balancer or CDN.
set_real_ip_from 10.0.0.0/8;
real_ip_header   X-Forwarded-For;
real_ip_recursive on;
```

Then define the zones. nginx does not count a request whose key is an empty string, so the `map` decides what is counted:

```nginx
# In http {}. 10m of shared memory holds about 160k addresses and evicts the
# oldest when full, so it cannot grow without bound.
map "$request_method:$request_uri" $hm_post_limit_key {
    "~^POST:[^?]*/(wp-admin|wp-json)/"  "";
    "~^POST:"                           $binary_remote_addr;
    default                             "";
}

limit_req_zone $hm_post_limit_key  zone=hm_forms:10m      rate=10r/m;
limit_req_zone $binary_remote_addr zone=hm_activation:10m rate=20r/m;

limit_req_status 429;
```

Apply them in the `server {}` block. A `limit_req` set at server level applies to every `location` that does not set its own:

```nginx
# Every front-end POST: these forms, the resend form on any page,
# wp-login.php and comments.
limit_req zone=hm_forms burst=10 nodelay;

# Every request to the activation page. Use your own slug, with the site path
# in front on a subdirectory network.
location = /activate/ {
    limit_req zone=hm_forms burst=10 nodelay;
    limit_req zone=hm_activation burst=10 nodelay;
    try_files $uri /index.php?$args;
}
```

This counts every front-end `POST` outside `wp-admin` and the REST API. If other plugins post to the front end often, such as a shop's cart requests, raise the rate or add their paths to the first line of the `map`. With plain permalinks the REST API is `/?rest_route=…`, so add that too. The activation `location` assumes pretty permalinks.

### Apache

Again, recover the real client address first:

```apache
<IfModule mod_remoteip.c>
    RemoteIPHeader        X-Forwarded-For
    RemoteIPTrustedProxy  10.0.0.0/8
</IfModule>
```

`mod_qos` can count only the requests you mark, in fixed-size shared memory:

```apache
<IfModule mod_qos.c>
    # Up to 100k client addresses are tracked.
    QS_ClientEntries 100000

    # Mark front-end POSTs, but not wp-admin or the REST API.
    SetEnvIf Request_Method "^POST$"         HM_LIMIT=1
    SetEnvIf Request_URI    "/(wp-admin|wp-json)/" !HM_LIMIT

    # Mark every request to the activation page.
    SetEnvIf Request_URI    "^/activate/"    HM_LIMIT=1

    # At most 10 marked requests per client per minute.
    QS_ClientEventLimitCount 10 60 HM_LIMIT
</IfModule>
```

If `mod_qos` still sees the proxy's address, set `QS_ClientIpFromHeader` to the header your proxy sets. `mod_security` can do the same with rules on `REQUEST_METHOD` and `REQUEST_URI`.

Avoid `mod_evasive` and `mod_ratelimit` for this. `mod_evasive` settings apply to every URL on the server, so a limit strict enough to protect these forms also blocks editors in `wp-admin` and offices that share one address. `mod_ratelimit` limits bandwidth, not requests.

### At the edge

If a CDN or WAF fronts the site, such as Cloudflare, Fastly or CloudFront, put the rules there instead. It sees the real client address without any of the above, and stops the traffic before it reaches your server.

## Registration hooks

- `hm_user_activation_registration_enabled` — filter whether the form accepts submissions (`bool $enabled`, `string $network_setting`).
- `hm_user_activation_signup_meta` — filter the meta stored against the signup (`array $meta`, `string $user_name`, `string $user_email`). The meta starts with `add_to_blog` (this site) and `new_role` (the default role). Core's `add_signup_meta` filter is applied first.
- `hm_user_activation_registered` — action fired after a signup is created (`string $user_name`, `string $user_email`, `array $meta`).
- `hm_user_activation_registration_show_username` — filter whether the form asks for a username (`bool $show`). It starts as the block's **Ask for a username** setting. It runs when the form renders and when it is posted. Use it when the block is rendered outside the page content, for example from a template with `render_block()`, because the plugin reads the setting from the registration page's own content.
- `hm_user_activation_generated_user_name` — filter the username made from the email address when the field is off (`string $user_name`, `string $user_email`). The result still goes through `wpmu_validate_user_signup()`, so it must follow core's rules: 4 to 60 lowercase letters and numbers, not all numbers.

### Registration without a username

Turn off **Ask for a username** in the block's settings, or set `"showUsername": false` on the block. The form then has no Username field.

On submit, the plugin makes the username with `HM\UserActivation\Registration\generate_user_name( string $user_email ): string`. The rules are:

- It takes the part of the address before the `@` and keeps lowercase letters and numbers.
- It cuts that to 50 characters.
- It adds `user` in front when fewer than four characters are left, or when only numbers are left. An empty or invalid address gives `user`.
- If an account or a pending signup already has the name, it adds `2`, then `3`, up to `9`. After that it adds a random six character suffix.
- It skips names in the network's banned names (`illegal_names`) and in the `illegal_user_logins` filter.

It never looks at whether the email address has an account, so the result says nothing about who is registered. The name goes through `wpmu_validate_user_signup()` like any typed username, so core's rules and every validation hook still apply.

The first Registration Form block in the registration page's content decides. That includes blocks inside other blocks, and inside synced patterns that are published and not password protected. Later form blocks on the page do not change it. A posted `user_name` is ignored when the page's setting is off. A form that posts no username at all gets a generated one too, for example a form in a template that has **Ask for a username** off. A form that posts an empty username still gets core's error. A site that renders the block from PHP can use the `hm_user_activation_registration_show_username` filter to set the field for the whole site. If validation fails on the username, the message shows in the generic error slot (`#hm-registration-generic-error`), because there is no field to show it next to.


## Extending the forms

Both front-end forms fire WordPress core's own sign-up and registration hooks, so a plugin that adds fields to core's forms works with this plugin's forms unchanged. Use these core hooks for new fields. The plugin's own hooks are still there for sites that use them.

The plugin only runs on multisite, so the registration form follows `wp-signup.php`, not the single-site `wp-login.php?action=register` form.

### Registration Form block

These hooks fire where `wp-signup.php` fires them for a user-only signup, with the same arguments:

1. `signup_user_init` filter, before the form is drawn (`array $defaults` with `user_name`, `user_email` and `errors`).
2. `signup_hidden_fields` action, at the top of the form (`string $context`, always `validate-user`).
3. `signup_extra_fields` action, after the email field and before the submit button (`WP_Error $errors`). Print your fields here. Errors with the `generic` code are printed just above it, as core does.
4. `wpmu_validate_user_signup` filter, when the form is posted (`array $result` with `user_name`, `orig_username`, `user_email` and `errors`). Add an error to `$result['errors']` to stop the signup. Use your field's name as the error code, and print `$errors->get_error_message( 'your_field' )` next to the field in `signup_extra_fields`. Every error also shows in the **Registration Errors** group.
5. `add_signup_meta` filter, when the signup is created (`array $meta`). Add your values to store them with the signup. The plugin's `hm_user_activation_signup_meta` runs after it.
6. `wpmu_activate_user` action, when the account is activated (`int $user_id`, `string $password`, `array $meta`). Core passes the signup meta. Save your values to the user here.

Save in `wpmu_activate_user`, not `user_register`. The account is usually created later, from the activation link, so the registration form's `$_POST` is gone by then.

After an error, the form is shown again with the username and email filled in. Fill in your own fields from `$_POST`, as on `wp-signup.php`. A field can also be filled in from a query arg, for example `$_GET['company']`.

These `wp-signup.php` hooks are not fired: `before_signup_header`, `signup_header`, `before_signup_form` and `after_signup_form` wrap the whole `wp-signup.php` page, `preprocess_signup_form` only fires when that page shows an empty form, and `signup_finished` fires on its confirmation screen. On this page the theme and the group blocks own that output.

```php
add_action( 'signup_extra_fields', function ( WP_Error $errors ) {
	$checked = ! empty( $_POST['accept_terms'] );
	printf(
		'<p><label><input type="checkbox" name="accept_terms" value="1" %s> I accept the terms</label></p>',
		checked( $checked, true, false )
	);
	if ( $errors->get_error_message( 'accept_terms' ) ) {
		printf( '<p class="error">%s</p>', esc_html( $errors->get_error_message( 'accept_terms' ) ) );
	}
} );

add_filter( 'wpmu_validate_user_signup', function ( array $result ) {
	if ( isset( $_POST['user_email'] ) && empty( $_POST['accept_terms'] ) ) {
		$result['errors']->add( 'accept_terms', 'Please accept the terms.' );
	}
	return $result;
} );

add_filter( 'add_signup_meta', function ( array $meta ) {
	if ( ! empty( $_POST['accept_terms'] ) ) {
		$meta['accepted_terms'] = time();
	}
	return $meta;
} );

add_action( 'wpmu_activate_user', function ( $user_id, $password, $meta ) {
	if ( ! empty( $meta['accepted_terms'] ) ) {
		update_user_meta( $user_id, 'accepted_terms', (int) $meta['accepted_terms'] );
	}
}, 10, 3 );
```

### Complete your account form

This form is the second step of a multisite signup, where the user chooses a password. The account does not exist yet, so the form fires the hooks that `wp-login.php` fires to register a new user:

1. `register_form` action, inside the form after the password fields. Print your fields here. The plugin's `hm_user_activation_complete_account_fields` fires after it.
2. `register_post` action, when the form is posted and the password has been checked (`string $user_login`, `string $user_email`, `WP_Error $errors`).
3. `registration_errors` filter, straight after it (`WP_Error $errors`, `string $user_login`, `string $user_email`). Add an error to keep the form on the page. The login and email come from the signup. `$errors` already holds any password errors. The plugin's `hm_user_activation_complete_account_errors` runs after it and sees every error.
4. `user_register` action, when the account is created (`int $user_id`, `array $userdata`). This happens while the form's POST is still being handled, so read your fields from `$_POST` and save them here. `wpmu_activate_user` and `hm_user_activation_complete_account` fire after it.

If the signup's account already exists, `user_register` does not fire. This happens when core created the user but then failed to create the site for a site signup. Use `hm_user_activation_complete_account` if you need to cover that case.

Errors show at the top of the form. Fill in your fields again from `$_POST`, as on `wp-login.php`. After a failed security check, the plugin removes your fields from `$_POST`, so they are not filled in again.

The hook arguments never include the password. The password is still in `$_POST['pass1']` and `$_POST['pass2']`, as on core's reset password form, because password policy plugins read it there. Never save or log all of `$_POST`.

```php
add_action( 'register_form', function () {
	printf(
		'<p><label for="company">Company</label> <input id="company" name="company" value="%s"></p>',
		esc_attr( wp_unslash( $_POST['company'] ?? '' ) )
	);
} );

add_filter( 'registration_errors', function ( WP_Error $errors ) {
	if ( trim( wp_unslash( $_POST['company'] ?? '' ) ) === '' ) {
		$errors->add( 'company', 'Please enter your company.' );
	}
	return $errors;
} );

add_action( 'user_register', function ( $user_id ) {
	if ( isset( $_POST['company'] ) ) {
		update_user_meta( $user_id, 'company', sanitize_text_field( wp_unslash( $_POST['company'] ) ) );
	}
} );
```

A plugin that adds the same field to both forms asks for it twice. Use one form or the other for each field.

## Email placeholders

### Activation email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{activation_link}`

### Welcome email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{display_name}` `{first_name}` `{last_name}` `{nickname}` `{login_url}` `{password_reset_link}`

### Password reset email
`{site_name}` `{site_url}` `{network_name}` `{username}` `{reset_link}`

## Development

The block and editor sources live in `src/`. `npm run build` compiles them to `build/`, which is git-ignored. `npm run start` rebuilds when a file changes. The plugin loads its blocks and editor scripts from `build/`, so a source checkout needs a build before the blocks work. Until then, the admin screens show a notice. On `main` and the `release` branch, the plugin version is the placeholder `__VERSION__`. The Release workflow replaces it in each tag.

## Testing

The plugin has PHPUnit tests and end-to-end tests. The end-to-end tests use Playwright, `@wordpress/scripts` and `@wordpress/env`. The tests run on a subdirectory multisite network with the plugin network-activated. Network activation matters because WordPress only loads network-active plugins on `wp-activate.php`.

### Running the tests

You need Node.js and Docker.

1. Install dependencies and the browser, then build the blocks:
   ```bash
   npm install
   npx playwright install chromium
   npm run build
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

### Running the PHPUnit tests

The PHPUnit tests run in the `wp-env` tests container, on a multisite install. They use the WordPress test library that `wp-env` provides.

```bash
composer install
npm run wp-env start
npm run test:php
```

The test library drops every table with its table prefix. The bootstrap uses `wptests_` so the end-to-end site, which uses `wp_`, is not touched.

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

## Releasing

Releases are made by the **Release** workflow (`.github/workflows/release.yml`). Each release is a tag such as `1.2.0` and a GitHub release with a ZIP.

1. Check that `main` has everything for the release and that its checks are green.
2. Pick the version with [semantic versioning](https://semver.org/): a new major version for breaking changes, a new minor version for new features, and a new patch version for fixes only.
3. Go to **Actions → Release → Run workflow**, enter the version without a leading `v`, and run it. With the GitHub CLI: `gh workflow run release.yml -f version=1.2.0`.

The workflow does this:

1. It stops if the version is not `X.Y.Z` or the tag already exists.
2. It runs `npm ci --ignore-scripts` and `npm run build` on `main`. Install scripts are skipped, so no dependency runs code while the job holds a write token.
3. It replaces `__VERSION__` in `hm-user-activation.php` with the version, and sets the version in each built `block.json`, so block stylesheets get a new cache key.
4. It commits `build/` and the versioned plugin file, and tags that commit. The commit is only on the tag, not on `main`.
5. It builds the ZIP with `git archive` and checks it with `.github/scripts/check-archive.sh`. The ZIP must contain `build/` and the version, and must not contain sources, tests or tooling.
6. It saves the ZIP as a workflow artifact, pushes the tag, then publishes a GitHub release with the ZIP and generated notes.

If the run fails after the tag was pushed, don't run it again, because the tag already exists. Download the ZIP from the run's artifacts, or make it again with `git archive --format=zip --prefix=hm-user-activation/ -o hm-user-activation-1.2.0.zip 1.2.0`, then run `gh release create 1.2.0 hm-user-activation-1.2.0.zip --verify-tag --generate-notes`.

Packagist picks up the new tag by itself. Tags are never moved or deleted, because Packagist does not accept a changed tag. If a release is wrong, release a new patch version.

The **Build to release branch** workflow needs the `release` branch to exist. It was created once from `main` with `git push origin main:release`. If it is ever deleted, create it the same way, then merge a change to `main`: the workflow fails while `release` and `main` point at the same commit.

What ships is set by the `export-ignore` rules in `.gitattributes`. The **Package** workflow builds and checks a ZIP the same way on every pull request, so a change that would break the release fails there first.
