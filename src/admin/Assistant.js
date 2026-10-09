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

function loadChat() {
	try {
		return JSON.parse( window.sessionStorage.getItem( STORE ) || '[]' );
	} catch {
		return [];
	}
}

export function ChatProvider( { state, onState, view, children } ) {
	const [ messages, setMessages ] = useState( loadChat );
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		try {
			window.sessionStorage.setItem(
				STORE,
				JSON.stringify( messages.slice( -30 ) )
			);
		} catch {
			// A private window keeps no chat across pages.
		}
	}, [ messages ] );
	const send = useCallback(
		async ( text ) => {
			const message = text.trim();
			if ( ! message || busy ) {
				return;
			}
			setOpen( true );
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
		[ busy, messages, onState, view ]
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
		setOpen( false );
	};
	return (
		<ChatContext.Provider
			value={ {
				state,
				messages,
				open,
				setOpen,
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

function ChatInput( { placeholder, autoFocus, big = false } ) {
	const chat = useChat();
	const [ text, setText ] = useState( '' );
	const submit = ( e ) => {
		e.preventDefault();
		chat.send( text );
		setText( '' );
	};
	return (
		<form className={ big ? 'cd-ain' : 'cd-pill' } onSubmit={ submit }>
			{ big ? null : <Engenty kind="drop" size={ 26 } /> }
			<input
				type="text"
				value={ text }
				// eslint-disable-next-line jsx-a11y/no-autofocus -- the chat was just opened.
				autoFocus={ autoFocus }
				placeholder={ placeholder }
				aria-label={ __(
					'Nachricht an den Assistenten',
					'cookie-dialog'
				) }
				onChange={ ( e ) => setText( e.target.value ) }
				onFocus={ () => chat.messages.length && chat.setOpen( true ) }
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

function ChatPanel() {
	const chat = useChat();
	const end = useRef();
	useEffect( () => {
		end.current?.scrollIntoView( { block: 'end' } );
	}, [ chat.messages.length, chat.busy ] );
	if ( ! chat.open || ( ! chat.messages.length && ! chat.busy ) ) {
		return null;
	}
	const last = chat.state?.assistant?.last;
	return (
		<div className="cd-chat" role="log" aria-live="polite">
			<div className="cd-chat__head">
				<span>
					<Icon name="sparkle" size={ 12 } />{ ' ' }
					{ __( 'Assistent', 'cookie-dialog' ) }
					{ last ? ` · ${ last }` : '' }
				</span>
				<span className="cd-row-inline">
					<button
						type="button"
						className="cd-link-btn"
						onClick={ chat.clear }
					>
						{ __( 'Neu', 'cookie-dialog' ) }
					</button>
					<button
						type="button"
						className="cd-icon-btn"
						aria-label={ __( 'Schließen', 'cookie-dialog' ) }
						onClick={ () => chat.setOpen( false ) }
					>
						<Icon name="x" size={ 16 } />
					</button>
				</span>
			</div>
			<div className="cd-chat__list">
				{ chat.messages.map( ( m, i ) =>
					m.role === 'me' ? (
						<div key={ i } className="cd-msg cd-msg--me">
							<div className="cd-msg__b">{ m.text }</div>
						</div>
					) : (
						<div key={ i } className="cd-msg">
							<Engenty kind="drop" size={ 30 } />
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
											{ __(
												'Rückgängig',
												'cookie-dialog'
											) }
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
										{ __(
											'Zurückgenommen.',
											'cookie-dialog'
										) }
									</p>
								) : null }
							</div>
						</div>
					)
				) }
				{ chat.busy ? (
					<div className="cd-msg">
						<Engenty kind="drop" size={ 30 } />
						<div className="cd-msg__b">
							<Spinner
								label={ __( 'Denkt nach …', 'cookie-dialog' ) }
							/>
						</div>
					</div>
				) : null }
				{ chat.error ? (
					<Notice tone="error">{ chat.error }</Notice>
				) : null }
				<div ref={ end } />
			</div>
		</div>
	);
}

/**
 * The slim line at the bottom of every page; on Start it shows once the card scrolled away.
 * @param root0
 * @param root0.placeholder
 * @param root0.hidden
 */
export function Dock( { placeholder, hidden = false } ) {
	const chat = useChat();
	if ( ! chat.ready ) {
		if ( hidden ) {
			return null;
		}
		return (
			<div className="cd-dock">
				<a
					className="cd-pill cd-pill--off"
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
	return (
		<div className={ `cd-dock${ hidden ? ' is-hidden' : '' }` }>
			<div className="cd-dock__in">
				<ChatPanel />
				<ChatInput
					placeholder={
						placeholder ||
						__(
							'Sag dem Assistenten, was anders sein soll …',
							'cookie-dialog'
						)
					}
				/>
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
 * The assistant's card on Start: ready with a line to type into, or what it does without a provider.
 * @param root0
 * @param root0.title
 * @param root0.text
 * @param root0.suggestions
 * @param root0.placeholder
 * @param root0.cardRef
 */
export function AssistantCard( {
	title,
	text,
	suggestions = [],
	placeholder,
	cardRef,
} ) {
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
				<ChatInput placeholder={ placeholder } big />
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
				<ChatPanel />
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
