import { createRoot, useEffect, useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

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
			empty={ __( 'No made-to-order products with an active BOM yet.', 'pv-bom-stock' ) }
			columns={ [
				{ key: 'name', label: __( 'Product', 'pv-bom-stock' ) },
				{ key: 'buildable_qty', label: __( 'Buildable now', 'pv-bom-stock' ) },
				{
					key: 'bottleneck',
					label: __( 'Bottleneck', 'pv-bom-stock' ),
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
			empty={ __( 'Nothing is currently low on stock.', 'pv-bom-stock' ) }
			columns={ [
				{ key: 'name', label: __( 'Component', 'pv-bom-stock' ) },
				{ key: 'stock', label: __( 'On hand', 'pv-bom-stock' ), render: ( row ) => formatNumber( row.stock ) },
				{ key: 'threshold', label: __( 'Threshold', 'pv-bom-stock' ), render: ( row ) => formatNumber( row.threshold ) },
				{ key: 'blocks_products', label: __( 'Blocks', 'pv-bom-stock' ), render: ( row ) => `${ row.blocks_products } product(s)` },
			] }
		/>
	);
}

function MarginTab( { restNamespace } ) {
	return (
		<ReportTable
			restNamespace={ restNamespace }
			path="/reports/margin"
			empty={ __( 'No made-to-order/manufactured products with an active BOM yet.', 'pv-bom-stock' ) }
			columns={ [
				{ key: 'name', label: __( 'Product', 'pv-bom-stock' ) },
				{ key: 'price', label: __( 'Price', 'pv-bom-stock' ), render: ( row ) => formatMoney( row.price ) },
				{ key: 'cost', label: __( 'BOM cost', 'pv-bom-stock' ), render: ( row ) => formatMoney( row.cost ) },
				{ key: 'margin', label: __( 'Margin', 'pv-bom-stock' ), render: ( row ) => formatMoney( row.margin ) },
				{
					key: 'margin_pct',
					label: __( 'Margin %', 'pv-bom-stock' ),
					render: ( row ) => ( null === row.margin_pct ? '—' : `${ row.margin_pct }%` ),
				},
			] }
		/>
	);
}

function UsageTab( { restNamespace } ) {
	const { rows, loading, error } = useReport( restNamespace, '/reports/usage' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const perPage = 10;

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

	const filtered = rows.filter( ( row ) =>
		row.name.toLowerCase().includes( search.toLowerCase() )
	);
	const totalPages = Math.max( 1, Math.ceil( filtered.length / perPage ) );
	const page_ = Math.min( page, totalPages );
	const visible = filtered.slice( ( page_ - 1 ) * perPage, page_ * perPage );

	return (
		<>
			<TextControl
				label={ __( 'Search components', 'pv-bom-stock' ) }
				value={ search }
				onChange={ ( value ) => {
					setSearch( value );
					setPage( 1 );
				} }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			{ 0 === rows.length && <p>{ __( 'No components yet.', 'pv-bom-stock' ) }</p> }
			{ rows.length > 0 && 0 === filtered.length && (
				<p>{ __( 'No components match your search.', 'pv-bom-stock' ) }</p>
			) }

			{ filtered.length > 0 && (
				<>
					<table className="widefat striped" style={ { marginTop: '1em' } }>
						<thead>
							<tr>
								<th>{ __( 'Item name', 'pv-bom-stock' ) }</th>
								<th>{ __( 'On hand', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Consumed (30d)', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Consumed (90d)', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Days of stock', 'pv-bom-stock' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ visible.map( ( row ) => (
								<tr key={ row.component_id }>
									<td>{ row.name }</td>
									<td>{ formatNumber( row.stock ) } { row.unit }</td>
									<td>{ formatNumber( row.consumed_30d ) }</td>
									<td>{ formatNumber( row.consumed_90d ) }</td>
									<td>{ null === row.days_of_stock ? __( 'n/a', 'pv-bom-stock' ) : row.days_of_stock }</td>
								</tr>
							) ) }
						</tbody>
					</table>

					{ totalPages > 1 && (
						<p>
							<button
								className="button"
								disabled={ page_ <= 1 }
								onClick={ () => setPage( ( p ) => p - 1 ) }
							>
								{ __( '‹ Previous', 'pv-bom-stock' ) }
							</button>{ ' ' }
							{ __( 'Page', 'pv-bom-stock' ) } { page_ } { __( 'of', 'pv-bom-stock' ) } { totalPages }{ ' ' }
							<button
								className="button"
								disabled={ page_ >= totalPages }
								onClick={ () => setPage( ( p ) => p + 1 ) }
							>
								{ __( 'Next ›', 'pv-bom-stock' ) }
							</button>
						</p>
					) }
				</>
			) }
		</>
	);
}

function defaultDateFrom() {
	const d = new Date();
	d.setDate( d.getDate() - 30 );
	return d.toISOString().slice( 0, 10 );
}

function defaultDateTo() {
	return new Date().toISOString().slice( 0, 10 );
}

function ProfitabilityTab( { restNamespace, profitabilityExportUrl } ) {
	const [ view, setView ] = useState( 'product' );
	const [ dateFrom, setDateFrom ] = useState( defaultDateFrom() );
	const [ dateTo, setDateTo ] = useState( defaultDateTo() );
	const [ rows, setRows ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setLoading( true );
		setError( null );

		const path =
			'trend' === view
				? `/${ restNamespace }/reports/profitability/trend`
				: addQueryArgs( `/${ restNamespace }/reports/profitability/${ view }`, {
						date_from: dateFrom,
						date_to: dateTo,
				  } );

		apiFetch( { path } )
			.then( ( response ) => setRows( response.rows ) )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setLoading( false ) );
	}, [ restNamespace, view, dateFrom, dateTo ] );

	const keyField = 'order' === view ? 'order_id' : 'trend' === view ? 'month' : 'product_id';

	const labelFor = ( row ) => {
		if ( 'order' === view ) {
			return `#${ row.order_id }`;
		}
		if ( 'trend' === view ) {
			return row.month;
		}
		return row.product_name || `#${ row.product_id }`;
	};

	const exportHref = addQueryArgs( profitabilityExportUrl, {
		view,
		date_from: dateFrom,
		date_to: dateTo,
	} );

	const hasUncosted = rows.some( ( row ) => row.uncosted_quantity > 0 );

	return (
		<>
			<div style={ { display: 'flex', gap: '1em', alignItems: 'flex-end', marginBottom: '1em', flexWrap: 'wrap' } }>
				<SelectControl
					label={ __( 'View', 'pv-bom-stock' ) }
					value={ view }
					options={ [
						{ label: __( 'Product', 'pv-bom-stock' ), value: 'product' },
						{ label: __( 'Order', 'pv-bom-stock' ), value: 'order' },
						{ label: __( 'Trend (12 months)', 'pv-bom-stock' ), value: 'trend' },
					] }
					onChange={ setView }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ 'trend' !== view && (
					<>
						<TextControl
							type="date"
							label={ __( 'From', 'pv-bom-stock' ) }
							value={ dateFrom }
							onChange={ setDateFrom }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<TextControl
							type="date"
							label={ __( 'To', 'pv-bom-stock' ) }
							value={ dateTo }
							onChange={ setDateTo }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</>
				) }
				<a className="button" href={ exportHref }>
					{ __( 'Export CSV', 'pv-bom-stock' ) }
				</a>
			</div>

			{ loading && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! loading && ! error && 0 === rows.length && (
				<p>{ __( 'No realized sales in this range yet.', 'pv-bom-stock' ) }</p>
			) }

			{ ! loading && ! error && rows.length > 0 && (
				<>
					{ hasUncosted && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Some sales have no cost on file. Their margin below is a ceiling, not an exact figure — see the "uncosted" quantity per row.', 'pv-bom-stock' ) }
						</Notice>
					) }
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ 'order' === view ? __( 'Order', 'pv-bom-stock' ) : 'trend' === view ? __( 'Month', 'pv-bom-stock' ) : __( 'Product', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Qty', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Revenue', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Cost', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Uncosted qty', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Profit', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Margin', 'pv-bom-stock' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row[ keyField ] }>
									<td>{ labelFor( row ) }</td>
									<td>{ formatNumber( row.quantity ) }</td>
									<td>{ formatMoney( row.revenue ) }</td>
									<td>{ formatMoney( row.cost ) }</td>
									<td>{ row.uncosted_quantity > 0 ? formatNumber( row.uncosted_quantity ) : '—' }</td>
									<td>{ formatMoney( row.profit ) }</td>
									<td>
										{ null === row.margin
											? '—'
											: `${ row.uncosted_quantity > 0 ? '≤' : '' }${ row.margin }%` }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
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
					label={ __( 'Reason', 'pv-bom-stock' ) }
					value={ reason }
					options={ [
						{ label: __( 'All', 'pv-bom-stock' ), value: '' },
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
					{ __( 'Export CSV', 'pv-bom-stock' ) }
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
								<th>{ __( 'Date', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Product', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Delta', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Stock after', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Reason', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Reference', 'pv-bom-stock' ) }</th>
								<th>{ __( 'Note', 'pv-bom-stock' ) }</th>
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
									<td>{ row.ref_type ? ( row.ref_id ? `${ row.ref_type } #${ row.ref_id }` : row.ref_type ) : '—' }</td>
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
							{ __( '‹ Previous', 'pv-bom-stock' ) }
						</button>{ ' ' }
						{ __( 'Page', 'pv-bom-stock' ) } { page } { __( 'of', 'pv-bom-stock' ) } { totalPages }{ ' ' }
						<button
							className="button"
							disabled={ page >= totalPages }
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							{ __( 'Next ›', 'pv-bom-stock' ) }
						</button>
					</p>
				</>
			) }
		</>
	);
}

function ReportsApp( { restNamespace, ledgerExportUrl, profitabilityExportUrl } ) {
	return (
		<Card>
			<CardBody>
				<TabPanel
					tabs={ [
						{ name: 'buildable', title: __( 'Buildable', 'pv-bom-stock' ) },
						{ name: 'low-stock', title: __( 'Low Stock', 'pv-bom-stock' ) },
						{ name: 'margin', title: __( 'Margin', 'pv-bom-stock' ) },
						{ name: 'usage', title: __( 'Component Usage', 'pv-bom-stock' ) },
						{ name: 'profitability', title: __( 'Profitability', 'pv-bom-stock' ) },
						{ name: 'ledger', title: __( 'Ledger', 'pv-bom-stock' ) },
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
							case 'profitability':
								return (
									<ProfitabilityTab
										restNamespace={ restNamespace }
										profitabilityExportUrl={ profitabilityExportUrl }
									/>
								);
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
	const { restNamespace, ledgerExportUrl, profitabilityExportUrl } = window.wcbomReports;
	createRoot( root ).render(
		<ReportsApp
			restNamespace={ restNamespace }
			ledgerExportUrl={ ledgerExportUrl }
			profitabilityExportUrl={ profitabilityExportUrl }
		/>
	);
}
