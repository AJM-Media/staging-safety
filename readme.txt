=== Staging Safety ===
Contributors: ajmmedia
Donate link: https://ko-fi.com/ajmmedia
Tags: staging, environment, email, noindex, local development
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows which copy of your site you're on, holds emails on staging and local copies, and keeps them out of search engines.

== Description ==

Working across a live site, a staging site and a local copy? Staging Safety stops the three classic mistakes:

* **Editing the wrong site.** The admin bar shows Local, Development, Staging or Production in a different colour, on the dashboard and the front end.
* **A copy emailing real customers.** On copies, emails are held and logged (or all sent to one address you choose), so order emails, form notifications and password resets never reach real people.
* **A copy turning up in Google.** Copies get noindex on every page and their sitemap turned off.

It also watches the live site: if "Discourage search engines" gets switched on there, often after a staging database is pushed live, you get a warning straight away.

This plugin doesn't create staging sites. It protects the copies you already have, wherever they're hosted.

= How it knows which site is live =

Enter your live site's address once. The setting travels with the database, so every copy running at a different address knows it's a copy and turns its protections on. When you push a copy live, the address matches again and the protections switch off by themselves.

If `WP_ENVIRONMENT_TYPE` is set in wp-config.php, that always wins. Local addresses such as `.local`, `.test` and `localhost` are recognised without any setup.

Until the live address is set, any site that isn't clearly local is treated as live and nothing is blocked. Blocking emails or search engines on a real live site would be worse than doing nothing.

= Built to be safe =

* It never changes Settings > Reading, so a copy's settings can't hide your live site from search engines after a push.
* Held emails count as sent, so contact forms and checkouts on copies behave normally.
* Only the recipient and subject of held emails are logged (the last 50), never the message.

= For developers =

Set these in wp-config.php:

* `STAGING_SAFETY_PRODUCTION_URL`: your live site's address.
* `STAGING_SAFETY_DISABLE`: set to `true` to turn the protections off.

Filters: `staging_safety_environment`, `staging_safety_local_suffixes`, `staging_safety_staging_suffixes`.

Working on a local copy with missing images? [Load Media from Production](https://wordpress.org/plugins/load-media-from-production/) loads them from your live site.

= Privacy =

The plugin collects no data and makes no external requests. On copies of your site, it stores the recipient and subject of the last 50 held or rerouted emails in your database. Clear the log at any time from the settings page; it's deleted when you uninstall the plugin.

== Installation ==

1. Install and activate the plugin on your live site, then copy the site to staging or local as usual. Installing it on a copy works too.
2. Go to Settings > Staging Safety.
3. Enter your live site's address and save (or click "This is the live site" in the setup notice on the live site).
4. Choose what happens to emails on copies.

== Frequently Asked Questions ==

= Does it slow my site down? =

No. It adds an admin bar item and a few filters, and makes no external requests.

= Emails still go out on my staging site =

Another plugin may have replaced WordPress's email function, which some SMTP and email service plugins do. The settings page tells you when this happens. Use that plugin's own option to stop sending on copies.

= My host's staging address wasn't recognised =

Enter your live site's address in the settings. Any other address is then treated as a copy.

= Will it hide my live site from Google? =

No. Noindex is only added on sites whose address differs from the live address you entered, or that are clearly local, or that `WP_ENVIRONMENT_TYPE` marks as non-production.

== Screenshots ==

1. The admin bar on a staging copy, showing what's being held back.
2. Settings: live address, email handling, search engines and the email log.

== Changelog ==

= 1.0.0 =
* First release.
