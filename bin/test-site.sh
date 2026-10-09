#!/bin/sh
# A WordPress with SQLite, the plugin and a demo site that loads what real sites load from third
# parties (Google Fonts, GA4, Meta Pixel, YouTube, Maps, Instagram, Gravatar):
#   WP_PATH=/tmp/wp WP_URL=http://127.0.0.1:8920 sh bin/test-site.sh
# Then: wp --path=$WP_PATH server --host=127.0.0.1 --port=8920
set -eu
WP_PATH=${WP_PATH:?WP_PATH is the folder for WordPress}
WP_URL=${WP_URL:-http://127.0.0.1:8920}
PLUGIN=$(cd "$(dirname "$0")/.." && pwd)
WP="wp --path=$WP_PATH"
mkdir -p "$WP_PATH"
[ -f "$WP_PATH/wp-load.php" ] || $WP core download --quiet
if [ ! -d "$WP_PATH/wp-content/plugins/sqlite-database-integration" ]; then
	curl -fsSL -o /tmp/sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
	unzip -q /tmp/sqlite.zip -d "$WP_PATH/wp-content/plugins" && rm /tmp/sqlite.zip
fi
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP_PATH/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$WP_PATH/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP_PATH/wp-content/db.php"
[ -f "$WP_PATH/wp-config.php" ] || $WP config create --dbname=wp --dbuser=x --dbpass=x --skip-check --quiet
$WP core is-installed 2>/dev/null || $WP core install --url="$WP_URL" --title="Atelier Grün" --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email --quiet
$WP language core install de_DE --activate --quiet || true
$WP rewrite structure '/%postname%/' --quiet
ln -sfn "$PLUGIN" "$WP_PATH/wp-content/plugins/cookie-dialog"
mkdir -p "$WP_PATH/wp-content/mu-plugins"
cp "$PLUGIN/tests/e2e/fixtures/demo.php" "$WP_PATH/wp-content/mu-plugins/atelier-demo.php"
$WP plugin activate cookie-dialog --quiet
if ! $WP post list --post_type=page --name=leistungen --field=ID | grep -q .; then
	FRONT=$($WP post create --post_type=page --post_status=publish --post_title="Start" --post_content='<!-- wp:paragraph --><p>Gärten, die bleiben. Wir planen und bauen Gärten in Wien und Umgebung.</p><!-- /wp:paragraph -->' --porcelain)
	$WP post create --post_type=page --post_status=publish --post_title="Leistungen" --post_name=leistungen --post_content='<!-- wp:html --><iframe width="560" height="315" src="https://www.youtube.com/embed/aqz-KE-bpKQ" title="YouTube" allowfullscreen></iframe><!-- /wp:html -->' --quiet
	$WP post create --post_type=page --post_status=publish --post_title="Kontakt" --post_name=kontakt --post_content='<!-- wp:html --><iframe src="https://www.google.com/maps/embed/v1/place?key=TEST&q=Gr%C3%BCngasse+12,+1050+Wien" width="600" height="450"></iframe><!-- /wp:html --><!-- wp:shortcode -->[cookie_settings]<!-- /wp:shortcode -->' --quiet
	$WP post create --post_type=page --post_status=publish --post_title="Impressum" --post_name=impressum --post_content='<!-- wp:paragraph --><p>Atelier Grün GmbH</p><!-- /wp:paragraph -->' --quiet
	PRIV=$($WP option get wp_page_for_privacy_policy)
	$WP post update "$PRIV" --post_status=publish --post_title="Datenschutzerklärung" --post_name=datenschutz --quiet
	$WP option update show_on_front page --quiet
	$WP option update page_on_front "$FRONT" --quiet
fi
echo "ready: $WP_URL"
