import { createRoot, useEffect, useState, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import ComponentPicker from './component-picker';

const CONDITION_ALWAYS = 'always';
const CONDITION_ATTRIBUTE = 'attribute';
const CONDITION_ADDON = 'addon';

function fromServer( item ) {
	return { key: `saved-${ item.item_id }`, ...item, surcharge: item.surcharge ?? '' };
}

function emptyLine() {
	return {
		key: `new-${ Math.random().toString( 36 ).slice( 2 ) }`,
		item_id: 0,
		component_id: 0,
		component: null,
		qty: 1,
		condition_type: CONDITION_ALWAYS,
		condition_key: '',
		condition_value: '',
		surcharge: '',
	};
}

function BomEditor( { productId, restNamespace, variationAttributes, weightFromBom } ) {
	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ buildable, setBuildable ] = useState( null );

	const loadBuildable = useCallback( () => {
		apiFetch( { path: `/${ restNamespace }/buildable/${ productId }` } )
			.then( setBuildable )
			.catch( () => setBuildable( null ) );
	}, [ restNamespace, productId ] );

	useEffect( () => {
		apiFetch( { path: `/${ restNamespace }/boms/${ productId }` } )
			.then( ( response ) => {
				const bom = response.bom;
				setItems( bom ? bom.items.map( fromServer ) : [] );
			} )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setLoading( false ) );

		loadBuildable();
	}, [ productId, restNamespace, loadBuildable ] );

	function updateItem( key, patch ) {
		setItems( ( current ) =>
			current.map( ( item ) => ( item.key === key ? { ...item, ...patch } : item ) )
		);
	}

	function removeItem( key ) {
		setItems( ( current ) => current.filter( ( item ) => item.key !== key ) );
	}

	function addItem() {
		setItems( ( current ) => [ ...current, emptyLine() ] );
	}

	function save() {
		setSaving( true );
		setError( null );

		const payload = {
			items: items
				.filter( ( item ) => item.component_id )
				.map( ( item ) => ( {
					component_id: item.component_id,
					qty: parseFloat( item.qty ) || 0,
					condition_type: item.condition_type,
					condition_key: item.condition_type === CONDITION_ALWAYS ? null : item.condition_key,
					condition_value: item.condition_type === CONDITION_ALWAYS ? null : item.condition_value,
					surcharge: item.surcharge !== '' && item.surcharge !== null && item.surcharge !== undefined
						? parseFloat( item.surcharge )
						: null,
				} ) ),
		};

		apiFetch( { path: `/${ restNamespace }/boms/${ productId }`, method: 'POST', data: payload } )
			.then( ( response ) => {
				const bom = response.bom;
				setItems( bom.items.map( fromServer ) );
				loadBuildable();
			} )
			.catch( ( err ) => setError( err.message || String( err ) ) )
			.finally( () => setSaving( false ) );
	}

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<Card>
			<CardBody>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ buildable && (
					<Notice status={ buildable.buildable_qty > 0 ? 'success' : 'warning' } isDismissible={ false }>
						{ buildable.note
							? buildable.note
							: sprintf(
									/* translators: 1: buildable quantity, 2: bottleneck component name, 3: estimated cost */
									__( 'Buildable now: %1$d (limited by %2$s) — estimated cost per unit: $%3$s', 'wcbom' ),
									buildable.buildable_qty,
									buildable.bottleneck ? buildable.bottleneck.name : __( 'n/a', 'wcbom' ),
									buildable.estimated_cost
							  ) }
					</Notice>
				) }

				<table className="widefat wcbom-bom-lines">
					<thead>
						<tr>
							<th>{ __( 'Component', 'wcbom' ) }</th>
							<th>{ __( 'Qty', 'wcbom' ) }</th>
							<th>{ __( 'Consume when', 'wcbom' ) }</th>
							<th>{ __( 'Surcharge', 'wcbom' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( item ) => (
							<BomLineRow
								key={ item.key }
								item={ item }
								restNamespace={ restNamespace }
								variationAttributes={ variationAttributes }
								weightFromBom={ weightFromBom }
								onChange={ ( patch ) => updateItem( item.key, patch ) }
								onRemove={ () => removeItem( item.key ) }
							/>
						) ) }
					</tbody>
				</table>

				<p>
					<Button variant="secondary" onClick={ addItem }>
						{ __( '+ Add line', 'wcbom' ) }
					</Button>
				</p>

				<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
					{ __( 'Save BOM', 'wcbom' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

function BomLineRow( { item, restNamespace, variationAttributes, weightFromBom, onChange, onRemove } ) {
	const attribute = variationAttributes.find( ( attr ) => attr.taxonomy === item.condition_key );
	const missingWeight = weightFromBom && item.component && ! ( parseFloat( item.component.weight ) > 0 );
	const surchargeOnAttribute = item.condition_type === CONDITION_ATTRIBUTE && parseFloat( item.surcharge ) > 0;

	return (
		<tr>
			<td>
				<ComponentPicker
					restNamespace={ restNamespace }
					value={ item.component }
					onSelect={ ( component ) =>
						onChange( { component_id: component.id, component } )
					}
					onClear={ () => onChange( { component_id: 0, component: null } ) }
				/>
				{ missingWeight && (
					<p className="description wcbom-line-warning">
						{ __( '⚠ This component has no weight set — it will contribute 0 to the BOM-derived shipping weight.', 'wcbom' ) }
					</p>
				) }
			</td>
			<td>
				<TextControl
					type="number"
					step="0.0001"
					min="0"
					value={ item.qty }
					onChange={ ( qty ) => onChange( { qty } ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ item.component && (
					<span className="description"> { item.component.unit }</span>
				) }
			</td>
			<td>
				<SelectControl
					value={ item.condition_type }
					options={ [
						{ label: __( 'Always', 'wcbom' ), value: CONDITION_ALWAYS },
						{ label: __( 'Variation attribute', 'wcbom' ), value: CONDITION_ATTRIBUTE },
						{ label: __( 'Add-on field', 'wcbom' ), value: CONDITION_ADDON },
					] }
					onChange={ ( condition_type ) =>
						onChange( { condition_type, condition_key: '', condition_value: '' } )
					}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>

				{ item.condition_type === CONDITION_ATTRIBUTE && (
					<>
						<SelectControl
							value={ item.condition_key }
							options={ [
								{ label: __( '— attribute —', 'wcbom' ), value: '' },
								...variationAttributes.map( ( attr ) => ( {
									label: attr.label,
									value: attr.taxonomy,
								} ) ),
							] }
							onChange={ ( condition_key ) => onChange( { condition_key, condition_value: '' } ) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<SelectControl
							value={ item.condition_value }
							options={ [
								{ label: __( '— value —', 'wcbom' ), value: '' },
								...( attribute ? attribute.terms : [] ).map( ( term ) => ( {
									label: term.name,
									value: term.slug,
								} ) ),
							] }
							onChange={ ( condition_value ) => onChange( { condition_value } ) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</>
				) }

				{ item.condition_type === CONDITION_ADDON && (
					<>
						<TextControl
							placeholder={ __( 'Add-on field name', 'wcbom' ) }
							value={ item.condition_key }
							onChange={ ( condition_key ) => onChange( { condition_key } ) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<TextControl
							placeholder={ __( 'Add-on field value', 'wcbom' ) }
							value={ item.condition_value }
							onChange={ ( condition_value ) => onChange( { condition_value } ) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</>
				) }
			</td>
			<td>
				<TextControl
					type="number"
					step="0.01"
					min="0"
					placeholder={ __( 'none', 'wcbom' ) }
					value={ item.surcharge }
					onChange={ ( surcharge ) => onChange( { surcharge } ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ surchargeOnAttribute && (
					<p className="description wcbom-line-warning">
						{ __( '⚠ Stacks additively with variation pricing — make sure this option doesn\'t already charge more through its own variation price.', 'wcbom' ) }
					</p>
				) }
			</td>
			<td>
				<Button variant="tertiary" isDestructive onClick={ onRemove }>
					{ __( 'Remove', 'wcbom' ) }
				</Button>
			</td>
		</tr>
	);
}

const root = document.getElementById( 'wcbom-bom-editor-root' );
if ( root && window.wcbomBomEditor ) {
	const { productId, restNamespace, variationAttributes, weightFromBom } = window.wcbomBomEditor;
	createRoot( root ).render(
		<BomEditor
			productId={ productId }
			restNamespace={ restNamespace }
			variationAttributes={ variationAttributes || [] }
			weightFromBom={ !! weightFromBom }
		/>
	);
}
