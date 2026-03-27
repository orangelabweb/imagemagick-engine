//Variables
var rt_images = '';
var rt_total = 1;
var rt_count = 1;
var rt_force = 0;
var rt_precision = 0;
var rt_sizes = '';

// Ajax test IM path
function imeTestPath() {
	jQuery( '.cli_path_icon' ).hide();
	jQuery( '#cli_path_error' ).hide();
	jQuery( '#cli_path_progress' ).show();
	jQuery.get( ajaxurl, {
		action: 'ime_test_im_path',
		ime_nonce: ime_admin.ime_nonce,
		mode: 'cli',
		cli_path: jQuery( '#cli_path' ).val()
	}, function( data ) {
		jQuery( '#cli_path_progress' ).hide();
		if ( data && data.found ) {
			jQuery( '#cli_path_yes' ).show();
			jQuery( '#cli_path_no' ).hide();
		} else {
			jQuery( '#cli_path_yes' ).hide();
			jQuery( '#cli_path_no' ).show();
			var engine = ( data && data.engine ) ? data.engine : 'ImageMagick';
			var tpl = ( data && data.open_basedir ) ? ime_admin.path_open_basedir : ime_admin.path_not_found;
			jQuery( '#cli_path_error' ).text( tpl.replace( '%s', engine ) ).show();
		}
	} );
}

// Ajax test GraphicsMagick path
function imeTestGmPath() {
	jQuery( '.gm_path_icon' ).hide();
	jQuery( '#gm_path_error' ).hide();
	jQuery( '#gm_path_progress' ).show();
	jQuery.get( ajaxurl, {
		action: 'ime_test_im_path',
		ime_nonce: ime_admin.ime_nonce,
		mode: 'graphicsmagick',
		gm_path: jQuery( '#gm_path' ).val()
	}, function( data ) {
		jQuery( '#gm_path_progress' ).hide();
		if ( data && data.found ) {
			jQuery( '#gm_path_yes' ).show();
			jQuery( '#gm_path_no' ).hide();
		} else {
			jQuery( '#gm_path_yes' ).hide();
			jQuery( '#gm_path_no' ).show();
			var engine = ( data && data.engine ) ? data.engine : 'GraphicsMagick';
			var tpl = ( data && data.open_basedir ) ? ime_admin.path_open_basedir : ime_admin.path_not_found;
			jQuery( '#gm_path_error' ).text( tpl.replace( '%s', engine ) ).show();
		}
	} );
}

function imeStartResize() {
	rt_sizes = '';
	rt_force = 0;

	jQuery( '#regenerate-images-metabox input' ).each( function() {
	var i = jQuery( this );
	var name = i.attr( 'name' );

	if ( i.is( ':checked' ) && name && 'regen-size-' == name.substring( 0, 11 ) ) {
		rt_sizes = rt_sizes + name.substring( 11 ) + '|';
	}
	} );

	if ( jQuery( '#force' ).is( ':checked' ) ) {
rt_force = 1;
}

	if ( rt_total > 20000 ) {
rt_precision = 3;
} else if ( rt_total > 2000 ) {
rt_precision = 2;
} else if ( rt_total > 200 ) {
rt_precision = 1;
} else {
rt_precision = 0;
}

	var rt_percent = 0;

	rt_count = 1;
	jQuery( '#ime-regenbar' ).progressbar();
	jQuery( '#ime-regenbar-percent' ).html( rt_percent.toFixed( rt_precision ) + ' %' );
	jQuery( '#ime-regeneration' ).addClass( 'working' );

	imeRegenImages( rt_images.shift() );
}

//Regeneration of progressbar
function imeRegenImages( id ) {
	jQuery.post( ajaxurl, { action: 'ime_process_image', ime_nonce: ime_admin.ime_nonce, id: id, sizes: rt_sizes, force: rt_force }, function( data ) {
	var n = parseInt( data, 10 );
	if ( isNaN( n ) ) {
		alert( data );
	}

	// todo: test and handle negative return

	if ( rt_images.length <= 0 ) {
		jQuery( '#regen-message' ).removeClass( 'hidden' ).html( '<p><strong>' + ime_admin.done + '</strong> ' + ime_admin.processed_fmt.replace( '%d', rt_total ) + '.</p>' );
		jQuery( '#ime-regeneration' ).removeClass( 'working' );
		jQuery( '#ime-regenbar' ).progressbar( 'value', 0 );
		return;
	}

	var next_id = rt_images.shift();
	var rt_percent = ( rt_count / rt_total ) * 100;
	jQuery( '#ime-regenbar' ).progressbar( 'value', rt_percent );
	jQuery( '#ime-regenbar-percent' ).html( rt_percent.toFixed( rt_precision ) + ' %' );
	rt_count = rt_count + 1;

	// tail recursion
	imeRegenImages( next_id );
	} );
}

// Regen single image on media pages
function imeRegenMediaImage( id, sizes, force ) {
	var link = jQuery( '#ime-regen-link-' + id );

	if ( link.hasClass( 'disabled' ) ) {
return false;
}

	link.addClass( 'disabled' );

	var spinner = jQuery( '#ime-spinner-' + id ).children( 'img' );
	spinner.show();

	var message = jQuery( '#ime-message-' + id ).show();
	jQuery.post( ajaxurl, { action: 'ime_process_image', ime_nonce: ime_admin.ime_nonce, id: id, sizes: sizes, force: force }, function( data ) {
	spinner.hide();
	link.removeClass( 'disabled' );

	var n = parseInt( data, 10 );
	if ( isNaN( n ) || n < 0 ) {
		message.html( ime_admin.failed );
		if ( isNaN( n ) ) {
alert( data );
}
	} else {
		message.html( ime_admin.resized );
	}
	} );
}

jQuery( document ).ready( function( $ ) {
	jQuery( '#ime_cli_path_test' ).click( imeTestPath );
	jQuery( '#ime_gm_path_test' ).click( imeTestGmPath );

	jQuery( document ).on( 'click', '.ime-regen-button', function( e ) {
		e.preventDefault();
		var el = jQuery( this );
		imeRegenMediaImage( el.data( 'post-id' ), el.data( 'sizes' ), el.data( 'force' ) );
	} );

	$( '#regenerate-images' ).click( function() {
		$( '#regenerate-images-metabox img.ajax-feedback' ).show();
		$.post( ajaxurl, { action: 'ime_regeneration_get_images', ime_nonce: ime_admin.ime_nonce, }, function( data ) {
			jQuery( '#regen-message' ).addClass( 'hidden' );
			rt_images = data.split( ',' );
			rt_total = rt_images.length;

			if ( rt_total > 0 ) {
				imeStartResize();
			} else {
				alert( ime_admin.noimg );
			}
		} );
	} );
} );
