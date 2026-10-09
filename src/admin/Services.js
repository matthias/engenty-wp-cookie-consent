import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, post, problem } from './api';
import { Dock } from './Assistant';
import {
	Button,
	Card,
	Chip,
	Drawer,
	Field,
	Icon,
	LOCAL_KINDS,
	Line,
	Logo,
	Notice,
	Row,
	Section,
	Switch,
	pick,
	when,
} from './ui';

const SIGNALS = [
	'ad_storage',
	'ad_user_data',
	'ad_personalization',
	'analytics_storage',
	'functionality_storage',
	'personalization_storage',
];

/** The library's services people add most, first among the quick-add chips. */
const POPULAR = [
	'hotjar',
	'matomo',
	'microsoft-clarity',
	'vimeo',
	'linkedin-insight',
	'tiktok-pixel',
	'hubspot',
	'mailchimp',
	'calendly',
	'spotify',
	'stripe',
	'cloudflare-turnstile',
	'google-tag-manager',
	'google-ads',
	'youtube',
	'google-maps',
];

const DURATIONS = [
	'session',
	'30min',
	'1d',
	'7d',
	'30d',
	'6mo',
	'13mo',
	'1y',
	'2y',
	'persistent',
];

function durationLabel( d ) {
	if ( d === 'session' ) {
		return __( 'Sitzung', 'cookie-dialog' );
	}
	if ( d === 'persistent' ) {
		return __( 'dauerhaft', 'cookie-dialog' );
	}
	const m = d.match( /^(\d+)(min|h|d|w|mo|y)$/ );
	if ( ! m ) {
		return d;
	}
	const unit = {
		min: __( 'Min.', 'cookie-dialog' ),
		h: __( 'Std.', 'cookie-dialog' ),
		d: __( 'Tage', 'cookie-dialog' ),
		w: __( 'Wochen', 'cookie-dialog' ),
		mo: __( 'Monate', 'cookie-dialog' ),
		y: __( 'Jahre', 'cookie-dialog' ),
	}[ m[ 2 ] ];
	return `${ m[ 1 ] } ${ unit }`;
}

const isLocalKind = ( s ) => LOCAL_KINDS.includes( s.kind );

function whereFound( state, service ) {
	const hosts = state.scan.hosts.filter(
		( h ) =>
			h.service === service.id ||
			( service.preset && h.preset === service.preset ) ||
			service.hosts.some(
				( x ) => h.host === x || h.host.endsWith( '.' + x )
			)
	);
	const pages = [ ...new Set( hosts.flatMap( ( h ) => h.pages ) ) ];
	return { hosts, pages };
}

function pageName( path ) {
	if ( path === '/' ) {
		return __( 'Start', 'cookie-dialog' );
	}
	const last = path.replace( /\/$/, '' ).split( '/' ).pop() || path;
	return decodeURIComponent( last )
		.replace( /-/g, ' ' )
		.replace( /^./, ( c ) => c.toUpperCase() );
}

function Held( { service, config } ) {
	if ( service.category === 'necessary' ) {
		return (
			<span className="cd-held is-lock">
				{ __( 'immer aktiv', 'cookie-dialog' ) }
			</span>
		);
	}
	if ( service.kind === 'embed' ) {
		return (
			<span className="cd-held is-moss">
				<Icon name="check" size={ 11 } />{ ' ' }
				{ __( 'Platzhalter', 'cookie-dialog' ) }
			</span>
		);
	}
	const cat = config.categories.find( ( c ) => c.id === service.category );
	if ( cat && ! cat.enabled ) {
		return (
			<span className="cd-held">
				{ __( 'nie geladen', 'cookie-dialog' ) }
			</span>
		);
	}
	return (
		<span className="cd-held">
			{ __( 'wartet auf Einwilligung', 'cookie-dialog' ) }
		</span>
	);
}

/* ------------------------------------------------------------------ one service */

function ServiceDrawer( { state, onState, initial, onClose } ) {
	const isNew = ! initial.id;
	const [ draft, setDraft ] = useState( initial );
	const [ edit, setEdit ] = useState( isNew );
	const [ lang, setLang ] = useState( state.config.languages[ 0 ] );
	const [ open, setOpen ] = useState( null );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ confirm, setConfirm ] = useState( false );
	const timer = useRef();
	const config = state.config;

	const persist = useCallback(
		async ( next ) => {
			if ( ! next.name ) {
				return;
			}
			setError( null );
			try {
				const res = await post( '/service', { service: next } );
				onState( res );
				setSaved( true );
				if ( ! next.id && res.added ) {
					setDraft( ( d ) => ( { ...d, id: res.added } ) );
				}
			} catch ( e ) {
				setError( problem( e ).message );
			}
		},
		[ onState ]
	);
	const change = ( patch ) => {
		const next = { ...draft, ...patch };
		setDraft( next );
		setSaved( false );
		window.clearTimeout( timer.current );
		if ( next.id ) {
			timer.current = window.setTimeout( () => persist( next ), 700 );
		}
	};
	const finish = async () => {
		window.clearTimeout( timer.current );
		if ( draft.name && ( ! draft.id || ! saved ) ) {
			await persist( draft );
		}
		onClose();
	};
	const remove = async () => {
		try {
			onState(
				await api( `/service/${ draft.id }`, { method: 'DELETE' } )
			);
			onClose();
		} catch ( e ) {
			setError( problem( e ).message );
		}
	};

	const found = whereFound( state, draft );
	const cat = config.categories.find( ( c ) => c.id === draft.category );
	const purpose = pick( draft.purpose, lang );
	const embed = draft.kind === 'embed';

	return (
		<Drawer
			title={ draft.name || __( 'Eigener Dienst', 'cookie-dialog' ) }
			head={
				<>
					<Logo name={ draft.name } />
				</>
			}
			onClose={ finish }
			footer={
				<>
					{ draft.id && draft.id !== 'cookie-dialog' ? (
						confirm ? (
							<span className="cd-row-inline">
								<Button
									kind="danger-solid"
									size="sm"
									onClick={ remove }
								>
									{ __(
										'Wirklich entfernen',
										'cookie-dialog'
									) }
								</Button>
								<Button
									kind="ghost"
									size="sm"
									onClick={ () => setConfirm( false ) }
								>
									{ __( 'Abbrechen', 'cookie-dialog' ) }
								</Button>
							</span>
						) : (
							<Button
								kind="danger"
								onClick={ () => setConfirm( true ) }
							>
								{ __( 'Entfernen', 'cookie-dialog' ) }
							</Button>
						)
					) : (
						<span />
					) }
					<span className="cd-row-inline">
						{ saved ? (
							<span className="cd-fine">
								<Icon
									name="check"
									size={ 12 }
									style={ { color: 'var(--cd-moss)' } }
								/>{ ' ' }
								{ config.live
									? sprintf(
											/* translators: %d: version */
											__(
												'Gespeichert · gilt ab Version %d',
												'cookie-dialog'
											),
											config.version
									  )
									: __( 'Gespeichert', 'cookie-dialog' ) }
							</span>
						) : null }
						<Button
							kind="primary"
							onClick={ finish }
							disabled={ ! draft.name }
						>
							{ __( 'Fertig', 'cookie-dialog' ) }
						</Button>
					</span>
				</>
			}
		>
			<div className="cd-row-inline cd-drawer__chips">
				{ cat ? <Chip>{ pick( cat.name ) }</Chip> : null }
				{ draft.preset ? (
					<Chip>{ __( 'aus der Bibliothek', 'cookie-dialog' ) }</Chip>
				) : (
					<Chip>{ __( 'eigener Dienst', 'cookie-dialog' ) }</Chip>
				) }
			</div>
			<Section
				title={ __( 'Im Dialog', 'cookie-dialog' ) }
				hint={ __(
					'So sehen Besucher den Dienst in der Auswahl.',
					'cookie-dialog'
				) }
				aside={
					isNew ? null : (
						<Button
							kind="ghost"
							size="sm"
							onClick={ () => setEdit( ! edit ) }
						>
							{ edit
								? __( 'Zusammenfassung', 'cookie-dialog' )
								: __( 'Ändern', 'cookie-dialog' ) }
						</Button>
					)
				}
			>
				{ config.languages.length > 1 ? (
					<div className="cd-row-inline">
						{ config.languages.map( ( l ) => (
							<button
								key={ l }
								type="button"
								className={ `cd-tab${
									l === lang ? ' is-on' : ''
								}` }
								onClick={ () => setLang( l ) }
							>
								{ l.toUpperCase() }
							</button>
						) ) }
					</div>
				) : null }
				{ edit ? (
					<Card className="cd-col cd-gap12">
						<Field label={ __( 'Name', 'cookie-dialog' ) }>
							<input
								type="text"
								value={ draft.name }
								onChange={ ( e ) =>
									change( { name: e.target.value } )
								}
							/>
						</Field>
						<Field label={ __( 'Kategorie', 'cookie-dialog' ) }>
							<select
								value={ draft.category }
								disabled={ draft.id === 'cookie-dialog' }
								onChange={ ( e ) =>
									change( { category: e.target.value } )
								}
							>
								{ config.categories.map( ( c ) => (
									<option key={ c.id } value={ c.id }>
										{ pick( c.name ) }
									</option>
								) ) }
							</select>
						</Field>
						<Field label={ __( 'Anbieter', 'cookie-dialog' ) }>
							<input
								type="text"
								value={ draft.provider.name }
								onChange={ ( e ) =>
									change( {
										provider: {
											...draft.provider,
											name: e.target.value,
										},
									} )
								}
							/>
						</Field>
						<Field
							label={ __(
								'Adresse des Anbieters',
								'cookie-dialog'
							) }
						>
							<input
								type="text"
								value={ draft.provider.address }
								onChange={ ( e ) =>
									change( {
										provider: {
											...draft.provider,
											address: e.target.value,
										},
									} )
								}
							/>
						</Field>
						<Field
							label={ sprintf(
								/* translators: %s: language */ __(
									'Zweck (%s)',
									'cookie-dialog'
								),
								lang.toUpperCase()
							) }
						>
							<textarea
								rows={ 3 }
								value={ draft.purpose?.[ lang ] || '' }
								onChange={ ( e ) =>
									change( {
										purpose: {
											...draft.purpose,
											[ lang ]: e.target.value,
										},
									} )
								}
							/>
						</Field>
						<Field
							label={ __(
								'Datenschutzerklärung des Anbieters',
								'cookie-dialog'
							) }
						>
							<input
								type="url"
								value={ draft.privacy }
								placeholder="https://"
								onChange={ ( e ) =>
									change( { privacy: e.target.value } )
								}
							/>
						</Field>
						<div className="cd-field">
							<span className="cd-field__label">
								{ __( 'Cookies', 'cookie-dialog' ) }
							</span>
							{ draft.cookies.map( ( c, i ) => (
								<div key={ i } className="cd-cookie-row">
									<input
										type="text"
										value={ c.name }
										aria-label={ __(
											'Name',
											'cookie-dialog'
										) }
										onChange={ ( e ) =>
											change( {
												cookies: draft.cookies.map(
													( x, j ) =>
														j === i
															? {
																	...x,
																	name: e
																		.target
																		.value,
															  }
															: x
												),
											} )
										}
									/>
									<select
										value={
											DURATIONS.includes( c.duration )
												? c.duration
												: c.duration
										}
										aria-label={ __(
											'Dauer',
											'cookie-dialog'
										) }
										onChange={ ( e ) =>
											change( {
												cookies: draft.cookies.map(
													( x, j ) =>
														j === i
															? {
																	...x,
																	duration:
																		e.target
																			.value,
															  }
															: x
												),
											} )
										}
									>
										{ [
											...new Set( [
												c.duration,
												...DURATIONS,
											] ),
										].map( ( d ) => (
											<option key={ d } value={ d }>
												{ durationLabel( d ) }
											</option>
										) ) }
									</select>
									<select
										value={ c.type }
										aria-label={ __(
											'Art',
											'cookie-dialog'
										) }
										onChange={ ( e ) =>
											change( {
												cookies: draft.cookies.map(
													( x, j ) =>
														j === i
															? {
																	...x,
																	type: e
																		.target
																		.value,
															  }
															: x
												),
											} )
										}
									>
										<option value="http">HTTP</option>
										<option value="local">
											Local Storage
										</option>
										<option value="session">
											Session Storage
										</option>
									</select>
									<button
										type="button"
										className="cd-icon-btn"
										aria-label={ __(
											'Cookie entfernen',
											'cookie-dialog'
										) }
										onClick={ () =>
											change( {
												cookies: draft.cookies.filter(
													( x, j ) => j !== i
												),
											} )
										}
									>
										<Icon name="x" size={ 14 } />
									</button>
								</div>
							) ) }
							<Button
								kind="ghost"
								size="sm"
								icon="plus"
								onClick={ () =>
									change( {
										cookies: [
											...draft.cookies,
											{
												name: '',
												duration: '1y',
												type: 'http',
											},
										],
									} )
								}
							>
								{ __( 'Cookie', 'cookie-dialog' ) }
							</Button>
						</div>
					</Card>
				) : (
					<div className="cd-mini">
						<b>{ draft.name }</b>
						<dl className="cd-kv">
							{ draft.provider.name ? (
								<>
									<dt>
										{ __( 'Anbieter', 'cookie-dialog' ) }
									</dt>
									<dd>
										{ [
											draft.provider.name,
											draft.provider.address,
										]
											.filter( Boolean )
											.join( ', ' ) }
									</dd>
								</>
							) : null }
							{ purpose ? (
								<>
									<dt>{ __( 'Zweck', 'cookie-dialog' ) }</dt>
									<dd>{ purpose }</dd>
								</>
							) : null }
							{ draft.privacy ? (
								<>
									<dt>
										{ __( 'Datenschutz', 'cookie-dialog' ) }
									</dt>
									<dd>
										<a
											href={ draft.privacy }
											target="_blank"
											rel="noopener noreferrer"
										>
											{ draft.privacy.replace(
												/^https?:\/\/(www\.)?/,
												''
											) }
										</a>
									</dd>
								</>
							) : null }
						</dl>
						{ draft.cookies.length ? (
							<table>
								<thead>
									<tr>
										<th>
											{ __( 'Cookie', 'cookie-dialog' ) }
										</th>
										<th>
											{ __( 'Dauer', 'cookie-dialog' ) }
										</th>
										<th>
											{ __( 'Art', 'cookie-dialog' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ draft.cookies.map( ( c ) => (
										<tr key={ c.name }>
											<td>{ c.name }</td>
											<td>
												{ durationLabel( c.duration ) }
											</td>
											<td>
												{
													{
														http: 'HTTP',
														local: 'Local Storage',
														session:
															'Session Storage',
													}[ c.type ]
												}
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) : (
							<span className="cd-fine">
								{ __(
									'Setzt keine Cookies.',
									'cookie-dialog'
								) }
							</span>
						) }
					</div>
				) }
			</Section>
			<Section title={ __( 'Auf deiner Website', 'cookie-dialog' ) }>
				<Card className="cd-col">
					<div className="cd-cl">
						<em>{ __( 'Erkannt an', 'cookie-dialog' ) }</em>
						{ edit ? (
							<input
								type="text"
								value={ draft.hosts.join( ', ' ) }
								aria-label={ __( 'Hosts', 'cookie-dialog' ) }
								onChange={ ( e ) =>
									change( {
										hosts: e.target.value
											.split( /[\s,]+/ )
											.filter( Boolean ),
									} )
								}
							/>
						) : (
							<span>
								{ draft.hosts.join( ' · ' ) ||
									__(
										'nur über Snippet oder data-cookie-service',
										'cookie-dialog'
									) }
							</span>
						) }
					</div>
					<div className="cd-cl">
						<em>{ __( 'Wo', 'cookie-dialog' ) }</em>
						<span>
							{ found.pages.length
								? found.pages.map( pageName ).join( ', ' )
								: state.scan.at
								? __(
										'bei der letzten Prüfung nicht gefunden',
										'cookie-dialog'
								  )
								: __( 'noch nicht gelesen', 'cookie-dialog' ) }
						</span>
					</div>
				</Card>
			</Section>
			{ embed ? (
				<Section
					title={ __( 'Bis zur Einwilligung', 'cookie-dialog' ) }
					hint={ __(
						'Die Schalter gelten für alle Einbettungen.',
						'cookie-dialog'
					) }
				>
					<Card className="cd-col cd-gap12">
						<div className="cd-phmock">
							<span className="cd-phmock__icon">
								<Icon name="play" size={ 22 } />
							</span>
							<b>
								{ draft.embed?.type === 'map'
									? __(
											'Diese Karte kommt von',
											'cookie-dialog'
									  )
									: __(
											'Dieser Inhalt kommt von',
											'cookie-dialog'
									  ) }{ ' ' }
								{ draft.name }.
							</b>
							<span>
								{ pick( draft.embed?.label, lang ) ||
									__( 'Laden', 'cookie-dialog' ) }{ ' ' }
								· { __( 'Immer laden', 'cookie-dialog' ) }
							</span>
						</div>
						<Line
							label={ __(
								'Vorschaubild und Titel über deinen Server',
								'cookie-dialog'
							) }
						>
							<Switch
								checked={ config.thirdParty.thumbnails }
								label={ __(
									'Vorschaubild und Titel über deinen Server',
									'cookie-dialog'
								) }
								onChange={ async ( thumbnails ) =>
									onState(
										await post( '/config', {
											patch: {
												thirdParty: { thumbnails },
											},
										} )
									)
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
								checked={ config.thirdParty.always }
								label={ __(
									'„Immer laden“ anbieten',
									'cookie-dialog'
								) }
								onChange={ async ( always ) =>
									onState(
										await post( '/config', {
											patch: { thirdParty: { always } },
										} )
									)
								}
							/>
						</Line>
						{ ( draft.preset || draft.id ) === 'youtube' ? (
							<Line
								label={ __(
									'Danach über youtube-nocookie.com',
									'cookie-dialog'
								) }
							>
								<Switch
									checked={ config.thirdParty.nocookie }
									label={ __(
										'Danach über youtube-nocookie.com',
										'cookie-dialog'
									) }
									onChange={ async ( nocookie ) =>
										onState(
											await post( '/config', {
												patch: {
													thirdParty: { nocookie },
												},
											} )
										)
									}
								/>
							</Line>
						) : null }
						{ draft.embed?.type === 'map' ? (
							<Field
								label={ __(
									'Adresse im Platzhalter',
									'cookie-dialog'
								) }
								hint={ __(
									'Leer: aus der Karte gelesen.',
									'cookie-dialog'
								) }
							>
								<input
									type="text"
									value={ draft.embed?.address || '' }
									onChange={ ( e ) =>
										change( {
											embed: {
												...draft.embed,
												address: e.target.value,
											},
										} )
									}
								/>
							</Field>
						) : null }
					</Card>
				</Section>
			) : null }
			<Card pad0>
				<Row
					title={ __( 'Google-Signale', 'cookie-dialog' ) }
					sub={
						draft.signals.join( ' · ' ) ||
						__(
							'keine eigenen – die Kategorie gibt sie',
							'cookie-dialog'
						)
					}
					right={
						<Icon
							name={ open === 'signals' ? 'down' : 'chevron' }
							size={ 16 }
						/>
					}
					onClick={ () =>
						setOpen( open === 'signals' ? null : 'signals' )
					}
				/>
				{ open === 'signals' ? (
					<div className="cd-rowx">
						<div className="cd-row-inline">
							{ SIGNALS.map( ( sig ) => (
								<button
									key={ sig }
									type="button"
									className={ `cd-sig${
										draft.signals.includes( sig )
											? ' is-on'
											: ''
									}` }
									aria-pressed={
										draft.signals.includes( sig )
											? 'true'
											: 'false'
									}
									onClick={ () =>
										change( {
											signals: draft.signals.includes(
												sig
											)
												? draft.signals.filter(
														( x ) => x !== sig
												  )
												: [ ...draft.signals, sig ],
										} )
									}
								>
									{ sig }
								</button>
							) ) }
						</div>
						<p className="cd-fine">
							{ __(
								'Mit der Einwilligung für diesen Dienst gehen diese Signale an Google. Eine ganze Kategorie gibt ihre eigenen dazu (Statistik: analytics_storage, Marketing: die drei ad_*).',
								'cookie-dialog'
							) }
						</p>
					</div>
				) : null }
				<Row
					title={ __( 'Anders einbinden', 'cookie-dialog' ) }
					sub={ __(
						'Snippet, eigenes Skript-Tag, data-cookie-service',
						'cookie-dialog'
					) }
					right={
						<Icon
							name={ open === 'ways' ? 'down' : 'chevron' }
							size={ 16 }
						/>
					}
					onClick={ () => setOpen( open === 'ways' ? null : 'ways' ) }
				/>
				{ open === 'ways' ? (
					<div className="cd-rowx cd-col cd-gap12">
						<Field
							label={ __( 'Snippet', 'cookie-dialog' ) }
							hint={ __(
								'Der Code des Anbieters. Das Plugin druckt ihn zurückgehalten und lädt ihn bei Einwilligung – ohne Neuladen. <noscript> fällt weg.',
								'cookie-dialog'
							) }
						>
							<textarea
								className="cd-code"
								rows={ 6 }
								value={ draft.snippet }
								onChange={ ( e ) =>
									change( { snippet: e.target.value } )
								}
							/>
						</Field>
						<div className="cd-field">
							<span className="cd-field__label">
								{ __(
									'Im Theme oder in einem Plugin',
									'cookie-dialog'
								) }
							</span>
							<code className="cd-codeline">{ `<script type="text/plain" data-cookie-service="${
								draft.id || 'id'
							}">…</script>` }</code>
							<code className="cd-codeline">{ `[cookie_placeholder service="${
								draft.id || 'id'
							}"]…[/cookie_placeholder]` }</code>
						</div>
						<Field
							label={ __(
								'Skript-Handles in WordPress',
								'cookie-dialog'
							) }
							hint={ __(
								'Durch Komma getrennt. Diese Skripte warten auf die Einwilligung.',
								'cookie-dialog'
							) }
						>
							<input
								type="text"
								value={ draft.handles.join( ', ' ) }
								onChange={ ( e ) =>
									change( {
										handles: e.target.value
											.split( /[\s,]+/ )
											.filter( Boolean ),
									} )
								}
							/>
						</Field>
						<Field
							label={ __( 'Muster', 'cookie-dialog' ) }
							hint={ __(
								'Teile von Adressen oder Code, an denen der Dienst erkannt wird.',
								'cookie-dialog'
							) }
						>
							<input
								type="text"
								value={ draft.patterns.join( ', ' ) }
								onChange={ ( e ) =>
									change( {
										patterns: e.target.value
											.split( /\s*,\s*/ )
											.filter( Boolean ),
									} )
								}
							/>
						</Field>
					</div>
				) : null }
			</Card>
			{ error ? <Notice tone="error">{ error }</Notice> : null }
		</Drawer>
	);
}

/* ------------------------------------------------------------------ a host nobody assigned */

function AdoptDrawer( { state, onState, entry, onClose } ) {
	const [ q, setQ ] = useState( entry.suggestion?.name || '' );
	const [ category, setCategory ] = useState(
		entry.suggestion?.category || 'marketing'
	);
	const [ own, setOwn ] = useState( { name: '', provider: '', purpose: '' } );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const needle = q.trim().toLowerCase();
	const matches = state.library
		.filter( ( p ) => ! LOCAL_KINDS.includes( p.kind ) )
		.filter(
			( p ) =>
				! needle ||
				p.name.toLowerCase().includes( needle ) ||
				p.keywords.some( ( k ) => k.includes( needle ) ) ||
				p.provider.toLowerCase().includes( needle )
		)
		.slice( 0, 8 );
	const adopt = async ( args ) => {
		setBusy( true );
		setError( null );
		try {
			onState(
				await post( '/fix', {
					action: 'adopt',
					args: { host: entry.host, category, ...args },
				} )
			);
			onClose();
		} catch ( e ) {
			setError( problem( e ).message );
			setBusy( false );
		}
	};
	return (
		<Drawer title={ entry.host } onClose={ onClose }>
			<Card className="cd-col">
				<div className="cd-cl">
					<em>{ __( 'Wo', 'cookie-dialog' ) }</em>
					<span>{ entry.pages.map( pageName ).join( ', ' ) }</span>
				</div>
				<div className="cd-cl">
					<em>{ __( 'Seit', 'cookie-dialog' ) }</em>
					<span>{ when( entry.first, false ) }</span>
				</div>
				{ entry.urls.slice( 0, 3 ).map( ( u ) => (
					<code key={ u.url } className="cd-codeline">
						{ u.url }
					</code>
				) ) }
			</Card>
			{ entry.suggestion ? (
				<Section title={ __( 'Vorschlag', 'cookie-dialog' ) }>
					<Card className="cd-col">
						<b>{ entry.suggestion.name }</b>
						<span className="cd-fine">
							{ entry.suggestion.purpose }
						</span>
						<div className="cd-row-inline">
							<Button
								kind="primary"
								busy={ busy }
								onClick={ () =>
									adopt(
										entry.suggestion.preset
											? {
													preset: entry.suggestion
														.preset,
											  }
											: {
													name: entry.suggestion.name,
													purpose:
														entry.suggestion
															.purpose,
													provider:
														entry.suggest
															?.provider || '',
											  }
									)
								}
							>
								{ __( 'Übernehmen', 'cookie-dialog' ) }
							</Button>
						</div>
					</Card>
				</Section>
			) : null }
			<Section title={ __( 'Kategorie', 'cookie-dialog' ) }>
				<select
					className="cd-select"
					value={ category }
					onChange={ ( e ) => setCategory( e.target.value ) }
				>
					{ state.config.categories.map( ( c ) => (
						<option key={ c.id } value={ c.id }>
							{ pick( c.name ) }
						</option>
					) ) }
				</select>
			</Section>
			<Section title={ __( 'Aus der Bibliothek', 'cookie-dialog' ) }>
				<input
					type="search"
					className="cd-search"
					value={ q }
					placeholder={ __( 'Dienst suchen', 'cookie-dialog' ) }
					onChange={ ( e ) => setQ( e.target.value ) }
				/>
				<Card pad0>
					{ matches.map( ( p ) => (
						<Row
							key={ p.id }
							lead={ <Logo name={ p.name } /> }
							title={ p.name }
							sub={ p.provider }
							right={
								<Button
									size="sm"
									busy={ busy }
									onClick={ () => adopt( { preset: p.id } ) }
								>
									{ __( 'Zuordnen', 'cookie-dialog' ) }
								</Button>
							}
						/>
					) ) }
				</Card>
			</Section>
			<Section title={ __( 'Eigener Dienst', 'cookie-dialog' ) }>
				<Card className="cd-col cd-gap12">
					<Field label={ __( 'Name', 'cookie-dialog' ) }>
						<input
							type="text"
							value={ own.name }
							onChange={ ( e ) =>
								setOwn( { ...own, name: e.target.value } )
							}
						/>
					</Field>
					<Field label={ __( 'Anbieter', 'cookie-dialog' ) }>
						<input
							type="text"
							value={ own.provider }
							onChange={ ( e ) =>
								setOwn( { ...own, provider: e.target.value } )
							}
						/>
					</Field>
					<Field label={ __( 'Zweck', 'cookie-dialog' ) }>
						<input
							type="text"
							value={ own.purpose }
							onChange={ ( e ) =>
								setOwn( { ...own, purpose: e.target.value } )
							}
						/>
					</Field>
					<div>
						<Button
							disabled={ ! own.name.trim() }
							busy={ busy }
							onClick={ () => adopt( own ) }
						>
							{ __(
								'Als eigenen Dienst anlegen',
								'cookie-dialog'
							) }
						</Button>
					</div>
				</Card>
			</Section>
			{ error ? <Notice tone="error">{ error }</Notice> : null }
		</Drawer>
	);
}

/* ------------------------------------------------------------------ the page */

function readHash() {
	const m = window.location.hash.match( /^#(host|service|new)=?(.*)$/ );
	return m ? { kind: m[ 1 ], value: decodeURIComponent( m[ 2 ] ) } : null;
}

function emptyService() {
	return {
		id: '',
		preset: '',
		name: '',
		category: 'marketing',
		kind: 'script',
		provider: { name: '', address: '' },
		purpose: {},
		privacy: '',
		cookies: [],
		hosts: [],
		patterns: [],
		handles: [],
		strict: false,
		signals: [],
		snippet: '',
		source: 'custom',
	};
}

export default function Services( { state, onState, save } ) {
	const { config } = state;
	const [ q, setQ ] = useState( '' );
	const [ drawer, setDrawer ] = useState( readHash );
	const [ newCat, setNewCat ] = useState( null );
	const [ editCat, setEditCat ] = useState( null );
	const [ showAll, setShowAll ] = useState( false );
	const [ busy, setBusy ] = useState( null );
	const [ message, setMessage ] = useState( null );

	useEffect( () => {
		const onHash = () => setDrawer( readHash() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );
	const openDrawer = ( kind, value = '' ) => {
		window.history.replaceState(
			null,
			'',
			`#${ kind }${ value ? '=' + encodeURIComponent( value ) : '' }`
		);
		setDrawer( { kind, value } );
	};
	const closeDrawer = () => {
		window.history.replaceState(
			null,
			'',
			window.location.pathname + window.location.search
		);
		setDrawer( null );
	};

	const needle = q.trim().toLowerCase();
	const hit = ( s ) =>
		! needle ||
		s.name.toLowerCase().includes( needle ) ||
		s.hosts.some( ( h ) => h.includes( needle ) ) ||
		s.provider.name.toLowerCase().includes( needle );
	const inDialog = config.services.filter(
		( s ) => ! isLocalKind( s ) && hit( s )
	);
	const local = config.services.filter(
		( s ) => isLocalKind( s ) && hit( s )
	);
	const unassigned = state.unassigned.filter(
		( e ) => ! needle || e.host.includes( needle )
	);
	const ownHosts = state.scan.hosts.filter( ( h ) => h.own );
	const added = new Set( config.services.map( ( s ) => s.preset || s.id ) );
	const candidates = state.library
		.filter(
			( p ) =>
				! added.has( p.id ) &&
				! LOCAL_KINDS.includes( p.kind ) &&
				p.kind !== 'core'
		)
		.filter(
			( p ) =>
				! needle ||
				p.name.toLowerCase().includes( needle ) ||
				p.keywords.some( ( k ) => k.includes( needle ) )
		)
		.sort( ( a, b ) => {
			const ia = POPULAR.indexOf( a.id );
			const ib = POPULAR.indexOf( b.id );
			return (
				( ia < 0 ? 99 : ia ) - ( ib < 0 ? 99 : ib ) ||
				a.name.localeCompare( b.name )
			);
		} );
	const chips = showAll || needle ? candidates : candidates.slice( 0, 12 );

	const addPreset = async ( id ) => {
		setBusy( id );
		try {
			const res = await post( '/service', { preset: id } );
			onState( res );
			setMessage( null );
		} catch ( e ) {
			setMessage( problem( e ).message );
		} finally {
			setBusy( null );
		}
	};
	const localize = async ( s ) => {
		setBusy( s.id );
		setMessage( null );
		try {
			const host = state.scan.hosts.find(
				( h ) =>
					h.preset === s.preset ||
					s.hosts.some(
						( x ) => h.host === x || h.host.endsWith( '.' + x )
					)
			);
			if ( ! host ) {
				throw new Error(
					__(
						'Die letzte Prüfung hat keine Datei dieses Dienstes gefunden.',
						'cookie-dialog'
					)
				);
			}
			onState( await post( '/local', { host: host.host } ) );
		} catch ( e ) {
			setMessage( problem( e ).message );
		} finally {
			setBusy( null );
		}
	};
	const saveCategory = ( id, patch ) =>
		save( {
			categories: config.categories.map( ( c ) =>
				c.id === id ? { ...c, ...patch } : c
			),
		} );

	const drawerService =
		drawer?.kind === 'service'
			? config.services.find( ( s ) => s.id === drawer.value )
			: null;
	const drawerHost =
		drawer?.kind === 'host'
			? state.unassigned.find( ( e ) => e.host === drawer.value )
			: null;

	return (
		<>
			<div className="cd-toolbar">
				<div className="cd-searchbox">
					<Icon name="search" size={ 15 } />
					<input
						type="search"
						value={ q }
						placeholder={ __(
							'Dienst oder Host suchen',
							'cookie-dialog'
						) }
						onChange={ ( e ) => setQ( e.target.value ) }
					/>
				</div>
				<span className="cd-row-inline">
					<Button
						size="sm"
						icon="plus"
						onClick={ () =>
							setNewCat( { name: '', description: '' } )
						}
					>
						{ __( 'Kategorie', 'cookie-dialog' ) }
					</Button>
					<Button
						size="sm"
						kind="primary"
						icon="plus"
						onClick={ () => openDrawer( 'new' ) }
					>
						{ __( 'Dienst', 'cookie-dialog' ) }
					</Button>
				</span>
			</div>
			{ message ? <Notice tone="error">{ message }</Notice> : null }
			{ newCat ? (
				<Card className="cd-col cd-gap12">
					<Field
						label={ __( 'Name der Kategorie', 'cookie-dialog' ) }
					>
						<input
							type="text"
							value={ newCat.name }
							onChange={ ( e ) =>
								setNewCat( { ...newCat, name: e.target.value } )
							}
						/>
					</Field>
					<Field label={ __( 'Beschreibung', 'cookie-dialog' ) }>
						<input
							type="text"
							value={ newCat.description }
							onChange={ ( e ) =>
								setNewCat( {
									...newCat,
									description: e.target.value,
								} )
							}
						/>
					</Field>
					<div className="cd-row-inline">
						<Button
							kind="primary"
							size="sm"
							disabled={ ! newCat.name.trim() }
							onClick={ () => {
								const id = newCat.name
									.toLowerCase()
									.normalize( 'NFD' )
									.replace( /[^a-z0-9]+/g, '-' )
									.replace( /^-|-$/g, '' );
								save( {
									categories: [
										...config.categories,
										{
											id,
											enabled: true,
											name: { de: newCat.name },
											description: {
												de: newCat.description,
											},
										},
									],
								} );
								setNewCat( null );
							} }
						>
							{ __( 'Anlegen', 'cookie-dialog' ) }
						</Button>
						<Button
							kind="ghost"
							size="sm"
							onClick={ () => setNewCat( null ) }
						>
							{ __( 'Abbrechen', 'cookie-dialog' ) }
						</Button>
					</div>
				</Card>
			) : null }
			{ unassigned.length ? (
				<Section
					title={
						<>
							{ __( 'Zu prüfen', 'cookie-dialog' ) }{ ' ' }
							<Chip tone="amber">{ unassigned.length }</Chip>
						</>
					}
					hint={ __(
						'Neu auf deiner Website und noch nicht im Dialog – lädt bis dahin ohne Einwilligung.',
						'cookie-dialog'
					) }
				>
					<Card pad0>
						{ unassigned.map( ( e ) => (
							<Row
								key={ e.host }
								lead={ <Logo name="?" /> }
								title={ e.host }
								sub={ [
									e.pages.map( pageName ).join( ', ' ),
									e.new
										? sprintf(
												/* translators: %s: date */ __(
													'seit %s',
													'cookie-dialog'
												),
												when( e.first, false )
										  )
										: null,
									e.suggestion
										? sprintf(
												/* translators: 1: service, 2: purpose, 3: category */
												__(
													'Vorschlag: %1$s, %2$s → %3$s',
													'cookie-dialog'
												),
												e.suggestion.name,
												e.suggestion.purpose,
												pick(
													config.categories.find(
														( c ) =>
															c.id ===
															e.suggestion
																.category
													)?.name
												)
										  )
										: null,
								]
									.filter( Boolean )
									.join( ' · ' ) }
								right={
									<>
										{ e.suggestion ? (
											<Button
												size="sm"
												busy={ busy === e.host }
												onClick={ async () => {
													setBusy( e.host );
													try {
														onState(
															await post(
																'/fix',
																{
																	action: 'adopt',
																	args: {
																		host: e.host,
																	},
																}
															)
														);
													} catch ( err ) {
														setMessage(
															problem( err )
																.message
														);
													} finally {
														setBusy( null );
													}
												} }
											>
												{ __(
													'Übernehmen',
													'cookie-dialog'
												) }
											</Button>
										) : null }
										<Button
											size="sm"
											kind="ghost"
											onClick={ () =>
												openDrawer( 'host', e.host )
											}
										>
											{ e.suggestion
												? __(
														'Ändern',
														'cookie-dialog'
												  )
												: __(
														'Zuordnen',
														'cookie-dialog'
												  ) }
										</Button>
									</>
								}
							/>
						) ) }
					</Card>
				</Section>
			) : null }
			{ config.categories.map( ( cat ) => {
				const list = inDialog.filter( ( s ) => s.category === cat.id );
				if ( ! list.length && needle ) {
					return null;
				}
				const editing = editCat === cat.id;
				return (
					<Section
						key={ cat.id }
						title={ pick( cat.name ) }
						hint={
							cat.id === 'necessary'
								? __(
										'Ohne sie funktioniert die Seite nicht. Immer aktiv.',
										'cookie-dialog'
								  )
								: pick( cat.description )
						}
						aside={
							<span className="cd-row-inline">
								<Button
									kind="ghost"
									size="sm"
									onClick={ () =>
										setEditCat( editing ? null : cat.id )
									}
								>
									{ editing
										? __( 'Fertig', 'cookie-dialog' )
										: __( 'Bearbeiten', 'cookie-dialog' ) }
								</Button>
								{ cat.locked ? null : (
									<Switch
										checked={ cat.enabled }
										label={ sprintf(
											/* translators: %s: category */ __(
												'%s im Dialog anbieten',
												'cookie-dialog'
											),
											pick( cat.name )
										) }
										onChange={ ( enabled ) =>
											saveCategory( cat.id, { enabled } )
										}
									/>
								) }
							</span>
						}
					>
						{ editing ? (
							<CategoryEdit
								cat={ cat }
								languages={ config.languages }
								empty={
									! config.services.some(
										( s ) => s.category === cat.id
									)
								}
								onSave={ ( patch ) =>
									saveCategory( cat.id, patch )
								}
								onMove={
									cat.locked
										? null
										: ( step ) => {
												const order = [
													...config.categories,
												];
												const i = order.findIndex(
													( c ) => c.id === cat.id
												);
												const j = i + step;
												if (
													j < 1 ||
													j >= order.length
												) {
													return;
												}
												[ order[ i ], order[ j ] ] = [
													order[ j ],
													order[ i ],
												];
												save( { categories: order } );
										  }
								}
								onRemove={ () => {
									setEditCat( null );
									save( {
										categories: config.categories.filter(
											( c ) => c.id !== cat.id
										),
									} );
								} }
							/>
						) : null }
						{ ! cat.enabled ? (
							<Notice>
								{ __(
									'Nicht im Dialog: Diese Dienste laden nie.',
									'cookie-dialog'
								) }
							</Notice>
						) : null }
						<Card pad0>
							{ list.length ? (
								list.map( ( s ) => {
									const found = whereFound( state, s );
									return (
										<Row
											key={ s.id }
											lead={ <Logo name={ s.name } /> }
											title={ s.name }
											sub={ [
												found.pages.length
													? found.pages
															.slice( 0, 3 )
															.map( pageName )
															.join( ', ' )
													: null,
												s.hosts[ 0 ],
												s.cookies.length
													? sprintf(
															/* translators: %d: cookies */ _n(
																'%d Cookie',
																'%d Cookies',
																s.cookies
																	.length,
																'cookie-dialog'
															),
															s.cookies.length
													  )
													: __(
															'kein Cookie',
															'cookie-dialog'
													  ),
												s.signals.length
													? s.signals.join( ', ' )
													: null,
											]
												.filter( Boolean )
												.join( ' · ' ) }
											right={
												<>
													<Held
														service={ s }
														config={ config }
													/>
													<Icon
														name="chevron"
														size={ 16 }
													/>
												</>
											}
											onClick={ () =>
												openDrawer( 'service', s.id )
											}
										/>
									);
								} )
							) : (
								<Row
									title={ __(
										'Noch kein Dienst',
										'cookie-dialog'
									) }
									sub={ __(
										'Unten aus der Bibliothek hinzufügen.',
										'cookie-dialog'
									) }
								/>
							) }
						</Card>
					</Section>
				);
			} ) }
			{ local.length || ownHosts.length ? (
				<Section
					title={ __(
						'Von deinem Server statt von Dritten',
						'cookie-dialog'
					) }
					hint={ __(
						'Keine Einwilligung nötig – die Dateien liegen bei dir und werden wöchentlich geprüft.',
						'cookie-dialog'
					) }
				>
					<Card pad0>
						{ local.map( ( s ) => {
							const avatar = s.kind === 'avatar';
							const emoji = s.preset === 'wp-emoji';
							const proxied =
								avatar &&
								config.thirdParty.gravatar === 'proxy';
							const off = emoji && ! config.thirdParty.emoji;
							let right;
							if ( s.local || proxied ) {
								right = (
									<span className="cd-held is-moss">
										<Icon name="check" size={ 11 } />{ ' ' }
										{ proxied
											? __(
													'über den Server',
													'cookie-dialog'
											  )
											: __( 'lokal', 'cookie-dialog' ) }
									</span>
								);
							} else if ( off ) {
								right = (
									<span className="cd-held is-moss">
										<Icon name="check" size={ 11 } />{ ' ' }
										{ __( 'aus', 'cookie-dialog' ) }
									</span>
								);
							} else if ( avatar ) {
								right = (
									<Button
										size="sm"
										onClick={ () =>
											save( {
												thirdParty: {
													gravatar: 'proxy',
												},
											} )
										}
									>
										{ __(
											'Über den Server',
											'cookie-dialog'
										) }
									</Button>
								);
							} else if ( emoji ) {
								right = (
									<Button
										size="sm"
										onClick={ () =>
											save( {
												thirdParty: { emoji: false },
											} )
										}
									>
										{ __( 'Ausschalten', 'cookie-dialog' ) }
									</Button>
								);
							} else {
								right = (
									<Button
										size="sm"
										busy={ busy === s.id }
										onClick={ () => localize( s ) }
									>
										{ __(
											'Lokal einbinden',
											'cookie-dialog'
										) }
									</Button>
								);
							}
							const sub = {
								font: __( 'Schriften', 'cookie-dialog' ),
								style: __( 'Stylesheets', 'cookie-dialog' ),
								library: __(
									'Skript-Bibliothek – jetzt notwendig, Host in der Tabelle genannt',
									'cookie-dialog'
								),
								image: emoji
									? __(
											'Emoji-Skript von WordPress',
											'cookie-dialog'
									  )
									: __( 'Bilder', 'cookie-dialog' ),
								avatar: __(
									'Avatare in Kommentaren',
									'cookie-dialog'
								),
							}[ s.kind ];
							return (
								<Row
									key={ s.id }
									lead={ <Logo name={ s.name } /> }
									title={ s.name }
									sub={ `${ sub } · ${
										s.local
											? __( 'vorher', 'cookie-dialog' ) +
											  ' '
											: ''
									}${ s.hosts[ 0 ] || '' }` }
									right={ right }
								/>
							);
						} ) }
						{ ownHosts.map( ( h ) => (
							<Row
								key={ h.host }
								lead={ <Logo name={ h.host } /> }
								title={ h.host }
								sub={ __(
									'Deine eigene Domain – etwa ein CDN. Als Auftragsverarbeiter notwendig.',
									'cookie-dialog'
								) }
								right={
									<span className="cd-held is-lock">
										{ __(
											'eigene Domain',
											'cookie-dialog'
										) }
									</span>
								}
							/>
						) ) }
					</Card>
				</Section>
			) : null }
			<Section
				title={ __( 'Hinzufügen', 'cookie-dialog' ) }
				hint={ __(
					'Aus der Bibliothek: Anbieter, Zweck, Cookies und Texte sind schon da.',
					'cookie-dialog'
				) }
			>
				<div className="cd-qadd">
					{ chips.map( ( p ) => (
						<button
							key={ p.id }
							type="button"
							disabled={ busy === p.id }
							onClick={ () => addPreset( p.id ) }
						>
							<Icon name="plus" size={ 13 } /> { p.name }
						</button>
					) ) }
					{ ! showAll &&
					! needle &&
					candidates.length > chips.length ? (
						<button
							type="button"
							className="is-quiet"
							onClick={ () => setShowAll( true ) }
						>
							{ sprintf(
								/* translators: %d: more services */
								_n(
									'%d weiterer',
									'%d weitere',
									candidates.length - chips.length,
									'cookie-dialog'
								),
								candidates.length - chips.length
							) }
						</button>
					) : null }
					<button type="button" onClick={ () => openDrawer( 'new' ) }>
						<Icon name="plus" size={ 13 } />{ ' ' }
						{ __( 'Eigener Dienst', 'cookie-dialog' ) }
					</button>
				</div>
			</Section>
			{ drawerService ? (
				<ServiceDrawer
					key={ drawerService.id }
					state={ state }
					onState={ onState }
					initial={ drawerService }
					onClose={ closeDrawer }
				/>
			) : null }
			{ drawer?.kind === 'new' ? (
				<ServiceDrawer
					key="new"
					state={ state }
					onState={ onState }
					initial={ emptyService() }
					onClose={ closeDrawer }
				/>
			) : null }
			{ drawerHost ? (
				<AdoptDrawer
					state={ state }
					onState={ onState }
					entry={ drawerHost }
					onClose={ closeDrawer }
				/>
			) : null }
			<Dock />
		</>
	);
}

function CategoryEdit( { cat, languages, empty, onSave, onRemove, onMove } ) {
	const [ lang, setLang ] = useState( languages[ 0 ] );
	const [ name, setName ] = useState( cat.name?.[ lang ] || '' );
	const [ desc, setDesc ] = useState( cat.description?.[ lang ] || '' );
	useEffect( () => {
		setName( cat.name?.[ lang ] || '' );
		setDesc( cat.description?.[ lang ] || '' );
	}, [ lang, cat ] );
	return (
		<Card className="cd-col cd-gap12">
			{ languages.length > 1 ? (
				<div className="cd-row-inline">
					{ languages.map( ( l ) => (
						<button
							key={ l }
							type="button"
							className={ `cd-tab${
								l === lang ? ' is-on' : ''
							}` }
							onClick={ () => setLang( l ) }
						>
							{ l.toUpperCase() }
						</button>
					) ) }
				</div>
			) : null }
			<Field label={ __( 'Name', 'cookie-dialog' ) }>
				<input
					type="text"
					value={ name }
					onChange={ ( e ) => setName( e.target.value ) }
					onBlur={ () =>
						onSave( { name: { ...cat.name, [ lang ]: name } } )
					}
				/>
			</Field>
			<Field label={ __( 'Beschreibung im Dialog', 'cookie-dialog' ) }>
				<input
					type="text"
					value={ desc }
					onChange={ ( e ) => setDesc( e.target.value ) }
					onBlur={ () =>
						onSave( {
							description: { ...cat.description, [ lang ]: desc },
						} )
					}
				/>
			</Field>
			{ onMove ? (
				<div className="cd-row-inline">
					<Button
						kind="ghost"
						size="sm"
						icon="up"
						onClick={ () => onMove( -1 ) }
					>
						{ __( 'Nach oben', 'cookie-dialog' ) }
					</Button>
					<Button
						kind="ghost"
						size="sm"
						icon="down"
						onClick={ () => onMove( 1 ) }
					>
						{ __( 'Nach unten', 'cookie-dialog' ) }
					</Button>
				</div>
			) : null }
			{ ! cat.locked &&
			empty &&
			! [ 'functional', 'statistics', 'marketing' ].includes( cat.id ) ? (
				<div>
					<Button kind="danger" size="sm" onClick={ onRemove }>
						{ __( 'Kategorie löschen', 'cookie-dialog' ) }
					</Button>
				</div>
			) : null }
		</Card>
	);
}
