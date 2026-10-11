/**
 * The dialog on the site: two layers, the visitor's cookie, the log, Google's Consent Mode update,
 * held scripts and placeholders released on consent, the reopen button and `window.cookieDialog`.
 * No framework; the dialog lives in a shadow root so a theme's styles cannot change its buttons.
 */
import './dialog.css';

type Choice = { id: string; v: number; t: number; c: string[]; s: string[] };
type Service = {
	id: string;
	name: string;
	cat: string;
	provider: string;
	address: string;
	purpose: string;
	privacy: string;
	cookies: [ string, string, string ][];
	embed: { type: string; label: string } | null;
	hosts?: string[];
};
type Category = { id: string; name: string; desc: string; locked: boolean };
/** One language's words, when visitors may pick the dialog's language. */
type Localized = {
	texts: Record< string, string >;
	services: Service[];
	categories: Category[];
};
type Colors = Record<
	'background' | 'text' | 'button' | 'buttonText' | 'link',
	string
>;
type Design = {
	layout: 'box' | 'bar' | 'modal';
	position: 'left' | 'right' | 'center';
	size: 's' | 'm' | 'l';
	scheme: 'light' | 'dark' | 'auto';
	colors: Colors;
	dark: Colors;
	radius: number;
	font: 'theme' | 'system' | 'custom';
	fontFamily: string;
	headFont: string;
	logo: string;
	backdrop: boolean;
	lock: boolean;
	branding: boolean;
	buttons: 'same' | 'accept' | 'outline';
	showTitle: boolean;
	showLinks: boolean;
	showLanguages: boolean;
};
type Preview = { layer: '1' | '2' | 'ph'; edit: boolean; lang: string };
type SitePreview = {
	label: string;
	note: string;
	title: string;
	restart: string;
	end: string;
	endUrl: string;
};
type Config = {
	v: number;
	reask: number;
	live: boolean;
	lang: string;
	site: string;
	texts: Record< string, string >;
	langs: Record< string, Localized > | false;
	design: Design;
	categories: Category[];
	services: Service[];
	catSignals: Record< string, string[] >;
	serviceSignals: Record< string, string[] >;
	consentMode: boolean;
	months: number;
	gpc: boolean;
	reopen: { button: boolean; position: 'left' | 'right' };
	privacy: string;
	imprint: string;
	small: boolean;
	unknownIframes: boolean;
	nocookie: boolean;
	rest: string;
	preview: Preview | false;
	sitePreview: SitePreview | false;
};
type Decision = 'all' | 'necessary' | 'custom' | 'service';
type Source = 'dialog' | 'settings' | 'placeholder' | 'withdraw';

declare global {
	interface Window {
		cookieDialog: unknown;
		dataLayer?: unknown[];
		gtag?: ( ...args: unknown[] ) => void;
		fbq?: ( ...args: unknown[] ) => void;
		uetq?: { push: ( ...args: unknown[] ) => void };
	}
	interface Navigator {
		globalPrivacyControl?: boolean;
	}
}

const COOKIE = 'cookie_dialog';
/** The language a visitor picked in the dialog, kept in their browser. */
const LANG_KEY = 'cookie-dialog-lang';
const SIGNALS = [
	'ad_storage',
	'ad_user_data',
	'ad_personalization',
	'analytics_storage',
	'functionality_storage',
	'personalization_storage',
];

const configNode = document.getElementById( 'cookie-dialog-config' );
let cfg: Config | null = null;
try {
	cfg = configNode ? JSON.parse( configNode.textContent || '' ) : null;
} catch {
	cfg = null;
}

/* ------------------------------------------------------------------ choice */

/** The preview on the site keeps its choice in this tab only, never in the cookie. */
const PREVIEW_KEY = 'cookie-dialog-preview';

function storedChoice( c: Config ): string | null {
	if ( c.sitePreview ) {
		try {
			return window.sessionStorage.getItem( PREVIEW_KEY );
		} catch {
			return null;
		}
	}
	const m = document.cookie.match(
		new RegExp( `(?:^|; )${ COOKIE }=([^;]*)` )
	);
	return m ? decodeURIComponent( m[ 1 ] ) : null;
}

function readChoice( c: Config ): Choice | null {
	const raw = storedChoice( c );
	if ( ! raw ) {
		return null;
	}
	try {
		const data = JSON.parse( raw ) as Choice;
		const maxAge = c.months * 30 * 86400;
		if (
			! data ||
			typeof data.v !== 'number' ||
			data.v < c.reask ||
			data.t < Date.now() / 1000 - maxAge
		) {
			return null;
		}
		data.c = Array.isArray( data.c ) ? data.c : [];
		data.s = Array.isArray( data.s ) ? data.s : [];
		return data;
	} catch {
		return null;
	}
}

function writeChoice( c: Config, choice: Choice ) {
	if ( c.sitePreview ) {
		try {
			window.sessionStorage.setItem(
				PREVIEW_KEY,
				JSON.stringify( choice )
			);
		} catch {
			// Without storage the preview's choice holds for this page only.
		}
		return;
	}
	const secure = location.protocol === 'https:' ? '; Secure' : '';
	document.cookie = `${ COOKIE }=${ encodeURIComponent(
		JSON.stringify( choice )
	) }; path=/; max-age=${ c.months * 30 * 86400 }; SameSite=Lax${ secure }`;
}

function uuid(): string {
	const cr: Crypto = window.crypto;
	if ( typeof cr.randomUUID === 'function' ) {
		return cr.randomUUID();
	}
	const b = new Uint8Array( 16 );
	cr.getRandomValues( b );
	// eslint-disable-next-line no-bitwise -- the version and variant bits of a UUID v4.
	b[ 6 ] = ( b[ 6 ] & 0x0f ) | 0x40;
	// eslint-disable-next-line no-bitwise -- the version and variant bits of a UUID v4.
	b[ 8 ] = ( b[ 8 ] & 0x3f ) | 0x80;
	const h = Array.from( b, ( x ) => x.toString( 16 ).padStart( 2, '0' ) );
	return `${ h.slice( 0, 4 ).join( '' ) }-${ h.slice( 4, 6 ).join( '' ) }-${ h
		.slice( 6, 8 )
		.join( '' ) }-${ h.slice( 8, 10 ).join( '' ) }-${ h
		.slice( 10 )
		.join( '' ) }`;
}

/* ------------------------------------------------------------------ the dialog */

class CookieDialog {
	c: Config;
	choice: Choice | null;
	host: HTMLElement | null = null;
	root: ShadowRoot | null = null;
	layer: 0 | 1 | 2 | 3 = 0;
	draft: { cats: Set< string >; services: Set< string > } = {
		cats: new Set(),
		services: new Set(),
	};
	open: Set< string > = new Set();
	listeners: ( ( detail: unknown ) => void )[] = [];
	released = new Set< Element >();
	/** Services loaded once from a placeholder on this page: what their embed adds stays. */
	once = new Set< string >();
	lastFocus: Element | null = null;
	fab: HTMLButtonElement | null = null;
	gpc: boolean;

	constructor( c: Config ) {
		this.c = c;
		this.choice = c.preview ? null : readChoice( c );
		this.gpc = Boolean( c.gpc && navigator.globalPrivacyControl );
	}

	start() {
		this.pickedLang();
		this.mount();
		if ( this.c.preview ) {
			this.preview( this.c.preview );
			return;
		}
		if ( this.c.sitePreview ) {
			this.previewBar( this.c.sitePreview );
		}
		if ( this.choice ) {
			this.apply( null );
		} else {
			this.show( 1 );
		}
		this.idsFill();
		this.watch();
		document.addEventListener( 'click', ( e ) => this.onPageClick( e ) );
		if ( location.hash === '#cookie-settings' ) {
			this.show( 2 );
		}
		window.addEventListener( 'hashchange', () => {
			if ( location.hash === '#cookie-settings' ) {
				this.show( 2 );
			}
		} );
	}

	/* ---------- consent */

	allowed( key: string, category?: string ): boolean {
		const ch = this.choice;
		if ( ! ch ) {
			return false;
		}
		if ( ch.s.includes( key ) || ch.c.includes( key ) ) {
			return true;
		}
		return category
			? category.split( /\s+/ ).some( ( cat ) => ch.c.includes( cat ) )
			: false;
	}

	has = ( key: string ): boolean =>
		key === 'necessary' || this.allowed( key );

	servicesOf( cat: string ): Service[] {
		return this.c.services.filter( ( s ) => s.cat === cat );
	}

	decide( decision: Decision, source: Source ) {
		if ( this.c.preview ) {
			this.close();
			return;
		}
		const cats = new Set< string >( [ 'necessary' ] );
		const services = new Set< string >();
		if ( decision === 'all' ) {
			this.c.categories.forEach( ( cat ) => {
				if ( cat.id === 'marketing' && this.gpc ) {
					return;
				}
				cats.add( cat.id );
			} );
		} else if ( decision === 'custom' ) {
			this.draft.cats.forEach( ( id ) => cats.add( id ) );
			this.draft.services.forEach( ( id ) => services.add( id ) );
		}
		this.c.services.forEach( ( s ) => {
			if ( cats.has( s.cat ) ) {
				services.add( s.id );
			}
		} );
		const prev = this.choice;
		const choice: Choice = {
			id: prev?.id || uuid(),
			v: this.c.v,
			t: Math.floor( Date.now() / 1000 ),
			c: Array.from( cats ),
			s: Array.from( services ),
		};
		const revoked = prev
			? prev.s.some( ( id ) => ! services.has( id ) ) ||
			  prev.c.some( ( id ) => ! cats.has( id ) )
			: false;
		let d = decision;
		if ( decision === 'custom' ) {
			const all = this.c.categories.every( ( cat ) =>
				cats.has( cat.id )
			);
			d = all ? 'all' : 'custom';
			if ( cats.size === 1 && services.size === 0 ) {
				d = 'necessary';
			}
		}
		this.save(
			choice,
			d,
			revoked && source !== 'placeholder' ? 'withdraw' : source
		);
		this.close();
		this.apply( prev );
		if ( revoked && prev ) {
			this.forget( prev.s.filter( ( id ) => ! services.has( id ) ) );
		}
		// Scripts that ran cannot be taken back: a fresh page without them.
		if ( revoked && this.released.size ) {
			window.location.reload();
		}
	}

	/**
	 * What services set on this site goes when their consent goes: their cookies and storage keys.
	 * @param ids
	 */
	forget( ids: string[] ) {
		// The preview leaves the admin's own cookies alone.
		if ( this.c.sitePreview ) {
			return;
		}
		const names: string[] = [];
		const keys: string[] = [];
		this.c.services
			.filter( ( s ) => ids.includes( s.id ) )
			.forEach( ( s ) =>
				s.cookies.forEach( ( [ name, , type ] ) =>
					( type === 'HTTP' ? names : keys ).push( name )
				)
			);
		const matches = ( pattern: string, name: string ) =>
			pattern.endsWith( '*' )
				? name.startsWith( pattern.slice( 0, -1 ) )
				: name === pattern;
		const parts = location.hostname.split( '.' );
		const domains = [ '' ];
		for ( let i = 0; i < parts.length - 1; i++ ) {
			domains.push( `; domain=.${ parts.slice( i ).join( '.' ) }` );
		}
		document.cookie.split( '; ' ).forEach( ( pair ) => {
			const name = pair.split( '=' )[ 0 ];
			if (
				name !== COOKIE &&
				names.some( ( n ) => matches( n, name ) )
			) {
				domains.forEach( ( d ) => {
					document.cookie = `${ name }=; Max-Age=0; path=/${ d }`;
				} );
			}
		} );
		[ window.localStorage, window.sessionStorage ].forEach( ( store ) => {
			try {
				Object.keys( store ).forEach( ( key ) => {
					if ( keys.some( ( n ) => matches( n, key ) ) ) {
						store.removeItem( key );
					}
				} );
			} catch {
				// Storage can be blocked; nothing to remove then.
			}
		} );
	}

	allowService( id: string ) {
		if ( this.c.preview ) {
			return;
		}
		const prev = this.choice;
		const choice: Choice = prev
			? { ...prev, s: Array.from( new Set( [ ...prev.s, id ] ) ) }
			: {
					id: uuid(),
					v: this.c.v,
					t: Math.floor( Date.now() / 1000 ),
					c: [ 'necessary' ],
					s: [ id ],
			  };
		choice.v = this.c.v;
		choice.t = Math.floor( Date.now() / 1000 );
		this.save( choice, 'service', 'placeholder' );
		this.close();
		this.apply( prev );
	}

	save( choice: Choice, decision: Decision, source: Source ) {
		this.choice = choice;
		writeChoice( this.c, choice );
		if ( this.c.sitePreview ) {
			this.idsFill();
			return;
		}
		try {
			window
				.fetch( this.c.rest, {
					method: 'POST',
					credentials: 'omit',
					keepalive: true,
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						id: choice.id,
						version: this.c.v,
						lang: this.c.lang,
						source,
						decision,
						categories: choice.c,
						services: choice.s,
					} ),
				} )
				.catch( () => undefined );
		} catch {
			// The choice counts on this page even when the log cannot be reached.
		}
		this.idsFill();
	}

	/**
	 * Consent Mode, released scripts and embeds, events – after a choice or on a page with one.
	 * @param prev
	 */
	apply( prev: Choice | null ) {
		const ch = this.choice;
		if ( ! ch ) {
			return;
		}
		if ( this.c.consentMode && typeof window.gtag === 'function' ) {
			const granted = new Set< string >();
			ch.c.forEach( ( k ) =>
				( this.c.catSignals[ k ] || [] ).forEach( ( s ) =>
					granted.add( s )
				)
			);
			ch.s.forEach( ( k ) =>
				( this.c.serviceSignals[ k ] || [] ).forEach( ( s ) =>
					granted.add( s )
				)
			);
			const update: Record< string, string > = {};
			SIGNALS.forEach( ( s ) => {
				update[ s ] = granted.has( s ) ? 'granted' : 'denied';
			} );
			if ( prev || granted.size ) {
				window.gtag( 'consent', 'update', update );
			}
		}
		this.release();
		const detail = {
			id: ch.id,
			version: ch.v,
			categories: ch.c.slice(),
			services: ch.s.slice(),
		};
		const flags: Record< string, boolean > = {};
		this.c.categories.forEach( ( cat ) => {
			flags[ cat.id ] = ch.c.includes( cat.id );
		} );
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push( {
			event: 'cookie_dialog_consent',
			cookie_dialog: flags,
			cookie_dialog_categories: ch.c.join( ',' ),
			cookie_dialog_services: ch.s.join( ',' ),
		} );
		if ( typeof window.fbq === 'function' ) {
			window.fbq(
				'consent',
				this.allowed( 'meta-pixel', 'marketing' ) ? 'grant' : 'revoke'
			);
		}
		if ( window.uetq && typeof window.uetq.push === 'function' ) {
			window.uetq.push( 'consent', 'update', {
				ad_storage: this.allowed( 'microsoft-uet', 'marketing' )
					? 'granted'
					: 'denied',
			} );
		}
		document.dispatchEvent(
			new CustomEvent( 'cookie-dialog:consent', { detail } )
		);
		this.listeners.forEach( ( fn ) => fn( detail ) );
		this.fabShow();
	}

	/** Held scripts in their order (each external one loaded before the next runs), images, placeholders. */
	release() {
		const held = Array.from(
			document.querySelectorAll< HTMLScriptElement >(
				'script[type="text/plain"][data-cookie-service], script[type="text/plain"][data-cookie-category]'
			)
		).filter(
			( el ) =>
				! this.released.has( el ) &&
				this.allowed(
					el.dataset.cookieService || '',
					el.dataset.cookieCategory
				)
		);
		const next = ( i: number ) => {
			if ( i >= held.length ) {
				return;
			}
			const old = held[ i ];
			this.released.add( old );
			const el = document.createElement( 'script' );
			Array.from( old.attributes ).forEach( ( a ) => {
				if (
					a.name !== 'type' &&
					! a.name.startsWith( 'data-cookie-' )
				) {
					el.setAttribute( a.name, a.value );
				}
			} );
			if ( old.dataset.cookieType === 'module' ) {
				el.type = 'module';
			}
			el.setAttribute(
				'data-cookie-released',
				old.dataset.cookieService || ''
			);
			if ( old.src ) {
				el.async = false;
				el.addEventListener( 'load', () => next( i + 1 ) );
				el.addEventListener( 'error', () => next( i + 1 ) );
				old.replaceWith( el );
			} else {
				el.text = old.text;
				old.replaceWith( el );
				next( i + 1 );
			}
		};
		next( 0 );

		document
			.querySelectorAll< HTMLImageElement >( 'img[data-cookie-src]' )
			.forEach( ( img ) => {
				if ( this.allowed( img.dataset.cookieService || '' ) ) {
					img.src = img.dataset.cookieSrc || '';
					img.removeAttribute( 'data-cookie-src' );
				}
			} );
		document
			.querySelectorAll< HTMLElement >(
				'.cookie-dialog-ph[data-cookie-service]'
			)
			.forEach( ( ph ) => {
				const id = ph.dataset.cookieService || '';
				const service = this.c.services.find( ( s ) => s.id === id );
				if ( id && this.allowed( id, service?.cat ) ) {
					this.loadPlaceholder( ph );
				}
			} );
	}

	loadPlaceholder( ph: HTMLElement ) {
		const tpl = ph.querySelector( 'template' );
		if ( ! tpl ) {
			return;
		}
		if ( ph.dataset.cookieService ) {
			this.once.add( ph.dataset.cookieService );
		}
		const frag = tpl.content.cloneNode( true ) as DocumentFragment;
		// New script elements before the fragment goes in: a script cloned from the template would run
		// as it is inserted, and a copy made afterwards a second time.
		frag.querySelectorAll( 'script' ).forEach( ( old ) => {
			const el = document.createElement( 'script' );
			Array.from( old.attributes ).forEach( ( a ) =>
				el.setAttribute( a.name, a.value )
			);
			if ( el.src ) {
				el.async = false;
			}
			el.text = old.text;
			old.replaceWith( el );
		} );
		ph.replaceWith( frag );
	}

	/** Iframes a script adds later, for a service that waits, become placeholders. */
	watch() {
		const held = this.c.services.filter(
			( s ) => s.hosts && s.hosts.length
		);
		if ( ! held.length || ! ( 'MutationObserver' in window ) ) {
			return;
		}
		const match = ( src: string ) => {
			let host = '';
			try {
				host = new URL( src, location.href ).hostname;
			} catch {
				return null;
			}
			return (
				held.find( ( s ) =>
					( s.hosts || [] ).some(
						( h ) => host === h || host.endsWith( '.' + h )
					)
				) || null
			);
		};
		new MutationObserver( ( records ) => {
			records.forEach( ( r ) =>
				r.addedNodes.forEach( ( node ) => {
					if ( ! ( node instanceof HTMLIFrameElement ) ) {
						return;
					}
					const service = match( node.src );
					if (
						! service ||
						this.once.has( service.id ) ||
						this.allowed( service.id, service.cat )
					) {
						return;
					}
					node.replaceWith( this.clientPlaceholder( node, service ) );
				} )
			);
		} ).observe( document.documentElement, {
			childList: true,
			subtree: true,
		} );
	}

	clientPlaceholder( iframe: HTMLIFrameElement, s: Service ): HTMLElement {
		const t = this.c.texts;
		const fill = ( text: string ) =>
			text
				.replace( /\{service\}/g, s.name )
				.replace( /\{provider\}/g, s.provider || s.name );
		const ph = document.createElement( 'div' );
		ph.className = `cookie-dialog-ph cookie-dialog-ph--${
			s.embed?.type || 'other'
		}`;
		ph.dataset.cookieService = s.id;
		ph.dataset.cookieSrc = iframe.src;
		const w = Number( iframe.getAttribute( 'width' ) );
		const h = Number( iframe.getAttribute( 'height' ) );
		if ( w && h ) {
			ph.style.setProperty( '--cookie-dialog-ratio', `${ w } / ${ h }` );
		}
		const tpl = document.createElement( 'template' );
		tpl.setAttribute( 'data-cookie-dialog', 'embed' );
		tpl.content.appendChild( iframe.cloneNode( true ) );
		ph.appendChild( tpl );
		const over = document.createElement( 'div' );
		over.className = 'cookie-dialog-ph__over';
		const title = document.createElement( 'b' );
		title.className = 'cookie-dialog-ph__title';
		const key =
			{
				video: 'phVideo',
				map: 'phMap',
				social: 'phSocial',
				audio: 'phAudio',
			}[ s.embed?.type || '' ] || 'phOther';
		title.textContent = fill( t[ key ] || '' );
		const p = document.createElement( 'p' );
		p.className = 'cookie-dialog-ph__text';
		p.textContent = fill( s.cookies.length ? t.phCookies : t.phText );
		const buttons = document.createElement( 'span' );
		buttons.className = 'cookie-dialog-ph__buttons';
		const load = document.createElement( 'button' );
		load.type = 'button';
		load.className = 'cookie-dialog-ph__btn';
		load.setAttribute( 'data-cookie-load', '' );
		load.textContent = s.embed?.label || t.phLoad;
		const always = document.createElement( 'button' );
		always.type = 'button';
		always.className = 'cookie-dialog-ph__btn is-secondary';
		always.setAttribute( 'data-cookie-always', '' );
		always.textContent = t.phAlways;
		buttons.append( load, always );
		over.append( title, p, buttons );
		ph.appendChild( over );
		return ph;
	}

	onPageClick( e: Event ) {
		const target = e.target as Element | null;
		if ( ! target || ! target.closest ) {
			return;
		}
		const settings = target.closest(
			'[data-cookie-settings], a[href$="#cookie-settings"]'
		);
		if ( settings ) {
			e.preventDefault();
			this.show( 2 );
			return;
		}
		const load = target.closest( '[data-cookie-load]' );
		const always = target.closest( '[data-cookie-always]' );
		const ph = ( load || always )?.closest< HTMLElement >(
			'.cookie-dialog-ph'
		);
		if ( ! ph ) {
			return;
		}
		e.preventDefault();
		const id = ph.dataset.cookieService || '';
		if ( always && id ) {
			this.allowService( id );
		} else {
			this.loadPlaceholder( ph );
		}
	}

	idsFill() {
		document
			.querySelectorAll< HTMLElement >( '[data-cookie-dialog-id]' )
			.forEach( ( el ) => {
				const code = el.querySelector( 'code' );
				if ( this.choice && code ) {
					code.textContent = this.choice.id;
					el.hidden = false;
				}
			} );
	}

	/* ---------- rendering */

	mount() {
		const host = document.createElement( 'div' );
		host.id = 'cookie-dialog';
		host.setAttribute( 'data-cookie-dialog', 'root' );
		const root = host.attachShadow( { mode: 'open' } );
		const css = document.getElementById(
			'cookie-dialog-css'
		) as HTMLLinkElement | null;
		if ( css?.href ) {
			const link = document.createElement( 'link' );
			link.rel = 'stylesheet';
			link.href = css.href;
			root.appendChild( link );
		}
		this.host = host;
		this.root = root;
		this.theme();
		document.body.prepend( host );
	}

	theme() {
		const d = this.c.design;
		const h = this.host;
		if ( ! h ) {
			return;
		}
		const set = ( name: string, value: string ) =>
			h.style.setProperty( name, value );
		const light = d.colors;
		const dark = d.dark;
		[ 'background', 'text', 'button', 'buttonText', 'link' ].forEach(
			( k ) => {
				set( `--cd-l-${ k }`, light[ k as keyof Colors ] );
				set( `--cd-d-${ k }`, dark[ k as keyof Colors ] );
			}
		);
		set( '--cd-r', `${ d.radius }px` );
		// Unset, the dialog inherits the page's font.
		h.style.removeProperty( '--cd-font' );
		h.style.removeProperty( '--cd-head-font' );
		if ( d.font === 'system' ) {
			set(
				'--cd-font',
				'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'
			);
		} else if ( d.font === 'custom' && d.fontFamily ) {
			set( '--cd-font', d.fontFamily );
		}
		if ( d.headFont ) {
			set( '--cd-head-font', d.headFont );
		}
		h.dataset.scheme = d.scheme;
	}

	t( key: string ): string {
		return this.c.texts[ key ] || '';
	}

	el(
		tag: string,
		attrs: Record< string, string | boolean | undefined > = {},
		children: ( Node | string | null | false | undefined )[] = []
	): HTMLElement {
		const node = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ k, v ] ) => {
			if ( v === false || v === undefined ) {
				return;
			}
			node.setAttribute( k, v === true ? '' : v );
		} );
		children.forEach( ( c ) => {
			if ( c === null || c === false || c === undefined ) {
				return;
			}
			node.append(
				typeof c === 'string' ? document.createTextNode( c ) : c
			);
		} );
		return node;
	}

	/**
	 * A text that may hold links: parsed in an inert document, only a, strong, em and br kept.
	 * @param html
	 * @param attrs
	 * @param tag
	 */
	rich( html: string, attrs: Record< string, string > = {}, tag = 'p' ) {
		const node = this.el( tag, attrs );
		const doc = new DOMParser().parseFromString(
			`<body>${ html }</body>`,
			'text/html'
		);
		const copy = ( from: Node, to: Node ) => {
			from.childNodes.forEach( ( n ) => {
				if ( n.nodeType === Node.TEXT_NODE ) {
					to.appendChild(
						document.createTextNode( n.textContent || '' )
					);
				} else if ( n instanceof Element ) {
					const name = n.tagName.toLowerCase();
					if ( [ 'a', 'strong', 'em', 'br' ].includes( name ) ) {
						const el = document.createElement( name );
						if ( name === 'a' ) {
							const href = n.getAttribute( 'href' ) || '';
							if ( /^(https?:|\/|#|mailto:)/i.test( href ) ) {
								el.setAttribute( 'href', href );
							}
						}
						copy( n, el );
						to.appendChild( el );
					} else {
						copy( n, to );
					}
				}
			} );
		};
		copy( doc.body, node );
		return node;
	}

	editable( node: HTMLElement, key: string ): HTMLElement {
		if ( this.c.preview && this.c.preview.edit ) {
			node.setAttribute( 'data-key', key );
			node.classList.add( 'cd-editable' );
		}
		return node;
	}

	logo(): HTMLElement | null {
		const d = this.c.design;
		if ( ! d.logo ) {
			return null;
		}
		return this.el( 'div', { class: 'cd-logo' }, [
			this.el( 'img', { src: d.logo, alt: this.c.site } ),
		] );
	}

	foot( extra?: Node | null, first = false ): HTMLElement {
		const links: HTMLElement[] = [];
		const shown = ! first || this.c.design.showLinks;
		if ( shown && this.c.privacy ) {
			links.push(
				this.el( 'a', { href: this.c.privacy }, [
					this.t( 'privacy' ),
				] )
			);
		}
		if ( shown && this.c.imprint ) {
			links.push(
				this.el( 'a', { href: this.c.imprint }, [
					this.t( 'imprint' ),
				] )
			);
		}
		return this.el( 'div', { class: 'cd-foot' }, [
			extra || null,
			this.el( 'span', { class: 'cd-foot__links' }, links ),
			this.langPicker(),
			this.c.design.branding
				? this.el(
						'a',
						{
							class: 'cd-brand',
							href: 'https://engenty.ai/cookie-dialog',
							target: '_blank',
							rel: 'noopener',
						},
						[ 'Cookie Dialog' ]
				  )
				: null,
		] );
	}

	classes( kind: string ): string {
		const d = this.c.design;
		const small = this.c.small && ! this.c.preview;
		const layout = small ? 'box' : d.layout;
		return [
			'cd',
			`cd--${ kind }`,
			`cd--${ layout }`,
			`cd--${ d.size }`,
			`cd--${ small && d.position === 'center' ? 'left' : d.position }`,
			small ? 'cd--small' : '',
		]
			.filter( Boolean )
			.join( ' ' );
	}

	modal(): boolean {
		const d = this.c.design;
		return ! this.c.small && ( d.layout === 'modal' || d.lock );
	}

	/**
	 * A button's class by the chosen style: both filled, accept filled and only necessary outlined,
	 * or every button outlined.
	 * @param main Whether it is the button the style fills (accept on the first layer, save on the second).
	 */
	btn( main: boolean ): string {
		const { buttons } = this.c.design;
		const filled = buttons === 'same' || ( buttons === 'accept' && main );
		return filled ? 'cd-btn' : 'cd-btn cd-btn--sec';
	}

	layer1(): HTMLElement {
		const accept = this.el(
			'button',
			{ type: 'button', class: this.btn( true ), 'data-act': 'all' },
			[ this.t( 'accept' ) ]
		);
		const reject = this.el(
			'button',
			{
				type: 'button',
				class: this.btn( false ),
				'data-act': 'necessary',
			},
			[ this.t( 'reject' ) ]
		);
		const settings = this.el(
			'button',
			{ type: 'button', class: 'cd-link', 'data-act': 'settings' },
			[ this.t( 'settings' ) ]
		);
		this.editable( accept, 'accept' );
		this.editable( reject, 'reject' );
		this.editable( settings, 'settings' );
		const bar = this.c.design.layout === 'bar' && ! this.c.small;
		const title = this.editable(
			this.el(
				'h2',
				{
					id: 'cd-t',
					class: this.c.design.showTitle
						? 'cd-title'
						: 'cd-title cd-sr',
				},
				[ this.t( 'title' ) ]
			),
			'title'
		);
		const text = this.editable(
			this.rich( this.t( 'text' ), { id: 'cd-d', class: 'cd-text' } ),
			'text'
		);
		const gpc = this.gpc
			? this.el( 'p', { class: 'cd-gpc' }, [ this.t( 'gpc' ) ] )
			: null;
		if ( bar ) {
			return this.el(
				'div',
				{
					class: this.classes( 'first' ),
					role: 'dialog',
					'aria-modal': this.modal() ? 'true' : 'false',
					'aria-labelledby': 'cd-t',
					'aria-describedby': 'cd-d',
				},
				[
					this.el( 'div', { class: 'cd-bar__text' }, [
						title,
						text,
						gpc,
						this.foot( null, true ),
					] ),
					this.el( 'div', { class: 'cd-bar__btns' }, [
						settings,
						reject,
						accept,
					] ),
				]
			);
		}
		return this.el(
			'div',
			{
				class: this.classes( 'first' ),
				role: 'dialog',
				'aria-modal': this.modal() ? 'true' : 'false',
				'aria-labelledby': 'cd-t',
				'aria-describedby': 'cd-d',
			},
			[
				this.logo(),
				title,
				text,
				gpc,
				this.el( 'div', { class: 'cd-btns' }, [ accept, reject ] ),
				settings,
				this.foot( null, true ),
			]
		);
	}

	switchEl(
		label: string,
		state: 'on' | 'off' | 'mixed' | 'lock',
		data: Record< string, string >,
		small = false
	): HTMLElement {
		return this.el( 'button', {
			type: 'button',
			role: 'switch',
			class: `cd-sw${ small ? ' cd-sw--sm' : '' } is-${ state }`,
			'aria-checked':
				state === 'mixed' ? 'mixed' : String( state !== 'off' ),
			'aria-label': label,
			disabled: state === 'lock',
			...data,
		} );
	}

	catState( cat: Category ): 'on' | 'off' | 'mixed' | 'lock' {
		if ( cat.locked ) {
			return 'lock';
		}
		const list = this.servicesOf( cat.id );
		const on = list.filter( ( s ) =>
			this.draft.services.has( s.id )
		).length;
		if (
			this.draft.cats.has( cat.id ) ||
			( list.length && on === list.length )
		) {
			return 'on';
		}
		return on ? 'mixed' : 'off';
	}

	service( s: Service, cat: Category ): HTMLElement {
		const t = ( k: string ) => this.t( k );
		const kv: Node[] = [];
		if ( s.provider ) {
			kv.push(
				this.el( 'dt', {}, [ t( 'provider' ) ] ),
				this.el( 'dd', {}, [
					[ s.provider, s.address ].filter( Boolean ).join( ', ' ),
				] )
			);
		}
		if ( s.purpose ) {
			kv.push(
				this.el( 'dt', {}, [ t( 'purpose' ) ] ),
				this.el( 'dd', {}, [ s.purpose ] )
			);
		}
		if ( s.privacy ) {
			kv.push(
				this.el( 'dt', {}, [ t( 'policy' ) ] ),
				this.el( 'dd', {}, [
					this.el(
						'a',
						{
							href: s.privacy,
							target: '_blank',
							rel: 'noopener noreferrer',
						},
						[ s.privacy.replace( /^https?:\/\/(www\.)?/, '' ) ]
					),
				] )
			);
		}
		const table = s.cookies.length
			? this.el( 'table', {}, [
					this.el( 'thead', {}, [
						this.el( 'tr', {}, [
							this.el( 'th', {}, [ t( 'cookie' ) ] ),
							this.el( 'th', {}, [ t( 'duration' ) ] ),
							this.el( 'th', {}, [ t( 'type' ) ] ),
						] ),
					] ),
					this.el(
						'tbody',
						{},
						s.cookies.map( ( [ name, dur, type ] ) =>
							this.el( 'tr', {}, [
								this.el( 'td', {}, [ name ] ),
								this.el( 'td', {}, [ dur ] ),
								this.el( 'td', {}, [ type ] ),
							] )
						)
					),
			  ] )
			: null;
		const on =
			this.draft.services.has( s.id ) || this.draft.cats.has( cat.id );
		return this.el( 'div', { class: 'cd-svc' }, [
			this.el( 'div', { class: 'cd-svc__head' }, [
				this.el( 'b', {}, [ s.name ] ),
				cat.locked
					? this.el( 'span', { class: 'cd-always' }, [
							t( 'alwaysOn' ),
					  ] )
					: this.switchEl(
							s.name,
							on ? 'on' : 'off',
							{ 'data-svc': s.id },
							true
					  ),
			] ),
			kv.length ? this.el( 'dl', { class: 'cd-kv' }, kv ) : null,
			table,
		] );
	}

	layer2(): HTMLElement {
		const t = ( k: string ) => this.t( k );
		const cats = this.c.categories.map( ( cat ) => {
			const list = this.servicesOf( cat.id );
			const open = this.open.has( cat.id );
			const count =
				list.length === 1
					? t( 'service' )
					: t( 'services' ).replace( '%d', String( list.length ) );
			return this.el( 'div', { class: 'cd-cat' }, [
				this.el( 'div', { class: 'cd-cat__head' }, [
					this.el(
						'button',
						{
							type: 'button',
							class: 'cd-cat__toggle',
							'aria-expanded': open ? 'true' : 'false',
							'aria-controls': `cd-c-${ cat.id }`,
							'data-open': cat.id,
						},
						[
							this.el( 'b', {}, [ cat.name ] ),
							this.el( 'span', { class: 'cd-cat__n' }, [
								count,
							] ),
						]
					),
					this.switchEl( cat.name, this.catState( cat ), {
						'data-cat': cat.id,
					} ),
				] ),
				cat.desc
					? this.el( 'p', { class: 'cd-cat__desc' }, [ cat.desc ] )
					: null,
				this.el(
					'div',
					{
						class: 'cd-cat__svcs',
						id: `cd-c-${ cat.id }`,
						hidden: ! open,
					},
					list.map( ( s ) => this.service( s, cat ) )
				),
			] );
		} );
		const id = this.choice
			? this.el( 'span', { class: 'cd-id' }, [
					`${ t( 'consentId' ) }: ${ this.choice.id.slice(
						0,
						8
					) } · ${ new Date(
						this.choice.t * 1000
					).toLocaleDateString( this.c.lang ) }`,
			  ] )
			: null;
		const title = this.editable(
			this.el( 'h2', { id: 'cd-t2', class: 'cd-title' }, [
				t( 'layerTitle' ),
			] ),
			'layerTitle'
		);
		const text = this.editable(
			this.el( 'p', { class: 'cd-text' }, [ t( 'layerText' ) ] ),
			'layerText'
		);
		return this.el(
			'div',
			{
				class: this.classes( 'layer' ),
				role: 'dialog',
				'aria-modal': this.modal() || this.choice ? 'true' : 'false',
				'aria-labelledby': 'cd-t2',
			},
			[
				this.el(
					'button',
					{
						type: 'button',
						class: 'cd-x',
						'aria-label': t( 'close' ),
						'data-act': 'back',
					},
					[ '×' ]
				),
				this.el( 'div', { class: 'cd-layer__head' }, [
					this.logo(),
					title,
					text,
				] ),
				this.el( 'div', { class: 'cd-layer__body' }, cats ),
				this.el( 'div', { class: 'cd-layer__foot' }, [
					this.el( 'div', { class: 'cd-btns cd-btns--3' }, [
						this.editable(
							this.el(
								'button',
								{
									type: 'button',
									class: this.btn( true ),
									'data-act': 'save',
								},
								[ t( 'save' ) ]
							),
							'save'
						),
						this.el(
							'button',
							{
								type: 'button',
								class: 'cd-btn cd-btn--sec',
								'data-act': 'all',
							},
							[ t( 'accept' ) ]
						),
						this.el(
							'button',
							{
								type: 'button',
								class: 'cd-btn cd-btn--sec',
								'data-act': 'necessary',
							},
							[ t( 'reject' ) ]
						),
					] ),
					this.foot( id ),
				] ),
			]
		);
	}

	placeholderPreview(): HTMLElement {
		const s =
			this.c.services.find( ( x ) => x.embed?.type === 'video' ) ||
			this.c.services.find( ( x ) => x.embed ) ||
			( {
				id: 'demo',
				name: 'YouTube',
				cat: 'marketing',
				provider: 'Google Ireland Limited',
				address: '',
				purpose: '',
				privacy: '',
				cookies: [ [ 'YSC', '', '' ] ],
				embed: { type: 'video', label: '' },
			} as Service );
		const frame = document.createElement( 'iframe' );
		frame.width = '560';
		frame.height = '315';
		const ph = this.clientPlaceholder( frame, s );
		return this.el( 'div', { class: 'cd-ph-stage' }, [ ph ] );
	}

	show( layer: 1 | 2 | 3 ) {
		if ( ! this.root ) {
			return;
		}
		if ( this.layer === 0 ) {
			// eslint-disable-next-line @wordpress/no-global-active-element -- where focus was on the page, outside the dialog.
			this.lastFocus = document.activeElement;
		}
		if ( layer === 2 ) {
			this.draft = {
				cats: new Set(
					this.choice?.c.filter( ( c ) => c !== 'necessary' ) || []
				),
				services: new Set( this.choice?.s || [] ),
			};
		}
		this.layer = layer;
		this.render();
		this.fabShow();
	}

	render() {
		const root = this.root;
		if ( ! root ) {
			return;
		}
		root.querySelectorAll( '.cd, .cd-backdrop, .cd-ph-stage' ).forEach(
			( n ) => n.remove()
		);
		if ( this.layer === 0 ) {
			document.documentElement.classList.remove( 'cookie-dialog-locked' );
			return;
		}
		const d = this.c.design;
		const node =
			this.layer === 3
				? this.placeholderPreview()
				: this.layer === 2
				? this.layer2()
				: this.layer1();
		const backdrop =
			this.layer !== 3 &&
			! this.c.small &&
			( d.backdrop ||
				d.layout === 'modal' ||
				( this.layer === 2 && !! this.choice ) );
		if ( backdrop ) {
			root.appendChild( this.el( 'div', { class: 'cd-backdrop' } ) );
		}
		root.appendChild( node );
		document.documentElement.classList.toggle(
			'cookie-dialog-locked',
			Boolean( d.lock && ! this.c.small && ! this.c.preview )
		);
		node.addEventListener( 'click', ( e ) => this.onClick( e ) );
		node.addEventListener( 'keydown', ( e ) =>
			this.onKey( e as KeyboardEvent, node )
		);
		if ( this.c.preview && this.c.preview.edit ) {
			this.wireEditing( node );
		}
		if ( ! this.c.preview ) {
			const focus =
				this.layer === 2
					? node.querySelector< HTMLElement >( '.cd-cat__toggle' )
					: node.querySelector< HTMLElement >( '.cd-btn' );
			if ( this.modal() || this.layer === 2 ) {
				focus?.focus( { preventScroll: true } );
			}
		}
	}

	close() {
		this.layer = 0;
		this.render();
		this.fabShow();
		if ( this.lastFocus instanceof HTMLElement ) {
			this.lastFocus.focus( { preventScroll: true } );
		}
	}

	onClick( e: Event ) {
		const target = ( e.target as Element ).closest< HTMLElement >(
			'[data-act], [data-cat], [data-svc], [data-open], [data-lang]'
		);
		if (
			! target ||
			( this.c.preview &&
				this.c.preview.edit &&
				target.closest( '[contenteditable="true"]' ) )
		) {
			return;
		}
		if ( target.dataset.lang ) {
			const code = target.dataset.lang;
			this.useLang( code );
			try {
				window.localStorage.setItem( LANG_KEY, code );
			} catch {
				// Without storage the language holds for this page.
			}
			this.render();
			this.root
				?.querySelector< HTMLElement >( `[data-lang="${ code }"]` )
				?.focus( { preventScroll: true } );
			return;
		}
		if ( target.dataset.open ) {
			const id = target.dataset.open;
			if ( this.open.has( id ) ) {
				this.open.delete( id );
			} else {
				this.open.add( id );
			}
			this.render();
			this.root
				?.querySelector< HTMLElement >( `[data-open="${ id }"]` )
				?.focus();
			return;
		}
		if ( target.dataset.cat ) {
			const id = target.dataset.cat;
			const cat = this.c.categories.find( ( c ) => c.id === id );
			if ( ! cat || cat.locked ) {
				return;
			}
			const on = this.catState( cat ) !== 'on';
			const list = this.servicesOf( id );
			if ( on ) {
				this.draft.cats.add( id );
				list.forEach( ( s ) => this.draft.services.add( s.id ) );
			} else {
				this.draft.cats.delete( id );
				list.forEach( ( s ) => this.draft.services.delete( s.id ) );
			}
			this.render();
			this.root
				?.querySelector< HTMLElement >( `[data-cat="${ id }"]` )
				?.focus();
			return;
		}
		if ( target.dataset.svc ) {
			const id = target.dataset.svc;
			const s = this.c.services.find( ( x ) => x.id === id );
			if ( ! s ) {
				return;
			}
			if (
				this.draft.services.has( id ) ||
				this.draft.cats.has( s.cat )
			) {
				this.draft.services.delete( id );
				if ( this.draft.cats.has( s.cat ) ) {
					this.draft.cats.delete( s.cat );
					this.servicesOf( s.cat )
						.filter( ( x ) => x.id !== id )
						.forEach( ( x ) => this.draft.services.add( x.id ) );
				}
			} else {
				this.draft.services.add( id );
				const list = this.servicesOf( s.cat );
				if ( list.every( ( x ) => this.draft.services.has( x.id ) ) ) {
					this.draft.cats.add( s.cat );
				}
			}
			this.render();
			this.root
				?.querySelector< HTMLElement >( `[data-svc="${ id }"]` )
				?.focus();
			return;
		}
		switch ( target.dataset.act ) {
			case 'all':
				this.decide( 'all', this.layer === 2 ? 'settings' : 'dialog' );
				break;
			case 'necessary':
				this.decide(
					'necessary',
					this.layer === 2 ? 'settings' : 'dialog'
				);
				break;
			case 'save':
				this.decide( 'custom', 'settings' );
				break;
			case 'settings':
				this.show( 2 );
				break;
			case 'back':
				if ( this.choice || this.c.preview ) {
					this.close();
				} else {
					this.show( 1 );
				}
				break;
		}
	}

	/**
	 * Tab stays inside a modal dialog; Escape goes back from the second layer.
	 * @param e
	 * @param node
	 */
	onKey( e: KeyboardEvent, node: HTMLElement ) {
		if ( e.key === 'Escape' && this.layer === 2 ) {
			e.preventDefault();
			if ( this.choice ) {
				this.close();
			} else {
				this.show( 1 );
			}
			return;
		}
		if ( e.key !== 'Tab' || ! ( this.modal() || this.layer === 2 ) ) {
			return;
		}
		const items = Array.from(
			node.querySelectorAll< HTMLElement >(
				'button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
			)
		).filter( ( el ) => el.offsetParent !== null );
		if ( ! items.length ) {
			return;
		}
		const active = this.root?.activeElement;
		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		if ( e.shiftKey && active === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && active === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	fabShow() {
		if ( this.c.preview || ! this.c.reopen.button || ! this.root ) {
			return;
		}
		if ( ! this.fab ) {
			this.fab = this.el(
				'button',
				{
					type: 'button',
					class: `cd-fab cd-fab--${ this.c.reopen.position }`,
					'aria-label': this.t( 'reopen' ),
					title: this.t( 'reopen' ),
				},
				[]
			) as HTMLButtonElement;
			this.fab.innerHTML =
				'<svg width="24" height="24" aria-hidden="true" viewBox="0 0 24 24"><path fill="currentColor" d="M7.6 9.4 L9 4.3 Q9.3 3.3 10.3 3.6 L12 4.2 L13.7 3.6 Q14.7 3.3 15 4.3 L16.4 9.4 Z M3.2 10.3 H20.8 V12.1 H3.2 Z"/><path fill="currentColor" fill-rule="evenodd" d="M17 16.8 A5 5 0 1 0 7 16.8 A5 5 0 1 0 17 16.8 Z M15 16.8 A3 3 0 1 1 9 16.8 A3 3 0 1 1 15 16.8 Z"/><path fill="currentColor" d="M4.2 16 H7.2 V17.6 H4.2 Z M16.8 16 H19.8 V17.6 H16.8 Z"/><path fill="currentColor" d="M14.2 17.2 A1.5 1.5 0 1 0 11.2 17.2 A1.5 1.5 0 1 0 14.2 17.2 Z"/></svg>';
			this.fab.addEventListener( 'click', () => this.show( 2 ) );
			this.root.appendChild( this.fab );
		}
		this.fab.hidden = ! this.choice || this.layer !== 0;
	}

	/* ---------- the dialog's language */

	/** Whether visitors pick the dialog's language: switched on and more than one there. */
	langChoice(): boolean {
		return Boolean(
			this.c.design.showLanguages &&
				this.c.langs &&
				Object.keys( this.c.langs ).length > 1
		);
	}

	/** The language this visitor picked before, when there is a choice. */
	pickedLang() {
		if ( this.c.preview || ! this.langChoice() ) {
			return;
		}
		let code: string | null = null;
		try {
			code = window.localStorage.getItem( LANG_KEY );
		} catch {
			code = null;
		}
		if ( code && code !== this.c.lang ) {
			this.useLang( code );
		}
	}

	/**
	 * Texts, services and categories in another language; the log records it with the choice.
	 * @param code
	 */
	useLang( code: string ) {
		const words = this.c.langs ? this.c.langs[ code ] : null;
		if ( ! words ) {
			return;
		}
		this.c = {
			...this.c,
			lang: code,
			texts: words.texts,
			services: words.services,
			categories: words.categories,
		};
		if ( this.fab ) {
			this.fab.setAttribute( 'aria-label', this.t( 'reopen' ) );
			this.fab.title = this.t( 'reopen' );
		}
	}

	/** The languages as short codes, each with its own name for screen readers. */
	langPicker(): HTMLElement | null {
		if ( ! this.langChoice() || ! this.c.langs ) {
			return null;
		}
		const name = ( code: string ) => {
			try {
				return (
					new Intl.DisplayNames( [ code ], { type: 'language' } ).of(
						code
					) || code
				);
			} catch {
				return code;
			}
		};
		return this.el(
			'span',
			{ class: 'cd-langs' },
			Object.keys( this.c.langs ).map( ( code ) =>
				this.el(
					'button',
					{
						type: 'button',
						'data-lang': code,
						lang: code,
						title: name( code ),
						'aria-label': name( code ),
						'aria-pressed': code === this.c.lang ? 'true' : 'false',
					},
					[ code.toUpperCase() ]
				)
			)
		);
	}

	/* ---------- the preview on the site */

	/**
	 * A line at the top while the admin looks at the site as a new visitor: start again with no
	 * choice, or end the preview.
	 * @param p
	 */
	previewBar( p: SitePreview ) {
		const host = document.createElement( 'div' );
		host.setAttribute( 'data-cookie-dialog', 'preview' );
		const root = host.attachShadow( { mode: 'open' } );
		const style = document.createElement( 'style' );
		// One line at the top, as narrow as its words: left and right 0 with auto margins, not left 50%
		// (that halves the width it may take and wrapped the note into three lines).
		style.textContent =
			':host{all:initial}.bar{position:fixed;z-index:2147483646;top:10px;left:0;right:0;margin:0 auto;width:max-content;max-width:calc(100vw - 24px);box-sizing:border-box;display:flex;align-items:center;gap:8px;padding:4px 4px 4px 12px;border-radius:999px;background:rgba(40,38,35,.94);color:#f7f5f2;font:500 12.5px/1.2 system-ui,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.22)}b{flex:none;display:inline-flex;align-items:center;gap:6px;font-weight:650}b::before{content:"";width:7px;height:7px;border-radius:50%;background:#e9c46a}span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:rgba(247,245,242,.75)}button,a{flex:none;border:0;border-radius:999px;padding:5px 10px;font:inherit;cursor:pointer;text-decoration:none}button{background:rgba(255,255,255,.14);color:inherit}button:hover{background:rgba(255,255,255,.22)}a{background:#f7f5f2;color:#282623}@media (max-width:560px){span{display:none}}';
		const restart = document.createElement( 'button' );
		restart.type = 'button';
		restart.textContent = p.restart;
		restart.addEventListener( 'click', () => {
			try {
				window.sessionStorage.removeItem( PREVIEW_KEY );
			} catch {
				// Nothing kept: the page starts fresh anyway.
			}
			window.location.reload();
		} );
		const end = document.createElement( 'a' );
		end.href = p.endUrl;
		end.textContent = p.end;
		const label = document.createElement( 'b' );
		label.textContent = p.label;
		const note = document.createElement( 'span' );
		note.textContent = p.note;
		const bar = document.createElement( 'div' );
		bar.className = 'bar';
		bar.title = p.title;
		bar.setAttribute( 'role', 'status' );
		bar.append( label, note, restart, end );
		root.append( style, bar );
		document.body.append( host );
	}

	/* ---------- the admin's preview */

	preview( p: Preview ) {
		this.show( p.layer === '2' ? 2 : p.layer === 'ph' ? 3 : 1 );
		if ( p.layer === '2' ) {
			this.open.add( this.c.categories[ 1 ]?.id || '' );
			this.render();
		}
		window.addEventListener( 'message', ( e ) => {
			if (
				e.origin !== location.origin ||
				! e.data ||
				typeof e.data !== 'object'
			) {
				return;
			}
			if ( e.data.type === 'cookie-dialog:config' && e.data.config ) {
				const next = e.data.config as Partial< Config >;
				this.c = { ...this.c, ...next, preview: this.c.preview };
				this.theme();
				this.render();
			}
			if ( e.data.type === 'cookie-dialog:layer' ) {
				const l = String( e.data.layer );
				this.show( l === '2' ? 2 : l === 'ph' ? 3 : 1 );
			}
		} );
		window.parent?.postMessage(
			{ type: 'cookie-dialog:ready' },
			location.origin
		);
	}

	/**
	 * In the admin's preview a text is changed where it stands; the admin saves it.
	 * @param node
	 */
	wireEditing( node: HTMLElement ) {
		node.querySelectorAll< HTMLElement >( '[data-key]' ).forEach(
			( el ) => {
				el.addEventListener( 'click', ( e ) => {
					if ( el.isContentEditable ) {
						return;
					}
					e.preventDefault();
					e.stopPropagation();
					el.contentEditable = 'true';
					el.focus();
					window.parent?.postMessage(
						{ type: 'cookie-dialog:editing', key: el.dataset.key },
						location.origin
					);
				} );
				el.addEventListener( 'keydown', ( e ) => {
					if ( e.key === 'Enter' && ! e.shiftKey ) {
						e.preventDefault();
						el.blur();
					}
				} );
				el.addEventListener( 'blur', () => {
					el.contentEditable = 'false';
					const key = el.dataset.key || '';
					const value =
						key === 'text'
							? el.innerHTML.trim()
							: ( el.textContent || '' ).trim();
					if ( value !== this.t( key ) ) {
						this.c.texts[ key ] = value;
						window.parent?.postMessage(
							{
								type: 'cookie-dialog:text',
								key,
								value,
								lang: this.c.lang,
							},
							location.origin
						);
					}
				} );
			}
		);
	}
}

/* ------------------------------------------------------------------ start */

const stub = {
	has: ( key: string ) => key === 'necessary',
	open: () => undefined,
	consent: () => null,
	on: () => undefined,
	version: 0,
};

if ( cfg ) {
	const dialog = new CookieDialog( cfg );
	window.cookieDialog = {
		has: ( key: string ) => dialog.has( key ),
		open: ( layer: 1 | 2 = 2 ) => dialog.show( layer ),
		consent: () => ( dialog.choice ? { ...dialog.choice } : null ),
		on: ( event: string, fn: ( detail: unknown ) => void ) => {
			if ( event === 'consent' ) {
				dialog.listeners.push( fn );
			}
		},
		version: cfg.v,
	};
	const go = () => dialog.start();
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', go );
	} else {
		go();
	}
} else {
	window.cookieDialog = stub;
}

export {};
