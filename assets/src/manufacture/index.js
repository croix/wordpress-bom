import { createRoot, useEffect, useState, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Modal,
	Notice,
	RadioControl,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

function newOpKey() {
	return window.crypto.randomUUID();
}

// @wordpress/components' BaseControl no longer applies its own
// bottom margin (confirmed 0px either way in the currently-targeted WP
// version), so stacked fields need explicit spacing or their labels
// visually run into the field above. Plain CSS, not a component prop,
// so it can't drift with future @wordpress/components changes.
function Field( { children } ) {
	return <div style={ { marginBottom: '16px' } }>{ children }</div>;
}

function formatNumber( n ) {
	return String( Math.round( n * 10000 ) / 10000 );
}

const STATUS_LABELS = {
	draft: __( 'Draft', 'wcbom' ),
	completed: __( 'Completed', 'wcbom' ),
	partially_reversed: __( 'Partially reversed', 'wcbom' ),
	reversed: __( 'Reversed', 'wcbom' ),
};

function ManufactureApp( { restNamespace } ) {
	const [ orders, setOrders ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ statusFilter, setStatusFilter ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ showCreate, setShowCreate ] = useState( false );
	const [ detailOrder, setDetailOrder ] = useState( null ); // { order, mode: 'complete'|'reverse' }

	const load = useCallback( () => {
		setLoading( true );
		apiFetch( { path: `/${ restNamespace }/manufacture-orders${ statusFilter ? `?status=${ statusFilter }` : '' }` } )
			.then( ( response ) => setOrders( response.orders ) )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) )
			.finally( () => setLoading( false ) );
	}, [ restNamespace, statusFilter ] );

	useEffect( load, [ load ] );

	function openDetail( order, mode ) {
		// Refetch this one order so drafts get a fresh "planned" preview
		// (component stock may have changed since the list loaded).
		apiFetch( { path: `/${ restNamespace }/manufacture-orders/${ order.mo_id }` } )
			.then( ( response ) => setDetailOrder( { order: response.order, mode } ) )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) );
	}

	function deleteDraft( order ) {
		if ( ! window.confirm( __( 'Delete this draft manufacture order? Nothing has been built yet, so no stock is affected.', 'wcbom' ) ) ) {
			return;
		}

		apiFetch( { path: `/${ restNamespace }/manufacture-orders/${ order.mo_id }`, method: 'DELETE' } )
			.then( () => {
				setNotice( { status: 'success', message: __( 'Draft deleted.', 'wcbom' ) } );
				load();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) );
	}

	function onCreated() {
		setShowCreate( false );
		setNotice( { status: 'success', message: __( 'Manufacture order created as a draft. Nothing has been built yet.', 'wcbom' ) } );
		load();
	}

	function onActioned( message ) {
		setDetailOrder( null );
		setNotice( { status: 'success', message } );
		load();
	}

	return (
		<Card>
			<CardBody>
				<h1>{ __( 'Manufacturing', 'wcbom' ) }</h1>

				{ notice && (
					<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
						{ notice.message }
					</Notice>
				) }

				<p>
					<SelectControl
						label={ __( 'Status', 'wcbom' ) }
						value={ statusFilter }
						options={ [
							{ label: __( 'All', 'wcbom' ), value: '' },
							...Object.entries( STATUS_LABELS ).map( ( [ value, label ] ) => ( { label, value } ) ),
						] }
						onChange={ setStatusFilter }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</p>

				<p>
					<Button variant="primary" onClick={ () => setShowCreate( true ) }>
						{ __( '+ New manufacture order', 'wcbom' ) }
					</Button>
				</p>

				{ loading ? (
					<Spinner />
				) : (
					<table className="widefat wcbom-mo-table">
						<thead>
							<tr>
								<th>{ __( 'ID', 'wcbom' ) }</th>
								<th>{ __( 'Product', 'wcbom' ) }</th>
								<th>{ __( 'Built', 'wcbom' ) }</th>
								<th>{ __( 'Reversed', 'wcbom' ) }</th>
								<th>{ __( 'Status', 'wcbom' ) }</th>
								<th>{ __( 'Created', 'wcbom' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ orders.map( ( order ) => (
								<tr key={ order.mo_id }>
									<td>#{ order.mo_id }</td>
									<td>{ order.product_name }</td>
									<td>{ order.qty_built }</td>
									<td>{ order.qty_reversed }</td>
									<td>{ STATUS_LABELS[ order.status ] || order.status }</td>
									<td>{ order.created_at }</td>
									<td>
										{ 'draft' === order.status && (
											<>
												<Button variant="primary" onClick={ () => openDetail( order, 'complete' ) }>
													{ __( 'Complete', 'wcbom' ) }
												</Button>{ ' ' }
												<Button variant="tertiary" isDestructive onClick={ () => deleteDraft( order ) }>
													{ __( 'Delete', 'wcbom' ) }
												</Button>
											</>
										) }
										{ ( 'completed' === order.status || 'partially_reversed' === order.status ) && (
											<Button variant="secondary" onClick={ () => openDetail( order, 'reverse' ) }>
												{ __( 'Reverse', 'wcbom' ) }
											</Button>
										) }
										{ ( 'completed' === order.status || 'partially_reversed' === order.status || 'reversed' === order.status ) && (
											<>{ ' ' }
												<Button variant="tertiary" onClick={ () => openDetail( order, 'view' ) }>
													{ __( 'View', 'wcbom' ) }
												</Button>
											</>
										) }
									</td>
								</tr>
							) ) }
							{ 0 === orders.length && (
								<tr>
									<td colSpan={ 7 }>{ __( 'No manufacture orders yet.', 'wcbom' ) }</td>
								</tr>
							) }
						</tbody>
					</table>
				) }
			</CardBody>

			{ showCreate && (
				<CreateModal restNamespace={ restNamespace } onClose={ () => setShowCreate( false ) } onCreated={ onCreated } />
			) }

			{ detailOrder && 'complete' === detailOrder.mode && (
				<CompleteModal
					restNamespace={ restNamespace }
					order={ detailOrder.order }
					onClose={ () => setDetailOrder( null ) }
					onCompleted={ () => onActioned( __( 'Manufacture order completed.', 'wcbom' ) ) }
				/>
			) }

			{ detailOrder && 'reverse' === detailOrder.mode && (
				<ReverseModal
					restNamespace={ restNamespace }
					order={ detailOrder.order }
					onClose={ () => setDetailOrder( null ) }
					onReversed={ () => onActioned( __( 'Manufacture order reversed.', 'wcbom' ) ) }
				/>
			) }

			{ detailOrder && 'view' === detailOrder.mode && (
				<ViewModal order={ detailOrder.order } onClose={ () => setDetailOrder( null ) } />
			) }
		</Card>
	);
}

function CreateModal( { restNamespace, onClose, onCreated } ) {
	const [ mode, setMode ] = useState( 'existing' );
	const [ existing, setExisting ] = useState( [] );
	const [ templates, setTemplates ] = useState( [] );
	const [ productId, setProductId ] = useState( '' );
	const [ templateId, setTemplateId ] = useState( '' );
	const [ title, setTitle ] = useState( '' );
	const [ price, setPrice ] = useState( '' );
	const [ attributes, setAttributes ] = useState( {} );
	const [ qtyBuilt, setQtyBuilt ] = useState( '1' );
	const [ notes, setNotes ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `/${ restNamespace }/manufacture/existing` } ).then( ( r ) => setExisting( r.existing ) );
		apiFetch( { path: `/${ restNamespace }/manufacture/templates` } ).then( ( r ) => setTemplates( r.templates ) );
	}, [ restNamespace ] );

	const selectedTemplate = templates.find( ( t ) => String( t.id ) === String( templateId ) );

	function submit() {
		setError( null );

		const qty = parseInt( qtyBuilt, 10 );
		if ( ! qty || qty <= 0 ) {
			setError( __( 'Enter a quantity to build.', 'wcbom' ) );
			return;
		}

		const payload = { qty_built: qty, notes: notes || null };

		if ( 'existing' === mode ) {
			if ( ! productId ) {
				setError( __( 'Choose a product to restock.', 'wcbom' ) );
				return;
			}
			payload.mode = 'existing';
			payload.product_id = parseInt( productId, 10 );
		} else {
			if ( ! templateId || ! title.trim() ) {
				setError( __( 'Choose a template and enter a title.', 'wcbom' ) );
				return;
			}
			payload.mode = 'template';
			payload.template_product_id = parseInt( templateId, 10 );
			payload.title = title;
			payload.price = price;
			payload.attributes = attributes;
		}

		setSubmitting( true );
		apiFetch( { path: `/${ restNamespace }/manufacture-orders`, method: 'POST', data: payload } )
			.then( onCreated )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSubmitting( false ) );
	}

	return (
		<Modal title={ __( 'New manufacture order', 'wcbom' ) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Field>
				<RadioControl
					label={ __( 'What are you building?', 'wcbom' ) }
					selected={ mode }
					options={ [
						{ label: __( 'Restock an existing manufactured product', 'wcbom' ), value: 'existing' },
						{ label: __( 'New product from a template', 'wcbom' ), value: 'template' },
					] }
					onChange={ setMode }
				/>
			</Field>

			{ 'existing' === mode && (
				<Field>
					<SelectControl
						label={ __( 'Product', 'wcbom' ) }
						value={ productId }
						options={ [
							{ label: __( '— choose —', 'wcbom' ), value: '' },
							...existing.map( ( p ) => ( { label: `${ p.name } (${ p.stock } on hand)`, value: String( p.id ) } ) ),
						] }
						onChange={ setProductId }
						__next40pxDefaultSize
					/>
				</Field>
			) }

			{ 'template' === mode && (
				<>
					<Field>
						<SelectControl
							label={ __( 'Template', 'wcbom' ) }
							value={ templateId }
							options={ [
								{ label: __( '— choose —', 'wcbom' ), value: '' },
								...templates.map( ( t ) => ( { label: t.name, value: String( t.id ) } ) ),
							] }
							onChange={ ( value ) => {
								setTemplateId( value );
								setAttributes( {} );
							} }
							__next40pxDefaultSize
						/>
					</Field>

					{ selectedTemplate && selectedTemplate.attributes.map( ( attr ) => (
						<Field key={ attr.taxonomy }>
							<SelectControl
								label={ attr.label }
								value={ attributes[ attr.taxonomy ] || '' }
								options={ [
									{ label: __( '— choose —', 'wcbom' ), value: '' },
									...attr.terms.map( ( term ) => ( { label: term.name, value: term.slug } ) ),
								] }
								onChange={ ( value ) => setAttributes( { ...attributes, [ attr.taxonomy ]: value } ) }
								__next40pxDefaultSize
							/>
						</Field>
					) ) }

					<Field>
						<TextControl
							label={ __( 'New product title', 'wcbom' ) }
							value={ title }
							onChange={ setTitle }
							__next40pxDefaultSize
						/>
					</Field>
					<Field>
						<TextControl
							label={ __( 'Price', 'wcbom' ) }
							type="number"
							step="0.01"
							value={ price }
							onChange={ setPrice }
							__next40pxDefaultSize
						/>
					</Field>
				</>
			) }

			<Field>
				<TextControl
					label={ __( 'Quantity to build', 'wcbom' ) }
					type="number"
					min="1"
					value={ qtyBuilt }
					onChange={ setQtyBuilt }
					__next40pxDefaultSize
				/>
			</Field>

			<Field>
				<TextControl
					label={ __( 'Notes (optional)', 'wcbom' ) }
					value={ notes }
					onChange={ setNotes }
					__next40pxDefaultSize
				/>
			</Field>

			<p>
				<Button variant="primary" isBusy={ submitting } disabled={ submitting } onClick={ submit }>
					{ __( 'Create draft', 'wcbom' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" disabled={ submitting } onClick={ onClose }>
					{ __( 'Cancel', 'wcbom' ) }
				</Button>
			</p>
		</Modal>
	);
}

function CompleteModal( { restNamespace, order, onClose, onCompleted } ) {
	const [ opKey ] = useState( newOpKey );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ allowNegative, setAllowNegative ] = useState( false );

	const hasShortage = order.planned.some( ( line ) => line.shortage );

	function submit() {
		setSubmitting( true );
		setError( null );

		apiFetch( {
			path: `/${ restNamespace }/manufacture-orders/${ order.mo_id }/complete`,
			method: 'POST',
			data: { op_key: opKey, allow_negative: allowNegative },
		} )
			.then( onCompleted )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSubmitting( false ) );
	}

	return (
		<Modal title={ sprintf(
			/* translators: %d: manufacture order ID */
			__( 'Complete MO #%d', 'wcbom' ),
			order.mo_id
		) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<p>
				{ sprintf(
					/* translators: 1: quantity, 2: product name */
					__( 'Building %1$d × %2$s will consume:', 'wcbom' ),
					order.qty_built,
					order.product_name
				) }
			</p>

			<table className="widefat">
				<thead>
					<tr>
						<th>{ __( 'Component', 'wcbom' ) }</th>
						<th>{ __( 'Required', 'wcbom' ) }</th>
						<th>{ __( 'On hand', 'wcbom' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ order.planned.map( ( line ) => (
						<tr key={ line.component_id } style={ line.shortage ? { color: '#cc1818' } : undefined }>
							<td>{ line.name }</td>
							<td>{ formatNumber( line.qty_total ) } { line.unit }</td>
							<td>{ formatNumber( line.available ) } { line.unit }{ line.shortage && ' ⚠' }</td>
						</tr>
					) ) }
				</tbody>
			</table>

			{ hasShortage && (
				<>
					<Notice status="warning" isDismissible={ false }>
						{ __( 'One or more components are short. Building anyway will take that component negative.', 'wcbom' ) }
					</Notice>
					<Field>
						<CheckboxControl
							label={ __( 'Build anyway (allow negative stock)', 'wcbom' ) }
							checked={ allowNegative }
							onChange={ setAllowNegative }
						/>
					</Field>
				</>
			) }

			<p>
				<Button
					variant="primary"
					isBusy={ submitting }
					disabled={ submitting || ( hasShortage && ! allowNegative ) }
					onClick={ submit }
				>
					{ __( 'Complete', 'wcbom' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" disabled={ submitting } onClick={ onClose }>
					{ __( 'Cancel', 'wcbom' ) }
				</Button>
			</p>
		</Modal>
	);
}

function ReverseModal( { restNamespace, order, onClose, onReversed } ) {
	const [ opKey ] = useState( newOpKey );
	const [ qty, setQty ] = useState( String( order.remaining ) );
	const [ scrap, setScrap ] = useState( {} );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	function submit() {
		const parsedQty = parseInt( qty, 10 );
		if ( ! parsedQty || parsedQty < 1 || parsedQty > order.remaining ) {
			setError( sprintf(
				/* translators: %d: maximum number of units still available to reverse */
				__( 'Enter between 1 and %d unit(s).', 'wcbom' ),
				order.remaining
			) );
			return;
		}

		setSubmitting( true );
		setError( null );

		apiFetch( {
			path: `/${ restNamespace }/manufacture-orders/${ order.mo_id }/reverse`,
			method: 'POST',
			data: {
				op_key: opKey,
				qty: parsedQty,
				scrap_component_ids: Object.entries( scrap ).filter( ( [ , checked ] ) => checked ).map( ( [ id ] ) => parseInt( id, 10 ) ),
			},
		} )
			.then( onReversed )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSubmitting( false ) );
	}

	return (
		<Modal title={ sprintf(
			/* translators: %d: manufacture order ID */
			__( 'Reverse MO #%d', 'wcbom' ),
			order.mo_id
		) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Field>
				<TextControl
					label={ sprintf(
						/* translators: %d: maximum number of units still available to reverse */
						__( 'Units to reverse (max %d)', 'wcbom' ),
						order.remaining
					) }
					type="number"
					min="1"
					max={ order.remaining }
					value={ qty }
					onChange={ setQty }
					__next40pxDefaultSize
				/>
			</Field>

			<p><strong>{ __( 'Scrap (check any component that was NOT recoverable and should not be restored):', 'wcbom' ) }</strong></p>
			<div style={ { marginBottom: '16px' } }>
				{ order.items.map( ( item ) => (
					<div key={ item.component_id } style={ { marginBottom: '8px' } }>
						<CheckboxControl
							label={ `${ item.name } (${ formatNumber( item.qty_per_unit ) } ${ item.unit }/unit)` }
							checked={ !! scrap[ item.component_id ] }
							onChange={ ( checked ) => setScrap( { ...scrap, [ item.component_id ]: checked } ) }
						/>
					</div>
				) ) }
			</div>

			<p>
				<Button variant="primary" isBusy={ submitting } disabled={ submitting } onClick={ submit }>
					{ __( 'Reverse', 'wcbom' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" disabled={ submitting } onClick={ onClose }>
					{ __( 'Cancel', 'wcbom' ) }
				</Button>
			</p>
		</Modal>
	);
}

function ViewModal( { order, onClose } ) {
	return (
		<Modal title={ sprintf(
			/* translators: 1: manufacture order ID, 2: finished product name */
			__( 'MO #%1$d — %2$s', 'wcbom' ),
			order.mo_id,
			order.product_name
		) } onRequestClose={ onClose }>
			<p>
				{ STATUS_LABELS[ order.status ] || order.status } — { __( 'built', 'wcbom' ) } { order.qty_built }, { __( 'reversed', 'wcbom' ) } { order.qty_reversed }
			</p>
			{ order.notes && <p>{ order.notes }</p> }

			<table className="widefat wcbom-pick-list">
				<thead>
					<tr>
						<th>{ __( 'Component', 'wcbom' ) }</th>
						<th>{ __( 'Per unit', 'wcbom' ) }</th>
						<th>{ __( 'Total consumed', 'wcbom' ) }</th>
						<th>{ __( 'Unit cost', 'wcbom' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ order.items.map( ( item ) => (
						<tr key={ item.component_id }>
							<td>{ item.name }</td>
							<td>{ formatNumber( item.qty_per_unit ) } { item.unit }</td>
							<td>{ formatNumber( item.qty_total ) } { item.unit }</td>
							<td>{ null !== item.unit_cost ? `$${ formatNumber( item.unit_cost ) }` : '—' }</td>
						</tr>
					) ) }
				</tbody>
			</table>

			<p>
				<Button variant="secondary" onClick={ () => window.print() }>
					{ __( 'Print pick list', 'wcbom' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'wcbom' ) }
				</Button>
			</p>
		</Modal>
	);
}

const root = document.getElementById( 'wcbom-manufacturing-root' );
if ( root && window.wcbomManufacture ) {
	createRoot( root ).render( <ManufactureApp restNamespace={ window.wcbomManufacture.restNamespace } /> );
}
