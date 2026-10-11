/**
 * The dialog on a real WordPress: nothing from third parties before consent, everything after,
 * rejection keeps the page clean, the choice can be reopened, embeds wait as placeholders, and the
 * page holds nothing about the visitor (page caches can keep it).
 *
 * Every request to a third party is recorded and answered empty, so the tests need no network.
 */
const { test, expect } = require( '@playwright/test' );

const TRACKERS =
	/googletagmanager\.com|google-analytics\.com|facebook\.(net|com)|trustindex\.io|youtube(-nocookie)?\.com|instagram\.com|google\.com\/maps/;

async function watch( page ) {
	const seen = [];
	const own = new URL( test.info().project.use.baseURL ).host;
	await page.route( '**/*', ( route ) => {
		const url = new URL( route.request().url() );
		if ( url.host === own || url.protocol === 'data:' ) {
			return route.continue();
		}
		seen.push( url.href );
		return route.fulfill( { status: 200, body: '' } );
	} );
	return seen;
}

const dialog = ( page ) => page.locator( '#cookie-dialog' );

async function choice( context ) {
	const cookie = ( await context.cookies() ).find(
		( c ) => c.name === 'cookie_dialog'
	);
	return cookie ? JSON.parse( decodeURIComponent( cookie.value ) ) : null;
}

test( 'no third party and no cookie before consent', async ( {
	page,
	context,
} ) => {
	const seen = await watch( page );
	await page.goto( '/' );
	await expect( dialog( page ).getByRole( 'dialog' ) ).toBeVisible();
	await page.waitForLoadState( 'networkidle' );
	expect( seen.filter( ( u ) => TRACKERS.test( u ) ) ).toEqual( [] );
	expect( ( await context.cookies() ).map( ( c ) => c.name ) ).toEqual( [] );
	const consent = await page.evaluate( () =>
		window.dataLayer
			.filter( ( e ) => e[ 0 ] === 'consent' )
			.map( ( e ) => [ e[ 1 ], e[ 2 ].analytics_storage ] )
	);
	expect( consent[ consent.length - 1 ] ).toEqual( [ 'default', 'denied' ] );
} );

test( 'accept all releases the tags and records the choice', async ( {
	page,
	context,
} ) => {
	const seen = await watch( page );
	await page.goto( '/' );
	const logged = page.waitForResponse( ( r ) =>
		r.url().includes( '/cookie-dialog/v1/consent' )
	);
	await dialog( page )
		.getByRole( 'button', { name: 'Alle akzeptieren' } )
		.click();
	expect( ( await logged ).status() ).toBe( 201 );
	await expect
		.poll( () =>
			seen.some( ( u ) => u.includes( 'googletagmanager.com/gtag/js' ) )
		)
		.toBe( true );
	const c = await choice( context );
	expect( c.c ).toEqual(
		expect.arrayContaining( [ 'necessary', 'statistics', 'marketing' ] )
	);
	const update = await page.evaluate(
		() =>
			window.dataLayer
				.filter( ( e ) => e[ 0 ] === 'consent' && e[ 1 ] === 'update' )
				.pop()[ 2 ]
	);
	expect( update.analytics_storage ).toBe( 'granted' );
	expect( update.ad_storage ).toBe( 'granted' );
	await expect( dialog( page ).getByRole( 'dialog' ) ).toHaveCount( 0 );
	// The next page knows the choice before any tag.
	await page.goto( '/kontakt/' );
	await expect( dialog( page ).getByRole( 'dialog' ) ).toHaveCount( 0 );
	await expect(
		page.locator( 'iframe[src*="google.com/maps"]' )
	).toHaveCount( 1 );
} );

test( 'only necessary keeps the page clean', async ( { page, context } ) => {
	const seen = await watch( page );
	await page.goto( '/' );
	await dialog( page )
		.getByRole( 'button', { name: 'Nur notwendige' } )
		.click();
	await page.reload();
	await page.waitForLoadState( 'networkidle' );
	expect( seen.filter( ( u ) => TRACKERS.test( u ) ) ).toEqual( [] );
	expect( ( await choice( context ) ).c ).toEqual( [ 'necessary' ] );
	await expect( dialog( page ).getByRole( 'dialog' ) ).toHaveCount( 0 );
} );

test( 'the choice can be reopened and changed per category', async ( {
	page,
	context,
} ) => {
	await watch( page );
	await page.goto( '/' );
	await dialog( page )
		.getByRole( 'button', { name: 'Nur notwendige' } )
		.click();
	await dialog( page )
		.getByRole( 'button', { name: 'Cookie-Einstellungen' } )
		.click();
	const layer = dialog( page ).getByRole( 'dialog' );
	await expect( layer.getByText( 'Deine Auswahl' ) ).toBeVisible();
	await expect( layer.getByText( /Deine Consent-ID/ ) ).toBeVisible();
	await layer.getByRole( 'switch', { name: 'Statistik' } ).click();
	await layer.getByRole( 'button', { name: 'Auswahl speichern' } ).click();
	expect( ( await choice( context ) ).c ).toEqual( [
		'necessary',
		'statistics',
	] );
	// A link to #cookie-settings anywhere opens it too.
	await page.goto( '/kontakt/' );
	await page.locator( '[data-cookie-settings]' ).first().click();
	await expect( dialog( page ).getByText( 'Deine Auswahl' ) ).toBeVisible();
} );

test( 'an embed waits as a placeholder and loads on click', async ( {
	page,
	context,
} ) => {
	const seen = await watch( page );
	await page.goto( '/leistungen/' );
	const ph = page.locator(
		'.cookie-dialog-ph[data-cookie-service="youtube"]'
	);
	await expect( ph ).toBeVisible();
	expect( seen.filter( ( u ) => u.includes( 'youtube' ) ) ).toEqual( [] );
	await ph.getByRole( 'button', { name: 'Video laden' } ).click();
	await expect(
		page.locator( 'iframe[src^="https://www.youtube-nocookie.com/embed/"]' )
	).toHaveCount( 1 );
	// Loading once is not consent.
	expect( await choice( context ) ).toBeNull();
} );

test( '„Immer laden“ is consent for that service, logged from the placeholder', async ( {
	page,
	context,
} ) => {
	await watch( page );
	await page.goto( '/leistungen/' );
	const logged = page.waitForRequest( ( r ) =>
		r.url().includes( '/cookie-dialog/v1/consent' )
	);
	await page
		.locator( '.cookie-dialog-ph' )
		.getByRole( 'button', { name: 'Immer laden' } )
		.click();
	const body = JSON.parse( ( await logged ).postData() );
	expect( body.source ).toBe( 'placeholder' );
	expect( body.services ).toContain( 'youtube' );
	expect( ( await choice( context ) ).s ).toContain( 'youtube' );
	await expect(
		page.locator( 'iframe[src*="youtube-nocookie.com"]' )
	).toHaveCount( 1 );
	// Only YouTube: the dialog still asks for the rest, on the next page too.
	const reject = dialog( page ).getByRole( 'button', {
		name: 'Nur notwendige',
	} );
	await expect( reject ).toBeVisible();
	await page.reload();
	await expect( reject ).toBeVisible();
	await expect(
		page.locator( 'iframe[src*="youtube-nocookie.com"]' )
	).toHaveCount( 1 );
	await reject.click();
	const decided = await choice( context );
	expect( decided.s ).toContain( 'youtube' );
	expect( decided.p ).toBeUndefined();
} );

test( 'the page is the same for every visitor', async ( { request } ) => {
	const a = await ( await request.get( '/' ) ).text();
	const b = await (
		await request.get( '/', {
			headers: {
				Cookie: 'cookie_dialog=%7B%22id%22%3A%22x%22%2C%22v%22%3A1%2C%22t%22%3A1%2C%22c%22%3A%5B%22necessary%22%5D%2C%22s%22%3A%5B%5D%7D',
			},
		} )
	).text();
	const config = ( html ) =>
		html.match( /id="cookie-dialog-config"[^>]*>([^<]*)</ )[ 1 ];
	expect( config( a ) ).toBe( config( b ) );
} );

test( 'keyboard: the dialog is reachable and the second layer closes with Escape', async ( {
	page,
} ) => {
	await watch( page );
	await page.goto( '/' );
	await page.keyboard.press( 'Tab' );
	const focused = await page.evaluate(
		() =>
			document.getElementById( 'cookie-dialog' ).shadowRoot.activeElement
				?.textContent
	);
	expect( focused ).toBe( 'Alle akzeptieren' );
	await dialog( page )
		.getByRole( 'button', { name: 'Einstellungen' } )
		.click();
	await expect( dialog( page ).getByText( 'Deine Auswahl' ) ).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await expect( dialog( page ).getByText( 'Cookies bei' ) ).toBeVisible();
} );
