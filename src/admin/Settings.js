import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import { boot, pageUrl, post, problem } from './api';
import { useBrowserModel } from './Assistant';
import {
	Accordion,
	Button,
	Card,
	Chip,
	languageName,
	months,
	Line,
	Notice,
	Seg,
	Switch,
	when,
} from './ui';

/** What the plugin recommends: a topic that differs shows „angepasst“. */
const RECOMMENDED = {
	google: {
		consentMode: true,
		mode: 'basic',
		regions: [ 'EEA', 'UK', 'CH' ],
		everywhere: false,
		urlPassthrough: false,
		adsRedaction: true,
	},
	thirdParty: {
		unknownIframes: true,
		thumbnails: true,
		always: true,
		nocookie: true,
		gravatar: 'proxy',
		emoji: false,
		hints: true,
	},
	validity: { months: 6, reaskOnChange: true, gpc: true },
	reopen: { button: true },
	pages: { small: true },
	log: { months: 12 },
	assistant: { browser: true, model: 'cheap' },
	scan: { weekly: true },
};

function same( value, rec ) {
	return Object.entries( rec ).every(
		( [ k, v ] ) => JSON.stringify( value?.[ k ] ) === JSON.stringify( v )
	);
}

function CopyLine( { value } ) {
	const [ copied, setCopied ] = useState( false );
	return (
		<span className="cd-copy">
			<code>{ value }</code>
			<button
				type="button"
				className="cd-link-btn"
				onClick={ () => {
					window.navigator.clipboard?.writeText( value );
					setCopied( true );
					window.setTimeout( () => setCopied( false ), 1500 );
				} }
			>
				{ copied
					? __( 'Kopiert', 'cookie-dialog' )
					: __( 'Kopieren', 'cookie-dialog' ) }
			</button>
		</span>
	);
}

function PagePicker( { value, pages, onChange, label } ) {
	return (
		<select
			className="cd-select"
			value={ value || 0 }
			aria-label={ label }
			onChange={ ( e ) => onChange( Number( e.target.value ) ) }
		>
			<option value={ 0 }>{ __( '– keine –', 'cookie-dialog' ) }</option>
			{ pages.map( ( p ) => (
				<option key={ p.id } value={ p.id }>
					{ p.title.rendered || `#${ p.id }` }
				</option>
			) ) }
		</select>
	);
}

export default function Settings( { state, onState, save } ) {
	const c = state.config;
	const a = state.assistant;
	const browser = useBrowserModel();
	const initial = window.location.hash === '#pages' ? 'pages' : 'google';
	const [ open, setOpen ] = useState( initial );
	const [ pages, setPages ] = useState( [] );
	const [ confirm, setConfirm ] = useState( false );
	const [ busy, setBusy ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ gtm, setGtm ] = useState( c.google.gtm );

	useEffect( () => setGtm( c.google.gtm ), [ c.google.gtm ] );
	useEffect( () => {
		apiFetch( {
			path: '/wp/v2/pages?per_page=100&_fields=id,title&orderby=title&order=asc&status=publish',
		} )
			.then( setPages )
			.catch( () => setPages( [] ) );
	}, [] );

	const toggle = ( id ) => setOpen( open === id ? null : id );
	const g = c.google;
	const t = c.thirdParty;
	const regions = g.everywhere
		? __( 'überall', 'cookie-dialog' )
		: g.regions
				.map(
					( r ) =>
						( {
							EEA: __( 'EWR', 'cookie-dialog' ),
							UK: 'UK',
							CH: __( 'Schweiz', 'cookie-dialog' ),
						} )[ r ]
				)
				.join( ', ' );
	const gravatar = {
		proxy: __( 'Gravatar über den Server', 'cookie-dialog' ),
		off: __( 'Avatare aus', 'cookie-dialog' ),
		allow: __( 'Gravatar direkt', 'cookie-dialog' ),
	}[ t.gravatar ];
	const live = async ( on ) => {
		setBusy( 'live' );
		setError( null );
		try {
			onState( await post( '/live', { on } ) );
			setConfirm( false );
		} catch ( e ) {
			setError( problem( e ).message );
		} finally {
			setBusy( null );
		}
	};

	return (
		<>
			<Card pad0>
				<Accordion
					title={ __( 'Google', 'cookie-dialog' ) }
					summary={ [
						g.consentMode
							? __( 'Consent Mode v2', 'cookie-dialog' )
							: __( 'Consent Mode aus', 'cookie-dialog' ),
						g.mode === 'basic' ? 'Basic' : 'Advanced',
						g.gtm,
						regions,
					]
						.filter( Boolean )
						.join( ' · ' ) }
					recommended={ same( g, RECOMMENDED.google ) }
					open={ open === 'google' }
					onToggle={ () => toggle( 'google' ) }
				>
					<Line
						label={ __( 'Consent Mode v2', 'cookie-dialog' ) }
						hint={ __(
							'„Abgelehnt“ steht vor jedem Google-Tag; die Wahl geht als Update hinterher.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ g.consentMode }
							label={ __( 'Consent Mode v2', 'cookie-dialog' ) }
							onChange={ ( consentMode ) =>
								save( { google: { consentMode } } )
							}
						/>
					</Line>
					<Line
						label={ __( 'Modus', 'cookie-dialog' ) }
						hint={ __(
							'Basic: Tags laden erst nach Einwilligung – empfohlen in DE und AT. Advanced: Tags laden und senden cookielose Pings.',
							'cookie-dialog'
						) }
					>
						<Seg
							value={ g.mode }
							onChange={ ( mode ) =>
								save( { google: { mode } } )
							}
							options={ [
								{ value: 'basic', label: 'Basic' },
								{ value: 'advanced', label: 'Advanced' },
							] }
						/>
					</Line>
					<Line
						label={ __( 'Tag Manager', 'cookie-dialog' ) }
						hint={ __(
							'Vom Plugin geladen, nach dem Standard. Eigener Container im Theme? Leer lassen.',
							'cookie-dialog'
						) }
					>
						<input
							type="text"
							className="cd-input-s"
							value={ gtm }
							placeholder="GTM-XXXXXXX"
							onChange={ ( e ) =>
								setGtm( e.target.value.toUpperCase() )
							}
							onBlur={ () =>
								gtm !== g.gtm && save( { google: { gtm } } )
							}
						/>
					</Line>
					<Line
						label={ __(
							'Regionen mit „abgelehnt“',
							'cookie-dialog'
						) }
						hint={ __(
							'Außerhalb darf Google ohne Wahl messen – der Dialog hält Tags trotzdem zurück.',
							'cookie-dialog'
						) }
					>
						<span className="cd-row-inline">
							{ [
								[ 'EEA', __( 'EWR', 'cookie-dialog' ) ],
								[ 'UK', 'UK' ],
								[ 'CH', __( 'Schweiz', 'cookie-dialog' ) ],
							].map( ( [ id, label ] ) => {
								const on =
									g.everywhere || g.regions.includes( id );
								return (
									<button
										key={ id }
										type="button"
										className={ `cd-sig${
											on ? ' is-on' : ''
										}` }
										aria-pressed={ on ? 'true' : 'false' }
										disabled={ g.everywhere }
										onClick={ () =>
											save( {
												google: {
													regions: on
														? g.regions.filter(
																( r ) =>
																	r !== id
														  )
														: [ ...g.regions, id ],
												},
											} )
										}
									>
										{ label }
									</button>
								);
							} ) }
							<button
								type="button"
								className={ `cd-sig${
									g.everywhere ? ' is-on' : ''
								}` }
								aria-pressed={ g.everywhere ? 'true' : 'false' }
								onClick={ () =>
									save( {
										google: { everywhere: ! g.everywhere },
									} )
								}
							>
								{ __( 'Überall', 'cookie-dialog' ) }
							</button>
						</span>
					</Line>
					<Line
						label={ __( 'URL-Passthrough', 'cookie-dialog' ) }
						hint={ __(
							'Klick-IDs in Links weitergeben, solange Cookies abgelehnt sind.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ g.urlPassthrough }
							label={ __( 'URL-Passthrough', 'cookie-dialog' ) }
							onChange={ ( urlPassthrough ) =>
								save( { google: { urlPassthrough } } )
							}
						/>
					</Line>
					<Line
						label={ __( 'Ads-Daten kürzen', 'cookie-dialog' ) }
						hint="ads_data_redaction"
					>
						<Switch
							checked={ g.adsRedaction }
							label={ __( 'Ads-Daten kürzen', 'cookie-dialog' ) }
							onChange={ ( adsRedaction ) =>
								save( { google: { adsRedaction } } )
							}
						/>
					</Line>
					<p className="cd-fine">
						{ __(
							'IAB TCF 2.2 braucht nur, wer AdSense, Ad Manager oder AdMob nutzt.',
							'cookie-dialog'
						) }
					</p>
				</Accordion>

				<Accordion
					title={ __( 'Von Dritten', 'cookie-dialog' ) }
					summary={ [
						t.unknownIframes
							? __(
									'Einbettungen als Platzhalter',
									'cookie-dialog'
							  )
							: __(
									'nur bekannte Einbettungen',
									'cookie-dialog'
							  ),
						state.local.length
							? __( 'Schriften lokal', 'cookie-dialog' )
							: null,
						gravatar,
						t.emoji ? null : __( 'Emoji aus', 'cookie-dialog' ),
					]
						.filter( Boolean )
						.join( ' · ' ) }
					recommended={ same( t, RECOMMENDED.thirdParty ) }
					open={ open === 'third' }
					onToggle={ () => toggle( 'third' ) }
				>
					<Line
						label={ __(
							'Unbekannte iframes als Platzhalter',
							'cookie-dialog'
						) }
						hint={ __(
							'Auch Einbettungen, die die Bibliothek nicht kennt, laden erst auf Klick.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ t.unknownIframes }
							label={ __(
								'Unbekannte iframes als Platzhalter',
								'cookie-dialog'
							) }
							onChange={ ( unknownIframes ) =>
								save( { thirdParty: { unknownIframes } } )
							}
						/>
					</Line>
					<Line
						label={ __(
							'Vorschaubilder über deinen Server',
							'cookie-dialog'
						) }
						hint={ __(
							'YouTube, Vimeo, Spotify: Bild und Titel in der Mediathek, keine Verbindung zum Anbieter.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ t.thumbnails }
							label={ __(
								'Vorschaubilder über deinen Server',
								'cookie-dialog'
							) }
							onChange={ ( thumbnails ) =>
								save( { thirdParty: { thumbnails } } )
							}
						/>
					</Line>
					<Line
						label={ __(
							'„Immer laden“ anbieten',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ t.always }
							label={ __(
								'„Immer laden“ anbieten',
								'cookie-dialog'
							) }
							onChange={ ( always ) =>
								save( { thirdParty: { always } } )
							}
						/>
					</Line>
					<Line
						label={ __(
							'YouTube über youtube-nocookie.com',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ t.nocookie }
							label={ __(
								'YouTube über youtube-nocookie.com',
								'cookie-dialog'
							) }
							onChange={ ( nocookie ) =>
								save( { thirdParty: { nocookie } } )
							}
						/>
					</Line>
					<Line label={ __( 'Avatare', 'cookie-dialog' ) }>
						<Seg
							value={ t.gravatar }
							onChange={ ( v ) =>
								save( { thirdParty: { gravatar: v } } )
							}
							options={ [
								{
									value: 'proxy',
									label: __(
										'Über den Server',
										'cookie-dialog'
									),
								},
								{
									value: 'off',
									label: __( 'Aus', 'cookie-dialog' ),
								},
								{
									value: 'allow',
									label: __( 'Direkt', 'cookie-dialog' ),
								},
							] }
						/>
					</Line>
					<Line
						label={ __(
							'WordPress-Emoji-Skript laden',
							'cookie-dialog'
						) }
						hint={ __(
							'Lädt Bilder von s.w.org. Browser zeigen Emoji längst selbst.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ t.emoji }
							label={ __(
								'WordPress-Emoji-Skript laden',
								'cookie-dialog'
							) }
							onChange={ ( emoji ) =>
								save( { thirdParty: { emoji } } )
							}
						/>
					</Line>
					<Line
						label={ __(
							'Hinweise an Dritte entfernen',
							'cookie-dialog'
						) }
						hint="preconnect, dns-prefetch"
					>
						<Switch
							checked={ t.hints }
							label={ __(
								'Hinweise an Dritte entfernen',
								'cookie-dialog'
							) }
							onChange={ ( hints ) =>
								save( { thirdParty: { hints } } )
							}
						/>
					</Line>
					<a href={ pageUrl( 'services' ) }>
						{ __(
							'Was von deinem Server kommt, steht unter Dienste',
							'cookie-dialog'
						) }{ ' ' }
						›
					</a>
				</Accordion>

				<Accordion
					title={ __( 'Gültigkeit', 'cookie-dialog' ) }
					summary={ [
						months( c.validity.months ),
						c.validity.reaskOnChange
							? __(
									'neu fragen, wenn Dienste sich ändern',
									'cookie-dialog'
							  )
							: null,
						c.validity.gpc
							? __(
									'Global Privacy Control beachtet',
									'cookie-dialog'
							  )
							: null,
					]
						.filter( Boolean )
						.join( ' · ' ) }
					recommended={ same( c.validity, RECOMMENDED.validity ) }
					open={ open === 'validity' }
					onToggle={ () => toggle( 'validity' ) }
				>
					<Line
						label={ __( 'Wahl gilt', 'cookie-dialog' ) }
						hint={ __(
							'6 Monate empfiehlt die CNIL, bis 12 die DSK.',
							'cookie-dialog'
						) }
					>
						<select
							className="cd-select"
							value={ c.validity.months }
							onChange={ ( e ) =>
								save( {
									validity: {
										months: Number( e.target.value ),
									},
								} )
							}
						>
							{ [ 1, 3, 6, 9, 12 ].map( ( m ) => (
								<option key={ m } value={ m }>
									{ months( m ) }
								</option>
							) ) }
						</select>
					</Line>
					<Line
						label={ __(
							'Neu fragen, wenn sich Texte oder Dienste ändern',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ c.validity.reaskOnChange }
							label={ __( 'Neu fragen', 'cookie-dialog' ) }
							onChange={ ( reaskOnChange ) =>
								save( { validity: { reaskOnChange } } )
							}
						/>
					</Line>
					<Line
						label={ __(
							'Global Privacy Control beachten',
							'cookie-dialog'
						) }
						hint={ __(
							'Sendet der Browser das Signal, bleibt Marketing bei „Alle akzeptieren“ aus.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ c.validity.gpc }
							label={ __(
								'Global Privacy Control beachten',
								'cookie-dialog'
							) }
							onChange={ ( gpc ) =>
								save( { validity: { gpc } } )
							}
						/>
					</Line>
				</Accordion>

				<Accordion
					title={ __( 'Erneut öffnen', 'cookie-dialog' ) }
					summary={ [
						c.reopen.button
							? c.reopen.position === 'left'
								? __( 'Button unten links', 'cookie-dialog' )
								: __( 'Button unten rechts', 'cookie-dialog' )
							: __( 'kein Button', 'cookie-dialog' ),
						__(
							'Shortcode, Block und #cookie-settings',
							'cookie-dialog'
						),
					].join( ' · ' ) }
					recommended={ same( c.reopen, RECOMMENDED.reopen ) }
					open={ open === 'reopen' }
					onToggle={ () => toggle( 'reopen' ) }
				>
					<Line
						label={ __( 'Button zum Öffnen', 'cookie-dialog' ) }
						hint={ __(
							'Erscheint nach der ersten Wahl. Widerrufen muss so leicht sein wie Zustimmen.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ c.reopen.button }
							label={ __( 'Button zum Öffnen', 'cookie-dialog' ) }
							onChange={ ( button ) =>
								save( { reopen: { button } } )
							}
						/>
					</Line>
					<Line label={ __( 'Position', 'cookie-dialog' ) }>
						<Seg
							value={ c.reopen.position }
							onChange={ ( position ) =>
								save( { reopen: { position } } )
							}
							options={ [
								{
									value: 'left',
									label: _x(
										'Links',
										'position',
										'cookie-dialog'
									),
								},
								{
									value: 'right',
									label: __( 'Rechts', 'cookie-dialog' ),
								},
							] }
						/>
					</Line>
					<div className="cd-col">
						<span className="cd-fine">
							{ __(
								'Oder selbst platzieren – im Footer, im Menü, in der Datenschutzerklärung:',
								'cookie-dialog'
							) }
						</span>
						<CopyLine value="[cookie_settings]" />
						<CopyLine value='<a href="#cookie-settings">Cookie-Einstellungen</a>' />
						<span className="cd-fine">
							{ __(
								'Block: „Cookie-Einstellungen“. Im Menü: ein eigener Link auf #cookie-settings.',
								'cookie-dialog'
							) }
						</span>
					</div>
				</Accordion>

				<Accordion
					title={ __( 'Seiten', 'cookie-dialog' ) }
					summary={ [
						state.pages.privacy?.title ||
							__( 'keine Datenschutzerklärung', 'cookie-dialog' ),
						state.pages.imprint?.title ||
							__( 'kein Impressum', 'cookie-dialog' ),
						c.pages.small
							? __( 'dort nur klein', 'cookie-dialog' )
							: null,
					]
						.filter( Boolean )
						.join( ' · ' ) }
					recommended={
						same( c.pages, RECOMMENDED.pages ) &&
						Boolean( c.pages.privacy && c.pages.imprint )
					}
					open={ open === 'pages' }
					onToggle={ () => toggle( 'pages' ) }
				>
					<Line
						label={ __( 'Datenschutzerklärung', 'cookie-dialog' ) }
					>
						<PagePicker
							label={ __(
								'Datenschutzerklärung',
								'cookie-dialog'
							) }
							value={ c.pages.privacy }
							pages={ pages }
							onChange={ ( privacy ) =>
								save( { pages: { privacy } } )
							}
						/>
					</Line>
					<Line label={ __( 'Impressum', 'cookie-dialog' ) }>
						<PagePicker
							label={ __( 'Impressum', 'cookie-dialog' ) }
							value={ c.pages.imprint }
							pages={ pages }
							onChange={ ( imprint ) =>
								save( { pages: { imprint } } )
							}
						/>
					</Line>
					<Line
						label={ __( 'Dort nur klein zeigen', 'cookie-dialog' ) }
						hint={ __(
							'Damit man lesen kann, bevor man entscheidet.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ c.pages.small }
							label={ __(
								'Dort nur klein zeigen',
								'cookie-dialog'
							) }
							onChange={ ( small ) =>
								save( { pages: { small } } )
							}
						/>
					</Line>
					{ state.pages.privacy ? (
						<span className="cd-fine">
							{ __(
								'Die Cookie-Tabelle für die Datenschutzerklärung: Block „Cookie-Tabelle“ oder',
								'cookie-dialog'
							) }{ ' ' }
							<code>[cookie_table]</code>
						</span>
					) : null }
				</Accordion>

				<Accordion
					title={ __( 'Protokoll', 'cookie-dialog' ) }
					summary={ sprintf(
						/* translators: %d: months */
						_n(
							'%d Monat · IP gehasht · CSV',
							'%d Monate · IP gehasht · CSV',
							c.log.months,
							'cookie-dialog'
						),
						c.log.months
					) }
					recommended={ same( c.log, RECOMMENDED.log ) }
					open={ open === 'log' }
					onToggle={ () => toggle( 'log' ) }
				>
					<Line
						label={ __( 'Aufbewahren', 'cookie-dialog' ) }
						hint={ __(
							'Danach löscht ein täglicher Job die Einträge.',
							'cookie-dialog'
						) }
					>
						<select
							className="cd-select"
							value={ c.log.months }
							onChange={ ( e ) =>
								save( {
									log: { months: Number( e.target.value ) },
								} )
							}
						>
							{ [ 6, 12, 24, 36 ].map( ( m ) => (
								<option key={ m } value={ m }>
									{ months( m ) }
								</option>
							) ) }
						</select>
					</Line>
					<p className="cd-fine">
						{ __(
							'Gespeichert werden Consent-ID, Zeit, Wahl, Version, Sprache, Ort der Entscheidung, Browser-Familie und die IP-Adresse als SHA-256 mit täglich neuem Salz – nie die IP selbst.',
							'cookie-dialog'
						) }
					</p>
					<Button
						size="sm"
						icon="download"
						href={ `${ boot.csv }&range=all` }
					>
						{ __( 'Alles als CSV', 'cookie-dialog' ) }
					</Button>
				</Accordion>

				<Accordion
					title={ __( 'Assistent', 'cookie-dialog' ) }
					summary={ [
						a.ready
							? a.providers.join( ', ' )
							: __( 'kein KI-Anbieter', 'cookie-dialog' ),
						a.ready
							? {
									cheap: __( 'günstig', 'cookie-dialog' ),
									strong: __( 'stark', 'cookie-dialog' ),
									custom: c.assistant.custom,
							  }[ c.assistant.model ]
							: null,
						c.assistant.browser
							? __( 'Einfaches im Browser', 'cookie-dialog' )
							: null,
					]
						.filter( Boolean )
						.join( ' · ' ) }
					recommended={
						same( c.assistant, RECOMMENDED.assistant ) && a.ready
					}
					open={ open === 'assistant' }
					onToggle={ () => toggle( 'assistant' ) }
				>
					<Line
						label={ __( 'KI-Anbieter', 'cookie-dialog' ) }
						hint={
							a.wp
								? __(
										'WordPress verwaltet ihn unter Einstellungen › Connectors – ein API Key für alle Plugins.',
										'cookie-dialog'
								  )
								: __( 'Ab WordPress 7.0.', 'cookie-dialog' )
						}
					>
						<span className="cd-row-inline">
							{ a.ready ? (
								<Chip tone="moss" icon="check">
									{ a.providers.join( ', ' ) }
								</Chip>
							) : (
								<Chip>{ __( 'keiner', 'cookie-dialog' ) }</Chip>
							) }
							{ a.wp ? (
								<Button size="sm" href={ a.connectors }>
									{ __( 'Connectors', 'cookie-dialog' ) }
								</Button>
							) : null }
						</span>
					</Line>
					{ ! a.ready && a.wp ? (
						<Notice>
							{ __(
								'Empfohlen: Vercel AI Gateway – 5 $ im Monat gratis, ein API Key für die Modelle aller Anbieter.',
								'cookie-dialog'
							) }{ ' ' }
							<a href={ a.vercel }>
								{ __( 'Plugin installieren', 'cookie-dialog' ) }
							</a>
						</Notice>
					) : null }
					<Line
						label={ __( 'Modell', 'cookie-dialog' ) }
						hint={ __(
							'Günstig reicht für alles hier: eine Einrichtung kostet etwa 4 Cent.',
							'cookie-dialog'
						) }
					>
						<Seg
							value={ c.assistant.model }
							onChange={ ( model ) =>
								save( { assistant: { model } } )
							}
							options={ [
								{
									value: 'cheap',
									label: __( 'Günstig', 'cookie-dialog' ),
								},
								{
									value: 'strong',
									label: __( 'Stark', 'cookie-dialog' ),
								},
								{
									value: 'custom',
									label: __( 'Eigenes', 'cookie-dialog' ),
								},
							] }
						/>
					</Line>
					{ c.assistant.model === 'custom' ? (
						<Line label={ __( 'Modell-ID', 'cookie-dialog' ) }>
							<input
								type="text"
								className="cd-input-s"
								defaultValue={ c.assistant.custom }
								placeholder="google/gemini-2.5-flash-lite"
								onBlur={ ( e ) =>
									save( {
										assistant: { custom: e.target.value },
									} )
								}
							/>
						</Line>
					) : null }
					<Line
						label={ __(
							'Einfache Aufgaben im Browser',
							'cookie-dialog'
						) }
						hint={
							browser === 'available' ||
							browser === 'downloadable' ||
							browser === 'downloading'
								? __(
										'Dein Browser hat ein eingebautes Modell: Übersetzen, Kürzen, Zuordnen und der Datenschutz-Absatz laufen dort, nichts verlässt den Rechner.',
										'cookie-dialog'
								  )
								: __(
										'Dieser Browser hat kein eingebautes Modell (Chrome ab 148, Edge).',
										'cookie-dialog'
								  )
						}
					>
						<Switch
							checked={ c.assistant.browser }
							label={ __(
								'Einfache Aufgaben im Browser',
								'cookie-dialog'
							) }
							onChange={ ( v ) =>
								save( { assistant: { browser: v } } )
							}
						/>
					</Line>
					{ a.tokens ? (
						<p className="cd-fine">
							{ sprintf(
								/* translators: 1: tokens, 2: last model */ __(
									'Diesen Monat: %1$s Tokens · zuletzt %2$s',
									'cookie-dialog'
								),
								a.tokens.toLocaleString(),
								a.last
							) }
						</p>
					) : null }
				</Accordion>

				<Accordion
					title={ __( 'Sprachen', 'cookie-dialog' ) }
					summary={ `${ c.languages
						.map( ( l ) => languageName( l ) )
						.join( ', ' ) }${
						state.siteLanguages.length > 1
							? ` · ${ __(
									'wie deine Website',
									'cookie-dialog'
							  ) }`
							: ''
					}` }
					recommended={ state.siteLanguages.every( ( l ) =>
						c.languages.includes( l )
					) }
					open={ open === 'languages' }
					onToggle={ () => toggle( 'languages' ) }
				>
					<p className="cd-fine">
						{ __(
							'Der Dialog spricht die Sprache der Seite: mit Polylang oder WPML jede Sprache der Website, sonst die von WordPress. Texte und Übersetzungen bearbeitest du unter Dialog.',
							'cookie-dialog'
						) }
					</p>
					<Line
						label={ __(
							'Sprachen deiner Website',
							'cookie-dialog'
						) }
					>
						<span className="cd-row-inline">
							{ state.siteLanguages.map( ( l ) => (
								<Chip
									key={ l }
									tone={
										c.languages.includes( l )
											? 'moss'
											: 'amber'
									}
									icon={
										c.languages.includes( l )
											? 'check'
											: 'warn'
									}
								>
									{ languageName( l ) }
								</Chip>
							) ) }
						</span>
					</Line>
					<Button size="sm" href={ pageUrl( 'dialog' ) }>
						{ __( 'Texte und Sprachen', 'cookie-dialog' ) } ›
					</Button>
				</Accordion>

				<Accordion
					title={ __( 'Website lesen', 'cookie-dialog' ) }
					summary={ [
						c.scan.weekly
							? __( 'jede Woche', 'cookie-dialog' )
							: __( 'nur von Hand', 'cookie-dialog' ),
						state.scan.at
							? sprintf(
									/* translators: %s: time */ __(
										'zuletzt %s',
										'cookie-dialog'
									),
									when( state.scan.at )
							  )
							: null,
						sprintf(
							/* translators: %d: pages */
							_n(
								'%d Seite',
								'%d Seiten',
								state.scan.pages.length,
								'cookie-dialog'
							),
							state.scan.pages.length
						),
					]
						.filter( Boolean )
						.join( ' · ' ) }
					recommended={ same( c.scan, RECOMMENDED.scan ) }
					open={ open === 'scan' }
					onToggle={ () => toggle( 'scan' ) }
				>
					<Line
						label={ __( 'Jede Woche neu lesen', 'cookie-dialog' ) }
						hint={ __(
							'Neue Skripte erscheinen unter Dienste › Zu prüfen.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ c.scan.weekly }
							label={ __(
								'Jede Woche neu lesen',
								'cookie-dialog'
							) }
							onChange={ ( weekly ) =>
								save( { scan: { weekly } } )
							}
						/>
					</Line>
					<ul className="cd-list">
						{ state.scan.pages.map( ( p ) => (
							<li key={ p.url }>
								<span>{ p.title }</span>
								<a
									className="cd-fine"
									href={ p.url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ p.url.replace( state.site.home, '/' ) }
								</a>
							</li>
						) ) }
					</ul>
					{ state.scan.errors.length ? (
						<Notice tone="error">
							{ state.scan.errors
								.map( ( e ) => `${ e.url }: ${ e.message }` )
								.join( ' · ' ) }
						</Notice>
					) : null }
					<Button
						size="sm"
						icon="refresh"
						busy={ busy === 'scan' }
						onClick={ async () => {
							setBusy( 'scan' );
							try {
								onState( await post( '/scan' ) );
							} catch ( e ) {
								setError( problem( e ).message );
							} finally {
								setBusy( null );
							}
						} }
					>
						{ __( 'Jetzt lesen', 'cookie-dialog' ) }
					</Button>
				</Accordion>
			</Card>
			{ error ? <Notice tone="error">{ error }</Notice> : null }
			{ c.live ? (
				confirm ? (
					<div className="cd-confirm">
						<p>
							{ __(
								'Ausschalten? Skripte laufen dann wieder ohne Einwilligung. Das Protokoll bleibt.',
								'cookie-dialog'
							) }
						</p>
						<span className="cd-row-inline">
							<Button
								kind="danger-solid"
								busy={ busy === 'live' }
								onClick={ () => live( false ) }
							>
								{ __( 'Dialog ausschalten', 'cookie-dialog' ) }
							</Button>
							<Button
								kind="ghost"
								onClick={ () => setConfirm( false ) }
							>
								{ __( 'Abbrechen', 'cookie-dialog' ) }
							</Button>
						</span>
					</div>
				) : (
					<div className="cd-row-inline">
						<Button
							kind="danger"
							onClick={ () => setConfirm( true ) }
						>
							{ __( 'Dialog ausschalten', 'cookie-dialog' ) }
						</Button>
						<span className="cd-fine">
							{ __(
								'Skripte laufen dann wieder ohne Einwilligung. Das Protokoll bleibt.',
								'cookie-dialog'
							) }
						</span>
					</div>
				)
			) : (
				<div className="cd-row-inline">
					<Button kind="primary" href={ pageUrl( 'start' ) }>
						{ __( 'Zum Einschalten', 'cookie-dialog' ) } →
					</Button>
					<span className="cd-fine">
						{ __( 'Der Dialog ist noch aus.', 'cookie-dialog' ) }
					</span>
				</div>
			) }
		</>
	);
}
