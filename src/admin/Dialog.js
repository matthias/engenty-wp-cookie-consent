import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { pageUrl, problem } from './api';
import { Dock, runJob, useBrowserModel } from './Assistant';
import Preview from './Preview';
import {
	Button,
	Card,
	Chip,
	Field,
	Icon,
	LANGUAGES,
	Line,
	Notice,
	Section,
	Seg,
	Switch,
} from './ui';

const COLORS = [
	[ 'background', __( 'Hintergrund', 'cookie-dialog' ) ],
	[ 'text', __( 'Text', 'cookie-dialog' ) ],
	[ 'button', __( 'Button', 'cookie-dialog' ) ],
	[ 'buttonText', __( 'Button-Text', 'cookie-dialog' ) ],
	[ 'link', __( 'Links', 'cookie-dialog' ) ],
];

const TEXT_KEYS = [
	[ 'title', __( 'Titel', 'cookie-dialog' ) ],
	[ 'text', __( 'Text', 'cookie-dialog' ) ],
	[ 'accept', __( 'Alle akzeptieren', 'cookie-dialog' ) ],
	[ 'reject', __( 'Nur notwendige', 'cookie-dialog' ) ],
	[ 'settings', __( 'Einstellungen', 'cookie-dialog' ) ],
	[ 'layerTitle', __( 'Titel der Auswahl', 'cookie-dialog' ) ],
	[ 'layerText', __( 'Text der Auswahl', 'cookie-dialog' ) ],
	[ 'save', __( 'Auswahl speichern', 'cookie-dialog' ) ],
	[ 'privacy', __( 'Link Datenschutz', 'cookie-dialog' ) ],
	[ 'imprint', __( 'Link Impressum', 'cookie-dialog' ) ],
	[ 'reopen', __( 'Button zum Öffnen', 'cookie-dialog' ) ],
	[ 'phLoad', __( 'Platzhalter: Laden', 'cookie-dialog' ) ],
	[ 'phAlways', __( 'Platzhalter: Immer laden', 'cookie-dialog' ) ],
];

/**
 * WCAG contrast of two hex colours.
 * @param hex
 */
function luminance( hex ) {
	const h = hex.replace( '#', '' );
	const full = h.length === 3 ? h.replace( /(.)/g, '$1$1' ) : h;
	const c = [ 0, 2, 4 ].map( ( i ) => {
		const v = parseInt( full.slice( i, i + 2 ), 16 ) / 255;
		return v <= 0.03928 ? v / 12.92 : ( ( v + 0.055 ) / 1.055 ) ** 2.4;
	} );
	return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
}
export function contrast( a, b ) {
	const [ x, y ] = [ luminance( a ), luminance( b ) ];
	return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
}

function LayoutThumb( { value, current, label, onPick } ) {
	return (
		<button
			type="button"
			className={ `cd-opt${ value === current ? ' is-on' : '' }` }
			aria-pressed={ value === current ? 'true' : 'false' }
			onClick={ () => onPick( value ) }
		>
			<span className={ `cd-opt__pv cd-opt__pv--${ value }` }>
				<i />
			</span>
			{ label }
		</button>
	);
}

function Swatches( { colors, onChange } ) {
	return (
		<div className="cd-swatches">
			{ COLORS.map( ( [ key, label ] ) => (
				<span key={ key } className="cd-swatch" title={ label }>
					<input
						type="color"
						value={ colors[ key ] }
						aria-label={ label }
						onChange={ ( e ) =>
							onChange( { ...colors, [ key ]: e.target.value } )
						}
					/>
					<span style={ { background: colors[ key ] } } />
				</span>
			) ) }
		</div>
	);
}

function pickLogo( onPick ) {
	const media = window.wp?.media;
	if ( ! media ) {
		return;
	}
	const frame = media( {
		title: __( 'Logo im Dialog', 'cookie-dialog' ),
		multiple: false,
		library: { type: 'image' },
	} );
	frame.on( 'select', () => {
		const file = frame.state().get( 'selection' ).first().toJSON();
		onPick( file.sizes?.medium?.url || file.url );
	} );
	frame.open();
}

function Looks( { state, design, setDesign } ) {
	const [ more, setMore ] = useState( false );
	const c = design.colors;
	const ratio = Math.min(
		contrast( c.buttonText, c.button ),
		contrast( c.text, c.background )
	);
	const theme = state.scan.theme?.colors || {};
	const set = ( patch ) => setDesign( { ...design, ...patch } );
	return (
		<Card className="cd-col cd-gap16">
			<div className="cd-grid3">
				<LayoutThumb
					value="box"
					current={ design.layout }
					label={ __( 'Box', 'cookie-dialog' ) }
					onPick={ ( layout ) => set( { layout } ) }
				/>
				<LayoutThumb
					value="bar"
					current={ design.layout }
					label={ __( 'Leiste', 'cookie-dialog' ) }
					onPick={ ( layout ) => set( { layout } ) }
				/>
				<LayoutThumb
					value="modal"
					current={ design.layout }
					label={ __( 'Fenster', 'cookie-dialog' ) }
					onPick={ ( layout ) => set( { layout } ) }
				/>
			</div>
			{ design.layout === 'box' ? (
				<Line label={ __( 'Position', 'cookie-dialog' ) }>
					<Seg
						value={ design.position }
						onChange={ ( position ) => set( { position } ) }
						options={ [
							{
								value: 'left',
								label: __( 'Links', 'cookie-dialog' ),
							},
							{
								value: 'center',
								label: __( 'Mitte', 'cookie-dialog' ),
							},
							{
								value: 'right',
								label: __( 'Rechts', 'cookie-dialog' ),
							},
						] }
					/>
				</Line>
			) : null }
			<Line label={ __( 'Größe', 'cookie-dialog' ) }>
				<Seg
					value={ design.size }
					onChange={ ( size ) => set( { size } ) }
					options={ [
						{ value: 's', label: 'S' },
						{ value: 'm', label: 'M' },
						{ value: 'l', label: 'L' },
					] }
				/>
			</Line>
			<div className="cd-col">
				<div className="cd-line">
					<span>{ __( 'Farben', 'cookie-dialog' ) }</span>
					<Chip
						tone={ ratio >= 4.5 ? 'moss' : 'amber' }
						icon={ ratio >= 4.5 ? 'check' : 'warn' }
					>
						{ sprintf(
							/* translators: %s: contrast ratio */
							__( 'Kontrast %s : 1', 'cookie-dialog' ),
							ratio.toFixed( 1 ).replace( '.', ',' )
						) }
					</Chip>
				</div>
				<div className="cd-row-inline">
					<Swatches
						colors={ c }
						onChange={ ( colors ) => set( { colors } ) }
					/>
					{ theme.button || theme.link ? (
						<Button
							kind="ghost"
							size="sm"
							onClick={ () =>
								set( {
									colors: {
										...c,
										button:
											theme.button ||
											theme.link ||
											c.button,
										link:
											theme.link ||
											theme.button ||
											c.link,
										text: theme.text || c.text,
									},
								} )
							}
						>
							{ __( 'Vom Theme', 'cookie-dialog' ) }
						</Button>
					) : null }
				</div>
				{ design.scheme !== 'light' ? (
					<>
						<span className="cd-fine">
							{ __( 'Dunkel', 'cookie-dialog' ) }
						</span>
						<Swatches
							colors={ design.dark }
							onChange={ ( dark ) => set( { dark } ) }
						/>
					</>
				) : null }
			</div>
			<Line label={ __( 'Ecken', 'cookie-dialog' ) }>
				<input
					type="range"
					min="0"
					max="32"
					value={ design.radius }
					aria-label={ __( 'Ecken', 'cookie-dialog' ) }
					onChange={ ( e ) =>
						set( { radius: Number( e.target.value ) } )
					}
					className="cd-range"
				/>
			</Line>
			<Line label={ __( 'Hell / dunkel', 'cookie-dialog' ) }>
				<Seg
					value={ design.scheme }
					onChange={ ( scheme ) => set( { scheme } ) }
					options={ [
						{
							value: 'auto',
							label: __( 'Wie das Gerät', 'cookie-dialog' ),
						},
						{
							value: 'light',
							label: __( 'Hell', 'cookie-dialog' ),
						},
						{
							value: 'dark',
							label: __( 'Dunkel', 'cookie-dialog' ),
						},
					] }
				/>
			</Line>
			<button
				type="button"
				className="cd-more"
				aria-expanded={ more ? 'true' : 'false' }
				onClick={ () => setMore( ! more ) }
			>
				<span>
					{ __(
						'Schrift, Logo, Abdunkeln, Seite sperren',
						'cookie-dialog'
					) }
				</span>
				<Icon name={ more ? 'down' : 'chevron' } size={ 16 } />
			</button>
			{ more ? (
				<div className="cd-col cd-gap16">
					<Line label={ __( 'Schrift', 'cookie-dialog' ) }>
						<Seg
							value={ design.font }
							onChange={ ( font ) => set( { font } ) }
							options={ [
								{
									value: 'theme',
									label: __( 'Theme', 'cookie-dialog' ),
								},
								{
									value: 'system',
									label: __( 'System', 'cookie-dialog' ),
								},
								{
									value: 'custom',
									label: __( 'Eigene', 'cookie-dialog' ),
								},
							] }
						/>
					</Line>
					{ design.font === 'custom' ? (
						<Field
							label={ __( 'Schriftart (CSS)', 'cookie-dialog' ) }
						>
							<input
								type="text"
								value={ design.fontFamily }
								placeholder="Inter, sans-serif"
								onChange={ ( e ) =>
									set( { fontFamily: e.target.value } )
								}
							/>
						</Field>
					) : null }
					<Field
						label={ __(
							'Schrift der Überschrift',
							'cookie-dialog'
						) }
						hint={ __( 'Leer: wie der Text.', 'cookie-dialog' ) }
					>
						<input
							type="text"
							value={ design.headFont }
							placeholder="Fraunces, serif"
							onChange={ ( e ) =>
								set( { headFont: e.target.value } )
							}
						/>
					</Field>
					<Line label={ __( 'Logo', 'cookie-dialog' ) }>
						<span className="cd-row-inline">
							{ design.logo ? (
								<img
									className="cd-logo-thumb"
									src={ design.logo }
									alt=""
								/>
							) : null }
							<Button
								size="sm"
								onClick={ () =>
									pickLogo( ( logo ) => set( { logo } ) )
								}
							>
								{ design.logo
									? __( 'Ändern', 'cookie-dialog' )
									: __( 'Wählen', 'cookie-dialog' ) }
							</Button>
							{ design.logo ? (
								<Button
									size="sm"
									kind="ghost"
									onClick={ () => set( { logo: '' } ) }
								>
									{ __( 'Entfernen', 'cookie-dialog' ) }
								</Button>
							) : null }
						</span>
					</Line>
					<Line label={ __( 'Seite abdunkeln', 'cookie-dialog' ) }>
						<Switch
							checked={ design.backdrop }
							label={ __( 'Seite abdunkeln', 'cookie-dialog' ) }
							onChange={ ( backdrop ) => set( { backdrop } ) }
						/>
					</Line>
					<Line
						label={ __( 'Seite sperren', 'cookie-dialog' ) }
						hint={ __(
							'Scrollen erst nach der Wahl – nur wenn es sein muss.',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ design.lock }
							label={ __( 'Seite sperren', 'cookie-dialog' ) }
							onChange={ ( lock ) => set( { lock } ) }
						/>
					</Line>
					<Line
						label={ __(
							'„Cookie Dialog“ im Dialog nennen',
							'cookie-dialog'
						) }
					>
						<Switch
							checked={ design.branding }
							label={ __(
								'„Cookie Dialog“ im Dialog nennen',
								'cookie-dialog'
							) }
							onChange={ ( branding ) => set( { branding } ) }
						/>
					</Line>
				</div>
			) : null }
		</Card>
	);
}

function Texts( { state, onState, save } ) {
	const { config } = state;
	const browser = useBrowserModel();
	const [ tone, setTone ] = useState( false );
	const [ all, setAll ] = useState( false );
	const [ lang, setLang ] = useState( config.languages[ 0 ] );
	const [ busy, setBusy ] = useState( null );
	const [ message, setMessage ] = useState( null );
	const [ adding, setAdding ] = useState( false );
	const job = async ( name, input ) => {
		setBusy( name );
		setMessage( null );
		try {
			const res = await runJob( name, input, onState );
			setMessage( { tone: 'success', text: res?.message } );
		} catch ( e ) {
			setMessage( { tone: 'error', text: problem( e ).message } );
		} finally {
			setBusy( null );
		}
	};
	const simple =
		state.assistant.ready ||
		( state.assistant.browser && browser && browser !== 'unavailable' );
	const texts = state.texts[ lang ] || {};
	const addLanguage = ( code ) => {
		setAdding( false );
		save( { languages: [ ...config.languages, code ] } ).then( () => {
			if ( code !== 'de' && code !== 'en' && simple ) {
				job( 'translate', { lang: code } );
			}
		} );
	};
	const others = [
		...new Set( [ ...state.siteLanguages, ...Object.keys( LANGUAGES ) ] ),
	].filter( ( l ) => ! config.languages.includes( l ) );
	return (
		<Card className="cd-col cd-gap12">
			<div className="cd-line">
				<span>{ __( 'Ton', 'cookie-dialog' ) }</span>
				<span className="cd-row-inline">
					{ config.tone.style ? (
						<Chip>{ config.tone.style }</Chip>
					) : null }
					<Chip>
						{ config.tone.address === 'Sie'
							? __( 'per Sie', 'cookie-dialog' )
							: __( 'per du', 'cookie-dialog' ) }
					</Chip>
					<Button
						kind="ghost"
						size="sm"
						onClick={ () => setTone( ! tone ) }
					>
						{ __( 'Ändern', 'cookie-dialog' ) }
					</Button>
				</span>
			</div>
			{ tone ? (
				<div className="cd-inset cd-col">
					<Line label={ __( 'Anrede (Deutsch)', 'cookie-dialog' ) }>
						<Seg
							value={ config.tone.address }
							onChange={ ( address ) =>
								save(
									{ tone: { address } },
									__( 'Anrede geändert', 'cookie-dialog' )
								)
							}
							options={ [
								{ value: 'du', label: 'du' },
								{ value: 'Sie', label: 'Sie' },
							] }
						/>
					</Line>
					<div className="cd-row-inline">
						<Button
							size="sm"
							busy={ busy === 'shorten' }
							disabled={ ! simple }
							onClick={ () => job( 'shorten', { lang } ) }
						>
							{ __( 'Kürzer', 'cookie-dialog' ) }
						</Button>
						<Button
							size="sm"
							busy={ busy === 'setup' }
							disabled={ ! state.assistant.ready }
							onClick={ () => job( 'setup', {} ) }
						>
							{ __( 'Im Ton meiner Website', 'cookie-dialog' ) }
						</Button>
					</div>
					{ ! state.assistant.ready ? (
						<span className="cd-fine">
							{ simple
								? __(
										'„Kürzer“ macht dein Browser. Für den Ton deiner Website braucht WordPress einen KI-Anbieter.',
										'cookie-dialog'
								  )
								: __(
										'Dafür braucht WordPress einen KI-Anbieter – oder Chrome mit eingebautem Modell für „Kürzer“.',
										'cookie-dialog'
								  ) }
						</span>
					) : null }
				</div>
			) : null }
			<div className="cd-line">
				<span>{ __( 'Sprachen', 'cookie-dialog' ) }</span>
				<span className="cd-row-inline">
					{ config.languages.map( ( l ) => (
						<span
							key={ l }
							className={ `cd-chip${
								l === lang ? ' cd-chip--ember' : ''
							}` }
						>
							<button
								type="button"
								className="cd-chip__btn"
								onClick={ () => setLang( l ) }
							>
								{ LANGUAGES[ l ] || l.toUpperCase() }
							</button>
							{ config.languages.length > 1 ? (
								<button
									type="button"
									className="cd-chip__x"
									aria-label={ sprintf(
										/* translators: %s: language */ __(
											'%s entfernen',
											'cookie-dialog'
										),
										LANGUAGES[ l ] || l
									) }
									onClick={ () =>
										save( {
											languages: config.languages.filter(
												( x ) => x !== l
											),
										} )
									}
								>
									×
								</button>
							) : null }
						</span>
					) ) }
					<Button
						kind="ghost"
						size="sm"
						aria-label={ __(
							'Sprache hinzufügen',
							'cookie-dialog'
						) }
						onClick={ () => setAdding( ! adding ) }
					>
						<Icon name="plus" size={ 13 } />
					</Button>
				</span>
			</div>
			{ adding ? (
				<select
					className="cd-select"
					defaultValue=""
					aria-label={ __( 'Sprache hinzufügen', 'cookie-dialog' ) }
					onChange={ ( e ) =>
						e.target.value && addLanguage( e.target.value )
					}
				>
					<option value="">
						{ __( '– Sprache wählen –', 'cookie-dialog' ) }
					</option>
					{ others.map( ( l ) => (
						<option key={ l } value={ l }>
							{ LANGUAGES[ l ] || l.toUpperCase() }
							{ state.siteLanguages.includes( l )
								? ` · ${ __(
										'auf deiner Website',
										'cookie-dialog'
								  ) }`
								: '' }
						</option>
					) ) }
				</select>
			) : null }
			{ ! [ 'de', 'en' ].includes( lang ) ? (
				<div className="cd-row-inline">
					<Button
						size="sm"
						busy={ busy === 'translate' }
						disabled={ ! simple }
						onClick={ () => job( 'translate', { lang } ) }
					>
						{ sprintf(
							/* translators: %s: language */ __(
								'Auf %s übersetzen',
								'cookie-dialog'
							),
							LANGUAGES[ lang ] || lang
						) }
					</Button>
				</div>
			) : null }
			<div className="cd-line">
				<span>{ __( 'Links', 'cookie-dialog' ) }</span>
				<a className="cd-fine" href={ pageUrl( 'settings', '#pages' ) }>
					{ [ state.pages.privacy?.title, state.pages.imprint?.title ]
						.filter( Boolean )
						.join( ' · ' ) ||
						__( 'Seiten wählen', 'cookie-dialog' ) }
				</a>
			</div>
			<button
				type="button"
				className="cd-more"
				aria-expanded={ all ? 'true' : 'false' }
				onClick={ () => setAll( ! all ) }
			>
				<span>{ __( 'Alle Texte als Liste', 'cookie-dialog' ) }</span>
				<Icon name={ all ? 'down' : 'chevron' } size={ 16 } />
			</button>
			{ all ? (
				<div className="cd-col cd-gap12">
					{ TEXT_KEYS.map( ( [ key, label ] ) => (
						<TextField
							key={ `${ lang }-${ key }` }
							label={ label }
							value={ texts[ key ] || '' }
							long={ key === 'text' || key === 'layerText' }
							onSave={ ( value ) =>
								save(
									{ texts: { [ lang ]: { [ key ]: value } } },
									__( 'Texte geändert', 'cookie-dialog' )
								)
							}
						/>
					) ) }
				</div>
			) : null }
			{ message ? (
				<Notice tone={ message.tone }>{ message.text }</Notice>
			) : null }
		</Card>
	);
}

function TextField( { label, value, long, onSave } ) {
	const [ draft, setDraft ] = useState( value );
	useEffect( () => setDraft( value ), [ value ] );
	const commit = () => draft !== value && onSave( draft );
	return (
		<Field label={ label }>
			{ long ? (
				<textarea
					rows={ 3 }
					value={ draft }
					onChange={ ( e ) => setDraft( e.target.value ) }
					onBlur={ commit }
				/>
			) : (
				<input
					type="text"
					value={ draft }
					onChange={ ( e ) => setDraft( e.target.value ) }
					onBlur={ commit }
				/>
			) }
		</Field>
	);
}

export default function Dialog( { state, onState, save } ) {
	const [ design, setDesignState ] = useState( state.config.design );
	const timer = useRef();
	useEffect(
		() => setDesignState( state.config.design ),
		[ state.config.design ]
	);
	const setDesign = useCallback(
		( next ) => {
			setDesignState( next );
			window.clearTimeout( timer.current );
			timer.current = window.setTimeout(
				() => save( { design: next } ),
				500
			);
		},
		[ save ]
	);
	const onText = useCallback(
		( key, value, lang ) =>
			save(
				{ texts: { [ lang ]: { [ key ]: value } } },
				__( 'Texte geändert', 'cookie-dialog' )
			),
		[ save ]
	);
	const c = state.config;
	return (
		<>
			<div className="cd-split cd-split--dialog">
				<div className="cd-split__levers">
					<Section
						title={ __( 'Aussehen', 'cookie-dialog' ) }
						hint={ __(
							'Aus deinem Theme. Ändere nur, was nicht passt.',
							'cookie-dialog'
						) }
					>
						<Looks
							state={ state }
							design={ design }
							setDesign={ setDesign }
						/>
					</Section>
					<Section
						title={ __( 'Texte', 'cookie-dialog' ) }
						hint={ __(
							'Klick auf einen Text in der Vorschau, um ihn zu ändern – oder sag dem Assistenten, wie er klingen soll.',
							'cookie-dialog'
						) }
					>
						<Texts
							state={ state }
							onState={ onState }
							save={ save }
						/>
					</Section>
				</div>
				<div className="cd-split__preview">
					<Card className="cd-card--preview">
						<Preview
							state={ state }
							layers={ [ '1', '2', 'ph' ] }
							edit
							design={ design }
							texts={ state.texts }
							onText={ onText }
							reloadKey={ `${ c.services.length }-${ c.categories.length }-${ c.tone.address }` }
						/>
						<p className="cd-fine">
							{ c.live
								? sprintf(
										/* translators: %d: next version */
										__(
											'Gespeichert, sobald du etwas änderst. Neue Texte werden Version %d – Besucher werden einmal neu gefragt.',
											'cookie-dialog'
										),
										c.version + 1
								  )
								: __(
										'Gespeichert, sobald du etwas änderst. Auf der Website erscheint es, wenn du den Dialog einschaltest.',
										'cookie-dialog'
								  ) }
						</p>
					</Card>
				</div>
			</div>
			<Dock />
		</>
	);
}
