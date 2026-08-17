/**
 * POST to admin-ajax.php with the plugin nonce.
 *
 * Resolves with the `data` payload of wp_send_json_success().
 * Rejects with an Error carrying the server's message.
 */
function imeRequest( action, data ) {
	var body = new URLSearchParams();
	body.append( 'action', action );
	body.append( 'ime_nonce', ime_admin.ime_nonce );

	Object.keys( data || {} ).forEach( function( key ) {
		body.append( key, data[ key ] );
	} );

	return window.fetch( ime_admin.ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: body.toString()
	} ).then( function( response ) {
		if ( ! response.ok ) {
			throw new Error( ime_admin.request_failed );
		}
		return response.json();
	} ).then( function( json ) {
		if ( ! json || ! json.success ) {
			throw new Error(
				( json && json.data && json.data.message ) || ime_admin.request_failed
			);
		}
		return json.data;
	} );
}

document.addEventListener( 'alpine:init', function() {
	Alpine.data( 'imeSettings', function() {
		return {
			tab: ime_admin.initial_tab,
			enabled: ime_admin.enabled,
			mode: ime_admin.mode,

			get isTabSettings() {
				return this.tab === 'settings';
			},

			get isTabRegenerate() {
				return this.tab === 'regenerate';
			},

			get isPhp() { return this.mode === 'php'; },
			get isGmagick() { return this.mode === 'gmagick'; },
			get isCli() { return this.mode === 'cli'; },
			get isGraphicsmagick() { return this.mode === 'graphicsmagick'; },

			cliPathState: 'unknown',
			cliPathMessage: '',
			gmPathState: 'unknown',
			gmPathMessage: '',

			get cliPathTesting() { return this.cliPathState === 'testing'; },
			get cliPathError() { return this.cliPathState === 'error'; },
			get cliPathOk() { return this.cliPathState === 'ok'; },
			get gmPathTesting() { return this.gmPathState === 'testing'; },
			get gmPathError() { return this.gmPathState === 'error'; },
			get gmPathOk() { return this.gmPathState === 'ok'; },

			testCliPath: function() {
				this.testPath( 'cli', 'cli_path', 'cliPath' );
			},

			testGmPath: function() {
				this.testPath( 'graphicsmagick', 'gm_path', 'gmPath' );
			},

			testPath: function( engineMode, field, prefix ) {
				var self = this;
				var payload = { mode: engineMode };
				payload[ field ] = document.getElementById( field ).value;

				self[ prefix + 'State' ] = 'testing';
				self[ prefix + 'Message' ] = '';

				imeRequest( 'ime_test_im_path', payload ).then( function( data ) {
					self[ prefix + 'State' ] = 'ok';
					self[ prefix + 'Message' ] = data.version
						? data.engine + ' ' + data.version
						: ime_admin.path_found;
				} ).catch( function( error ) {
					self[ prefix + 'State' ] = 'error';
					self[ prefix + 'Message' ] = error.message;
				} );
			},

			get settingsTabClass() {
				return this.tab === 'settings' ? 'nav-tab-active' : '';
			},

			get regenerateTabClass() {
				return this.tab === 'regenerate' ? 'nav-tab-active' : '';
			},

			selectTab: function( name ) {
				this.tab = name;

				var url = new URL( window.location.href );
				url.searchParams.set( 'tab', name );
				window.history.replaceState( {}, '', url.toString() );
			},

			selectSettingsTab: function() {
				this.selectTab( 'settings' );
			},

			selectRegenerateTab: function() {
				this.selectTab( 'regenerate' );
			},

			setAllQuality: function() { this.setAllHandleModes( 'quality' ); },
			setAllSize: function() { this.setAllHandleModes( 'size' ); },
			setAllSkip: function() { this.setAllHandleModes( 'skip' ); },

			setAllHandleModes: function( value ) {
				var inputs = document.querySelectorAll( '.ime-handle-mode--' + value );
				Array.prototype.forEach.call( inputs, function( input ) {
					input.checked = true;
				} );
			}
		};
	} );
} );

//Variables
var rt_images = '';
var rt_total = 1;
var rt_count = 1;
var rt_force = 0;
var rt_precision = 0;
var rt_sizes = '';

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
	jQuery( document ).on( 'click', '.ime-regen-button', function( e ) {
		e.preventDefault();
		var el = jQuery( this );
		imeRegenMediaImage( el.data( 'post-id' ), el.data( 'sizes' ), el.data( 'force' ) );
	} );

	$( '#regenerate-images' ).click( function() {
		$( '#regenerate-images-metabox img.ajax-feedback' ).show();
		$.post( ajaxurl, { action: 'ime_regeneration_get_images', ime_nonce: ime_admin.ime_nonce, }, function( data ) {
			jQuery( '#regen-message' ).addClass( 'hidden' );
			rt_images = Array.isArray( data ) ? data : [];
			rt_total = rt_images.length;

			if ( rt_total > 0 ) {
				imeStartResize();
			} else {
				alert( ime_admin.noimg );
			}
		}, 'json' );
	} );
} );
