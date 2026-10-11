import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { engentyMarkup } from './engenties';

const ICONS = {
	check: '<path d="M4 12.5l5 5L20 6.5"/>',
	x: '<path d="M6 6l12 12M18 6L6 18"/>',
	plus: '<path d="M12 5v14M5 12h14"/>',
	chevron: '<path d="M9 6l6 6-6 6"/>',
	down: '<path d="M6 9l6 6 6-6"/>',
	up: '<path d="M6 15l6-6 6 6"/>',
	search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
	download: '<path d="M12 4v12M6 11l6 6 6-6M4 20h16"/>',
	send: '<path d="M4 12l16-8-6 16-2-7z"/>',
	sparkle:
		'<path d="M12 3l2 5.5L19.5 10 14 12l-2 5.5L10 12 4.5 10 10 8.5z"/>',
	lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
	warn: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18v.5"/>',
	play: '<path d="M7 4l13 8-13 8z"/>',
	external: '<path d="M14 4h6v6M20 4l-9 9M19 14v6H4V5h6"/>',
	globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
	trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
	refresh: '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/>',
	eye: '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
	server: '<rect x="4" y="4" width="16" height="7" rx="2"/><rect x="4" y="13" width="16" height="7" rx="2"/><path d="M8 7.5h.01M8 16.5h.01"/>',
	undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
	dockSide:
		'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/>',
	dockBottom:
		'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 14h18"/>',
	chip: '<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/>',
};

export function Icon( { name, size = 16, style } ) {
	return (
		<svg
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			className="cd-icon"
			style={ style }
			// eslint-disable-next-line react/no-danger -- fixed markup above.
			dangerouslySetInnerHTML={ { __html: ICONS[ name ] || '' } }
		/>
	);
}

/**
 * An engenty as the studio draws it: one eye, its own shape and colour.
 * @param root0
 * @param root0.kind
 * @param root0.size
 * @param root0.dim
 */
export function Engenty( { kind = 'round', size = 28, dim = false } ) {
	return (
		<svg
			width={ size }
			height={ size }
			viewBox="0 0 120 120"
			overflow="visible"
			aria-hidden="true"
			className={ `cd-engenty${ dim ? ' is-dim' : '' }` }
			// eslint-disable-next-line react/no-danger -- fixed markup from engenties.js.
			dangerouslySetInnerHTML={ { __html: engentyMarkup( kind ) } }
		/>
	);
}

export function Spinner( { label } ) {
	return (
		<span className="cd-spinner" role="status">
			<span className="cd-spinner__dot" />
			{ label ? <span>{ label }</span> : null }
		</span>
	);
}

export function Button( {
	kind = 'secondary',
	size,
	busy = false,
	href,
	external,
	icon,
	children,
	className = '',
	...rest
} ) {
	const cls = `cd-btn cd-btn--${ kind }${
		size ? ` cd-btn--${ size }` : ''
	} ${ className }`;
	const inner = (
		<>
			{ busy ? <span className="cd-spinner__dot" /> : null }
			{ icon && ! busy ? <Icon name={ icon } size={ 14 } /> : null }
			{ children }
			{ external ? <Icon name="external" size={ 13 } /> : null }
		</>
	);
	if ( href ) {
		return (
			<a
				className={ cls }
				href={ href }
				{ ...( external
					? { target: '_blank', rel: 'noopener noreferrer' }
					: {} ) }
				{ ...rest }
			>
				{ inner }
			</a>
		);
	}
	return (
		<button
			type="button"
			className={ cls }
			disabled={ busy || rest.disabled }
			{ ...rest }
		>
			{ inner }
		</button>
	);
}

export function Card( { pad0 = false, className = '', children, ...rest } ) {
	return (
		<div
			className={ `cd-card${
				pad0 ? ' cd-card--pad0' : ''
			} ${ className }` }
			{ ...rest }
		>
			{ children }
		</div>
	);
}

/**
 * A heading and a hint above a card, as the studio's settings: the card holds only the content.
 * @param root0
 * @param root0.title
 * @param root0.hint
 * @param root0.aside
 * @param root0.children
 * @param root0.id
 */
export function Section( { title, hint, aside, children, id } ) {
	return (
		<section className="cd-sec" id={ id }>
			{ title || aside ? (
				<div className="cd-sec__head">
					<div>
						{ title ? <h2>{ title }</h2> : null }
						{ hint ? <p>{ hint }</p> : null }
					</div>
					{ aside }
				</div>
			) : null }
			{ children }
		</section>
	);
}

export function Chip( { tone = '', children, icon } ) {
	return (
		<span className={ `cd-chip${ tone ? ` cd-chip--${ tone }` : '' }` }>
			{ icon ? <Icon name={ icon } size={ 11 } /> : null }
			{ children }
		</span>
	);
}

export function Notice( { tone = 'info', children } ) {
	return (
		<div className={ `cd-notice cd-notice--${ tone }` } role="status">
			{ children }
		</div>
	);
}

export function Status( { ok } ) {
	return ok ? (
		<span className="cd-status cd-status--ok">
			<Icon name="check" size={ 13 } />
		</span>
	) : (
		<span className="cd-status cd-status--warn">
			<Icon name="warn" size={ 12 } />
		</span>
	);
}

export function Switch( { checked, onChange, label, disabled = false } ) {
	return (
		<button
			type="button"
			role="switch"
			aria-checked={ checked ? 'true' : 'false' }
			aria-label={ label }
			className={ `cd-sw${ checked ? ' is-on' : '' }` }
			disabled={ disabled }
			onClick={ () => onChange( ! checked ) }
		/>
	);
}

export function Seg( { value, options, onChange, label } ) {
	return (
		<span className="cd-seg" role="radiogroup" aria-label={ label }>
			{ options.map( ( o ) => (
				<button
					key={ o.value }
					type="button"
					role="radio"
					aria-checked={ o.value === value ? 'true' : 'false' }
					className={ o.value === value ? 'is-on' : '' }
					onClick={ () => onChange( o.value ) }
				>
					{ o.label }
				</button>
			) ) }
		</span>
	);
}

/**
 * One line of a list card: a mark, a name and a line below, something on the right.
 * @param root0
 * @param root0.lead
 * @param root0.title
 * @param root0.sub
 * @param root0.right
 * @param root0.onClick
 * @param root0.className
 */
export function Row( { lead, title, sub, right, onClick, className = '' } ) {
	const body = (
		<>
			{ lead }
			<span className="cd-row__t">
				<b>{ title }</b>
				{ sub ? <span>{ sub }</span> : null }
			</span>
		</>
	);
	return (
		<div className={ `cd-row ${ className }` }>
			{ onClick ? (
				<button
					type="button"
					className="cd-row__main"
					onClick={ onClick }
				>
					{ body }
				</button>
			) : (
				<span className="cd-row__main">{ body }</span>
			) }
			{ right ? <span className="cd-row__right">{ right }</span> : null }
		</div>
	);
}

export function Logo( { name } ) {
	const letters = ( name || '?' )
		.replace( /[^A-Za-z0-9ÄÖÜäöü ]/g, '' )
		.split( ' ' )
		.filter( Boolean )
		.slice( 0, 2 )
		.map( ( w ) => w[ 0 ] )
		.join( '' );
	return <span className="cd-logo">{ letters || '?' }</span>;
}

export function Field( { label, hint, children } ) {
	return (
		// eslint-disable-next-line jsx-a11y/label-has-associated-control -- the control is the child.
		<label className="cd-field">
			<span className="cd-field__label">{ label }</span>
			{ children }
			{ hint ? <span className="cd-field__hint">{ hint }</span> : null }
		</label>
	);
}

/**
 * A side panel over the page, closed with Esc or a click beside it.
 * @param root0
 * @param root0.title
 * @param root0.head
 * @param root0.onClose
 * @param root0.footer
 * @param root0.children
 */
export function Drawer( { title, head, onClose, footer, children } ) {
	const panel = useRef();
	useEffect( () => {
		const onKey = ( e ) => e.key === 'Escape' && onClose();
		window.addEventListener( 'keydown', onKey );
		panel.current?.focus();
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ onClose ] );
	return (
		<div
			className="cd-drawer"
			role="dialog"
			aria-modal="true"
			aria-label={ title }
		>
			<button
				type="button"
				className="cd-drawer__back"
				aria-label={ __( 'Schließen', 'cookie-dialog' ) }
				onClick={ onClose }
			/>
			<div className="cd-drawer__panel" ref={ panel } tabIndex={ -1 }>
				<header className="cd-drawer__head">
					<div className="cd-drawer__title">
						{ head }
						<h2>{ title }</h2>
					</div>
					<button
						type="button"
						className="cd-icon-btn"
						onClick={ onClose }
						aria-label={ __( 'Schließen', 'cookie-dialog' ) }
					>
						<Icon name="x" size={ 18 } />
					</button>
				</header>
				<div className="cd-drawer__body">{ children }</div>
				{ footer ? (
					<footer className="cd-drawer__foot">{ footer }</footer>
				) : null }
			</div>
		</div>
	);
}

/**
 * One topic of a settings page: its summary in one line, the switches inside.
 * @param root0
 * @param root0.title
 * @param root0.summary
 * @param root0.recommended
 * @param root0.open
 * @param root0.onToggle
 * @param root0.children
 */
export function Accordion( {
	title,
	summary,
	recommended,
	open,
	onToggle,
	children,
} ) {
	// Without `recommended` the topic has nothing to recommend: no dot, no chip.
	let dot = ' is-plain';
	let chip = null;
	if ( recommended ) {
		dot = '';
		chip = <Chip>{ __( 'wie empfohlen', 'cookie-dialog' ) }</Chip>;
	} else if ( undefined !== recommended ) {
		dot = ' is-amber';
		chip = <Chip tone="amber">{ __( 'angepasst', 'cookie-dialog' ) }</Chip>;
	}
	return (
		<div className={ `cd-acc${ open ? ' is-open' : '' }` }>
			<button
				type="button"
				className="cd-acc__head"
				aria-expanded={ open ? 'true' : 'false' }
				onClick={ onToggle }
			>
				<span className={ `cd-dot${ dot }` } />
				<b>{ title }</b>
				<span className="cd-acc__sum">{ summary }</span>
				{ chip }
				<Icon name={ open ? 'down' : 'chevron' } size={ 16 } />
			</button>
			{ open ? <div className="cd-acc__body">{ children }</div> : null }
		</div>
	);
}

/**
 * A label with a hint on the left, a control on the right.
 * @param root0
 * @param root0.label
 * @param root0.hint
 * @param root0.children
 */
export function Line( { label, hint, children } ) {
	return (
		<div className="cd-line">
			<div className="cd-line__label">
				{ label }
				{ hint ? <span className="cd-fine">{ hint }</span> : null }
			</div>
			<div className="cd-line__control">{ children }</div>
		</div>
	);
}

/**
 * A value that settles: `onChange` after the person stops typing.
 * @param value
 * @param onChange
 * @param delay
 */
export function useDraft( value, onChange, delay = 600 ) {
	const [ draft, setDraft ] = useState( value );
	const timer = useRef();
	useEffect( () => setDraft( value ), [ value ] );
	const update = ( next ) => {
		setDraft( next );
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => onChange( next ), delay );
	};
	return [ draft, update ];
}

export function when( iso, withTime = true ) {
	if ( ! iso ) {
		return '';
	}
	const d =
		typeof iso === 'number' ? new Date( iso * 1000 ) : new Date( iso );
	return d.toLocaleString( undefined, {
		day: 'numeric',
		month: 'short',
		...( withTime ? { hour: '2-digit', minute: '2-digit' } : {} ),
	} );
}

export const CATEGORY_ORDER = [
	'necessary',
	'functional',
	'statistics',
	'marketing',
];

export const LOCAL_KINDS = [ 'font', 'style', 'library', 'image', 'avatar' ];

export const pick = ( map, lang = 'de' ) =>
	typeof map === 'string'
		? map
		: map?.[ lang ] ||
		  map?.de ||
		  map?.en ||
		  Object.values( map || {} )[ 0 ] ||
		  '';

/** Languages offered for the dialog; their names come from the browser in the admin's language. */
export const LANGUAGE_CODES = [
	'de',
	'en',
	'fr',
	'it',
	'es',
	'nl',
	'pl',
	'cs',
	'sk',
	'hu',
	'sl',
	'hr',
	'pt',
	'da',
	'sv',
	'fi',
	'ro',
	'el',
	'tr',
];

/**
 * A language's name in the admin's language: „Englisch“, “English”.
 * @param code
 */
export function languageName( code ) {
	try {
		const locale = ( window.cookieDialogAdmin?.locale || 'de' ).replace(
			'_',
			'-'
		);
		const name = new window.Intl.DisplayNames( [ locale ], {
			type: 'language',
		} ).of( code );
		return name
			? name.charAt( 0 ).toUpperCase() + name.slice( 1 )
			: code.toUpperCase();
	} catch {
		return code.toUpperCase();
	}
}

/**
 * „6 Monate“, with the singular for one.
 * @param n
 */
export const months = ( n ) =>
	/* translators: %d: months */
	sprintf( _n( '%d Monat', '%d Monate', n, 'cookie-dialog' ), n );
