import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api, boot, problem, query } from './api';
import { Dock } from './Assistant';
import { Split } from './Start';
import {
	Button,
	Card,
	Chip,
	Drawer,
	Icon,
	Notice,
	Seg,
	Spinner,
	pick,
	when,
} from './ui';

const SOURCES = [
	[ '', __( 'Alle', 'cookie-dialog' ) ],
	[ 'dialog', __( 'Dialog', 'cookie-dialog' ) ],
	[ 'settings', __( 'Auswahl', 'cookie-dialog' ) ],
	[ 'placeholder', __( 'Platzhalter', 'cookie-dialog' ) ],
	[ 'withdraw', __( 'Widerruf', 'cookie-dialog' ) ],
];

function Decision( { row, config } ) {
	if ( row.decision === 'necessary' ) {
		return (
			<Chip tone={ row.source === 'withdraw' ? 'rose' : '' }>
				{ row.source === 'withdraw'
					? __( 'Widerruf · Nur notwendige', 'cookie-dialog' )
					: __( 'Nur notwendige', 'cookie-dialog' ) }
			</Chip>
		);
	}
	if ( row.decision === 'service' ) {
		const names = row.services
			.map(
				( id ) =>
					config.services.find( ( s ) => s.id === id )?.name || id
			)
			.slice( -2 );
		return (
			<Chip tone="moss" icon="check">
				{ names.join( ', ' ) }
			</Chip>
		);
	}
	return (
		<span className="cd-row-inline cd-gap4">
			{ config.categories
				.filter( ( c ) => c.id !== 'necessary' )
				.map( ( c ) => {
					const on = row.categories.includes( c.id );
					return (
						<Chip
							key={ c.id }
							tone={ on ? 'moss' : '' }
							icon={ on ? 'check' : 'x' }
						>
							{ pick( c.name ) }
						</Chip>
					);
				} ) }
		</span>
	);
}

function VersionDrawer( { version, onClose } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		api( `/version/${ version }` )
			.then( setData )
			.catch( ( e ) => setError( problem( e ).message ) );
	}, [ version ] );
	const snap = data?.snapshot;
	const lang = snap?.languages?.[ 0 ];
	return (
		<Drawer
			title={ sprintf(
				/* translators: %d: version */ __(
					'Version %d',
					'cookie-dialog'
				),
				version
			) }
			onClose={ onClose }
		>
			{ error ? <Notice tone="error">{ error }</Notice> : null }
			{ ! data && ! error ? <Spinner /> : null }
			{ snap ? (
				<div className="cd-col cd-gap16">
					<p className="cd-fine">
						{ sprintf(
							/* translators: 1: date, 2: note */
							__( 'Seit %1$s · %2$s', 'cookie-dialog' ),
							when( data.created.replace( ' ', 'T' ) + 'Z' ),
							data.note || '–'
						) }
					</p>
					<Card className="cd-col">
						<b>{ snap.texts[ lang ]?.title }</b>
						<span>
							{ snap.texts[ lang ]?.text?.replace(
								/<[^>]+>/g,
								''
							) }
						</span>
						<span className="cd-fine">
							{ snap.texts[ lang ]?.accept } ·{ ' ' }
							{ snap.texts[ lang ]?.reject } ·{ ' ' }
							{ snap.texts[ lang ]?.settings }
						</span>
					</Card>
					{ snap.categories.map( ( c ) => (
						<Card key={ c.id } className="cd-col">
							<b>{ pick( c.name, lang ) }</b>
							<span className="cd-fine">
								{ pick( c.description, lang ) }
							</span>
							<span>
								{ snap.services
									.filter( ( s ) => s.category === c.id )
									.map( ( s ) => s.name )
									.join( ' · ' ) || '–' }
							</span>
						</Card>
					) ) }
					<p className="cd-fine">
						{ __(
							'Das hat jede Person gesehen, die bei dieser Version entschieden hat – in allen Sprachen gespeichert.',
							'cookie-dialog'
						) }
					</p>
				</div>
			) : null }
		</Drawer>
	);
}

export default function Log( { state } ) {
	const [ range, setRange ] = useState( '30d' );
	const [ source, setSource ] = useState( '' );
	const [ q, setQ ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ version, setVersion ] = useState( null );

	useEffect( () => {
		const t = window.setTimeout(
			() =>
				api( `/log${ query( { range, source, q, page } ) }` )
					.then( ( d ) => {
						setData( d );
						setError( null );
					} )
					.catch( ( e ) => setError( problem( e ).message ) ),
			q ? 300 : 0
		);
		return () => window.clearTimeout( t );
	}, [ range, source, q, page ] );

	const s = data?.stats;
	const pages = data ? Math.max( 1, Math.ceil( data.total / 25 ) ) : 1;
	return (
		<>
			<div className="cd-toolbar">
				<Seg
					label={ __( 'Zeitraum', 'cookie-dialog' ) }
					value={ range }
					onChange={ ( r ) => {
						setRange( r );
						setPage( 1 );
					} }
					options={ [
						{
							value: '30d',
							label: __( '30 Tage', 'cookie-dialog' ),
						},
						{
							value: '12m',
							label: __( '12 Monate', 'cookie-dialog' ),
						},
						{ value: 'all', label: __( 'Alles', 'cookie-dialog' ) },
					] }
				/>
				<Button
					size="sm"
					icon="download"
					href={ `${ boot.csv }&range=${ range }${
						source ? `&source=${ source }` : ''
					}` }
				>
					CSV
				</Button>
			</div>
			{ error ? <Notice tone="error">{ error }</Notice> : null }
			{ s ? (
				<Card className="cd-col cd-gap16">
					<div className="cd-log-head">
						<div className="cd-big">
							<span>{ s.total.toLocaleString() }</span>
							<span className="cd-muted">
								{ sprintf(
									/* translators: %d: withdrawals */
									__(
										'Entscheidungen · %d Widerrufe',
										'cookie-dialog'
									),
									s.withdrawals
								) }
							</span>
						</div>
						<div className="cd-log-head__split">
							<Split stats={ s } />
						</div>
					</div>
					{ s.total ? (
						<div className="cd-bars">
							{ Object.entries( s.categories ).map(
								( [ id, v ] ) => (
									<div key={ id } className="cd-col cd-gap4">
										<div className="cd-line">
											<span className="cd-small">
												{ pick(
													state.config.categories.find(
														( c ) => c.id === id
													)?.name
												) || id }
											</span>
											<span className="cd-fine">
												{ v } %
											</span>
										</div>
										<div className="cd-bar">
											<i style={ { width: `${ v }%` } } />
										</div>
									</div>
								)
							) }
						</div>
					) : null }
					{ s.total && s.languages.length > 1 ? (
						<div className="cd-legend">
							{ s.languages.map( ( [ lang, n ] ) => (
								<span key={ lang || '-' }>
									{ ( lang || '–' ).toUpperCase() }{ ' ' }
									{ Math.round( ( 100 * n ) / s.total ) } %
								</span>
							) ) }
						</div>
					) : null }
				</Card>
			) : (
				<Spinner />
			) }
			<div className="cd-toolbar">
				<div className="cd-searchbox">
					<Icon name="search" size={ 15 } />
					<input
						type="search"
						value={ q }
						placeholder={ __(
							'Consent-ID suchen',
							'cookie-dialog'
						) }
						onChange={ ( e ) => {
							setQ( e.target.value );
							setPage( 1 );
						} }
					/>
				</div>
				<div className="cd-tabs" role="tablist">
					{ SOURCES.map( ( [ id, label ] ) => (
						<button
							key={ id }
							type="button"
							role="tab"
							aria-selected={ source === id ? 'true' : 'false' }
							className={ source === id ? 'is-on' : '' }
							onClick={ () => {
								setSource( id );
								setPage( 1 );
							} }
						>
							{ label }
						</button>
					) ) }
				</div>
			</div>
			<table className="cd-table">
				<thead>
					<tr>
						<th>{ __( 'Zeit', 'cookie-dialog' ) }</th>
						<th>{ __( 'Consent-ID', 'cookie-dialog' ) }</th>
						<th>{ __( 'Entscheidung', 'cookie-dialog' ) }</th>
						<th>{ __( 'Version', 'cookie-dialog' ) }</th>
						<th>{ __( 'Sprache', 'cookie-dialog' ) }</th>
						<th>{ __( 'Wo', 'cookie-dialog' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ data && ! data.rows.length ? (
						<tr>
							<td colSpan="6" className="cd-muted">
								{ q
									? __(
											'Keine Entscheidung mit dieser Consent-ID.',
											'cookie-dialog'
									  )
									: __(
											'Noch keine Entscheidungen in diesem Zeitraum.',
											'cookie-dialog'
									  ) }
							</td>
						</tr>
					) : null }
					{ ( data?.rows || [] ).map( ( r, i ) => (
						<tr key={ r.id + r.created + i }>
							<td className="cd-muted cd-nowrap">
								{ when( r.created ) }
							</td>
							<td>
								<code title={ r.id }>
									{ r.id.slice( 0, 9 ) }
								</code>
							</td>
							<td>
								<Decision row={ r } config={ state.config } />
							</td>
							<td>
								<button
									type="button"
									className="cd-link-btn"
									onClick={ () => setVersion( r.version ) }
								>
									{ r.version }
								</button>
							</td>
							<td>{ r.lang.toUpperCase() }</td>
							<td>
								{ SOURCES.find(
									( [ id ] ) => id === r.source
								)?.[ 1 ] || r.source }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			{ pages > 1 ? (
				<div className="cd-row-inline cd-pager">
					<Button
						size="sm"
						kind="ghost"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						‹
					</Button>
					<span className="cd-fine">
						{ page } / { pages }
					</span>
					<Button
						size="sm"
						kind="ghost"
						disabled={ page >= pages }
						onClick={ () => setPage( page + 1 ) }
					>
						›
					</Button>
				</div>
			) : null }
			<p className="cd-fine">
				{ sprintf(
					/* translators: %d: months */
					__(
						'Jede Zeile hält die Version des Dialogs, die die Person gesehen hat – der Nachweis nach Art. 7 DSGVO. IP-Adressen nur gehasht, gelöscht nach %d Monaten.',
						'cookie-dialog'
					),
					state.config.log.months
				) }
			</p>
			{ version ? (
				<VersionDrawer
					version={ version }
					onClose={ () => setVersion( null ) }
				/>
			) : null }
			<Dock
				placeholder={ __(
					'Frag zum Protokoll, z. B. „Wie viele haben gestern abgelehnt?“',
					'cookie-dialog'
				) }
			/>
		</>
	);
}
