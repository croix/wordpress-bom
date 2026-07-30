import { createRoot, useEffect, useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

import ComponentPicker from '../bom-editor/component-picker';

function formatNumber( n ) {
	if ( null === n || undefined === n ) {
		return '—';
	}
	return String( Math.round( n * 10000 ) / 10000 );
}

function formatMoney( n ) {
	if ( null === n || undefined === n ) {
		return '—';
	}
	return '$' + Number( n ).toFixed( 2 );
}

function useReport( restNamespace, path ) {
	const [ rows, setRows ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setLoading( true );
		setError( null );
		apiFetch( { path: `/${ restNamespace }${ path }` } )
			.then( ( response ) => setRows( response.rows ) )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setLoading( false ) );
	}, [ restNamespace, path ] );

	return { rows, loading, error };
}

function ReportTable( { restNamespace, path, columns, empty } ) {
	const { rows, loading, error } = useReport( restNamespace, path );

	if ( loading ) {
		return <Spinner />;
	}
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( 0 === rows.length ) {
		return <p>{ empty }</p>;
	}

	return (
		<table className="widefat striped">
			<thead>
				<tr>
					{ columns.map( ( col ) => (
						<th key={ col.key }>{ col.label }</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row, i ) => (
					<tr key={ row.product_id ?? row.component_id ?? i }>
						{ columns.map( ( col ) => (
							<td key={ col.key }>{ col.render ? col.render( row ) : row[ col.key ] }</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function BuildableTab( { restNamespace } ) {
	return (
		<ReportTable
			restNamespace={ restNamespace }
			path="/reports/buildable"
			empty={ __( 'No made-to-order products with an active BOM yet.', 'wcbom' ) }
			columns={ [
				{ key: 'name', label: __( 'Product', 'wcbom' ) },
				{ key: 'buildable_qty', label: __( 'Buildable now', 'wcbom' ) },
				{
					key: 'bottleneck',
					label: __( 'Bottleneck', 'wcbom' ),
					render: ( row ) => ( row.bottleneck ? row.bottleneck.name : '—' ),
				},
			] }
		/>
	);
}

function LowStockTab( { restNamespace } ) {
	return (
		<ReportTable
			restNamespace={ restNamespace }
			path="/reports/low-stock"
			empty={ __( 'Nothing is currently low on stock.', 'wcbom' ) }
			columns={ [
				{ key: 'name', label: __( 'Component', 'wcbom' ) },
				{ key: 'stock', label: __( 'On hand', 'wcbom' ), render: ( row ) => formatNumber( row.stock ) },
				{ key: 'threshold', label: __( 'Threshold', 'wcbom' ), render: ( row ) => formatNumber( row.threshold ) },
				{ key: 'blocks_products', label: __( 'Blocks', 'wcbom' ), render: ( row ) => `${ row.blocks_products } product(s)` },
			] }
		/>
	);
}

function MarginTab( { restNamespace } ) {
	return (
		<ReportTable
			restNamespace={ restNamespace }
			path="/reports/margin"
			empty={ __( 'No made-to-order/manufactured products with an active BOM yet.', 'wcbom' ) }
			columns={ [
				{ key: 'name', label: __( 'Product', 'wcbom' ) },
				{ key: 'price', label: __( 'Price', 'wcbom' ), render: ( row ) => formatMoney( row.price ) },
				{ key: 'cost', label: __( 'BOM cost', 'wcbom' ), render: ( row ) => formatMoney( row.cost ) },
				{ key: 'margin', label: __( 'Margin', 'wcbom' ), render: ( row ) => formatMoney( row.margin ) },
				{
					key: 'margin_pct',
					label: __( 'Margin %', 'wcbom' ),
					render: ( row ) => ( null === row.margin_pct ? '—' : `${ row.margin_pct }%` ),
				},
			] }
		/>
	);
}

function UsageTab( { restNamespace } ) {
	const [ component, setComponent ] = useState( null );
	const [ usage, setUsage ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! component ) {
			setUsage( null );
			return;
		}
		setLoading( true );
		setError( null );
		apiFetch( { path: `/${ restNamespace }/reports/usage/${ component.id }` } )
			.then( setUsage )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setLoading( false ) );
	}, [ restNamespace, component ] );

	return (
		<>
			<ComponentPicker
				restNamespace={ restNamespace }
				value={ component }
				onSelect={ setComponent }
				onClear={ () => setComponent( null ) }
			/>

			{ loading && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ usage && (
				<Card style={ { marginTop: '1em' } }>
					<CardBody>
						<p>
							{ __( 'On hand:', 'wcbom' ) } <strong>{ formatNumber( usage.stock ) }</strong>
							{ ' — ' }
							{ __( 'consumed last 30 days:', 'wcbom' ) } <strong>{ formatNumber( usage.consumed_30d ) }</strong>
							{ ' — ' }
							{ __( 'consumed last 90 days:', 'wcbom' ) } <strong>{ formatNumber( usage.consumed_90d ) }</strong>
							{ ' — ' }
							{ __( 'days of stock left:', 'wcbom' ) }{ ' ' }
							<strong>{ null === usage.days_of_stock ? __( 'n/a (no recent consumption)', 'wcbom' ) : usage.days_of_stock }</strong>
						</p>

						<h3>{ __( 'Used in', 'wcbom' ) }</h3>
						{ 0 === usage.used_in.length ? (
							<p>{ __( 'Not used in any active BOM.', 'wcbom' ) }</p>
						) : (
							<ul>
								{ usage.used_in.map( ( item ) => (
									<li key={ item.product_id }>{ item.name }</li>
								) ) }
							</ul>
						) }
					</CardBody>
				</Card>
			) }
		</>
	);
}

function LedgerTab( { restNamespace, ledgerExportUrl } ) {
	const [ rows, setRows ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ reason, setReason ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const perPage = 50;

	useEffect( () => {
		setLoading( true );
		setError( null );
		apiFetch( {
			path: addQueryArgs( `/${ restNamespace }/ledger`, {
				page,
				per_page: perPage,
				reason: reason || undefined,
			} ),
		} )
			.then( ( response ) => {
				setRows( response.rows );
				setTotal( response.total );
			} )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setLoading( false ) );
	}, [ restNamespace, page, reason ] );

	const exportHref = addQueryArgs( ledgerExportUrl, reason ? { reason } : {} );
	const totalPages = Math.max( 1, Math.ceil( total / perPage ) );

	return (
		<>
			<div style={ { display: 'flex', gap: '1em', alignItems: 'flex-end', marginBottom: '1em' } }>
				<SelectControl
					label={ __( 'Reason', 'wcbom' ) }
					value={ reason }
					options={ [
						{ label: __( 'All', 'wcbom' ), value: '' },
						{ label: 'order', value: 'order' },
						{ label: 'order_restore', value: 'order_restore' },
						{ label: 'refund', value: 'refund' },
						{ label: 'manufacture', value: 'manufacture' },
						{ label: 'manufacture_reverse', value: 'manufacture_reverse' },
						{ label: 'manual_adjust', value: 'manual_adjust' },
						{ label: 'received', value: 'received' },
						{ label: 'cycle_count', value: 'cycle_count' },
					] }
					onChange={ ( value ) => {
						setReason( value );
						setPage( 1 );
					} }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<a className="button" href={ exportHref }>
					{ __( 'Export CSV', 'wcbom' ) }
				</a>
			</div>

			{ loading && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! loading && ! error && (
				<>
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Date', 'wcbom' ) }</th>
								<th>{ __( 'Product', 'wcbom' ) }</th>
								<th>{ __( 'Delta', 'wcbom' ) }</th>
								<th>{ __( 'Stock after', 'wcbom' ) }</th>
								<th>{ __( 'Reason', 'wcbom' ) }</th>
								<th>{ __( 'Reference', 'wcbom' ) }</th>
								<th>{ __( 'Note', 'wcbom' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.ledger_id }>
									<td>{ row.created_at }</td>
									<td>{ row.product_name }</td>
									<td>{ formatNumber( row.delta ) }</td>
									<td>{ formatNumber( row.stock_after ) }</td>
									<td>{ row.reason }</td>
									<td>{ row.ref_type ? `${ row.ref_type } #${ row.ref_id }` : '—' }</td>
									<td>{ row.note || '—' }</td>
								</tr>
							) ) }
						</tbody>
					</table>

					<p>
						<button
							className="button"
							disabled={ page <= 1 }
							onClick={ () => setPage( ( p ) => p - 1 ) }
						>
							{ __( '‹ Previous', 'wcbom' ) }
						</button>{ ' ' }
						{ __( 'Page', 'wcbom' ) } { page } { __( 'of', 'wcbom' ) } { totalPages }{ ' ' }
						<button
							className="button"
							disabled={ page >= totalPages }
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							{ __( 'Next ›', 'wcbom' ) }
						</button>
					</p>
				</>
			) }
		</>
	);
}

function ReportsApp( { restNamespace, ledgerExportUrl } ) {
	return (
		<Card>
			<CardBody>
				<TabPanel
					tabs={ [
						{ name: 'buildable', title: __( 'Buildable', 'wcbom' ) },
						{ name: 'low-stock', title: __( 'Low Stock', 'wcbom' ) },
						{ name: 'margin', title: __( 'Margin', 'wcbom' ) },
						{ name: 'usage', title: __( 'Component Usage', 'wcbom' ) },
						{ name: 'ledger', title: __( 'Ledger', 'wcbom' ) },
					] }
				>
					{ ( tab ) => {
						switch ( tab.name ) {
							case 'low-stock':
								return <LowStockTab restNamespace={ restNamespace } />;
							case 'margin':
								return <MarginTab restNamespace={ restNamespace } />;
							case 'usage':
								return <UsageTab restNamespace={ restNamespace } />;
							case 'ledger':
								return <LedgerTab restNamespace={ restNamespace } ledgerExportUrl={ ledgerExportUrl } />;
							default:
								return <BuildableTab restNamespace={ restNamespace } />;
						}
					} }
				</TabPanel>
			</CardBody>
		</Card>
	);
}

const root = document.getElementById( 'wcbom-reports-root' );
if ( root && window.wcbomReports ) {
	const { restNamespace, ledgerExportUrl } = window.wcbomReports;
	createRoot( root ).render(
		<ReportsApp restNamespace={ restNamespace } ledgerExportUrl={ ledgerExportUrl } />
	);
}
