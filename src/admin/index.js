import {
	createContext,
	createRoot,
	useCallback,
	useContext,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api, boot, post, problem } from './api';
import { ChatDock, ChatProvider } from './Assistant';
import { Button, Engenty, Icon, Notice, Spinner } from './ui';
import Start from './Start';
import Dialog from './Dialog';
import Services from './Services';
import Log from './Log';
import Settings from './Settings';
import './admin.css';

const VIEWS = {
	start: [ Start, __( 'Start', 'cookie-dialog' ) ],
	dialog: [ Dialog, __( 'Dialog', 'cookie-dialog' ) ],
	services: [ Services, __( 'Dienste', 'cookie-dialog' ) ],
	log: [ Log, __( 'Protokoll', 'cookie-dialog' ) ],
	settings: [ Settings, __( 'Einstellungen', 'cookie-dialog' ) ],
};

/** What the assistant's input suggests on each page. */
const PLACEHOLDERS = {
	start: __( 'z. B. „Füge Hotjar hinzu“', 'cookie-dialog' ),
	log: __(
		'Frag zum Protokoll, z. B. „Wie viele haben gestern abgelehnt?“',
		'cookie-dialog'
	),
};

const AppContext = createContext( null );
export const useApp = () => useContext( AppContext );

/**
 * WordPress' count bubbles in the menu follow the state without a reload.
 * @param state
 */
function updateBubbles( state ) {
	const set = ( selector, n ) => {
		document.querySelectorAll( selector ).forEach( ( a ) => {
			let bubble = a.querySelector( '.awaiting-mod' );
			if ( ! n ) {
				bubble?.remove();
				return;
			}
			if ( ! bubble ) {
				bubble = document.createElement( 'span' );
				a.append( ' ', bubble );
			}
			bubble.className = `awaiting-mod count-${ n }`;
			bubble.innerHTML = `<span class="pending-count">${ n }</span>`;
		} );
	};
	set( '#toplevel_page_cookie-dialog > a .wp-menu-name', state.open );
	set(
		'#toplevel_page_cookie-dialog a[href$="page=cookie-dialog"]:not(.menu-top)',
		state.open
	);
	set(
		'#toplevel_page_cookie-dialog a[href$="page=cookie-dialog-services"]',
		state.unassigned.length
	);
}

function Top( { view, state, saving, savedAt } ) {
	const [ , label ] = VIEWS[ view ] || VIEWS.start;
	const c = state?.config;
	return (
		<header className="cd-top">
			<div className="cd-crumb">
				<Engenty kind="incognito" size={ 28 } />
				<a
					className="cd-crumb__brand"
					href={ `${ boot.admin }?page=cookie-dialog` }
				>
					Cookie Dialog
				</a>
				<i>/</i>
				<b>{ label }</b>
			</div>
			<span className="cd-top__space" />
			{ saving ? (
				<span className="cd-saved">
					<span className="cd-spinner__dot" />{ ' ' }
					{ __( 'Speichert …', 'cookie-dialog' ) }
				</span>
			) : savedAt ? (
				<span className="cd-saved">
					<Icon name="check" size={ 12 } />{ ' ' }
					{ __( 'Gespeichert', 'cookie-dialog' ) }
				</span>
			) : null }
			{ c ? (
				c.live ? (
					<>
						<span className="cd-pill-status is-live">
							<span className="cd-dot" />
							{ sprintf(
								/* translators: %d: version */
								__( 'Live · Version %d', 'cookie-dialog' ),
								c.version
							) }
						</span>
						<Button size="sm" href={ boot.site } external>
							{ __( 'Auf der Website ansehen', 'cookie-dialog' ) }
						</Button>
					</>
				) : (
					<span className="cd-pill-status">
						{ __( 'Noch nicht live', 'cookie-dialog' ) }
					</span>
				)
			) : null }
		</header>
	);
}

function App( { view } ) {
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ saving, setSaving ] = useState( 0 );
	const [ savedAt, setSavedAt ] = useState( null );
	const [ saveError, setSaveError ] = useState( null );
	const queue = useRef( Promise.resolve() );

	const onState = useCallback( ( next ) => {
		if ( next && next.config ) {
			setState( next );
			updateBubbles( next );
		}
	}, [] );

	useEffect( () => {
		api( '/state' )
			.then( onState )
			.catch( ( e ) => setError( problem( e ).message ) );
	}, [ onState ] );

	/** Saves part of the configuration; saves run one after another. */
	const save = useCallback(
		( patch, note = '' ) => {
			setSaving( ( n ) => n + 1 );
			setSaveError( null );
			const run = queue.current.then( () =>
				post( '/config', { patch, note } )
					.then( ( next ) => {
						onState( next );
						setSavedAt( Date.now() );
						return next;
					} )
					.catch( ( e ) => {
						setSaveError( problem( e ).message );
						throw e;
					} )
					.finally( () => setSaving( ( n ) => n - 1 ) )
			);
			queue.current = run.catch( () => undefined );
			return run;
		},
		[ onState ]
	);

	const [ View ] = VIEWS[ view ] || VIEWS.start;
	return (
		<div className="cd-app">
			<Top
				view={ view }
				state={ state }
				saving={ saving > 0 }
				savedAt={ savedAt }
			/>
			<main className="cd-main">
				{ error ? <Notice tone="error">{ error }</Notice> : null }
				{ saveError ? (
					<Notice tone="error">{ saveError }</Notice>
				) : null }
				{ ! state && ! error ? (
					<Spinner
						label={ __( 'Cookie Dialog lädt …', 'cookie-dialog' ) }
					/>
				) : null }
				{ state ? (
					<AppContext.Provider
						value={ { state, onState, save, saving: saving > 0 } }
					>
						<ChatProvider
							state={ state }
							onState={ onState }
							view={ view }
						>
							<View
								state={ state }
								onState={ onState }
								save={ save }
							/>
							<ChatDock
								placeholder={ PLACEHOLDERS[ view ] }
								quiet={ view === 'start' }
							/>
						</ChatProvider>
					</AppContext.Provider>
				) : null }
			</main>
		</div>
	);
}

const root = document.getElementById( 'cookie-dialog-app' );
if ( root ) {
	createRoot( root ).render( <App view={ root.dataset.view || 'start' } /> );
}
