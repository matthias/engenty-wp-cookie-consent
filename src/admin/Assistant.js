import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { post } from './api';
import { languageName } from './ui';

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
	const session = await window.LanguageModel.create( {
		initialPrompts: [ { role: 'system', content: system } ],
		expectedInputs: [ { type: 'text', languages: [ 'en' ] } ],
		expectedOutputs: [ { type: 'text', languages: [ lang ] } ],
	} );
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
 * Whether the browser translates on the device (Chrome's Translator) from one language to another.
 * @param source
 * @param target
 */
async function canTranslate( source, target ) {
	if ( ! window.Translator || ! source || ! target ) {
		return false;
	}
	try {
		// Chrome answers only in the visible tab; a hidden one gets no answer, so it waits 5 s at most.
		const state = await Promise.race( [
			window.Translator.availability( {
				sourceLanguage: source,
				targetLanguage: target,
			} ),
			new Promise( ( resolve ) =>
				window.setTimeout( () => resolve( 'unavailable' ), 5000 )
			),
		] );
		return state !== 'unavailable';
	} catch {
		return false;
	}
}

/**
 * The dialog's texts one by one through Chrome's Translator. Placeholders like {site} and %d become
 * numbers first and come back after; a text that loses one keeps its source wording.
 * @param {Object} run
 * @param {string} run.source
 * @param {string} run.target
 * @param {Object} run.strings `texts` and `categories` (name, description).
 */
async function translateInBrowser( { source, target, strings } ) {
	const translator = await window.Translator.create( {
		sourceLanguage: source,
		targetLanguage: target,
	} );
	const one = async ( text ) => {
		if ( ! text ) {
			return text;
		}
		const marks = [];
		const masked = text.replace( /\{[a-z_]+\}|%(?:\d+\$)?[sd]/gi, ( m ) => {
			marks.push( m );
			return `{${ marks.length - 1 }}`;
		} );
		const out = await translator.translate( masked );
		const back = out.replace(
			/\{\s*(\d+)\s*\}/g,
			( m, i ) => marks[ Number( i ) ] ?? m
		);
		return marks.every( ( m ) => back.includes( m ) ) ? back : text;
	};
	try {
		const texts = {};
		for ( const [ key, value ] of Object.entries( strings.texts ) ) {
			texts[ key ] = await one( value );
		}
		const categories = {};
		for ( const [ id, cat ] of Object.entries( strings.categories ) ) {
			categories[ id ] = {
				name: await one( cat.name ),
				description: await one( cat.description ),
			};
		}
		return { texts, categories };
	} finally {
		translator.destroy?.();
	}
}

const BROWSER_FAILED = () =>
	__(
		'Das Modell deines Browsers hat das nicht geschafft. Mit einem KI-Anbieter (Einstellungen › Konnektoren) klappt es.',
		'cookie-dialog'
	);

/**
 * What the server handed over, done in the browser: translating with Chrome's Translator where it
 * knows the languages, the rest with the built-in model in a language it writes.
 * @param {string} job
 * @param {Object} run The prompt, schema and – for translating – the texts.
 */
async function runInBrowserJob( job, run ) {
	if ( run.strings && ( await canTranslate( run.source, run.target ) ) ) {
		try {
			return await translateInBrowser( run );
		} catch {
			throw new Error( BROWSER_FAILED() );
		}
	}
	if ( ( await browserModel() ) === 'unavailable' ) {
		throw new Error(
			__(
				'Dafür braucht WordPress einen KI-Anbieter (Einstellungen › Konnektoren) – oder Chrome mit eingebautem Modell.',
				'cookie-dialog'
			)
		);
	}
	const lang = job === 'translate' ? run.target : 'en';
	let writes = 'unavailable';
	try {
		writes = await window.LanguageModel.availability( {
			expectedInputs: [ { type: 'text', languages: [ 'en' ] } ],
			expectedOutputs: [ { type: 'text', languages: [ lang ] } ],
		} );
	} catch {
		// Unknown language: not offered.
	}
	if ( writes === 'unavailable' ) {
		throw new Error(
			sprintf(
				/* translators: %s: language name */
				__(
					'Das Modell deines Browsers schreibt nicht auf %s. Mit einem KI-Anbieter (Einstellungen › Konnektoren) klappt es.',
					'cookie-dialog'
				),
				languageName( lang )
			)
		);
	}
	try {
		return await runInBrowser( run, lang );
	} catch {
		throw new Error( BROWSER_FAILED() );
	}
}

/**
 * A job (translate, shorten, classify, privacy, setup): the provider runs it on the server, else the
 * browser here, and the server applies the answer either way.
 * @param {string}   job
 * @param {Object}   input
 * @param {Function} onState Gets the new state.
 * @return {Promise<Object>} What was done (`message`, `undo`).
 */
export async function runJob( job, input, onState ) {
	const first = await post( '/job', { job, input } );
	if ( first.run ) {
		const result = await runInBrowserJob( job, first.run );
		const second = await post( '/job', { job, input, result } );
		onState( second );
		return second.result;
	}
	onState( first );
	return first.result;
}
