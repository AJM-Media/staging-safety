#!/usr/bin/env bash
set -uo pipefail

PHP_VERSION=${PHP_VERSION:-8.3}
WP_VERSION=${WP_VERSION:-latest}
INSTALL=${INSTALL:-single}

DIR=$(cd "$(dirname "$0")" && pwd)
REPO=$(dirname "$DIR")
WORK=$(mktemp -d)
SLUG=staging-safety
LIVE=http://shop.acme.co
COPY=http://copy.acme.co
GUESS=http://staging.acme.co
LOCAL=http://shop.local

PASSES=0
FAILS=0
RESULTS=()

die() { echo "SETUP FAILED: $*"; exit 1; }
pass() { PASSES=$((PASSES + 1)); RESULTS+=("PASS $1"); echo "PASS  $1"; }
fail() { FAILS=$((FAILS + 1)); RESULTS+=("FAIL $1: $2"); echo "FAIL  $1"; echo "      $2"; }
expect() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected '$2', got '$3'"; fi; }
expect_has() { if grep -qiF -- "$2" <<<"$3"; then pass "$1"; else fail "$1" "missing '$2' in '$3'"; fi; }
expect_not() { if grep -qiF -- "$2" <<<"$3"; then fail "$1" "found '$2'"; else pass "$1"; fi; }

x() { docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli site "$@"; }
wp() { x php -d memory_limit=1G /usr/local/bin/wp --path=/var/www/html "$@"; }
h() { wp --user=1 eval-file /tmp/stsf.php "$@" | tail -n 1; }
hu() { local url=$1; shift; wp --user=1 --url="$url" eval-file /tmp/stsf.php "$@" | tail -n 1; }
headers() { docker exec site curl -s -D - -o /dev/null "$1/"; }
body() { docker exec site curl -s "$1/"; }
sitemap() { docker exec site curl -s "$1/?sitemap=index" | grep -c "<sitemapindex"; }
move_to() { wp option update home "$1" --quiet; wp option update siteurl "$1" --quiet; }

echo "== PHP $PHP_VERSION, WordPress $WP_VERSION, $INSTALL"

git -C "$REPO" archive --prefix="$SLUG/" HEAD | tar -x -C "$WORK" || die "git archive"
for private in tests .github .wordpress-org listing-images README.md; do
	if [ -e "$WORK/$SLUG/$private" ]; then fail "release zip leaves out $private" "present"; else pass "release zip leaves out $private"; fi
done

docker network create stsf >/dev/null || die "network"
mkdir -p "$WORK/html" && chmod 777 "$WORK/html"
docker run -d --name db --network stsf -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:10.11 >/dev/null || die "db"
docker run -d --name site --network stsf \
	--network-alias shop.acme.co --network-alias copy.acme.co --network-alias staging.acme.co --network-alias shop.local \
	-v "$WORK/html:/var/www/html" --entrypoint docker-php-entrypoint "wordpress:php$PHP_VERSION-apache" apache2-foreground >/dev/null || die "site"

docker exec site sh -c 'curl -sSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp' || die "wp-cli"
WP_TARBALL=https://wordpress.org/wordpress-$WP_VERSION.tar.gz
[ "$WP_VERSION" = latest ] && WP_TARBALL=https://wordpress.org/latest.tar.gz
x sh -c "curl -sSfL --retry 5 --retry-all-errors -o /tmp/wordpress.tar.gz $WP_TARBALL && tar -xzf /tmp/wordpress.tar.gz --strip-components=1 -C /var/www/html" || die "core download"
echo "WordPress $(wp core version)"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=db --skip-check --quiet || die "config"
wp config set WP_DEBUG true --raw --quiet
wp config set WP_DEBUG_LOG true --raw --quiet
wp config set WP_DEBUG_DISPLAY false --raw --quiet
wp config set AUTOMATIC_UPDATER_DISABLED true --raw --quiet
wp config set DISABLE_WP_CRON true --raw --quiet

for i in $(seq 1 30); do
	if [ "$INSTALL" = multisite ]; then
		wp core multisite-install --url=$LIVE --title=STSF --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet 2>/dev/null && break
	else
		wp core install --url=$LIVE --title=STSF --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet 2>/dev/null && break
	fi
	sleep 2
done
wp core is-installed --network 2>/dev/null || wp core is-installed || die "install"

HTACCESS=htaccess-single
[ "$INSTALL" = multisite ] && HTACCESS=htaccess-multisite
cp "$DIR/conf/$HTACCESS" "$WORK/htaccess" && docker cp "$WORK/htaccess" site:/var/www/html/.htaccess || die "htaccess"
docker cp "$WORK/$SLUG" site:/var/www/html/wp-content/plugins/ || die "copy plugin"
docker cp "$DIR/helpers/stsf.php" site:/tmp/stsf.php || die "copy helper"
docker exec site chown -R www-data:www-data /var/www/html/wp-content/plugins/$SLUG

if [ "$INSTALL" = multisite ]; then
	wp site create --slug=two --quiet || die "site two"
	wp plugin activate "$SLUG" --network --quiet || die "activate"

	expect "multisite: main site starts unconfirmed" "production unknown open off index" "$(hu $LIVE state)"
	hu $LIVE confirm >/dev/null
	expect "multisite: main site confirmed" "production address open off index" "$(hu $LIVE state)"
	expect "multisite: site two has its own settings" "production unknown open off index" "$(hu $LIVE/two state)"
	expect "multisite: setup notice still shown on site two" "setup" "$(hu $LIVE/two notices)"
	expect "multisite: site two settings page renders" "ok" "$(hu $LIVE/two admin-check)"
	hu $LIVE/two confirm >/dev/null
	wp --url=$LIVE/two eval 'update_option( "stsf_mail_log", array( array( "time" => time(), "to" => "a@b.c", "subject" => "s", "action" => "held" ) ) );'
else
	wp plugin activate "$SLUG" --quiet || die "activate"

	expect "fresh install: treated as production, nothing blocked" "production unknown open off index" "$(h state)"
	expect "fresh install: setup notice shown" "setup" "$(h notices)"
	expect "fresh install: badge marks it unconfirmed" "Production ? | stsf-env stsf-env-production" "$(h badge)"
	expect_has "fresh install: emails sent normally" "true none customer@example.com,other@example.com copies=2" "$(h mail)"
	expect_not "fresh install: no noindex header" "x-robots-tag" "$(headers $LIVE)"
	expect "settings page renders" "ok" "$(h admin-check)"

	expect "This is the live site saves the address" "$LIVE" "$(h confirm)"
	expect "live: confirmed production" "production address open off index" "$(h state)"
	expect "live: setup notice gone" "none" "$(h notices)"

	move_to $COPY
	expect "copy: database moved to another address is staging" "staging address guarded hold noindex" "$(h state)"
	expect "copy: badge shows Staging" "Staging | stsf-env stsf-env-staging" "$(h badge)"
	expect "copy: email held and logged" "true held - copies=0 subject=-" "$(h mail)"
	HDR=$(headers $COPY)
	expect_has "copy: X-Robots-Tag header" "X-Robots-Tag: noindex, nofollow" "$HDR"
	ROBOTS=$(body $COPY | grep -io "<meta name=.robots[^>]*>")
	expect_has "copy: robots meta noindex" "noindex" "$ROBOTS"
	expect_has "copy: robots meta nofollow" "nofollow" "$ROBOTS"
	expect "copy: core sitemap off" "0" "$(sitemap $COPY)"
	expect "copy: Settings > Reading untouched" "1" "$(wp option get blog_public)"

	h set mail_mode=reroute reroute_to=dev@example.com >/dev/null
	expect "copy: reroute mode" "staging address guarded reroute noindex" "$(h state)"
	expect "copy: rerouted to one address, Cc/Bcc removed" "true rerouted dev@example.com copies=0 subject=[Staging, to customer@example.com, other@example.com] Order 42" "$(h mail)"
	h set mail_mode=reroute reroute_to=not-an-email >/dev/null
	expect "copy: invalid reroute address falls back to hold" "staging address guarded hold noindex" "$(h state)"
	h set mail_mode=off >/dev/null
	expect_has "copy: mail off sends normally" "true none customer@example.com" "$(h mail)"
	h set mail_mode=hold noindex=0 >/dev/null
	expect_not "copy: noindex can be turned off" "x-robots-tag" "$(headers $COPY)"
	h set mail_mode=hold noindex=1 label="Client review" >/dev/null
	expect "copy: custom badge text" "Client review | stsf-env stsf-env-staging" "$(h badge)"
	expect "copy: settings page renders" "ok" "$(h admin-check)"

	move_to $LIVE
	expect "pushed back live: guards off" "production address open off index" "$(h state)"
	expect "live: custom badge text not used on production" "Production | stsf-env stsf-env-production" "$(h badge)"
	expect_not "live: no noindex header" "x-robots-tag" "$(headers $LIVE)"
	expect_has "live: emails sent normally" "true none customer@example.com" "$(h mail)"
	expect "live: core sitemap on" "1" "$(sitemap $LIVE)"

	wp option update blog_public 0 --quiet
	expect "live: hidden-from-search warning" "hidden" "$(h notices)"
	expect_has "live: badge turns to alert" "stsf-env-alert" "$(h badge)"
	wp user meta update 1 stsf_hidden_dismissed "$(date +%s)" --quiet
	expect "live: warning can be snoozed" "none" "$(h notices)"
	wp user meta delete 1 stsf_hidden_dismissed --quiet
	h set warn_production=0 >/dev/null
	expect "live: warning can be turned off" "none" "$(h notices)"
	h set warn_production=1 >/dev/null
	wp option update blog_public 1 --quiet

	move_to $LOCAL
	expect "local address with saved live address: local and guarded" "local address guarded hold noindex" "$(h state)"
	move_to $LIVE

	wp config set WP_ENVIRONMENT_TYPE staging --quiet
	expect "WP_ENVIRONMENT_TYPE wins over the address" "staging constant guarded hold noindex" "$(h state)"
	wp config set WP_ENVIRONMENT_TYPE production --quiet
	move_to $COPY
	expect "WP_ENVIRONMENT_TYPE=production on another address stays production" "production constant open off index" "$(h state)"
	move_to $LIVE
	wp config delete WP_ENVIRONMENT_TYPE --quiet

	move_to $COPY
	wp config set STAGING_SAFETY_DISABLE true --raw --quiet
	expect "STAGING_SAFETY_DISABLE turns guards off" "staging address open off index" "$(h state)"
	wp config delete STAGING_SAFETY_DISABLE --quiet
	move_to $LIVE

	wp option delete stsf_settings --quiet
	move_to $GUESS
	expect "staging-looking address without live address: guessed, not guarded" "staging guess open off index" "$(h state)"
	expect "guessed: setup notice shown" "setup" "$(h notices)"
	expect "guessed: badge has a question mark" "Staging ? | stsf-env stsf-env-staging" "$(h badge)"
	move_to $LOCAL
	expect "local address without live address: guarded" "local guess guarded hold noindex" "$(h state)"
	move_to $LIVE

	h confirm >/dev/null
	move_to $COPY
	x sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && printf "<?php\nfunction wp_mail() { return true; }\n" > /var/www/html/wp-content/mu-plugins/fake-mailer.php'
	expect "replaced wp_mail(): warning on settings page" "ok+" "$(h admin-check "replaced WordPress")"
	x rm /var/www/html/wp-content/mu-plugins/fake-mailer.php
	expect "normal wp_mail(): no warning" "ok" "$(h admin-check "replaced WordPress")"
	move_to $LIVE
fi

NOTICES=$(x sh -c "grep -h '$SLUG' /var/www/html/wp-content/debug.log 2>/dev/null" | sort -u | head -n 5)
expect "no PHP notices from the plugin" "" "$NOTICES"

wp user meta update 1 stsf_donate_dismissed 1 --quiet
wp user meta update 1 stsf_setup_dismissed 1 --quiet
if [ "$INSTALL" = multisite ]; then
	wp plugin deactivate "$SLUG" --network --quiet
else
	wp plugin deactivate "$SLUG" --quiet
fi
wp plugin uninstall "$SLUG" --quiet
if wp option get stsf_settings >/dev/null 2>&1; then fail "uninstall removes settings" "stsf_settings still there"; else pass "uninstall removes settings"; fi
if [ "$INSTALL" = multisite ]; then
	if wp --url=$LIVE/two option get stsf_settings >/dev/null 2>&1; then fail "uninstall removes settings on every site" "still on site two"; else pass "uninstall removes settings on every site"; fi
	if wp --url=$LIVE/two option get stsf_mail_log >/dev/null 2>&1; then fail "uninstall removes the email log on every site" "still on site two"; else pass "uninstall removes the email log on every site"; fi
fi
expect "uninstall removes user meta" "" "$(wp user meta get 1 stsf_donate_dismissed 2>/dev/null)$(wp user meta get 1 stsf_setup_dismissed 2>/dev/null)"

echo
echo "== $PASSES passed, $FAILS failed"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
	{
		echo "### PHP $PHP_VERSION, WordPress $WP_VERSION, $INSTALL: $PASSES passed, $FAILS failed"
		for r in "${RESULTS[@]}"; do
			case $r in FAIL*) echo "- :x: ${r#FAIL }" ;; esac
		done
	} >> "$GITHUB_STEP_SUMMARY"
fi
if [ "$FAILS" -gt 0 ]; then
	echo "== web server log (last 20 lines)"
	docker logs --tail 20 site 2>&1
	exit 1
fi
