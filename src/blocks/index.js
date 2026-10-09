/**
 * The blocks' editor: each renders on the server, the editor shows the same output.
 */
import { registerBlockType } from '@wordpress/blocks';
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
	Placeholder,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

const services = window.cookieDialogBlocks?.services || [];

function SettingsButtonEdit( { attributes, setAttributes } ) {
	const props = useBlockProps();
	return (
		<div { ...props }>
			<InspectorControls>
				<PanelBody title={ __( 'Einstellungen', 'cookie-dialog' ) }>
					<TextControl
						label={ __( 'Beschriftung', 'cookie-dialog' ) }
						help={ __(
							'Leer: „Cookie-Einstellungen“ in der Sprache der Seite.',
							'cookie-dialog'
						) }
						value={ attributes.label }
						onChange={ ( label ) => setAttributes( { label } ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Darstellung', 'cookie-dialog' ) }
						value={ attributes.style }
						options={ [
							{
								label: __( 'Button', 'cookie-dialog' ),
								value: 'button',
							},
							{
								label: __( 'Link', 'cookie-dialog' ),
								value: 'link',
							},
						] }
						onChange={ ( style ) => setAttributes( { style } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="cookie-dialog/settings-button"
				attributes={ attributes }
			/>
		</div>
	);
}

function CookieTableEdit( { attributes, setAttributes } ) {
	const props = useBlockProps();
	return (
		<div { ...props }>
			<InspectorControls>
				<PanelBody title={ __( 'Einstellungen', 'cookie-dialog' ) }>
					<ToggleControl
						label={ __(
							'Consent-ID und Link zu den Einstellungen',
							'cookie-dialog'
						) }
						checked={ attributes.showId }
						onChange={ ( showId ) => setAttributes( { showId } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="cookie-dialog/cookie-table"
				attributes={ attributes }
			/>
		</div>
	);
}

function PlaceholderEdit( { attributes, setAttributes } ) {
	const props = useBlockProps();
	const picked = services.find( ( s ) => s.id === attributes.service );
	const title = __( 'Erst nach Einwilligung', 'cookie-dialog' );
	return (
		<div { ...props }>
			<InspectorControls>
				<PanelBody title={ __( 'Dienst', 'cookie-dialog' ) }>
					<SelectControl
						label={ __(
							'Lädt erst mit Einwilligung für',
							'cookie-dialog'
						) }
						value={ attributes.service }
						options={ [
							{
								label: __( '– wählen –', 'cookie-dialog' ),
								value: '',
							},
							...services.map( ( s ) => ( {
								label: s.name,
								value: s.id,
							} ) ),
						] }
						onChange={ ( service ) => setAttributes( { service } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				icon="lock"
				label={ picked ? `${ title }: ${ picked.name }` : title }
				instructions={
					picked
						? __(
								'Besucher sehen hier einen Platzhalter, bis sie dem Dienst zustimmen.',
								'cookie-dialog'
						  )
						: __(
								'Wähle rechts den Dienst, dem Besucher zuerst zustimmen.',
								'cookie-dialog'
						  )
				}
			>
				<div style={ { width: '100%' } }>
					<InnerBlocks />
				</div>
			</Placeholder>
		</div>
	);
}

registerBlockType( 'cookie-dialog/settings-button', {
	edit: SettingsButtonEdit,
	save: () => null,
} );

registerBlockType( 'cookie-dialog/cookie-table', {
	edit: CookieTableEdit,
	save: () => null,
} );

registerBlockType( 'cookie-dialog/placeholder', {
	edit: PlaceholderEdit,
	save: () => <InnerBlocks.Content />,
} );
