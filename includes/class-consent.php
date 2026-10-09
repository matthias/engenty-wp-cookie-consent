<?php
/**
 * The consent log: two tables, the public route the dialog records a choice through, versions with
 * their snapshots, numbers, the CSV, pruning.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per decision: the consent ID from the visitor's cookie, time, choice, the version the person saw,
 * language, where it was made, the IP as a SHA-256 with a daily salt, the browser's family.
 */
class Cookie_Dialog_Consent {

	const DB_VERSION = 1;

	const SOURCES   = array( 'dialog', 'settings', 'placeholder', 'withdraw' );
	const DECISIONS = array( 'all', 'necessary', 'custom', 'service' );

	/** Hooks the public route and the daily and weekly jobs. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'cookie_dialog_daily', array( __CLASS__, 'daily' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * The consents table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cookie_dialog_consents';
	}

	/**
	 * The versions table.
	 *
	 * @return string
	 */
	public static function versions_table() {
		global $wpdb;
		return $wpdb->prefix . 'cookie_dialog_versions';
	}

	/** Creates or updates the tables. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset  = $wpdb->get_charset_collate();
		$consents = self::table();
		$versions = self::versions_table();
		dbDelta(
			"CREATE TABLE {$consents} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	consent_id varchar(36) NOT NULL DEFAULT '',
	created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	version int(10) unsigned NOT NULL DEFAULT 0,
	lang varchar(12) NOT NULL DEFAULT '',
	source varchar(20) NOT NULL DEFAULT 'dialog',
	decision varchar(20) NOT NULL DEFAULT 'custom',
	categories varchar(255) NOT NULL DEFAULT '',
	services text NOT NULL,
	ip_hash varchar(64) NOT NULL DEFAULT '',
	ua varchar(40) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	KEY consent_id (consent_id),
	KEY created (created)
) {$charset};
CREATE TABLE {$versions} (
	version int(10) unsigned NOT NULL DEFAULT 0,
	created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	note varchar(255) NOT NULL DEFAULT '',
	snapshot longtext NOT NULL,
	PRIMARY KEY  (version)
) {$charset};"
		);
		update_option( 'cookie_dialog_db', self::DB_VERSION, false );
	}

	/** Tables of an older release are brought up to date. */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'cookie_dialog_db', 0 ) < self::DB_VERSION ) {
			self::install();
			self::schedule();
		}
	}

	/** The daily prune and the weekly scan. */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'cookie_dialog_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'cookie_dialog_daily' );
		}
		if ( ! wp_next_scheduled( 'cookie_dialog_weekly' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 06:00' ), 'weekly', 'cookie_dialog_weekly' );
		}
	}

	/** Once a day: decisions older than the retention go. */
	public static function daily() {
		self::prune();
	}

	/**
	 * Deletes decisions older than the retention.
	 *
	 * @param int|null $months Retention; the configured one by default.
	 * @return int Rows deleted.
	 */
	public static function prune( $months = null ) {
		global $wpdb;
		$months = $months ?? (int) Cookie_Dialog_Config::get()['log']['months'];
		$before = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, (int) $months ) . ' months' ) );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created < %s", $before ) );
	}

	/**
	 * A new version of what visitors are told, with its snapshot. A version nobody decided on yet
	 * and younger than 15 minutes is updated in place, so typing a text does not make ten versions.
	 *
	 * @param array  $new  The configuration about to be saved.
	 * @param array  $old  The one before.
	 * @param string $note What changed.
	 * @return array The configuration with its version.
	 */
	public static function new_version( $new, $old, $note = '' ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.newFound -- the new configuration beside the old.
		global $wpdb;
		$table    = self::versions_table();
		$current  = (int) $old['version'];
		$snapshot = wp_json_encode( Cookie_Dialog_Config::snapshot( $new ) );
		$now      = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$row      = $current ? $wpdb->get_row( $wpdb->prepare( "SELECT version, created FROM {$table} WHERE version = %d", $current ) ) : null;
		$consents = self::table();
		$used     = $current ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$consents} WHERE version = %d", $current ) ) : 1;
		$reask    = ! empty( $new['validity']['reaskOnChange'] ) || ! $old['live'];
		if ( $row && 0 === $used && $old['live'] && strtotime( $row->created . ' UTC' ) > time() - 15 * MINUTE_IN_SECONDS ) {
			$wpdb->update(
				$table,
				array(
					'snapshot' => $snapshot,
					'note'     => substr( (string) $note, 0, 255 ),
				),
				array( 'version' => $current )
			);
			$new['version'] = $current;
			$new['reask']   = $reask ? $current : (int) $old['reask'];
			return $new;
		}
		$version = max( $current, (int) $wpdb->get_var( "SELECT MAX(version) FROM {$table}" ) ) + 1;
		$wpdb->insert(
			$table,
			array(
				'version'  => $version,
				'created'  => $now,
				'note'     => substr( (string) $note, 0, 255 ),
				'snapshot' => $snapshot,
			)
		);
		// phpcs:enable
		$new['version'] = $version;
		$new['reask']   = $reask ? $version : max( 1, (int) $old['reask'] );
		if ( $old['live'] ) {
			Cookie_Dialog_Config::remember(
				$reask
					/* translators: 1: version number, 2: what changed */
					? sprintf( __( 'Version %1$d: %2$s – Besucher werden einmal neu gefragt', 'cookie-dialog' ), $version, $note ? $note : __( 'Texte oder Dienste geändert', 'cookie-dialog' ) )
					/* translators: 1: version number, 2: what changed */
					: sprintf( __( 'Version %1$d: %2$s', 'cookie-dialog' ), $version, $note ? $note : __( 'Texte oder Dienste geändert', 'cookie-dialog' ) )
			);
		}
		return $new;
	}

	/**
	 * One version with its snapshot.
	 *
	 * @param int $version Version.
	 * @return array|null
	 */
	public static function version( $version ) {
		global $wpdb;
		$table = self::versions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE version = %d", $version ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['snapshot'] = json_decode( (string) $row['snapshot'], true );
		return $row;
	}

	/**
	 * All versions, newest first, without snapshots.
	 *
	 * @return array
	 */
	public static function versions() {
		global $wpdb;
		$table = self::versions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (array) $wpdb->get_results( "SELECT version, created, note FROM {$table} ORDER BY version DESC LIMIT 200", ARRAY_A );
	}

	/** The public route the dialog records a decision through: no nonce, so page caches keep working. */
	public static function routes() {
		register_rest_route(
			'cookie-dialog/v1',
			'/consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'record' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Records one decision.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function record( WP_REST_Request $request ) {
		$config = Cookie_Dialog_Config::get();
		if ( ! $config['live'] ) {
			return new WP_Error( 'cookie_dialog_off', 'The dialog is off.', array( 'status' => 409 ) );
		}
		$hash = self::ip_hash();
		$key  = 'cookie_dialog_rate_' . substr( $hash, 0, 24 );
		$hits = (int) get_transient( $key );
		if ( $hits >= 30 ) {
			return new WP_Error( 'cookie_dialog_rate', 'Too many requests.', array( 'status' => 429 ) );
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );

		$row = self::clean( (array) $request->get_json_params(), $config );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$row['ip_hash'] = $hash;
		$row['ua']      = self::ua_family( (string) $request->get_header( 'user_agent' ) );
		self::insert( $row );
		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * A decision as the table keeps it, checked against the configuration.
	 *
	 * @param array $data   What the dialog sent.
	 * @param array $config Configuration.
	 * @return array|WP_Error
	 */
	public static function clean( array $data, array $config ) {
		$id = strtolower( (string) ( $data['id'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id ) ) {
			return new WP_Error( 'cookie_dialog_id', 'A consent ID is a UUID.', array( 'status' => 400 ) );
		}
		$version = (int) ( $data['version'] ?? 0 );
		if ( $version < 1 || $version > (int) $config['version'] ) {
			return new WP_Error( 'cookie_dialog_version', 'Unknown version.', array( 'status' => 400 ) );
		}
		$cat_ids     = wp_list_pluck( $config['categories'], 'id' );
		$service_ids = wp_list_pluck( $config['services'], 'id' );
		$categories  = array_values( array_intersect( array_map( 'strval', (array) ( $data['categories'] ?? array() ) ), $cat_ids ) );
		$services    = array_values( array_intersect( array_map( 'strval', (array) ( $data['services'] ?? array() ) ), $service_ids ) );
		if ( ! in_array( 'necessary', $categories, true ) ) {
			array_unshift( $categories, 'necessary' );
		}
		$source   = Cookie_Dialog_Config::one_of( $data['source'] ?? '', self::SOURCES, 'dialog' );
		$decision = Cookie_Dialog_Config::one_of( $data['decision'] ?? '', self::DECISIONS, 'custom' );
		return array(
			'consent_id' => $id,
			'created'    => current_time( 'mysql', true ),
			'version'    => $version,
			'lang'       => Cookie_Dialog_Config::lang_code( $data['lang'] ?? '' ),
			'source'     => $source,
			'decision'   => $decision,
			'categories' => ',' . implode( ',', $categories ) . ',',
			'services'   => implode( ',', $services ),
		);
	}

	/**
	 * Writes a decision.
	 *
	 * @param array $row The row.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->insert(
			self::table(),
			array_merge(
				array(
					'ip_hash' => '',
					'ua'      => '',
				),
				$row
			)
		);
	}

	/**
	 * The visitor's IP as a SHA-256 with a salt that changes every day: the same visitor is the same
	 * hash on one day only, and the IP cannot be read back.
	 *
	 * @return string
	 */
	public static function ip_hash() {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$day  = gmdate( 'Y-m-d' );
		$salt = get_option( 'cookie_dialog_salt' );
		if ( ! is_array( $salt ) || ( $salt['day'] ?? '' ) !== $day ) {
			$salt = array(
				'day'  => $day,
				'salt' => wp_generate_password( 32, true, true ),
			);
			update_option( 'cookie_dialog_salt', $salt, false );
		}
		return hash( 'sha256', $ip . '|' . $salt['salt'] );
	}

	/**
	 * The browser's family and whether it is a phone: „Chrome · mobil“.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function ua_family( $ua ) {
		$family = 'Other';
		foreach ( array(
			'bot|crawl|spider|slurp|headless' => 'Bot',
			'edg/'                            => 'Edge',
			'opr/|opera'                      => 'Opera',
			'samsungbrowser'                  => 'Samsung',
			'firefox|fxios'                   => 'Firefox',
			'chrome|crios'                    => 'Chrome',
			'safari'                          => 'Safari',
		) as $pattern => $name ) {
			if ( preg_match( '#' . $pattern . '#i', $ua ) ) {
				$family = $name;
				break;
			}
		}
		return $family . ( preg_match( '/mobile|android|iphone/i', $ua ) ? ' · mobil' : '' );
	}

	/**
	 * Whether the visitor of this request agreed to a category or a service, from the cookie.
	 *
	 * @param string $key Category or service ID.
	 * @return bool
	 */
	public static function visitor_has( $key ) {
		$choice = self::cookie();
		if ( ! $choice ) {
			return 'necessary' === $key;
		}
		return in_array( $key, $choice['c'], true ) || in_array( $key, $choice['s'], true );
	}

	/**
	 * The visitor's cookie, when it is valid for the current version.
	 *
	 * @return array|null `id`, `v`, `t`, `c`, `s`.
	 */
	public static function cookie() {
		if ( empty( $_COOKIE[ COOKIE_DIALOG_COOKIE ] ) ) {
			return null;
		}
		$data = json_decode( sanitize_text_field( wp_unslash( $_COOKIE[ COOKIE_DIALOG_COOKIE ] ) ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$config = Cookie_Dialog_Config::get();
		if ( (int) ( $data['v'] ?? 0 ) < (int) $config['reask'] || (int) ( $data['t'] ?? 0 ) < time() - (int) $config['validity']['months'] * MONTH_IN_SECONDS ) {
			return null;
		}
		return array(
			'id' => (string) ( $data['id'] ?? '' ),
			'v'  => (int) ( $data['v'] ?? 0 ),
			't'  => (int) ( $data['t'] ?? 0 ),
			'c'  => array_map( 'strval', (array) ( $data['c'] ?? array() ) ),
			's'  => array_map( 'strval', (array) ( $data['s'] ?? array() ) ),
		);
	}

	/**
	 * The WHERE clause and its values for a range, a source and a consent ID.
	 *
	 * @param array $args `range` (30d, 12m, all), `source`, `q`.
	 * @return array [ sql, values ]
	 */
	private static function where( array $args ) {
		$where  = array( '1=1' );
		$values = array();
		$range  = $args['range'] ?? '30d';
		if ( '30d' === $range ) {
			$where[]  = 'created >= %s';
			$values[] = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		} elseif ( '12m' === $range ) {
			$where[]  = 'created >= %s';
			$values[] = gmdate( 'Y-m-d H:i:s', strtotime( '-12 months' ) );
		}
		if ( ! empty( $args['source'] ) && in_array( $args['source'], self::SOURCES, true ) ) {
			$where[]  = 'source = %s';
			$values[] = $args['source'];
		}
		if ( ! empty( $args['q'] ) ) {
			global $wpdb;
			$where[]  = 'consent_id LIKE %s';
			$values[] = $wpdb->esc_like( strtolower( trim( (string) $args['q'] ) ) ) . '%';
		}
		return array( implode( ' AND ', $where ), $values );
	}

	/**
	 * A page of decisions, newest first.
	 *
	 * @param array $args `range`, `source`, `q`, `page`, `per`.
	 * @return array `rows`, `total`.
	 */
	public static function rows( array $args ) {
		global $wpdb;
		$table            = self::table();
		[ $where, $vals ] = self::where( $args );
		$per              = max( 1, min( 200, (int) ( $args['per'] ?? 50 ) ) );
		$page             = max( 1, (int) ( $args['page'] ?? 1 ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- own table, built from fixed parts.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the WHERE comes from self::where() with its placeholders.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) $wpdb->get_var( $vals ? $wpdb->prepare( $count_sql, $vals ) : $count_sql );
		$rows_sql  = "SELECT consent_id, created, version, lang, source, decision, categories, services, ua FROM {$table} WHERE {$where} ORDER BY created DESC, id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * A row for the admin.
	 *
	 * @param array $row The row.
	 * @return array
	 */
	private static function shape( $row ) {
		return array(
			'id'         => $row['consent_id'],
			'created'    => gmdate( 'c', (int) strtotime( $row['created'] . ' UTC' ) ),
			'version'    => (int) $row['version'],
			'lang'       => $row['lang'],
			'source'     => $row['source'],
			'decision'   => $row['decision'],
			'categories' => array_values( array_filter( explode( ',', (string) $row['categories'] ) ) ),
			'services'   => array_values( array_filter( explode( ',', (string) $row['services'] ) ) ),
			'ua'         => $row['ua'],
		);
	}

	/**
	 * The numbers of a range: decisions, withdrawals, the split of all / own choice / necessary only,
	 * the acceptance per category, decisions per day.
	 *
	 * @param array $args `range`.
	 * @return array
	 */
	public static function stats( array $args = array() ) {
		global $wpdb;
		$table            = self::table();
		[ $where, $vals ] = self::where( array( 'range' => $args['range'] ?? '30d' ) );
		$q                = static fn( $sql ) => $vals ? $wpdb->prepare( $sql, $vals ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table, built from fixed parts.
		$by_decision = $wpdb->get_results( $q( "SELECT decision, source, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY decision, source" ), ARRAY_A );
		$total       = 0;
		$withdrawals = 0;
		$split       = array(
			'all'       => 0,
			'custom'    => 0,
			'necessary' => 0,
		);
		foreach ( (array) $by_decision as $r ) {
			$n      = (int) $r['n'];
			$total += $n;
			if ( 'withdraw' === $r['source'] ) {
				$withdrawals += $n;
			}
			if ( 'service' === $r['decision'] ) {
				$split['custom'] += $n;
			} elseif ( isset( $split[ $r['decision'] ] ) ) {
				$split[ $r['decision'] ] += $n;
			}
		}
		$categories = array();
		foreach ( Cookie_Dialog_Config::get()['categories'] as $cat ) {
			if ( 'necessary' === $cat['id'] ) {
				continue;
			}
			$like                     = '%,' . $wpdb->esc_like( $cat['id'] ) . ',%';
			$n                        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where} AND categories LIKE %s", array_merge( $vals, array( $like ) ) ) );
			$categories[ $cat['id'] ] = $total ? (int) round( 100 * $n / $total ) : 0;
		}
		$days  = $wpdb->get_results( $q( "SELECT SUBSTR(created, 1, 10) AS day, decision, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY day, decision ORDER BY day" ), ARRAY_A );
		$langs = $wpdb->get_results( $q( "SELECT lang, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY lang ORDER BY n DESC" ), ARRAY_A );
		// phpcs:enable
		$per_day = array();
		foreach ( (array) $days as $r ) {
			$per_day[ $r['day'] ][ $r['decision'] ] = (int) $r['n'];
		}
		return array(
			'total'       => $total,
			'withdrawals' => $withdrawals,
			'split'       => $split,
			'percent'     => array_map( static fn( $n ) => $total ? (int) round( 100 * $n / $total ) : 0, $split ),
			'categories'  => $categories,
			'days'        => $per_day,
			'languages'   => array_map( static fn( $r ) => array( $r['lang'], (int) $r['n'] ), (array) $langs ),
		);
	}

	/**
	 * Streams the decisions of a range as CSV.
	 *
	 * @param array $args `range`, `source`, `q`.
	 */
	public static function csv( array $args ) {
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'consent_id', 'time_utc', 'version', 'language', 'source', 'decision', 'categories', 'services', 'browser' ), ',', '"', '\\' );
		$page = 1;
		do {
			$batch = self::rows(
				array_merge(
					$args,
					array(
						'page' => $page,
						'per'  => 200,
					)
				)
			);
			foreach ( $batch['rows'] as $row ) {
				fputcsv(
					$out,
					array(
						$row['id'],
						gmdate( 'Y-m-d H:i:s', strtotime( $row['created'] ) ),
						$row['version'],
						$row['lang'],
						$row['source'],
						$row['decision'],
						implode( ' ', $row['categories'] ),
						implode( ' ', $row['services'] ),
						$row['ua'],
					),
					',',
					'"',
					'\\'
				);
			}
			++$page;
			$more = count( $batch['rows'] ) === 200;
		} while ( $more );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
