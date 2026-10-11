# Staging Safety

[![Tests](https://github.com/AJM-Media/staging-safety/actions/workflows/tests.yml/badge.svg)](https://github.com/AJM-Media/staging-safety/actions/workflows/tests.yml)

WordPress plugin that shows which copy of a site you're on, holds emails on
staging and local copies, and keeps those copies out of search engines.

- Admin bar badge: Local, Development, Staging or Production, each in its own
  colour.
- On copies: emails held and logged (or rerouted to one address), noindex on
  every page, core sitemaps off.
- On the live site: a warning if "Discourage search engines" is switched on.
- The live site is identified by its saved address (or `WP_ENVIRONMENT_TYPE`),
  so a copied database knows it's a copy. Until that's set, only clearly local
  addresses are guarded.

The wp.org listing text is in [`readme.txt`](readme.txt).

## Development

The repo root is the plugin folder. Build the release zip from the committed
code; files marked `export-ignore` in `.gitattributes` are left out:

    git archive --format=zip --prefix=staging-safety/ -o ../staging-safety.zip HEAD

Tests run in GitHub Actions on every branch push (`tests/run.sh`): WordPress in
Docker, moved between a live address, a copy address, a staging-looking
address and a local one, across PHP 7.4-8.3, WordPress 6.0 and latest, single
site and multisite.

wp-config constants: `STAGING_SAFETY_PRODUCTION_URL`, `STAGING_SAFETY_DISABLE`.
Filters: `staging_safety_environment`, `staging_safety_local_suffixes`,
`staging_safety_staging_suffixes`.

## Support

Found a bug or have an idea? [Open an issue](https://github.com/AJM-Media/staging-safety/issues).

The plugin is free. If it saves you time, you can
[buy me a coffee](https://ko-fi.com/ajmmedia).

## Licence

GPLv2 or later.
