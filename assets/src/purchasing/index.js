import { createRoot, useEffect, useState, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import ComponentPicker from '../bom-editor/component-picker';

function newOpKey() {
	return window.crypto.randomUUID();
}

// See assets/src/manufacture/index.js's identical Field component for why
// this plain wrapper exists instead of relying on @wordpress/components'
// own margin props.
function Field( { children } ) {
	return <div style={ { marginBottom: '16px' } }>{ children }</div>;
}

function formatNumber( n ) {
	return String( Math.round( n * 10000 ) / 10000 );
}

const PO_STATUS_LABELS = {
	draft: __( 'Draft', 'pv-bom-stock' ),
	ordered: __( 'Ordered', 'pv-bom-stock' ),
	partially_received: __( 'Partially received', 'pv-bom-stock' ),
	received: __( 'Received', 'pv-bom-stock' ),
	cancelled: __( 'Cancelled', 'pv-bom-stock' ),
};

function PurchasingApp( { restNamespace } ) {
	return (
		<Card>
			<CardBody>
				<h1>{ __( 'Purchasing', 'pv-bom-stock' ) }</h1>
				<TabPanel
					tabs={ [
						{ name: 'orders', title: __( 'Purchase Orders', 'pv-bom-stock' ) },
						{ name: 'vendors', title: __( 'Vendors', 'pv-bom-stock' ) },
					] }
				>
					{ ( tab ) => ( 'orders' === tab.name
						? <PurchaseOrdersTab restNamespace={ restNamespace } />
						: <VendorsTab restNamespace={ restNamespace } /> ) }
				</TabPanel>
			</CardBody>
		</Card>
	);
}

function PurchaseOrdersTab( { restNamespace } ) {
	const [ orders, setOrders ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ statusFilter, setStatusFilter ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ showCreate, setShowCreate ] = useState( false );
	const [ editDraft, setEditDraft ] = useState( null );
	const [ detailOrder, setDetailOrder ] = useState( null ); // { order, mode: 'receive'|'view' }

	const load = useCallback( () => {
		setLoading( true );
		apiFetch( { path: `/${ restNamespace }/purchase-orders${ statusFilter ? `?status=${ statusFilter }` : '' }` } )
			.then( ( response ) => setOrders( response.orders ) )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) )
			.finally( () => setLoading( false ) );
	}, [ restNamespace, statusFilter ] );

	useEffect( load, [ load ] );

	function refetchThenOpen( order, mode ) {
		apiFetch( { path: `/${ restNamespace }/purchase-orders/${ order.po_id }` } )
			.then( ( response ) => setDetailOrder( { order: response.order, mode } ) )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) );
	}

	function place( order ) {
		apiFetch( { path: `/${ restNamespace }/purchase-orders/${ order.po_id }/place`, method: 'POST' } )
			.then( () => {
				setNotice( { status: 'success', message: __( 'Purchase order placed.', 'pv-bom-stock' ) } );
				load();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) );
	}

	// Same underlying action (POST .../cancel) for two different real-world
	// reasons — "called off before anything shipped" vs. "accepting a short
	// delivery and closing this out" — so only the button label and
	// confirmation copy differ by status; see PurchaseOrderService::cancel()'s
	// docblock for why there's no separate status for the latter.
	function cancel( order ) {
		const isPartial = 'partially_received' === order.status;
		const confirmText = isPartial
			? __( 'Close this purchase order? The remaining outstanding quantity will no longer be expected. Stock already received is unaffected.', 'pv-bom-stock' )
			: __( 'Cancel this purchase order? Already-received stock is unaffected — only the remaining outstanding quantity stops being expected.', 'pv-bom-stock' );

		if ( ! window.confirm( confirmText ) ) {
			return;
		}

		apiFetch( { path: `/${ restNamespace }/purchase-orders/${ order.po_id }/cancel`, method: 'POST' } )
			.then( () => {
				setNotice( { status: 'success', message: isPartial ? __( 'Purchase order closed.', 'pv-bom-stock' ) : __( 'Purchase order cancelled.', 'pv-bom-stock' ) } );
				load();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) );
	}

	function deleteDraft( order ) {
		if ( ! window.confirm( __( 'Delete this draft purchase order? Nothing has been ordered yet, so nothing is affected.', 'pv-bom-stock' ) ) ) {
			return;
		}

		apiFetch( { path: `/${ restNamespace }/purchase-orders/${ order.po_id }`, method: 'DELETE' } )
			.then( () => {
				setNotice( { status: 'success', message: __( 'Draft deleted.', 'pv-bom-stock' ) } );
				load();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) );
	}

	function onSaved( message ) {
		setShowCreate( false );
		setEditDraft( null );
		setNotice( { status: 'success', message } );
		load();
	}

	function onActioned( message ) {
		setDetailOrder( null );
		setNotice( { status: 'success', message } );
		load();
	}

	return (
		<>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<p>
				<SelectControl
					label={ __( 'Status', 'pv-bom-stock' ) }
					value={ statusFilter }
					options={ [
						{ label: __( 'All', 'pv-bom-stock' ), value: '' },
						...Object.entries( PO_STATUS_LABELS ).map( ( [ value, label ] ) => ( { label, value } ) ),
					] }
					onChange={ setStatusFilter }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</p>

			<p>
				<Button variant="primary" onClick={ () => setShowCreate( true ) }>
					{ __( '+ New purchase order', 'pv-bom-stock' ) }
				</Button>
			</p>

			{ loading ? (
				<Spinner />
			) : (
				<table className="widefat wcbom-po-table">
					<thead>
						<tr>
							<th>{ __( 'ID', 'pv-bom-stock' ) }</th>
							<th>{ __( 'Vendor', 'pv-bom-stock' ) }</th>
							<th>{ __( 'Reference', 'pv-bom-stock' ) }</th>
							<th>{ __( 'Expected', 'pv-bom-stock' ) }</th>
							<th>{ __( 'Status', 'pv-bom-stock' ) }</th>
							<th>{ __( 'Created', 'pv-bom-stock' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ orders.map( ( order ) => (
							<tr key={ order.po_id }>
								<td>#{ order.po_id }</td>
								<td>{ order.vendor_name }</td>
								<td>{ order.reference || '—' }</td>
								<td>{ order.expected_date || '—' }</td>
								<td>{ PO_STATUS_LABELS[ order.status ] || order.status }</td>
								<td>{ order.created_at }</td>
								<td>
									{ 'draft' === order.status && (
										<>
											<Button variant="primary" onClick={ () => place( order ) }>
												{ __( 'Place', 'pv-bom-stock' ) }
											</Button>{ ' ' }
											<Button variant="secondary" onClick={ () => setEditDraft( order ) }>
												{ __( 'Edit', 'pv-bom-stock' ) }
											</Button>{ ' ' }
											<Button variant="tertiary" isDestructive onClick={ () => deleteDraft( order ) }>
												{ __( 'Delete', 'pv-bom-stock' ) }
											</Button>
										</>
									) }
									{ ( 'ordered' === order.status || 'partially_received' === order.status ) && (
										<>
											<Button variant="primary" onClick={ () => refetchThenOpen( order, 'receive' ) }>
												{ __( 'Receive', 'pv-bom-stock' ) }
											</Button>{ ' ' }
											<Button variant="tertiary" isDestructive onClick={ () => cancel( order ) }>
												{ 'partially_received' === order.status ? __( 'Close', 'pv-bom-stock' ) : __( 'Cancel', 'pv-bom-stock' ) }
											</Button>{ ' ' }
										</>
									) }
									{ 'draft' !== order.status && (
										<>
											<Button variant="tertiary" onClick={ () => refetchThenOpen( order, 'view' ) }>
												{ __( 'View', 'pv-bom-stock' ) }
											</Button>{ ' ' }
											<Button variant="tertiary" onClick={ () => refetchThenOpen( order, 'send' ) }>
												{ __( 'Send PO', 'pv-bom-stock' ) }
											</Button>{ ' ' }
										</>
									) }
									<Button variant="tertiary" onClick={ () => refetchThenOpen( order, 'costs' ) }>
										{ __( 'Edit costs', 'pv-bom-stock' ) }
									</Button>
								</td>
							</tr>
						) ) }
						{ 0 === orders.length && (
							<tr>
								<td colSpan={ 7 }>{ __( 'No purchase orders yet.', 'pv-bom-stock' ) }</td>
							</tr>
						) }
					</tbody>
				</table>
			) }

			{ ( showCreate || editDraft ) && (
				<PurchaseOrderModal
					restNamespace={ restNamespace }
					order={ editDraft }
					onClose={ () => { setShowCreate( false ); setEditDraft( null ); } }
					onSaved={ () => onSaved( editDraft ? __( 'Purchase order updated.', 'pv-bom-stock' ) : __( 'Purchase order created as a draft. Nothing has been ordered yet.', 'pv-bom-stock' ) ) }
				/>
			) }

			{ detailOrder && 'receive' === detailOrder.mode && (
				<ReceiveModal
					restNamespace={ restNamespace }
					order={ detailOrder.order }
					onClose={ () => setDetailOrder( null ) }
					onReceived={ () => onActioned( __( 'Receipt recorded.', 'pv-bom-stock' ) ) }
				/>
			) }

			{ detailOrder && 'view' === detailOrder.mode && (
				<ViewModal order={ detailOrder.order } onClose={ () => setDetailOrder( null ) } />
			) }

			{ detailOrder && 'costs' === detailOrder.mode && (
				<CostsModal
					restNamespace={ restNamespace }
					order={ detailOrder.order }
					onClose={ () => setDetailOrder( null ) }
					onSaved={ () => onActioned( __( 'Costs updated.', 'pv-bom-stock' ) ) }
				/>
			) }

			{ detailOrder && 'send' === detailOrder.mode && (
				<SendModal
					restNamespace={ restNamespace }
					order={ detailOrder.order }
					onClose={ () => setDetailOrder( null ) }
					onSent={ ( result ) => {
						setDetailOrder( null );
						const parts = [
							sprintf(
								/* translators: %s: comma-separated list of email addresses the PO was sent to */
								__( 'Sent to: %s', 'pv-bom-stock' ),
								result.sent_to.join( ', ' )
							),
							...result.warnings,
						];
						setNotice( { status: result.warnings.length ? 'warning' : 'success', message: parts.join( ' ' ) } );
						load();
					} }
				/>
			) }
		</>
	);
}

function PurchaseOrderModal( { restNamespace, order, onClose, onSaved } ) {
	const isEdit = !! order;
	const [ vendors, setVendors ] = useState( [] );
	const [ vendorId, setVendorId ] = useState( order ? String( order.vendor_id ) : '' );
	const [ reference, setReference ] = useState( order ? ( order.reference || '' ) : '' );
	const [ expectedDate, setExpectedDate ] = useState( order ? ( order.expected_date || '' ) : '' );
	const [ notes, setNotes ] = useState( order ? ( order.notes || '' ) : '' );
	const [ lines, setLines ] = useState(
		order
			? order.items.map( ( item ) => ( {
				component: { id: item.component_id, name: item.name, unit: item.unit },
				qty: String( item.qty_ordered ),
				unitCost: null !== item.unit_cost ? String( item.unit_cost ) : '',
			} ) )
			: []
	);
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `/${ restNamespace }/vendors?active_only=1` } ).then( ( r ) => setVendors( r.vendors ) );
	}, [ restNamespace ] );

	function addLine( component ) {
		setLines( [ ...lines, { component, qty: '1', unitCost: '' } ] );
	}

	function updateLine( index, patch ) {
		setLines( lines.map( ( line, i ) => ( i === index ? { ...line, ...patch } : line ) ) );
	}

	function removeLine( index ) {
		setLines( lines.filter( ( _, i ) => i !== index ) );
	}

	function submit() {
		setError( null );

		if ( ! vendorId ) {
			setError( __( 'Choose a vendor.', 'pv-bom-stock' ) );
			return;
		}
		if ( 0 === lines.length ) {
			setError( __( 'Add at least one line item.', 'pv-bom-stock' ) );
			return;
		}

		const payload = {
			vendor_id: parseInt( vendorId, 10 ),
			reference: reference || null,
			expected_date: expectedDate || null,
			notes: notes || null,
			items: lines.map( ( line ) => ( {
				component_id: line.component.id,
				qty_ordered: parseFloat( line.qty ),
				unit_cost: '' !== line.unitCost ? parseFloat( line.unitCost ) : null,
			} ) ),
		};

		setSubmitting( true );
		const path = isEdit ? `/${ restNamespace }/purchase-orders/${ order.po_id }` : `/${ restNamespace }/purchase-orders`;
		apiFetch( { path, method: isEdit ? 'PUT' : 'POST', data: payload } )
			.then( onSaved )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSubmitting( false ) );
	}

	return (
		<Modal title={ isEdit
			? sprintf(
				/* translators: %d: purchase order ID */
				__( 'Edit draft PO #%d', 'pv-bom-stock' ),
				order.po_id
			)
			: __( 'New purchase order', 'pv-bom-stock' ) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Field>
				<SelectControl
					label={ __( 'Vendor', 'pv-bom-stock' ) }
					value={ vendorId }
					options={ [
						{ label: __( '— choose —', 'pv-bom-stock' ), value: '' },
						...vendors.map( ( v ) => ( { label: v.name, value: String( v.vendor_id ) } ) ),
					] }
					onChange={ setVendorId }
					__next40pxDefaultSize
				/>
			</Field>

			{ 0 === vendors.length && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'No vendors yet — add one on the Vendors tab first.', 'pv-bom-stock' ) }
				</Notice>
			) }

			<Field>
				<TextControl
					label={ __( 'Vendor reference / invoice # (optional)', 'pv-bom-stock' ) }
					value={ reference }
					onChange={ setReference }
					__next40pxDefaultSize
				/>
			</Field>

			<Field>
				<TextControl
					label={ __( 'Expected date (optional)', 'pv-bom-stock' ) }
					type="date"
					value={ expectedDate }
					onChange={ setExpectedDate }
					__next40pxDefaultSize
				/>
			</Field>

			<p><strong>{ __( 'Line items:', 'pv-bom-stock' ) }</strong></p>
			<table className="widefat" style={ { marginBottom: '8px' } }>
				<thead>
					<tr>
						<th>{ __( 'Component', 'pv-bom-stock' ) }</th>
						<th>{ __( 'Qty', 'pv-bom-stock' ) }</th>
						<th>{ __( 'Unit cost (optional)', 'pv-bom-stock' ) }</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					{ lines.map( ( line, index ) => (
						<tr key={ index }>
							<td>{ line.component.name }</td>
							<td>
								<TextControl
									type="number"
									step="0.0001"
									value={ line.qty }
									onChange={ ( value ) => updateLine( index, { qty: value } ) }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
							</td>
							<td>
								<TextControl
									type="number"
									step="0.01"
									value={ line.unitCost }
									onChange={ ( value ) => updateLine( index, { unitCost: value } ) }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
							</td>
							<td>
								<Button variant="tertiary" isDestructive onClick={ () => removeLine( index ) }>
									{ __( 'Remove', 'pv-bom-stock' ) }
								</Button>
							</td>
						</tr>
					) ) }
					{ 0 === lines.length && (
						<tr>
							<td colSpan={ 4 }>{ __( 'No lines yet.', 'pv-bom-stock' ) }</td>
						</tr>
					) }
				</tbody>
			</table>

			<Field>
				<ComponentPicker restNamespace={ restNamespace } value={ null } onSelect={ addLine } onClear={ () => {} } />
			</Field>

			<Field>
				<TextareaControl
					label={ __( 'Notes (optional)', 'pv-bom-stock' ) }
					value={ notes }
					onChange={ setNotes }
					__nextHasNoMarginBottom
				/>
			</Field>

			<p>
				<Button variant="primary" isBusy={ submitting } disabled={ submitting } onClick={ submit }>
					{ isEdit ? __( 'Save', 'pv-bom-stock' ) : __( 'Create draft', 'pv-bom-stock' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" disabled={ submitting } onClick={ onClose }>
					{ __( 'Cancel', 'pv-bom-stock' ) }
				</Button>
			</p>
		</Modal>
	);
}

function ReceiveModal( { restNamespace, order, onClose, onReceived } ) {
	const [ opKey ] = useState( newOpKey );
	const [ quantities, setQuantities ] = useState(
		Object.fromEntries( order.items.map( ( item ) => [ item.poi_id, String( item.qty_outstanding ) ] ) )
	);
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	function submit() {
		setError( null );

		const receipts = {};
		for ( const [ poiId, qty ] of Object.entries( quantities ) ) {
			const parsed = parseFloat( qty );
			if ( parsed > 0 ) {
				receipts[ poiId ] = parsed;
			}
		}

		if ( 0 === Object.keys( receipts ).length ) {
			setError( __( 'Enter a received quantity for at least one line.', 'pv-bom-stock' ) );
			return;
		}

		setSubmitting( true );
		apiFetch( {
			path: `/${ restNamespace }/purchase-orders/${ order.po_id }/receive`,
			method: 'POST',
			data: { op_key: opKey, receipts },
		} )
			.then( onReceived )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSubmitting( false ) );
	}

	return (
		<Modal title={ sprintf(
			/* translators: %d: purchase order ID */
			__( 'Receive PO #%d', 'pv-bom-stock' ),
			order.po_id
		) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<table className="widefat">
				<thead>
					<tr>
						<th>{ __( 'Component', 'pv-bom-stock' ) }</th>
						<th>{ __( 'Ordered', 'pv-bom-stock' ) }</th>
						<th>{ __( 'Received so far', 'pv-bom-stock' ) }</th>
						<th>{ __( 'Receiving now', 'pv-bom-stock' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ order.items.map( ( item ) => (
						<tr key={ item.poi_id }>
							<td>{ item.name }</td>
							<td>{ formatNumber( item.qty_ordered ) } { item.unit }</td>
							<td>{ formatNumber( item.qty_received ) } { item.unit }</td>
							<td>
								<TextControl
									type="number"
									step="0.0001"
									min="0"
									value={ quantities[ item.poi_id ] }
									onChange={ ( value ) => setQuantities( { ...quantities, [ item.poi_id ]: value } ) }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>

			<p className="description">
				{ __( 'Receiving more than what\'s outstanding is fine — vendors sometimes ship extra.', 'pv-bom-stock' ) }
			</p>

			<p>
				<Button variant="primary" isBusy={ submitting } disabled={ submitting } onClick={ submit }>
					{ __( 'Record receipt', 'pv-bom-stock' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" disabled={ submitting } onClick={ onClose }>
					{ __( 'Cancel', 'pv-bom-stock' ) }
				</Button>
			</p>
		</Modal>
	);
}

// Deliberately its own modal, separate from PurchaseOrderModal's draft-only
// vendor/reference/line-item editing: freight/tax/fees are editable at any
// PO status, since the real bill often arrives after placing or receiving.
function CostsModal( { restNamespace, order, onClose, onSaved } ) {
	const [ freight, setFreight ] = useState( null !== order.freight_cost ? String( order.freight_cost ) : '' );
	const [ tax, setTax ] = useState( null !== order.tax_cost ? String( order.tax_cost ) : '' );
	const [ fees, setFees ] = useState( null !== order.fees_cost ? String( order.fees_cost ) : '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	function submit() {
		setError( null );
		setSubmitting( true );

		apiFetch( {
			path: `/${ restNamespace }/purchase-orders/${ order.po_id }/costs`,
			method: 'PUT',
			data: {
				freight_cost: '' !== freight ? parseFloat( freight ) : null,
				tax_cost: '' !== tax ? parseFloat( tax ) : null,
				fees_cost: '' !== fees ? parseFloat( fees ) : null,
			},
		} )
			.then( onSaved )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSubmitting( false ) );
	}

	return (
		<Modal title={ sprintf(
			/* translators: %d: purchase order ID */
			__( 'Edit costs — PO #%d', 'pv-bom-stock' ),
			order.po_id
		) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<p className="description">
				{ __( 'Freight, tax, and other fees are amortized across this PO\'s line items proportional to their ordered value, for a landed-cost view on the PO detail — never written to any product\'s price.', 'pv-bom-stock' ) }
			</p>

			<Field>
				<TextControl
					label={ __( 'Freight / shipping', 'pv-bom-stock' ) }
					type="number"
					step="0.01"
					value={ freight }
					onChange={ setFreight }
					__next40pxDefaultSize
				/>
			</Field>
			<Field>
				<TextControl
					label={ __( 'Tax', 'pv-bom-stock' ) }
					type="number"
					step="0.01"
					value={ tax }
					onChange={ setTax }
					__next40pxDefaultSize
				/>
			</Field>
			<Field>
				<TextControl
					label={ __( 'Other fees', 'pv-bom-stock' ) }
					type="number"
					step="0.01"
					value={ fees }
					onChange={ setFees }
					__next40pxDefaultSize
				/>
			</Field>

			<p>
				<Button variant="primary" isBusy={ submitting } disabled={ submitting } onClick={ submit }>
					{ __( 'Save', 'pv-bom-stock' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" disabled={ submitting } onClick={ onClose }>
					{ __( 'Cancel', 'pv-bom-stock' ) }
				</Button>
			</p>
		</Modal>
	);
}

// Recipients are resolved server-side from records already on file (the
// vendor's own email, the current WP user's account email) — this modal
// never collects an address to send to, so it can't become an
// arbitrary-email relay.
function SendModal( { restNamespace, order, onClose, onSent } ) {
	const [ toVendor, setToVendor ] = useState( true );
	const [ toMyself, setToMyself ] = useState( false );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	function submit() {
		setError( null );
		setSubmitting( true );

		apiFetch( {
			path: `/${ restNamespace }/purchase-orders/${ order.po_id }/send`,
			method: 'POST',
			data: { to_vendor: toVendor, to_myself: toMyself },
		} )
			.then( onSent )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSubmitting( false ) );
	}

	return (
		<Modal title={ sprintf(
			/* translators: %d: purchase order ID */
			__( 'Send PO #%d', 'pv-bom-stock' ),
			order.po_id
		) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<p className="description">
				{ __( 'Sends a plain-text summary of this purchase order\'s details to whichever email address(es) below are on file.', 'pv-bom-stock' ) }
			</p>

			<Field>
				<CheckboxControl
					label={ sprintf(
						/* translators: %s: vendor name */
						__( 'Send to vendor (%s)', 'pv-bom-stock' ),
						order.vendor_name
					) }
					checked={ toVendor }
					onChange={ setToVendor }
				/>
			</Field>
			<Field>
				<CheckboxControl
					label={ __( 'Send a copy to myself', 'pv-bom-stock' ) }
					checked={ toMyself }
					onChange={ setToMyself }
				/>
			</Field>

			<p>
				<Button variant="primary" isBusy={ submitting } disabled={ submitting || ( ! toVendor && ! toMyself ) } onClick={ submit }>
					{ __( 'Send', 'pv-bom-stock' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" disabled={ submitting } onClick={ onClose }>
					{ __( 'Cancel', 'pv-bom-stock' ) }
				</Button>
			</p>
		</Modal>
	);
}

function ViewModal( { order, onClose } ) {
	const hasFees = order.total_fees > 0;
	const someMissingCost = hasFees && order.items.some( ( item ) => null === item.unit_cost );

	return (
		<Modal title={ sprintf(
			/* translators: 1: purchase order ID, 2: vendor name */
			__( 'PO #%1$d — %2$s', 'pv-bom-stock' ),
			order.po_id,
			order.vendor_name
		) } onRequestClose={ onClose }>
			<p>{ PO_STATUS_LABELS[ order.status ] || order.status }</p>
			{ order.reference && <p>{ sprintf(
				/* translators: %s: vendor reference/invoice number */
				__( 'Reference: %s', 'pv-bom-stock' ),
				order.reference
			) }</p> }
			{ order.notes && <p>{ order.notes }</p> }

			<table className="widefat">
				<thead>
					<tr>
						<th>{ __( 'Component', 'pv-bom-stock' ) }</th>
						<th>{ __( 'Ordered', 'pv-bom-stock' ) }</th>
						<th>{ __( 'Received', 'pv-bom-stock' ) }</th>
						<th>{ __( 'Unit cost', 'pv-bom-stock' ) }</th>
						{ hasFees && <th>{ __( 'Landed unit cost', 'pv-bom-stock' ) }</th> }
					</tr>
				</thead>
				<tbody>
					{ order.items.map( ( item ) => (
						<tr key={ item.poi_id }>
							<td>{ item.name }</td>
							<td>{ formatNumber( item.qty_ordered ) } { item.unit }</td>
							<td>{ formatNumber( item.qty_received ) } { item.unit }</td>
							<td>{ null !== item.unit_cost ? `$${ formatNumber( item.unit_cost ) }` : '—' }</td>
							{ hasFees && (
								<td>{ null !== item.landed_unit_cost ? `$${ formatNumber( item.landed_unit_cost ) }` : '—' }</td>
							) }
						</tr>
					) ) }
				</tbody>
			</table>

			{ hasFees && (
				<p className="description">
					{ sprintf(
						/* translators: 1: freight cost, 2: tax cost, 3: other fees, 4: total */
						__( 'Freight $%1$s + tax $%2$s + fees $%3$s = $%4$s amortized across lines by ordered value.', 'pv-bom-stock' ),
						formatNumber( order.freight_cost || 0 ),
						formatNumber( order.tax_cost || 0 ),
						formatNumber( order.fees_cost || 0 ),
						formatNumber( order.total_fees )
					) }
					{ someMissingCost && ' ' + __( 'Lines with no unit cost entered show "—" for landed cost — enter a unit cost on them for a complete breakdown.', 'pv-bom-stock' ) }
				</p>
			) }

			<p>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'pv-bom-stock' ) }
				</Button>
			</p>
		</Modal>
	);
}

function VendorsTab( { restNamespace } ) {
	const [ vendors, setVendors ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( null );
	const [ editVendor, setEditVendor ] = useState( null ); // vendor object, or {} for "new"

	const load = useCallback( () => {
		setLoading( true );
		apiFetch( { path: `/${ restNamespace }/vendors` } )
			.then( ( response ) => setVendors( response.vendors ) )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) )
			.finally( () => setLoading( false ) );
	}, [ restNamespace ] );

	useEffect( load, [ load ] );

	function archive( vendor ) {
		if ( ! window.confirm( sprintf(
			/* translators: %s: vendor name */
			__( 'Archive "%s"? Its purchase-order history is kept, but it won\'t appear when creating a new PO.', 'pv-bom-stock' ),
			vendor.name
		) ) ) {
			return;
		}

		apiFetch( { path: `/${ restNamespace }/vendors/${ vendor.vendor_id }`, method: 'DELETE' } )
			.then( () => {
				setNotice( { status: 'success', message: __( 'Vendor archived.', 'pv-bom-stock' ) } );
				load();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message || String( err ) } ) );
	}

	function onSaved() {
		setEditVendor( null );
		setNotice( { status: 'success', message: __( 'Vendor saved.', 'pv-bom-stock' ) } );
		load();
	}

	return (
		<>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<p>
				<Button variant="primary" onClick={ () => setEditVendor( {} ) }>
					{ __( '+ New vendor', 'pv-bom-stock' ) }
				</Button>
			</p>

			{ loading ? (
				<Spinner />
			) : (
				<table className="widefat wcbom-vendor-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'pv-bom-stock' ) }</th>
							<th>{ __( 'Email', 'pv-bom-stock' ) }</th>
							<th>{ __( 'Phone', 'pv-bom-stock' ) }</th>
							<th>{ __( 'Status', 'pv-bom-stock' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ vendors.map( ( vendor ) => (
							<tr key={ vendor.vendor_id }>
								<td>{ vendor.name }</td>
								<td>{ vendor.email || '—' }</td>
								<td>{ vendor.phone || '—' }</td>
								<td>{ vendor.is_active ? __( 'Active', 'pv-bom-stock' ) : __( 'Archived', 'pv-bom-stock' ) }</td>
								<td>
									<Button variant="secondary" onClick={ () => setEditVendor( vendor ) }>
										{ __( 'Edit', 'pv-bom-stock' ) }
									</Button>{ ' ' }
									{ vendor.is_active && (
										<Button variant="tertiary" isDestructive onClick={ () => archive( vendor ) }>
											{ __( 'Archive', 'pv-bom-stock' ) }
										</Button>
									) }
								</td>
							</tr>
						) ) }
						{ 0 === vendors.length && (
							<tr>
								<td colSpan={ 5 }>{ __( 'No vendors yet.', 'pv-bom-stock' ) }</td>
							</tr>
						) }
					</tbody>
				</table>
			) }

			{ editVendor && (
				<VendorModal restNamespace={ restNamespace } vendor={ editVendor } onClose={ () => setEditVendor( null ) } onSaved={ onSaved } />
			) }
		</>
	);
}

function VendorModal( { restNamespace, vendor, onClose, onSaved } ) {
	const isEdit = !! vendor.vendor_id;
	const [ name, setName ] = useState( vendor.name || '' );
	const [ email, setEmail ] = useState( vendor.email || '' );
	const [ phone, setPhone ] = useState( vendor.phone || '' );
	const [ website, setWebsite ] = useState( vendor.website || '' );
	const [ notes, setNotes ] = useState( vendor.notes || '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	function submit() {
		setError( null );

		if ( ! name.trim() ) {
			setError( __( 'A vendor name is required.', 'pv-bom-stock' ) );
			return;
		}

		const payload = { name, email: email || null, phone: phone || null, website: website || null, notes: notes || null };
		const path = isEdit ? `/${ restNamespace }/vendors/${ vendor.vendor_id }` : `/${ restNamespace }/vendors`;

		setSubmitting( true );
		apiFetch( { path, method: isEdit ? 'PUT' : 'POST', data: payload } )
			.then( onSaved )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSubmitting( false ) );
	}

	return (
		<Modal title={ isEdit ? __( 'Edit vendor', 'pv-bom-stock' ) : __( 'New vendor', 'pv-bom-stock' ) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Field>
				<TextControl label={ __( 'Name', 'pv-bom-stock' ) } value={ name } onChange={ setName } __next40pxDefaultSize />
			</Field>
			<Field>
				<TextControl label={ __( 'Email', 'pv-bom-stock' ) } type="email" value={ email } onChange={ setEmail } __next40pxDefaultSize />
			</Field>
			<Field>
				<TextControl label={ __( 'Phone', 'pv-bom-stock' ) } value={ phone } onChange={ setPhone } __next40pxDefaultSize />
			</Field>
			<Field>
				<TextControl label={ __( 'Website', 'pv-bom-stock' ) } type="url" value={ website } onChange={ setWebsite } __next40pxDefaultSize />
			</Field>
			<Field>
				<TextareaControl label={ __( 'Notes', 'pv-bom-stock' ) } value={ notes } onChange={ setNotes } __nextHasNoMarginBottom />
			</Field>

			<p>
				<Button variant="primary" isBusy={ submitting } disabled={ submitting } onClick={ submit }>
					{ __( 'Save', 'pv-bom-stock' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" disabled={ submitting } onClick={ onClose }>
					{ __( 'Cancel', 'pv-bom-stock' ) }
				</Button>
			</p>
		</Modal>
	);
}

const root = document.getElementById( 'wcbom-purchasing-root' );
if ( root && window.wcbomPurchasing ) {
	createRoot( root ).render( <PurchasingApp restNamespace={ window.wcbomPurchasing.restNamespace } /> );
}
