/**
 * POST to admin-ajax.php with the plugin nonce.
 *
 * Resolves with the `data` payload of wp_send_json_success().
 * Rejects with an Error carrying the server's message and, when the
 * server sent one, a `code` property identifying the failure.
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
			var error = new Error(
				( json && json.data && json.data.message ) || ime_admin.request_failed
			);
			error.code = ( json && json.data && json.data.code ) || '';
			throw error;
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

	Alpine.data( 'imeRegen', function() {
		return {
			state: 'idle',
			paused: false,
			done: 0,
			total: 0,
			failed: [],
			failedCount: 0,
			force: false,
			errorMessage: '',
			cancelRequested: false,
			runToken: 0,
			batchTimes: [],

			// Alpine calls init() automatically on the component root, so the
			// markup needs no x-init attribute — which the CSP build would
			// reject anyway if it carried arguments.
			init: function() {
				this.loadState();
			},

			get isIdle() { return this.state === 'idle'; },
			get isRunning() { return this.state === 'running'; },
			get isDone() { return this.state === 'done'; },
			get isPaused() { return this.paused; },
			get hasFailures() { return this.failed.length > 0; },
			get hasError() { return this.errorMessage !== ''; },

			get percent() {
				if ( ! this.total ) {
					return 0;
				}
				return Math.min( 100, ( this.done / this.total ) * 100 );
			},

			get headingText() {
				return this.paused ? ime_admin.regen_paused : ime_admin.regen_running;
			},

			get statusText() {
				var text = this.done.toLocaleString() + ' / ' + this.total.toLocaleString();
				var eta = this.etaText;

				if ( eta ) {
					text += ' · ' + eta;
				}
				if ( this.failedCount ) {
					text += ' · ' + ime_admin.regen_failed_fmt.replace( '%d', this.failedCount );
				}
				return text;
			},

			get etaText() {
				// Under ten images the average is noise, so say nothing.
				if ( this.done < 10 || this.batchTimes.length < 2 ) {
					return '';
				}

				// Moving average over the last five batches only: throughput
				// changes as image sizes vary, and a cumulative mean lags badly.
				var recent = this.batchTimes.slice( -5 );
				var totalSeconds = 0;
				var totalImages = 0;

				recent.forEach( function( entry ) {
					totalSeconds += entry.seconds;
					totalImages += entry.images;
				} );

				if ( ! totalImages ) {
					return '';
				}

				var remaining = ( this.total - this.done ) * ( totalSeconds / totalImages );
				var minutes = Math.max( 1, Math.round( remaining / 60 ) );

				return ime_admin.regen_eta_fmt.replace( '%d', minutes );
			},

			get doneText() {
				return ime_admin.regen_done_fmt.replace( '%d', this.total.toLocaleString() );
			},

			get failedText() {
				return ime_admin.regen_failed_fmt.replace( '%d', this.failedCount );
			},

			loadState: function() {
				var self = this;
				var token = self.runToken;

				imeRequest( 'ime_regen_state', {} ).then( function( data ) {
					if ( token !== self.runToken || ! data.running ) {
						return;
					}
					self.state = 'running';
					self.paused = true;
					self.done = data.done;
					self.total = data.total;
					self.failed = data.failed || [];
					self.failedCount = data.failed_count || 0;
				} ).catch( function() {
					// A missing queue is not an error worth showing.
				} );
			},

			selectedSizes: function() {
				var values = [];
				var inputs = document.querySelectorAll( '.ime-regen-size:checked' );

				Array.prototype.forEach.call( inputs, function( input ) {
					values.push( input.value );
				} );

				return values.join( '|' );
			},

			selectAllSizes: function() { this.setSizes( 'all' ); },
			selectNoSizes: function() { this.setSizes( 'none' ); },
			selectDefaultSizes: function() { this.setSizes( 'default' ); },

			setSizes: function( which ) {
				var inputs = document.querySelectorAll( '.ime-regen-size' );

				Array.prototype.forEach.call( inputs, function( input ) {
					if ( which === 'all' ) {
						input.checked = true;
					} else if ( which === 'none' ) {
						input.checked = false;
					} else {
						input.checked = input.dataset.default === '1';
					}
				} );
			},

			start: function() {
				var self = this;
				var sizes = this.selectedSizes();

				this.runToken++;

				self.errorMessage = '';
				self.failed = [];
				self.failedCount = 0;
				self.batchTimes = [];
				self.cancelRequested = false;

				imeRequest( 'ime_regen_start', {
					sizes: sizes,
					force: self.force ? 1 : 0
				} ).then( function( data ) {
					self.state = 'running';
					self.paused = false;
					self.done = 0;
					self.total = data.total;
					self.runBatch( self.runToken );
				} ).catch( function( error ) {
					self.errorMessage = error.message;
				} );
			},

			resume: function() {
				this.runToken++;

				this.errorMessage = '';
				this.paused = false;
				this.cancelRequested = false;
				this.runBatch( this.runToken );
			},

			cancel: function() {
				var self = this;
				var token = ++self.runToken;

				self.cancelRequested = true;

				imeRequest( 'ime_regen_cancel', {} ).then( function() {
					if ( token !== self.runToken ) {
						return;
					}
					self.state = 'idle';
					self.paused = false;
					self.done = 0;
					self.total = 0;
					self.failed = [];
					self.failedCount = 0;
				} ).catch( function( error ) {
					if ( token !== self.runToken ) {
						return;
					}
					self.errorMessage = error.message;
				} );
			},

			runBatch: function( token ) {
				var self = this;
				var startedAt = Date.now();
				var before = self.done;

				if ( self.cancelRequested || token !== self.runToken ) {
					return;
				}

				imeRequest( 'ime_regen_batch', {} ).then( function( data ) {
					if ( self.cancelRequested || token !== self.runToken ) {
						return;
					}

					self.batchTimes.push( {
						seconds: ( Date.now() - startedAt ) / 1000,
						images: Math.max( 1, data.done - before )
					} );

					if ( data.cancelled ) {
						self.state = 'idle';
						self.paused = false;
						self.done = 0;
						self.total = 0;
						self.failed = [];
						self.failedCount = 0;
						return;
					}

					self.done = data.done;
					self.total = data.total;
					self.failed = data.failed || [];
					self.failedCount = data.failed_count || 0;

					if ( data.finished ) {
						self.state = 'done';
						return;
					}

					self.runBatch( token );
				} ).catch( function( error ) {
					if ( token !== self.runToken ) {
						return;
					}

					if ( 'no_queue' === error.code ) {
						// The run ended while this page was stale. Not an error.
						self.state = 'idle';
						self.paused = false;
						self.done = 0;
						self.total = 0;
						return;
					}

					self.errorMessage = error.message;
					self.state = 'idle';
				} );
			}
		};
	} );

	Alpine.data( 'imeMediaRegen', function() {
		return {
			busy: false,
			message: '',

			init: function() {
				this.message = this.$el.dataset.message || '';
			},

			get spinnerClass() { return this.busy ? 'is-active' : ''; },

			regenerate: function() {
				var self = this;
				var el = this.$root;

				if ( self.busy ) {
					return;
				}

				self.busy = true;

				imeRequest( 'ime_process_image', {
					id: el.dataset.postId,
					sizes: el.dataset.sizes,
					force: el.dataset.force
				} ).then( function( data ) {
					self.busy = false;
					self.message = data.message;
				} ).catch( function( error ) {
					self.busy = false;
					self.message = error.message;
				} );
			}
		};
	} );
} );
