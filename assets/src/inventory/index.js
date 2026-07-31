import { createRoot, useEffect, useState, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Modal,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

function newOpKey() {
	return window.crypto.randomUUID();
}

function formatNumber( n ) {
	return String( Math.round( n * 10000 ) / 10000 );
}

// @wordpress/components' BaseControl no longer applies its own bottom
// margin regardless of __nextHasNoMarginBottom (confirmed empty either
// way in the currently-targeted WP version), so a field stacked directly
// after another element needs explicit spacing or its label visually
// runs into what's above. Plain CSS, not a component prop.
function Field( { children } ) {
	return <div style={ { marginBottom: '16px' } }>{ children }</div>;
}

function InventoryApp( { restNamespace } ) {
	const [ components, setComponents ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ search, setSearch ] = useState( '' );
	const [ selected, setSelected ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ modal, setModal ] = useState( null ); // { type: 'receive'|'count'|'adjust', components: [...] }
	const [ samplesInstalled, setSamplesInstalled ] = useState( false );
	const [ sampleBusy, setSampleBusy ] = useState( false );

	const load = useCallback( () => {
		setLoading( true );
		apiFetch( { path: `/${ restNamespace }/inventory${ search ? `?search=${ encodeURIComponent( search ) }` : '' }` } )
			.then( ( response ) => {
				setComponents( response.components );
				setSamplesInstalled( !! response.sample_data_installed );
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) )
			.finally( () => setLoading( false ) );
	}, [ restNamespace, search ] );

	function runSampleAction( action ) {
		setSampleBusy( true );
		apiFetch( { path: `/${ restNamespace }/sample-data/${ action }`, method: 'POST' } )
			.then( () => {
				setNotice( {
					status: 'success',
					message:
						'install' === action
							? __( 'Sample products installed. They are visible on your storefront until removed.', 'wcbom' )
							: __( 'Sample products removed.', 'wcbom' ),
				} );
				load();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) )
			.finally( () => setSampleBusy( false ) );
	}

	useEffect( load, [ load ] );

	function toggleSelected( id ) {
		setSelected( ( current ) => ( { ...current, [ id ]: ! current[ id ] } ) );
	}

	const selectedComponents = components.filter( ( c ) => selected[ c.id ] );

	// Present only when VendorsFeature (Phase 9) is enabled — the API
	// response omits `on_order` entirely on every row when it's off, so
	// this table renders identically to before that feature existed.
	const hasOnOrder = components.some( ( c ) => undefined !== c.on_order );

	function openReceive() {
		if ( selectedComponents.length === 0 ) {
			return;
		}
		setModal( { type: 'receive', components: selectedComponents, opKey: newOpKey() } );
	}

	function openCount( component ) {
		setModal( { type: 'count', components: [ component ], opKey: newOpKey() } );
	}

	function openAdjust( component ) {
		setModal( { type: 'adjust', components: [ component ], opKey: newOpKey() } );
	}

	function onApplied( result ) {
		setModal( null );
		setSelected( {} );

		if ( result.already_applied ) {
			setNotice( { status: 'info', message: result.message } );
		} else {
			const changed = Object.entries( result.buildable_changed || {} );
			const suffix = changed.length
				? ' ' + sprintf(
						/* translators: %d: number of made-to-order products whose buildable count changed */
						__( '(%d made-to-order product(s) had their buildable count updated.)', 'wcbom' ),
						changed.length
				  )
				: '';
			setNotice( { status: 'success', message: __( 'Applied.', 'wcbom' ) + suffix } );
		}

		load();
	}

	return (
		<Card>
			<CardBody>
				<h1>{ __( 'Component Inventory', 'wcbom' ) }</h1>

				{ notice && (
					<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
						{ notice.message }
					</Notice>
				) }

				<TextControl
					label={ __( 'Search', 'wcbom' ) }
					value={ search }
					onChange={ setSearch }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>

				<p>
					<Button
						variant="primary"
						disabled={ selectedComponents.length === 0 }
						onClick={ openReceive }
					>
						{ sprintf(
							/* translators: %d: number of components selected to receive */
							__( 'Receive selected (%d)', 'wcbom' ),
							selectedComponents.length
						) }
					</Button>
				</p>

				{ ! loading && components.length === 0 && ! search && (
					<Notice status="info" isDismissible={ false }>
						{ __( 'No components yet. You can install a sample tumbler catalog (components, a premade product, and a customizable made-to-order product with a working BOM) to explore how everything fits together. Sample products are visible on your storefront and can be removed with one click.', 'wcbom' ) }{ ' ' }
						<Button variant="primary" isBusy={ sampleBusy } disabled={ sampleBusy } onClick={ () => runSampleAction( 'install' ) }>
							{ __( 'Install sample products', 'wcbom' ) }
						</Button>
					</Notice>
				) }

				{ loading ? (
					<Spinner />
				) : (
					<table className="widefat wcbom-inventory-table">
						<thead>
							<tr>
								<th></th>
								<th>{ __( 'Component', 'wcbom' ) }</th>
								<th>{ __( 'SKU', 'wcbom' ) }</th>
								<th>{ __( 'On hand', 'wcbom' ) }</th>
								{ hasOnOrder && <th>{ __( 'On order', 'wcbom' ) }</th> }
								<th>{ __( 'Used in', 'wcbom' ) }</th>
								<th>{ __( 'Last movement', 'wcbom' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ components.map( ( c ) => (
								<tr key={ c.id }>
									<td>
										<CheckboxControl
											checked={ !! selected[ c.id ] }
											onChange={ () => toggleSelected( c.id ) }
											__nextHasNoMarginBottom
										/>
									</td>
									<td>{ c.name }</td>
									<td>{ c.sku }</td>
									<td>
										{ c.stock } { c.unit }
									</td>
									{ hasOnOrder && (
										<td>
											{ undefined !== c.on_order
												? `${ formatNumber( c.on_order ) } ${ c.unit }${ c.on_order_expected ? ` (${ c.on_order_expected })` : '' }`
												: '—' }
										</td>
									) }
									<td>{ sprintf(
										/* translators: %d: number of active BOMs this component is used in */
										__( '%d BOM(s)', 'wcbom' ),
										c.used_in_count
									) }</td>
									<td>
										{ c.last_movement
											? `${ c.last_movement.reason } (${ c.last_movement.delta > 0 ? '+' : '' }${ formatNumber( c.last_movement.delta ) })`
											: __( '—', 'wcbom' ) }
									</td>
									<td>
										<Button variant="secondary" onClick={ () => openCount( c ) }>
											{ __( 'Count', 'wcbom' ) }
										</Button>{ ' ' }
										<Button variant="secondary" onClick={ () => openAdjust( c ) }>
											{ __( 'Adjust', 'wcbom' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }

				{ samplesInstalled && (
					<p>
						<Button variant="tertiary" isDestructive isBusy={ sampleBusy } disabled={ sampleBusy } onClick={ () => runSampleAction( 'remove' ) }>
							{ __( 'Remove sample products', 'wcbom' ) }
						</Button>
					</p>
				) }
			</CardBody>

			{ modal && (
				<StockModal
					restNamespace={ restNamespace }
					type={ modal.type }
					components={ modal.components }
					opKey={ modal.opKey }
					onClose={ () => setModal( null ) }
					onApplied={ onApplied }
				/>
			) }
		</Card>
	);
}

const MODAL_CONFIG = {
	receive: {
		title: __( 'Receive stock', 'wcbom' ),
		endpoint: 'receive',
		qtyLabel: __( 'Quantity received', 'wcbom' ),
		submitLabel: __( 'Receive', 'wcbom' ),
	},
	count: {
		title: __( 'Cycle count', 'wcbom' ),
		endpoint: 'count',
		qtyLabel: __( 'Counted quantity', 'wcbom' ),
		submitLabel: __( 'Save count', 'wcbom' ),
	},
	adjust: {
		title: __( 'Manual adjustment', 'wcbom' ),
		endpoint: 'adjust',
		qtyLabel: __( 'Adjustment (+/-)', 'wcbom' ),
		submitLabel: __( 'Adjust', 'wcbom' ),
	},
};

function StockModal( { restNamespace, type, components, opKey, onClose, onApplied } ) {
	const config = MODAL_CONFIG[ type ];
	const [ quantities, setQuantities ] = useState( () =>
		Object.fromEntries( components.map( ( c ) => [ c.id, type === 'count' ? c.stock : '' ] ) )
	);
	const [ note, setNote ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	function submit() {
		if ( 'adjust' === type && '' === note.trim() ) {
			setError( __( 'A note is required for manual adjustments.', 'wcbom' ) );
			return;
		}

		const items = components
			.map( ( c ) => ( { product_id: c.id, qty: parseFloat( quantities[ c.id ] ) } ) )
			.filter( ( item ) => ! Number.isNaN( item.qty ) );

		if ( items.length === 0 ) {
			setError( __( 'Enter at least one quantity.', 'wcbom' ) );
			return;
		}

		setSubmitting( true );
		setError( null );

		apiFetch( {
			path: `/${ restNamespace }/inventory/${ config.endpoint }`,
			method: 'POST',
			data: { op_key: opKey, note: note || null, items },
		} )
			.then( onApplied )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSubmitting( false ) );
	}

	return (
		<Modal title={ config.title } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ components.map( ( c ) => (
				<div key={ c.id } style={ { marginBottom: '1em' } }>
					<TextControl
						type="number"
						step="0.0001"
						label={ `${ config.qtyLabel } — ${ c.name } (${ c.unit }, on hand: ${ c.stock })` }
						value={ quantities[ c.id ] }
						onChange={ ( value ) => setQuantities( { ...quantities, [ c.id ]: value } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					{ 'count' === type && '' !== quantities[ c.id ] && ! Number.isNaN( parseFloat( quantities[ c.id ] ) ) && (
						<p className="description">
							{ sprintf(
								/* translators: %s: signed drift amount */
								__( 'Drift: %s', 'wcbom' ),
								( parseFloat( quantities[ c.id ] ) - c.stock >= 0 ? '+' : '' ) +
									formatNumber( parseFloat( quantities[ c.id ] ) - c.stock )
							) }
						</p>
					) }
				</div>
			) ) }

			<Field>
				<TextControl
					label={ __( 'Note', 'wcbom' ) + ( 'adjust' === type ? ' ' + __( '(required)', 'wcbom' ) : ' ' + __( '(optional)', 'wcbom' ) ) }
					value={ note }
					onChange={ setNote }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</Field>

			<p>
				<Button variant="primary" isBusy={ submitting } disabled={ submitting } onClick={ submit }>
					{ config.submitLabel }
				</Button>{ ' ' }
				<Button variant="tertiary" disabled={ submitting } onClick={ onClose }>
					{ __( 'Cancel', 'wcbom' ) }
				</Button>
			</p>
		</Modal>
	);
}

const root = document.getElementById( 'wcbom-inventory-root' );
if ( root && window.wcbomInventory ) {
	createRoot( root ).render( <InventoryApp restNamespace={ window.wcbomInventory.restNamespace } /> );
}
