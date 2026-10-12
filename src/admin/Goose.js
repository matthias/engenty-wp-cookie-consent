import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { boot } from './api';
import { Button, Engenty, Icon } from './ui';

/**
 * engenty.goose, the copilot, when it is on this page (`window.engentyGoose`): the conversation about
 * the dialog is its. It may come after the app; it says so with `engenty-goose:ready`.
 */
export function useGoose() {
	const [ goose, setGoose ] = useState( () => window.engentyGoose || null );
	useEffect( () => {
		if ( goose ) {
			return undefined;
		}
		const on = () => setGoose( window.engentyGoose || null );
		document.addEventListener( 'engenty-goose:ready', on );
		return () => document.removeEventListener( 'engenty-goose:ready', on );
	}, [ goose ] );
	return goose;
}

/**
 * The card on Start: Goose's own card when it is here – what it read, a line to type into, what to
 * ask; the answers come in its dock. Without it, the same title and what Goose would do here, with
 * the way to activate or get it.
 * @param {Object}   root0
 * @param {string}   root0.title
 * @param {string}   root0.text        A line under the title (optional).
 * @param {string}   root0.placeholder What the input says before anything is typed.
 * @param {string[]} root0.suggestions What to ask.
 */
export function GooseCard( { title, text, placeholder, suggestions = [] } ) {
	const goose = useGoose();
	if ( goose?.Card ) {
		const { Card } = goose;
		return (
			<Card
				id="assistant"
				title={ title }
				text={ text }
				placeholder={ placeholder }
				suggestions={ suggestions }
			/>
		);
	}
	const g = boot.goose;
	// Active, but not for this person: nothing to offer.
	if ( ! g || g.state === 'active' ) {
		return null;
	}
	return (
		<div className="cd-acard cd-goose" id="assistant">
			<span className="cd-acard__eng">
				<Engenty kind="goose" size={ 56 } />
			</span>
			<span className="cd-kick">
				<Icon name="sparkle" size={ 12 } /> engenty.goose
			</span>
			<h2>{ title }</h2>
			{ text ? <p>{ text }</p> : null }
			<p className="cd-goose__why">
				{ __(
					'Mit engenty.goose, dem Copiloten für WordPress, sagst du hier, was anders sein soll: Er ändert Dialog, Dienste und Einstellungen, zeigt dir, wo was ist, und jede Änderung lässt sich zurücknehmen.',
					'cookie-dialog'
				) }
			</p>
			<div className="cd-row-inline cd-goose__go">
				{ g.activate ? (
					<Button kind="primary" size="sm" href={ g.activate }>
						{ __( 'engenty.goose aktivieren', 'cookie-dialog' ) }
					</Button>
				) : null }
				{ g.install ? (
					<Button
						kind="primary"
						size="sm"
						href={ g.install }
						external
					>
						{ __( 'engenty.goose holen', 'cookie-dialog' ) }
					</Button>
				) : null }
				{ ! g.activate && ! g.install ? (
					<span className="cd-fine">
						{ __(
							'Wer Plugins verwalten darf, kann engenty.goose dazuholen.',
							'cookie-dialog'
						) }
					</span>
				) : null }
			</div>
		</div>
	);
}
