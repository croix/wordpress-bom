import { useState, useEffect } from '@wordpress/element';
import { ComboboxControl, Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf } from '@wordpress/i18n';

export default function ComponentPicker( { restNamespace, value, onSelect, onClear } ) {
	const [ term, setTerm ] = useState( '' );
	const [ options, setOptions ] = useState( [] );
	const [ searching, setSearching ] = useState( false );

	useEffect( () => {
		if ( ! term ) {
			setOptions( [] );
			return;
		}

		setSearching( true );
		const handle = setTimeout( () => {
			apiFetch( { path: addQueryArgs( `/${ restNamespace }/components/search`, { term } ) } )
				.then( ( response ) =>
					setOptions(
						response.components.map( ( component ) => ( {
							label: `${ component.name } (${ component.stock ?? 0 } ${ component.unit })`,
							value: String( component.id ),
							component,
						} ) )
					)
				)
				.finally( () => setSearching( false ) );
		}, 300 );

		return () => clearTimeout( handle );
	}, [ term, restNamespace ] );

	function createComponent() {
		apiFetch( {
			path: `/${ restNamespace }/components`,
			method: 'POST',
			data: { name: term, unit: 'ea' },
		} ).then( ( response ) => onSelect( response.component ) );
	}

	if ( value && value.id ) {
		return (
			<span>
				{ value.name }{ ' ' }
				<Button variant="link" onClick={ onClear }>
					{ __( 'change', 'wcbom' ) }
				</Button>
			</span>
		);
	}

	return (
		<>
			<ComboboxControl
				value={ null }
				onFilterValueChange={ setTerm }
				options={ options }
				onChange={ ( id ) => {
					const found = options.find( ( option ) => option.value === id );
					if ( found ) {
						onSelect( found.component );
					}
				} }
				placeholder={ __( 'Search components…', 'wcbom' ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ term && ! searching && options.length === 0 && (
				<Button variant="link" onClick={ createComponent }>
					{ sprintf( __( '+ Create component "%s"', 'wcbom' ), term ) }
				</Button>
			) }
		</>
	);
}
