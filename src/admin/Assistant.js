import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { boot, post, problem } from './api';
import { Button, Engenty, Icon, Notice, Spinner } from './ui';

/* ------------------------------------------------------------------ the browser's model */

/**
 * Whether the admin's browser has a built-in model (Chrome 148+, Edge): available, downloadable,
 * downloading or unavailable. Asked once per visit.
 */
let browserState = null;
export async function browserModel() {
	if ( browserState ) {
		return browserState;
	}
	const LM = window.LanguageModel;
	if ( ! LM || typeof LM.availability !== 'function' ) {
		browserState = 'unavailable';
		return browserState;
	}
	try {
		browserState = await LM.availability( {
			expectedInputs: [ { type: 'text', languages: [ 'en' ] } ],
			expectedOutputs: [ { type: 'text', languages: [ 'en' ] } ],
		} );
	} catch {
		browserState = 'unavailable';
	}
	return browserState;
}

export function useBrowserModel() {
	const [ state, setState ] = useState( browserState );
	useEffect( () => {
		browserModel().then( setState );
	}, [] );
	return state;
}

/**
 * Runs one prompt in the browser's model with the JSON schema the server gave.
 * @param {Object} job
 * @param {string} job.system
 * @param {string} job.prompt
 * @param {Object} job.schema
 * @param {string} lang       The language of the answer.
 */
async function runInBrowser( { system, prompt, schema }, lang ) {
	const LM = window.LanguageModel;
	const create = ( languages ) =>
		LM.create( {
			initialPrompts: [ { role: 'system', content: system } ],
			expectedInputs: [ { type: 'text', languages: [ 'en' ] } ],
			expectedOutputs: [ { type: 'text', languages } ],
		} );
	let session;
	try {
		session = await create( [ lang || 'en' ] );
	} catch {
		session = await create( [ 'en' ] );
	}
	try {
		const out = await session.prompt( prompt, {
			responseConstraint: schema,
		} );
		return JSON.parse( out );
	} finally {
		session.destroy?.();
	}
}

/**
 * A job (translate, shorten, classify, privacy, setup): the provider runs it on the server, else the
 * browser's model here, and the server applies the answer either way.
 * @param {string}   job
 * @param {Object}   input
 * @param {Function} onState Gets the new state.
 * @return {Promise<Object>} What was done (`message`, `undo`).
 */
export async function runJob( job, input, onState ) {
	const first = await post( '/job', { job, input } );
	if ( first.run ) {
		const ready = await browserModel();
		if ( ready === 'unavailable' ) {
			throw new Error(
				__(
					'Dafür braucht WordPress einen KI-Anbieter (Einstellungen › Connectors) – oder Chrome mit eingebautem Modell.',
					'cookie-dialog'
				)
			);
		}
		const result = await runInBrowser(
			first.run,
			job === 'translate' ? input.lang : 'en'
		);
		const second = await post( '/job', { job, input, result } );
		onState( second );
		return second.result;
	}
	onState( first );
	return first.result;
}

/* ------------------------------------------------------------------ the chat */

const ChatContext = createContext( null );

const STORE = 'cookie-dialog-chat';
const LAYOUT = 'cookie-dialog-chat-layout';

/** The side column is offered from this window width; below it the chat sits at the bottom. */
export const SIDE_FROM = 1200;
const SIDE = { def: 420, min: 320, max: 720 };
const HISTORY = { def: 240, min: 96, max: 520 };

function loadChat() {
	try {
		return JSON.parse( window.sessionStorage.getItem( STORE ) || '[]' );
	} catch {
		return [];
	}
}

function loadLayout() {
	const fallback = {
		mode: window.innerWidth >= 1440 ? 'side' : 'bottom',
		open: true,
		side: SIDE.def,
		history: HISTORY.def,
		folded: false,
	};
	try {
		return {
			...fallback,
			...JSON.parse( window.localStorage.getItem( LAYOUT ) || '{}' ),
		};
	} catch {
		return fallback;
	}
}

const clamp = ( n, { min, max } ) => Math.max( min, Math.min( max, n ) );

/** The window's width, kept up to date. */
function useWindowWidth() {
	const [ width, setWidth ] = useState( window.innerWidth );
	useEffect( () => {
		const onResize = () => setWidth( window.innerWidth );
		window.addEventListener( 'resize', onResize );
		return () => window.removeEventListener( 'resize', onResize );
	}, [] );
	return width;
}

export function ChatProvider( { state, onState, view, children } ) {
	const [ messages, setMessages ] = useState( loadChat );
	const [ layout, setLayoutState ] = useState( loadLayout );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		try {
			window.sessionStorage.setItem(
				STORE,
				JSON.stringify( messages.slice( -40 ) )
			);
		} catch {
			// A private window keeps no chat across pages.
		}
	}, [ messages ] );
	const setLayout = useCallback( ( patch ) => {
		setLayoutState( ( current ) => {
			const next = { ...current, ...patch };
			try {
				window.localStorage.setItem( LAYOUT, JSON.stringify( next ) );
			} catch {
				// Without storage the layout holds for this page only.
			}
			return next;
		} );
	}, [] );
	const send = useCallback(
		async ( text ) => {
			const message = text.trim();
			if ( ! message || busy ) {
				return;
			}
			setLayout( { open: true, folded: false } );
			setError( null );
			const history = messages.map( ( m ) => ( {
				role: m.role,
				text: m.text,
			} ) );
			setMessages( ( list ) => [
				...list,
				{ role: 'me', text: message },
			] );
			setBusy( true );
			try {
				const res = await post( '/assist', { message, history, view } );
				onState( res );
				setMessages( ( list ) => [
					...list,
					{
						role: 'assistant',
						text: res.answer.message,
						cards: res.answer.cards,
						undo: res.answer.undo,
						version: res.answer.version,
					},
				] );
			} catch ( e ) {
				setError( problem( e ).message );
			} finally {
				setBusy( false );
			}
		},
		[ busy, messages, onState, view, setLayout ]
	);
	const undo = useCallback(
		async ( index ) => {
			const m = messages[ index ];
			if ( ! m?.undo ) {
				return;
			}
			try {
				onState( await post( '/undo', { token: m.undo } ) );
				setMessages( ( list ) =>
					list.map( ( x, i ) =>
						i === index ? { ...x, undo: null, undone: true } : x
					)
				);
			} catch ( e ) {
				setError( problem( e ).message );
			}
		},
		[ messages, onState ]
	);
	const clear = () => {
		setMessages( [] );
		setError( null );
	};
	return (
		<ChatContext.Provider
			value={ {
				state,
				messages,
				layout,
				setLayout,
				busy,
				error,
				send,
				undo,
				clear,
				ready: Boolean( state?.assistant?.ready ),
			} }
		>
			{ children }
		</ChatContext.Provider>
	);
}

export const useChat = () => useContext( ChatContext );

function ChatInput( { placeholder } ) {
	const chat = useChat();
	const [ text, setText ] = useState( '' );
	const submit = ( e ) => {
		e.preventDefault();
		chat.send( text );
		setText( '' );
	};
	return (
		<form className="cd-composer" onSubmit={ submit }>
			<input
				type="text"
				value={ text }
				placeholder={ placeholder }
				aria-label={ __(
					'Nachricht an den Assistenten',
					'cookie-dialog'
				) }
				onChange={ ( e ) => setText( e.target.value ) }
				disabled={ chat.busy }
			/>
			<button
				type="submit"
				className="cd-go"
				aria-label={ __( 'Senden', 'cookie-dialog' ) }
				disabled={ chat.busy || ! text.trim() }
			>
				{ chat.busy ? (
					<span className="cd-spinner__dot" />
				) : (
					<Icon name="send" size={ 15 } />
				) }
			</button>
		</form>
	);
}

/** The running conversation: what was asked, the answers with their cards and undo. */
function Messages() {
	const chat = useChat();
	const end = useRef();
	useEffect( () => {
		end.current?.scrollIntoView( { block: 'end' } );
	}, [ chat.messages.length, chat.busy ] );
	return (
		<div className="cd-chat__list" role="log" aria-live="polite">
			{ chat.messages.length || chat.busy ? null : (
				<p className="cd-chat__empty">
					{ __(
						'Sag, was anders sein soll – ich ändere Dialog, Dienste und Einstellungen. Jede Änderung kommt als Karte und lässt sich zurücknehmen.',
						'cookie-dialog'
					) }
				</p>
			) }
			{ chat.messages.map( ( m, i ) =>
				m.role === 'me' ? (
					<div key={ i } className="cd-msg cd-msg--me">
						<div className="cd-msg__b">{ m.text }</div>
					</div>
				) : (
					<div key={ i } className="cd-msg">
						<Engenty kind="drop" size={ 28 } />
						<div className="cd-msg__b">
							<p>{ m.text }</p>
							{ ( m.cards || [] ).map( ( c, j ) => (
								<div key={ j } className="cd-ccard">
									<span className="cd-ccard__ic">
										<Icon name="check" size={ 14 } />
									</span>
									<span className="cd-row__t">
										<b>{ c.title }</b>
										<span>{ c.text }</span>
									</span>
								</div>
							) ) }
							{ m.undo ? (
								<div className="cd-row-inline cd-msg__actions">
									<Button
										kind="ghost"
										size="sm"
										icon="undo"
										onClick={ () => chat.undo( i ) }
									>
										{ __( 'Rückgängig', 'cookie-dialog' ) }
									</Button>
									{ m.version ? (
										<span className="cd-fine">
											{ sprintf(
												/* translators: %d: version */
												__(
													'Version %d',
													'cookie-dialog'
												),
												m.version
											) }
										</span>
									) : null }
								</div>
							) : null }
							{ m.undone ? (
								<p className="cd-fine">
									{ __( 'Zurückgenommen.', 'cookie-dialog' ) }
								</p>
							) : null }
						</div>
					</div>
				)
			) }
			{ chat.busy ? (
				<div className="cd-msg">
					<Engenty kind="drop" size={ 28 } />
					<div className="cd-msg__b">
						<Spinner
							label={ __( 'Denkt nach …', 'cookie-dialog' ) }
						/>
					</div>
				</div>
			) : null }
			{ chat.error ? <Notice tone="error">{ chat.error }</Notice> : null }
			<div ref={ end } />
		</div>
	);
}

/**
 * The chat's head: who answers, and where the chat sits – at the bottom, in the side column, or
 * closed to the avatar.
 * @param root0
 * @param root0.mode     bottom or side.
 * @param root0.canSide  Whether the window is wide enough for the side column.
 * @param root0.children Extra controls (fold the history).
 */
function ChatHead( { mode, canSide, children } ) {
	const chat = useChat();
	const last = chat.state?.assistant?.last;
	return (
		<div className="cd-chat__head">
			<span className="cd-chat__who">
				<Icon name="sparkle" size={ 12 } />{ ' ' }
				{ __( 'Assistent', 'cookie-dialog' ) }
				{ last ? <span className="cd-fine"> · { last }</span> : null }
			</span>
			<span className="cd-row-inline cd-gap4">
				{ children }
				{ chat.messages.length ? (
					<button
						type="button"
						className="cd-link-btn"
						onClick={ chat.clear }
					>
						{ __( 'Neu', 'cookie-dialog' ) }
					</button>
				) : null }
				{ canSide ? (
					<button
						type="button"
						className="cd-icon-btn"
						title={
							mode === 'side'
								? __( 'Unten anzeigen', 'cookie-dialog' )
								: __(
										'In der Seitenleiste anzeigen',
										'cookie-dialog'
								  )
						}
						aria-label={
							mode === 'side'
								? __( 'Unten anzeigen', 'cookie-dialog' )
								: __(
										'In der Seitenleiste anzeigen',
										'cookie-dialog'
								  )
						}
						onClick={ () =>
							chat.setLayout( {
								mode: mode === 'side' ? 'bottom' : 'side',
							} )
						}
					>
						<Icon
							name={ mode === 'side' ? 'dockBottom' : 'dockSide' }
							size={ 16 }
						/>
					</button>
				) : null }
				<button
					type="button"
					className="cd-icon-btn"
					title={ __( 'Schließen', 'cookie-dialog' ) }
					aria-label={ __( 'Schließen', 'cookie-dialog' ) }
					onClick={ () => chat.setLayout( { open: false } ) }
				>
					<Icon name="x" size={ 16 } />
				</button>
			</span>
		</div>
	);
}

/**
 * A handle that resizes by dragging or with the arrow keys.
 * @param root0
 * @param root0.axis     x (width, dragged left grows) or y (height, dragged up grows).
 * @param root0.value    The current size.
 * @param root0.limits   min and max.
 * @param root0.onChange Gets the new size.
 * @param root0.label    What it resizes.
 */
function ResizeHandle( { axis, value, limits, onChange, label } ) {
	const start = useRef( null );
	const onPointerDown = ( e ) => {
		e.preventDefault();
		start.current = { pos: axis === 'x' ? e.clientX : e.clientY, value };
		e.currentTarget.setPointerCapture( e.pointerId );
		document.body.classList.add( 'cd-resizing' );
	};
	const onPointerMove = ( e ) => {
		if ( ! start.current ) {
			return;
		}
		const pos = axis === 'x' ? e.clientX : e.clientY;
		onChange(
			clamp( start.current.value + ( start.current.pos - pos ), limits )
		);
	};
	const onPointerUp = () => {
		start.current = null;
		document.body.classList.remove( 'cd-resizing' );
	};
	const onKeyDown = ( e ) => {
		const grow = axis === 'x' ? 'ArrowLeft' : 'ArrowUp';
		const shrink = axis === 'x' ? 'ArrowRight' : 'ArrowDown';
		if ( e.key === grow || e.key === shrink ) {
			e.preventDefault();
			onChange( clamp( value + ( e.key === grow ? 16 : -16 ), limits ) );
		}
	};
	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- a separator that can be moved is interactive (WAI-ARIA window splitter).
		<div
			className={ `cd-resize cd-resize--${ axis }` }
			role="separator"
			tabIndex={ 0 }
			aria-orientation={ axis === 'x' ? 'vertical' : 'horizontal' }
			aria-valuenow={ value }
			aria-valuemin={ limits.min }
			aria-valuemax={ limits.max }
			aria-label={ label }
			onPointerDown={ onPointerDown }
			onPointerMove={ onPointerMove }
			onPointerUp={ onPointerUp }
			onPointerCancel={ onPointerUp }
			onKeyDown={ onKeyDown }
		/>
	);
}

/**
 * The assistant on every page: a running chat at the bottom, or – when the window is wide enough –
 * an open, resizable column on the right that the page makes room for; closed, an engenty in the
 * bottom right corner opens it again. Without a provider, one quiet line says so.
 * @param root0
 * @param root0.placeholder What the input suggests on this page.
 * @param root0.quiet       No line without a provider (Start explains it in its card).
 */
export function ChatDock( { placeholder, quiet = false } ) {
	const chat = useChat();
	const width = useWindowWidth();
	const node = useRef();
	const canSide = width >= SIDE_FROM;
	const { layout } = chat;
	const mode = layout.mode === 'side' && canSide ? 'side' : 'bottom';
	const sideOpen = chat.ready && layout.open && mode === 'side';
	const side = clamp( layout.side, {
		min: SIDE.min,
		max: Math.min( SIDE.max, width - 760 ),
	} );

	// The page makes room for the side column.
	useEffect( () => {
		const app = node.current?.closest( '.cd-app' );
		if ( ! app ) {
			return undefined;
		}
		app.classList.toggle( 'has-side', sideOpen );
		app.style.setProperty( '--cd-side', sideOpen ? `${ side }px` : '0px' );
		return () => {
			app.classList.remove( 'has-side' );
			app.style.setProperty( '--cd-side', '0px' );
		};
	}, [ sideOpen, side ] );

	// The page keeps its last lines clear of the chat at the bottom.
	useEffect( () => {
		const el = node.current;
		const app = el?.closest( '.cd-app' );
		if ( ! el || ! app || ! ( 'ResizeObserver' in window ) ) {
			return undefined;
		}
		const measure = () =>
			app.style.setProperty(
				'--cd-bottom',
				el.classList.contains( 'cd-bdock' )
					? `${ Math.round( el.getBoundingClientRect().height ) }px`
					: '0px'
			);
		measure();
		const ro = new window.ResizeObserver( measure );
		ro.observe( el );
		return () => ro.disconnect();
	} );

	const text =
		placeholder ||
		__( 'Sag dem Assistenten, was anders sein soll …', 'cookie-dialog' );

	if ( ! chat.ready ) {
		return quiet ? (
			<span ref={ node } hidden />
		) : (
			<div className="cd-bdock" ref={ node }>
				<a
					className="cd-bdock__off"
					href={ `${ boot.admin }?page=cookie-dialog#assistant` }
				>
					<Engenty kind="drop" size={ 24 } dim />
					<span>
						{ __(
							'Assistent aus – alles andere funktioniert. KI-Anbieter einrichten',
							'cookie-dialog'
						) }
					</span>
					<Icon name="chevron" size={ 14 } />
				</a>
			</div>
		);
	}

	if ( ! layout.open ) {
		return (
			<button
				ref={ node }
				type="button"
				className="cd-avatar"
				title={ __( 'Assistent öffnen', 'cookie-dialog' ) }
				aria-label={ __( 'Assistent öffnen', 'cookie-dialog' ) }
				onClick={ () => chat.setLayout( { open: true } ) }
			>
				<Engenty kind="drop" size={ 44 } />
				{ chat.busy ? <span className="cd-avatar__busy" /> : null }
			</button>
		);
	}

	if ( mode === 'side' ) {
		return (
			<aside
				className="cd-side"
				ref={ node }
				aria-label={ __( 'Assistent', 'cookie-dialog' ) }
				style={ { width: side } }
			>
				<ResizeHandle
					axis="x"
					value={ side }
					limits={ {
						min: SIDE.min,
						max: Math.min( SIDE.max, width - 760 ),
					} }
					onChange={ ( v ) => chat.setLayout( { side: v } ) }
					label={ __( 'Breite des Assistenten', 'cookie-dialog' ) }
				/>
				<ChatHead mode="side" canSide={ canSide } />
				<Messages />
				<div className="cd-side__foot">
					<ChatInput placeholder={ text } />
				</div>
			</aside>
		);
	}

	const history = clamp( layout.history, HISTORY );
	const showHistory = ! layout.folded;
	return (
		<div className="cd-bdock" ref={ node }>
			<div className="cd-bdock__in">
				<div className="cd-bdock__card">
					{ showHistory ? (
						<ResizeHandle
							axis="y"
							value={ history }
							limits={ HISTORY }
							onChange={ ( v ) =>
								chat.setLayout( { history: v } )
							}
							label={ __( 'Höhe des Verlaufs', 'cookie-dialog' ) }
						/>
					) : null }
					<ChatHead mode="bottom" canSide={ canSide }>
						<button
							type="button"
							className="cd-icon-btn"
							title={
								showHistory
									? __(
											'Verlauf einklappen',
											'cookie-dialog'
									  )
									: __( 'Verlauf zeigen', 'cookie-dialog' )
							}
							aria-label={
								showHistory
									? __(
											'Verlauf einklappen',
											'cookie-dialog'
									  )
									: __( 'Verlauf zeigen', 'cookie-dialog' )
							}
							aria-expanded={ showHistory ? 'true' : 'false' }
							onClick={ () =>
								chat.setLayout( { folded: showHistory } )
							}
						>
							<Icon
								name={ showHistory ? 'down' : 'up' }
								size={ 16 }
							/>
						</button>
					</ChatHead>
					{ showHistory ? (
						<div
							className="cd-bdock__history"
							style={ { height: history } }
						>
							<Messages />
						</div>
					) : null }
					<ChatInput placeholder={ text } />
				</div>
			</div>
		</div>
	);
}

/**
 * Setting up a provider: Vercel AI Gateway in three steps, or another one under Connectors.
 * @param root0
 * @param root0.assistant
 */
function ProviderSteps( { assistant } ) {
	return (
		<div className="cd-steps3">
			<div className="cd-steps3__text">
				<b>{ __( 'Empfohlen: Vercel AI Gateway', 'cookie-dialog' ) }</b>
				<span className="cd-fine">
					{ __(
						'5 $ im Monat gratis, keine Kreditkarte · Plugin installieren, API Key holen, unter Einstellungen › Connectors einfügen · 3 Minuten',
						'cookie-dialog'
					) }
				</span>
			</div>
			<Button kind="primary" size="sm" href={ assistant.vercel }>
				{ __( 'Vercel-Plugin installieren', 'cookie-dialog' ) }
			</Button>
			<Button
				kind="ghost"
				size="sm"
				href="https://vercel.com/ai-gateway"
				external
			>
				{ __( 'API Key', 'cookie-dialog' ) }
			</Button>
			<Button kind="ghost" size="sm" href={ assistant.connectors }>
				{ __( 'Anderer Anbieter', 'cookie-dialog' ) }
			</Button>
		</div>
	);
}

/**
 * The assistant's card on Start: what it read and what to ask – the chat itself is at the bottom or on
 * the side – or what it does without a provider.
 * @param root0
 * @param root0.title
 * @param root0.text
 * @param root0.suggestions
 * @param root0.cardRef
 */
export function AssistantCard( { title, text, suggestions = [], cardRef } ) {
	const chat = useChat();
	const browser = useBrowserModel();
	const assistant = chat.state.assistant;
	if ( chat.ready ) {
		return (
			<div className="cd-acard" ref={ cardRef } id="assistant">
				<span className="cd-acard__eng">
					<Engenty kind="drop" size={ 52 } />
				</span>
				<span className="cd-kick">
					<Icon name="sparkle" size={ 12 } />{ ' ' }
					{ __( 'Assistent', 'cookie-dialog' ) }
				</span>
				<h2>{ title }</h2>
				<p>{ text }</p>
				{ suggestions.length ? (
					<div className="cd-sugg">
						{ suggestions.map( ( s ) => (
							<button
								key={ s }
								type="button"
								onClick={ () => chat.send( s ) }
								disabled={ chat.busy }
							>
								„{ s }“
							</button>
						) ) }
					</div>
				) : null }
			</div>
		);
	}
	const hasBrowser =
		assistant.browser && browser && browser !== 'unavailable';
	let heading = __( 'Der Assistent ist aus', 'cookie-dialog' );
	let body = __(
		'Dialog, Dienste, Blockieren, Protokoll und Google funktionieren ohne ihn. Für Texte im Ton deiner Website und den Chat braucht WordPress einen KI-Anbieter.',
		'cookie-dialog'
	);
	if ( ! assistant.wp ) {
		heading = __( 'Der Assistent braucht WordPress 7.0', 'cookie-dialog' );
		body = __(
			'Ab WordPress 7.0 denkt er über den KI-Anbieter, den du unter Einstellungen › Connectors einrichtest. Alles andere funktioniert schon jetzt.',
			'cookie-dialog'
		);
	} else if ( hasBrowser ) {
		heading = __( 'Einfaches übernimmt dein Browser', 'cookie-dialog' );
		body = __(
			'Übersetzen, Zuordnen und den Datenschutz-Absatz schreibt das eingebaute Modell deines Browsers – ohne Einrichtung, nichts verlässt den Rechner. Für Texte in deinem Ton und den Chat braucht WordPress einen KI-Anbieter.',
			'cookie-dialog'
		);
	}
	return (
		<div className="cd-acard is-off" ref={ cardRef } id="assistant">
			<span className="cd-acard__eng">
				<Engenty kind="drop" size={ 52 } dim />
			</span>
			<span className="cd-kick is-off">
				<Icon name="sparkle" size={ 12 } />{ ' ' }
				{ __( 'Assistent', 'cookie-dialog' ) }
			</span>
			<h2>{ heading }</h2>
			<p>{ body }</p>
			{ assistant.wp ? <ProviderSteps assistant={ assistant } /> : null }
		</div>
	);
}
