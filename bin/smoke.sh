#!/bin/sh
# Checks the plugin inside a real WordPress (bin/test-site.sh makes one):
#   WP_PATH=/path/to/wordpress sh bin/smoke.sh
set -eu
WP="wp --path=${WP_PATH:?WP_PATH is the WordPress folder}"
fail() { echo "FAIL: $1"; exit 1; }

$WP plugin is-active cookie-dialog || fail "the plugin is not active"

routes=$($WP eval 'echo implode(" ", array_keys(rest_get_server()->get_routes("cookie-dialog/v1")));')
for r in consent state config live scan fix service local log assist job undo; do
	echo "$routes" | grep -q "/cookie-dialog/v1/$r" || fail "REST route $r is missing"
done

for b in settings-button cookie-table placeholder; do
	$WP eval "echo WP_Block_Type_Registry::get_instance()->is_registered('cookie-dialog/$b') ? 'ok' : 'missing';" | grep -q ok || fail "block $b is not registered"
done

$WP eval 'echo count(Cookie_Dialog_Config::library());' | grep -qE '^[5-9][0-9]$' || fail "the presets library is not complete"

held=$($WP eval 'Cookie_Dialog_Config::save(Cookie_Dialog_Config::merge(Cookie_Dialog_Config::get(), array("services" => array(Cookie_Dialog_Config::service_from_preset(Cookie_Dialog_Config::preset("ga4")))))); echo Cookie_Dialog_Blocker::process("<script async src=\"https://www.googletagmanager.com/gtag/js?id=G-1\"></script>");')
echo "$held" | grep -q 'type="text/plain" data-cookie-service="ga4"' || fail "GA4 is not held back"

$WP cookie-dialog scan --propose >/dev/null || fail "the scan failed"
$WP cookie-dialog check --format=json | grep -q '"id":"fair"' || fail "the checks did not run"
$WP cookie-dialog live on | grep -q "On, version" || fail "the dialog does not switch on"

home=$($WP eval 'echo home_url("/");')
page=$(curl -fsS "$home")
echo "$page" | grep -q 'gtag("consent","default"' || fail "no Consent Mode default on the site"
echo "$page" | grep -q 'id="cookie-dialog-config"' || fail "no dialog config on the site"
echo "$page" | grep -q 'type="text/plain" data-cookie-service="ga4"' || fail "GA4 loads on the site before consent"

table=$($WP eval 'echo do_shortcode("[cookie_table]");')
echo "$table" | grep -q 'Google Analytics 4' || fail "the cookie table does not list GA4"

$WP cookie-dialog export | grep -q '"plugin": "cookie-dialog"' || fail "export failed"
echo "ok"
