import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { boot, pageUrl, post, problem } from './api';
import { AssistantCard, browserModel, runJob } from './Assistant';
import Preview from './Preview';
import {
	Button,
	Card,
	Chip,
	Drawer,
	Engenty,
	Icon,
	languageName,
	LOCAL_KINDS,
	Notice,
	Row,
	Section,
	Spinner,
	Status,
	pick,
	when,
} from './ui';

const CAT_ORDER = [
	'necessary',
	'functional',
	'media',
	'statistics',
	'marketing',
];

function catName( config, id ) {
	return (
		pick( config.categories.find( ( c ) => c.id === id )?.name, 'de' ) || id
	);
}

/**
 * Asks the browser's model or the provider about unknown hosts and drafts the texts once.
 * @param state
 * @param onState
 */
function useBackgroundJobs( state, onState ) {
	const asked = useRef( new Set() );
	useEffect( () => {
		if ( ! state.scan.at ) {
			return;
		}
		( async () => {
			const browser = await browserModel();
			const canAsk =
				state.assistant.ready ||
				( state.assistant.browser && browser === 'available' );
			if ( ! canAsk ) {
				return;
			}
			for ( const entry of state.unassigned ) {
				if ( entry.suggestion || asked.current.has( entry.host ) ) {
					continue;
				}
				asked.current.add( entry.host );
				try {
					await runJob( 'classify', { host: entry.host }, onState );
				} catch {
					// A guess that fails leaves the host for the person to assign.
				}
			}
			if (
				state.assistant.ready &&
				! state.drafted &&
				! state.config.live &&
				! asked.current.has( 'setup' )
			) {
				asked.current.add( 'setup' );
				try {
					await runJob( 'setup', {}, onState );
				} catch {
					// The library's texts stay.
				}
			}
		} )();
	}, [ state, onState ] );
}

/**
 * One fix of the Prüfung, wherever it shows.
 * @param state
 * @param onState
 */
export function useFix( state, onState ) {
	const [ busy, setBusy ] = useState( null );
	const [ message, setMessage ] = useState( null );
	const [ privacy, setPrivacy ] = useState( null );
	const run = async ( check, fix ) => {
		setMessage( null );
		if ( fix.action === 'edit' ) {
			const target = fix.args?.host
				? `#host=${ fix.args.host }`
				: `#service=${ fix.args?.service || '' }`;
			window.location.href = pageUrl( 'services', target );
			return;
		}
		if ( fix.action === 'pages' ) {
			window.location.href = pageUrl( 'settings', '#pages' );
			return;
		}
		if ( fix.action === 'read_privacy' ) {
			setPrivacy( true );
			return;
		}
		setBusy( check.id + fix.action );
		try {
			if ( fix.action === 'translate' ) {
				const res = await runJob( 'translate', fix.args, onState );
				setMessage( { tone: 'success', text: res?.message } );
			} else {
				const res = await post( '/fix', {
					action: fix.action,
					args: fix.args || {},
				} );
				onState( res );
				setMessage( {
					tone: res.result?.errors?.length ? 'info' : 'success',
					text: [
						res.result?.message,
						...( res.result?.errors || [] ),
					]
						.filter( Boolean )
						.join( ' ' ),
				} );
			}
		} catch ( e ) {
			setMessage( { tone: 'error', text: problem( e ).message } );
		} finally {
			setBusy( null );
		}
	};
	const drawer = privacy ? (
		<PrivacyDrawer
			state={ state }
			onState={ onState }
			onClose={ () => setPrivacy( null ) }
		/>
	) : null;
	return { run, busy, message, drawer };
}

function PrivacyDrawer( { state, onState, onClose } ) {
	const lang =
		boot.locale?.slice( 0, 2 ) === 'de'
			? 'de'
			: state.config.languages[ 0 ];
	const html = state.config.privacyText?.[ lang ];
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const write = async () => {
		setBusy( true );
		setError( null );
		try {
			await runJob( 'privacy', { lang }, onState );
		} catch ( e ) {
			setError( problem( e ).message );
		} finally {
			setBusy( false );
		}
	};
	const insert = async () => {
		setBusy( true );
		try {
			onState( await post( '/fix', { action: 'insert_privacy' } ) );
			onClose();
		} catch ( e ) {
			setError( problem( e ).message );
			setBusy( false );
		}
	};
	return (
		<Drawer
			title={ __(
				'Absatz für die Datenschutzerklärung',
				'cookie-dialog'
			) }
			onClose={ onClose }
			footer={
				<>
					<Button kind="ghost" onClick={ write } busy={ busy }>
						{ html
							? __( 'Neu schreiben', 'cookie-dialog' )
							: __(
									'Für meine Website schreiben',
									'cookie-dialog'
							  ) }
					</Button>
					<Button kind="primary" onClick={ insert } busy={ busy }>
						{ __( 'Mit Cookie-Tabelle einfügen', 'cookie-dialog' ) }
					</Button>
				</>
			}
		>
			<p className="cd-fine">
				{ __(
					'Darunter kommt die Cookie-Tabelle: alle Dienste und Cookies, und sie hält sich selbst aktuell.',
					'cookie-dialog'
				) }
			</p>
			{ html ? (
				<div
					className="cd-prose"
					// eslint-disable-next-line react/no-danger -- sanitized by the server (wp_kses_post).
					dangerouslySetInnerHTML={ { __html: html } }
				/>
			) : (
				<p>
					{ __(
						'Noch kein eigener Absatz: eingefügt wird die Vorlage der Bibliothek. Der Assistent oder das Modell deines Browsers schreibt ihn für deine Website.',
						'cookie-dialog'
					) }
				</p>
			) }
			{ error ? <Notice tone="error">{ error }</Notice> : null }
		</Drawer>
	);
}

/* ------------------------------------------------------------------ before the dialog is on */

function Proposal( { state, onState } ) {
	const { config, scan, unassigned } = state;
	const [ open, setOpen ] = useState( null );
	const fix = useFix( state, onState );
	const toggle = ( id ) => setOpen( open === id ? null : id );
	const dialogServices = config.services.filter(
		( s ) => ! ( LOCAL_KINDS.includes( s.kind ) && s.local )
	);
	const byCat = CAT_ORDER.map( ( id ) => [
		id,
		dialogServices.filter(
			( s ) => s.category === id && ! LOCAL_KINDS.includes( s.kind )
		),
	] ).filter( ( [ , list ] ) => list.length );
	const serviceCount = byCat.reduce(
		( n, [ , list ] ) => n + list.length,
		0
	);
	const embeds = config.services.filter( ( s ) => s.kind === 'embed' );
	const third = state.checks.find( ( c ) => c.id === 'third_party' );
	const localHosts =
		third && ! third.ok ? third.fixes[ 0 ]?.args?.hosts || [] : [];
	const localNames = [
		...config.services
			.filter(
				( s ) =>
					LOCAL_KINDS.includes( s.kind ) &&
					( s.local ||
						localHosts.some( ( h ) =>
							s.hosts.some( ( x ) => h.endsWith( x ) )
						) )
			)
			.map( ( s ) => s.name ),
		...( config.thirdParty.gravatar === 'proxy' &&
		scan.hosts.some( ( h ) => h.host.includes( 'gravatar' ) )
			? [ 'Gravatar' ]
			: [] ),
	];
	const uniqueLocal = Array.from( new Set( localNames ) );
	const langs = config.languages
		.map( ( l ) => languageName( l ) )
		.join( ' · ' );
	const d = config.design;
	const google = config.services.filter( ( s ) => s.signals.length );
	const privacy = state.checks.find( ( c ) => c.id === 'privacy' );
	const position = {
		left: __( 'unten links', 'cookie-dialog' ),
		right: __( 'unten rechts', 'cookie-dialog' ),
		center: __( 'unten in der Mitte', 'cookie-dialog' ),
	}[ d.position ];
	const layout = {
		box: __( 'Box', 'cookie-dialog' ),
		bar: __( 'Leiste', 'cookie-dialog' ),
		modal: __( 'Fenster', 'cookie-dialog' ),
	}[ d.layout ];
	const chev = ( id ) => (
		<Icon name={ open === id ? 'down' : 'chevron' } size={ 16 } />
	);

	return (
		<Card pad0>
			<Row
				lead={ <Status ok /> }
				title={ `${ sprintf(
					/* translators: %d: services */
					_n(
						'%d Dienst',
						'%d Dienste',
						serviceCount,
						'cookie-dialog'
					),
					serviceCount
				) } ${ sprintf(
					/* translators: %d: categories */
					_n(
						'in %d Kategorie',
						'in %d Kategorien',
						byCat.length,
						'cookie-dialog'
					),
					byCat.length
				) }` }
				sub={ byCat
					.map(
						( [ id, list ] ) =>
							`${ catName( config, id ) } ${ list.length }`
					)
					.join( ' · ' ) }
				right={ chev( 'services' ) }
				onClick={ () => toggle( 'services' ) }
			/>
			{ open === 'services' ? (
				<div className="cd-rowx">
					{ byCat.map( ( [ id, list ] ) => (
						<div key={ id } className="cd-cl">
							<em>{ catName( config, id ) }</em>
							{ list.map( ( s ) => s.name ).join( ' · ' ) }
						</div>
					) ) }
					<a href={ pageUrl( 'services' ) }>
						{ __( 'Dienste ansehen', 'cookie-dialog' ) } ›
					</a>
				</div>
			) : null }
			{ unassigned.map( ( entry ) => {
				const s = entry.suggestion;
				const check = state.checks.find(
					( c ) => c.id === `unknown:${ entry.host }`
				);
				return (
					<Row
						key={ entry.host }
						lead={ <Status /> }
						title={ sprintf(
							/* translators: %s: host */
							__( 'Skript unbekannt: %s', 'cookie-dialog' ),
							entry.host
						) }
						sub={
							s
								? sprintf(
										/* translators: 1: who suggests, 2: service, 3: purpose, 4: category */
										__(
											'%1$s: %2$s, %3$s → %4$s',
											'cookie-dialog'
										),
										entry.suggest?.by === 'browser'
											? __(
													'Vorschlag deines Browsers',
													'cookie-dialog'
											  )
											: __(
													'Vorschlag',
													'cookie-dialog'
											  ),
										s.name,
										s.purpose,
										catName( config, s.category )
								  )
								: __(
										'Lädt bis zur Zuordnung ohne Einwilligung',
										'cookie-dialog'
								  )
						}
						right={
							<>
								{ s && check ? (
									<Button
										size="sm"
										busy={ fix.busy === check.id + 'adopt' }
										onClick={ () =>
											fix.run( check, {
												action: 'adopt',
												args: { host: entry.host },
											} )
										}
									>
										{ __( 'Übernehmen', 'cookie-dialog' ) }
									</Button>
								) : null }
								<Button
									size="sm"
									kind="ghost"
									href={ pageUrl(
										'services',
										`#host=${ entry.host }`
									) }
								>
									{ s
										? __( 'Ändern', 'cookie-dialog' )
										: __( 'Zuordnen', 'cookie-dialog' ) }
								</Button>
							</>
						}
					/>
				);
			} ) }
			{ embeds.length ? (
				<Row
					lead={ <Status ok /> }
					title={ sprintf(
						/* translators: %d: number of embeds */
						_n(
							'%d Einbettung wartet auf Einwilligung',
							'%d Einbettungen warten auf Einwilligung',
							embeds.length,
							'cookie-dialog'
						),
						embeds.length
					) }
					sub={ sprintf(
						/* translators: %s: services */
						__(
							'%s – als Platzhalter mit Vorschaubild',
							'cookie-dialog'
						),
						embeds.map( ( s ) => s.name ).join( ', ' )
					) }
					right={ chev( 'embeds' ) }
					onClick={ () => toggle( 'embeds' ) }
				/>
			) : null }
			{ open === 'embeds' ? (
				<div className="cd-rowx">
					<p className="cd-fine">
						{ __(
							'Bis zur Einwilligung steht dort ein Platzhalter in der Größe des Inhalts: „Laden“ lädt einmal, „Immer laden“ ist die Einwilligung für den Dienst. YouTube lädt danach über youtube-nocookie.com.',
							'cookie-dialog'
						) }
					</p>
				</div>
			) : null }
			{ uniqueLocal.length ? (
				<Row
					lead={ <Status ok /> }
					title={ sprintf(
						/* translators: %d: number of things */
						_n(
							'%d Ding kommt von deinem Server statt von Dritten',
							'%d Dinge kommen von deinem Server statt von Dritten',
							uniqueLocal.length +
								( config.thirdParty.emoji ? 0 : 1 ),
							'cookie-dialog'
						),
						uniqueLocal.length + ( config.thirdParty.emoji ? 0 : 1 )
					) }
					sub={ `${ uniqueLocal.join( ', ' ) }${
						config.thirdParty.emoji
							? ''
							: ` · ${ __(
									'WordPress-Emoji aus',
									'cookie-dialog'
							  ) }`
					}` }
					right={ chev( 'local' ) }
					onClick={ () => toggle( 'local' ) }
				/>
			) : null }
			{ open === 'local' ? (
				<div className="cd-rowx">
					<p className="cd-fine">
						{ __(
							'Schriften, Styles und Bilder von Dritten werden beim Einschalten auf deinen Server kopiert und die Verweise umgeschrieben – dann sieht kein Dritter die IP-Adresse deiner Besucher, und es braucht keine Einwilligung.',
							'cookie-dialog'
						) }
					</p>
				</div>
			) : null }
			<Row
				lead={ <Status ok /> }
				title={
					state.drafted
						? __( 'Texte in deinem Ton', 'cookie-dialog' )
						: __( 'Standardtexte', 'cookie-dialog' )
				}
				sub={ `${ langs } · ${
					config.tone.address === 'Sie'
						? __( 'per Sie', 'cookie-dialog' )
						: __( 'per du', 'cookie-dialog' )
				}${
					state.drafted
						? ''
						: ` · ${ __(
								'mit einem KI-Anbieter in deinem Ton',
								'cookie-dialog'
						  ) }`
				}` }
				right={ <Icon name="chevron" size={ 16 } /> }
				onClick={ () => ( window.location.href = pageUrl( 'dialog' ) ) }
			/>
			<Row
				lead={ <Status ok /> }
				title={ __( 'Aussehen aus deinem Theme', 'cookie-dialog' ) }
				sub={ [
					`${ layout } ${ position }`,
					d.colors.button,
					d.headFont
						? d.headFont.split( ',' )[ 0 ].replace( /["']/g, '' )
						: null,
					d.logo ? __( 'dein Logo', 'cookie-dialog' ) : null,
				]
					.filter( Boolean )
					.join( ' · ' ) }
				right={ <Icon name="chevron" size={ 16 } /> }
				onClick={ () => ( window.location.href = pageUrl( 'dialog' ) ) }
			/>
			{ google.length || config.google.gtm ? (
				<Row
					lead={ <Status ok /> }
					title={ __( 'Google Consent Mode v2', 'cookie-dialog' ) }
					sub={ sprintf(
						/* translators: %s: Google services */
						__(
							'Vor %s · Tags warten auf Einwilligung',
							'cookie-dialog'
						),
						[ ...google.map( ( s ) => s.name ), config.google.gtm ]
							.filter( Boolean )
							.join( ', ' )
					) }
				/>
			) : null }
			{ privacy ? (
				<Row
					lead={ <Status ok={ privacy.ok } /> }
					title={ __( 'Datenschutzerklärung', 'cookie-dialog' ) }
					sub={ privacy.ok ? privacy.title : privacy.text }
					right={
						privacy.fixes.length ? (
							<Button
								size="sm"
								onClick={ () =>
									fix.run( privacy, privacy.fixes[ 0 ] )
								}
								busy={
									fix.busy ===
									privacy.id + privacy.fixes[ 0 ].action
								}
							>
								{ privacy.fixes[ 0 ].label }
							</Button>
						) : null
					}
				/>
			) : null }
			<Row
				lead={ <Status ok /> }
				title={ __( 'Protokoll', 'cookie-dialog' ) }
				sub={ sprintf(
					/* translators: %d: months */
					_n(
						'%d Monat · IP-Adressen nur gehasht',
						'%d Monate · IP-Adressen nur gehasht',
						config.log.months,
						'cookie-dialog'
					),
					config.log.months
				) }
			/>
			{ fix.message ? (
				<div className="cd-rowx">
					<Notice tone={ fix.message.tone }>
						{ fix.message.text }
					</Notice>
				</div>
			) : null }
			{ fix.drawer }
		</Card>
	);
}

function Before( { state, onState } ) {
	const [ going, setGoing ] = useState( false );
	const [ error, setError ] = useState( null );
	const go = async () => {
		setGoing( true );
		setError( null );
		try {
			onState( await post( '/live', { on: true } ) );
			window.scrollTo( { top: 0 } );
		} catch ( e ) {
			setError( problem( e ).message );
		} finally {
			setGoing( false );
		}
	};
	const c = state.scan.counts || {};
	const open = state.unassigned.length;
	return (
		<>
			<AssistantCard
				title={ sprintf(
					/* translators: %s: the site's host */
					__( 'Ich habe %s gelesen', 'cookie-dialog' ),
					state.site.host
				) }
				text={ sprintf(
					/* translators: 1: pages, 2: scripts, 3: embeds, 4: plugins */
					__(
						'%1$d Seiten, %2$d Skripte, %3$d Einbettungen, %4$d Plugins. Unten steht mein Vorschlag, rechts siehst du ihn auf deiner Startseite.',
						'cookie-dialog'
					),
					c.pages || 0,
					c.scripts || 0,
					c.embeds || 0,
					c.plugins || 0
				) }
				placeholder={ __(
					'Sag mir, was anders sein soll – z. B. „Schreib per Sie“',
					'cookie-dialog'
				) }
				suggestions={ [
					__( 'Schreib per Sie', 'cookie-dialog' ),
					__( 'Dunkler Dialog', 'cookie-dialog' ),
					__( 'Wir nutzen keine Statistik mehr', 'cookie-dialog' ),
				] }
			/>
			<div className="cd-split">
				<div className="cd-split__main">
					<Section
						title={ __( 'Vorschlag', 'cookie-dialog' ) }
						hint={ __(
							'Alles ist voreingestellt. Öffne eine Zeile, um sie zu prüfen.',
							'cookie-dialog'
						) }
						aside={
							<Button
								kind="ghost"
								size="sm"
								icon="refresh"
								onClick={ async () =>
									onState(
										await post( '/scan', { propose: true } )
									)
								}
							>
								{ __( 'Neu lesen', 'cookie-dialog' ) }
							</Button>
						}
					>
						<Proposal state={ state } onState={ onState } />
					</Section>
				</div>
				<div className="cd-split__side">
					<Section
						title={ __( 'So sieht es aus', 'cookie-dialog' ) }
						hint={ __(
							'Deine Startseite mit dem Dialog.',
							'cookie-dialog'
						) }
					>
						<Card className="cd-card--preview">
							<Preview
								state={ state }
								compact
								reloadKey={ `${
									state.config.services.length
								}-${ state.config.languages.join() }` }
							/>
						</Card>
					</Section>
				</div>
			</div>
			<div className="cd-ready">
				<span className="cd-ready__ok">
					<Icon name="check" size={ 18 } />
					{ open
						? sprintf(
								/* translators: %d: open points */
								_n(
									'Bereit – %d Skript noch ohne Zuordnung.',
									'Bereit – %d Skripte noch ohne Zuordnung.',
									open,
									'cookie-dialog'
								),
								open
						  )
						: __( 'Alles bereit.', 'cookie-dialog' ) }
				</span>
				<Button kind="primary" size="lg" busy={ going } onClick={ go }>
					{ going
						? __( 'Schalte ein …', 'cookie-dialog' )
						: __( 'Dialog einschalten', 'cookie-dialog' ) }{ ' ' }
					→
				</Button>
				<span className="cd-fine">
					{ __(
						'Bis dahin ändert sich nichts an deiner Website. Ausschalten geht jederzeit.',
						'cookie-dialog'
					) }
				</span>
				{ error ? <Notice tone="error">{ error }</Notice> : null }
			</div>
		</>
	);
}

/* ------------------------------------------------------------------ once it is on */

function Live( { state, onState } ) {
	const fix = useFix( state, onState );
	const [ passedOpen, setPassedOpen ] = useState( false );
	const open = state.checks.filter( ( c ) => ! c.ok );
	const passed = state.checks.filter( ( c ) => c.ok );
	const s = state.stats;
	return (
		<>
			<AssistantCard
				title={ __( 'Was soll anders sein?', 'cookie-dialog' ) }
				placeholder={ __(
					'Ich ändere Dialog, Dienste und Einstellungen – z. B. „Füge Hotjar hinzu“',
					'cookie-dialog'
				) }
				suggestions={ [
					__( 'Wie viele haben gestern abgelehnt?', 'cookie-dialog' ),
					__( 'Übersetze auf Italienisch', 'cookie-dialog' ),
					__( 'Mach den Dialog kleiner', 'cookie-dialog' ),
				] }
			/>
			<Section
				title={
					<>
						{ __( 'Zu tun', 'cookie-dialog' ) }{ ' ' }
						{ open.length ? (
							<Chip tone="amber">{ open.length }</Chip>
						) : null }
					</>
				}
				hint={
					state.scan.at
						? sprintf(
								/* translators: %s: time of the last scan */
								__(
									'Aus der Prüfung %s. Ein Klick erledigt es.',
									'cookie-dialog'
								),
								when( state.scan.at )
						  )
						: null
				}
				aside={
					<Button
						kind="ghost"
						size="sm"
						icon="refresh"
						onClick={ async () => onState( await post( '/scan' ) ) }
					>
						{ __( 'Jetzt prüfen', 'cookie-dialog' ) }
					</Button>
				}
			>
				<Card pad0>
					{ open.length ? null : (
						<Row
							lead={ <Status ok /> }
							title={ __( 'Alles in Ordnung', 'cookie-dialog' ) }
							sub={ __(
								'Jede Woche liest der Dialog deine Website neu und meldet sich hier.',
								'cookie-dialog'
							) }
						/>
					) }
					{ open.map( ( c ) => (
						<Row
							key={ c.id }
							lead={ <Status /> }
							title={ c.title }
							sub={ c.text }
							right={ c.fixes.map( ( f ) => (
								<Button
									key={ f.action }
									size="sm"
									kind={
										f.primary
											? 'primary'
											: f.action === 'edit' ||
											  f.action === 'read_privacy'
											? 'ghost'
											: 'secondary'
									}
									busy={ fix.busy === c.id + f.action }
									onClick={ () => fix.run( c, f ) }
								>
									{ f.label }
								</Button>
							) ) }
						/>
					) ) }
					<Row
						className="cd-row--quiet"
						lead={ <Status ok /> }
						title={ sprintf(
							/* translators: %d: passed checks */
							_n(
								'%d Prüfung bestanden',
								'%d Prüfungen bestanden',
								passed.length,
								'cookie-dialog'
							),
							passed.length
						) }
						right={
							<Icon
								name={ passedOpen ? 'down' : 'chevron' }
								size={ 16 }
							/>
						}
						onClick={ () => setPassedOpen( ! passedOpen ) }
					/>
					{ passedOpen ? (
						<div className="cd-rowx">
							{ passed.map( ( c ) => (
								<div key={ c.id } className="cd-cl">
									<Icon
										name="check"
										size={ 13 }
										style={ { color: 'var(--cd-moss)' } }
									/>
									<span>
										<b>{ c.title }</b> · { c.text }
									</span>
								</div>
							) ) }
						</div>
					) : null }
					{ fix.message ? (
						<div className="cd-rowx">
							<Notice tone={ fix.message.tone }>
								{ fix.message.text }
							</Notice>
						</div>
					) : null }
				</Card>
			</Section>
			<Section
				title={ __( 'Letzte 30 Tage', 'cookie-dialog' ) }
				aside={
					<Button kind="ghost" size="sm" href={ pageUrl( 'log' ) }>
						{ __( 'Protokoll', 'cookie-dialog' ) } ›
					</Button>
				}
			>
				<Card className="cd-col">
					<div className="cd-big">
						<span>{ s.total.toLocaleString() }</span>
						<span className="cd-muted">
							{ __( 'Entscheidungen', 'cookie-dialog' ) }
						</span>
					</div>
					<Split stats={ s } />
				</Card>
			</Section>
			{ state.history.length ? (
				<Section title={ __( 'Zuletzt', 'cookie-dialog' ) }>
					<Card>
						<ul className="cd-list">
							{ state.history.map( ( h, i ) => (
								<li key={ i }>
									<span>{ h.text }</span>
									<span className="cd-fine">
										{ when( h.t, false ) }
									</span>
								</li>
							) ) }
						</ul>
					</Card>
				</Section>
			) : null }
			{ fix.drawer }
		</>
	);
}

/**
 * All accepted, own choice, only necessary: one bar and its legend.
 * @param root0
 * @param root0.stats
 */
export function Split( { stats } ) {
	const p = stats.percent;
	if ( ! stats.total ) {
		return (
			<p className="cd-fine">
				{ __(
					'Noch keine Entscheidungen. Sie erscheinen, sobald Besucher wählen.',
					'cookie-dialog'
				) }
			</p>
		);
	}
	return (
		<>
			<div className="cd-stack" aria-hidden="true">
				<i
					style={ {
						width: `${ p.all }%`,
						background: 'var(--cd-moss)',
					} }
				/>
				<i
					style={ { width: `${ p.custom }%`, background: '#7fb38f' } }
				/>
				<i
					style={ {
						width: `${ p.necessary }%`,
						background: 'var(--cd-ink-4)',
					} }
				/>
			</div>
			<div className="cd-legend">
				<span>
					<i style={ { background: 'var(--cd-moss)' } } />
					{ __( 'Alle akzeptiert', 'cookie-dialog' ) } { p.all } %
				</span>
				<span>
					<i style={ { background: '#7fb38f' } } />
					{ __( 'Eigene Auswahl', 'cookie-dialog' ) } { p.custom } %
				</span>
				<span>
					<i style={ { background: 'var(--cd-ink-4)' } } />
					{ __( 'Nur notwendige', 'cookie-dialog' ) } { p.necessary }{ ' ' }
					%
				</span>
			</div>
		</>
	);
}

export default function Start( { state, onState } ) {
	const [ scanning, setScanning ] = useState( false );
	const [ error, setError ] = useState( null );
	useBackgroundJobs( state, onState );
	useEffect( () => {
		if ( state.scan.at || state.config.setup ) {
			return;
		}
		setScanning( true );
		post( '/scan', { propose: true } )
			.then( onState )
			.catch( ( e ) => setError( problem( e ).message ) )
			.finally( () => setScanning( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, on the first visit.
	}, [] );
	if ( scanning || ( ! state.scan.at && ! state.config.setup ) ) {
		return (
			<div className="cd-reading">
				<span className="cd-reading__eng">
					<Engenty kind="drop" size={ 72 } />
				</span>
				<h1>{ __( 'Ich lese deine Website …', 'cookie-dialog' ) }</h1>
				<p>
					{ __(
						'Startseite und bis zu 20 Seiten, als Besucher ohne Einwilligung: jedes Skript, jede Einbettung, jede Schrift von Dritten. Das dauert ein paar Sekunden.',
						'cookie-dialog'
					) }
				</p>
				{ error ? (
					<Notice tone="error">{ error }</Notice>
				) : (
					<Spinner />
				) }
			</div>
		);
	}
	return state.config.live ? (
		<Live state={ state } onState={ onState } />
	) : (
		<Before state={ state } onState={ onState } />
	);
}
