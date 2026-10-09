import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { boot } from './api';
import { Seg } from './ui';

/**
 * The home page with the dialog, as visitors see it: the real site in a frame, scaled down. Colours
 * and texts reach it at once through postMessage; a text clicked in it is changed where it stands.
 * @param root0
 * @param root0.state     The admin state.
 * @param root0.layers    Which layers can be shown: 1, 2, ph.
 * @param root0.edit      Whether texts are edited in the frame.
 * @param root0.design    The design to show (also before it is saved).
 * @param root0.texts     The texts per language, as saved.
 * @param root0.onText    Gets ( key, value, lang ) when a text was changed in the frame.
 * @param root0.reloadKey A new value reloads the frame (services or categories changed).
 * @param root0.compact   Smaller controls.
 */
export default function Preview( {
	state,
	layers = [ '1', '2' ],
	edit = false,
	design,
	texts,
	onText,
	reloadKey = '',
	compact = false,
} ) {
	const languages = state.config.languages;
	const [ layer, setLayer ] = useState( layers[ 0 ] );
	const [ lang, setLang ] = useState( languages[ 0 ] );
	const [ device, setDevice ] = useState( 'desktop' );
	const [ width, setWidth ] = useState( 600 );
	const [ ready, setReady ] = useState( false );
	const box = useRef();
	const frame = useRef();

	useEffect( () => {
		if ( ! box.current ) {
			return undefined;
		}
		const ro = new window.ResizeObserver( ( [ entry ] ) =>
			setWidth( Math.max( 280, entry.contentRect.width ) )
		);
		ro.observe( box.current );
		return () => ro.disconnect();
	}, [] );

	useEffect( () => {
		const onMessage = ( e ) => {
			if ( e.origin !== window.location.origin || ! e.data ) {
				return;
			}
			if ( e.data.type === 'cookie-dialog:ready' ) {
				setReady( true );
			}
			if ( e.data.type === 'cookie-dialog:text' && onText ) {
				onText( e.data.key, e.data.value, e.data.lang );
			}
		};
		window.addEventListener( 'message', onMessage );
		return () => window.removeEventListener( 'message', onMessage );
	}, [ onText ] );

	const send = ( message ) =>
		frame.current?.contentWindow?.postMessage(
			message,
			window.location.origin
		);

	useEffect( () => {
		if ( ready && design ) {
			send( { type: 'cookie-dialog:config', config: { design } } );
		}
	}, [ ready, design ] );

	useEffect( () => {
		if ( ready && texts?.[ lang ] ) {
			send( {
				type: 'cookie-dialog:config',
				config: { texts: texts[ lang ] },
			} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- when the texts or the language change.
	}, [ ready, texts, lang ] );

	useEffect( () => {
		if ( ready ) {
			send( { type: 'cookie-dialog:layer', layer } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- only when the layer changes.
	}, [ layer ] );

	const mobile = device === 'mobile';
	const siteW = mobile ? 390 : 1280;
	const siteH = mobile ? 780 : 800;
	const boxH = Math.round( ( width * 800 ) / 1280 );
	const scale = mobile ? boxH / siteH : width / siteW;
	const src = `${ boot.preview }&layer=${ layer }&lang=${ lang }${
		edit ? '&edit=1' : ''
	}&k=${ encodeURIComponent( reloadKey ) }-${ device }`;

	const layerLabels = {
		1: __( 'Erste Ebene', 'cookie-dialog' ),
		2: __( 'Auswahl', 'cookie-dialog' ),
		ph: __( 'Platzhalter', 'cookie-dialog' ),
	};

	return (
		<div className={ `cd-preview${ compact ? ' is-compact' : '' }` }>
			<div className="cd-preview__bar">
				<Seg
					label={ __( 'Ebene', 'cookie-dialog' ) }
					value={ layer }
					onChange={ setLayer }
					options={ layers.map( ( l ) => ( {
						value: l,
						label: layerLabels[ l ],
					} ) ) }
				/>
				<span className="cd-row-inline">
					{ languages.length > 1 ? (
						<Seg
							label={ __( 'Sprache', 'cookie-dialog' ) }
							value={ lang }
							onChange={ ( l ) => {
								setReady( false );
								setLang( l );
							} }
							options={ languages.map( ( l ) => ( {
								value: l,
								label: l.toUpperCase(),
							} ) ) }
						/>
					) : null }
					<Seg
						label={ __( 'Gerät', 'cookie-dialog' ) }
						value={ device }
						onChange={ ( d ) => {
							setReady( false );
							setDevice( d );
						} }
						options={ [
							{
								value: 'desktop',
								label: __( 'Desktop', 'cookie-dialog' ),
							},
							{
								value: 'mobile',
								label: __( 'Handy', 'cookie-dialog' ),
							},
						] }
					/>
				</span>
			</div>
			<div
				className={ `cd-preview__box${ mobile ? ' is-mobile' : '' }` }
				ref={ box }
				style={ { height: boxH } }
			>
				<iframe
					ref={ frame }
					key={ src }
					src={ src }
					title={ __(
						'Vorschau deiner Website mit dem Dialog',
						'cookie-dialog'
					) }
					onLoad={ () =>
						window.setTimeout( () => setReady( true ), 50 )
					}
					style={ {
						width: siteW,
						height: siteH,
						transform: `scale(${ scale })`,
						left: mobile ? ( width - siteW * scale ) / 2 : 0,
					} }
				/>
			</div>
		</div>
	);
}
