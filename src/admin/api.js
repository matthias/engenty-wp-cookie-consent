import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export const boot = window.cookieDialogAdmin || {};

/**
 * A route of `cookie-dialog/v1` (includes/class-rest.php).
 * @param path
 * @param options
 */
export const api = ( path, options = {} ) =>
	apiFetch( { path: `/cookie-dialog/v1${ path }`, ...options } );

export const post = ( path, data = {} ) =>
	api( path, { method: 'POST', data } );

/**
 * What went wrong, as one line.
 * @param error
 */
export function problem( error ) {
	return {
		code: String( error?.code || 'failed' ).replace(
			/^cookie_dialog_/,
			''
		),
		message:
			error?.message || __( 'Das hat nicht geklappt.', 'cookie-dialog' ),
	};
}

/**
 * `?a=b` of the set values.
 * @param params
 */
export function query( params ) {
	const q = new URLSearchParams();
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			q.set( key, String( value ) );
		}
	} );
	const s = q.toString();
	return s ? `?${ s }` : '';
}

/**
 * An admin page of this plugin.
 * @param view
 * @param extra
 */
export const pageUrl = ( view, extra = '' ) => {
	const slug = {
		start: 'cookie-dialog',
		dialog: 'cookie-dialog-dialog',
		services: 'cookie-dialog-services',
		log: 'cookie-dialog-log',
		settings: 'cookie-dialog-settings',
	}[ view ];
	return `${ boot.admin || 'admin.php' }?page=${ slug }${ extra }`;
};
