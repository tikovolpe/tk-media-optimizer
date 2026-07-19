( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var startButton    = document.getElementById( 'tkmo-start-batch' );
		var progressWrap   = document.getElementById( 'tkmo-progress-wrap' );
		var progressBar    = document.getElementById( 'tkmo-progress-bar' );
		var progressStatus = document.getElementById( 'tkmo-progress-status' );

		var ringProgress = document.getElementById( 'tkmo-ring-progress' );
		var ringPercent  = document.getElementById( 'tkmo-ring-percent' );
		var statTotal    = document.getElementById( 'tkmo-stat-total' );
		var statConverted = document.getElementById( 'tkmo-stat-converted' );
		var statPending   = document.getElementById( 'tkmo-stat-pending' );
		var statErrors    = document.getElementById( 'tkmo-stat-errors' );

		if ( ! startButton || typeof tkmoAdmin === 'undefined' ) {
			return;
		}

		function applyStats( stats ) {
			if ( ! stats ) {
				return;
			}

			if ( ringProgress ) {
				ringProgress.style.setProperty( '--tkmo-percent', stats.percent );
			}

			if ( ringPercent ) {
				ringPercent.textContent = stats.percent;
			}

			if ( statTotal ) {
				statTotal.textContent = stats.total;
			}

			if ( statConverted ) {
				statConverted.textContent = stats.converted;
			}

			if ( statPending ) {
				statPending.textContent = stats.pending;
			}

			if ( statErrors ) {
				statErrors.textContent = stats.errors;
			}
		}

		function refreshStats() {
			postAjax( 'tkmo_get_stats' ).then( function ( response ) {
				if ( response.success ) {
					applyStats( response.data );
				}
			} );
		}

		var total     = 0;
		var processed = 0;
		var converted = 0;
		var errors    = 0;

		function postAjax( action ) {
			var body = new URLSearchParams();
			body.append( 'action', action );
			body.append( 'nonce', tkmoAdmin.nonce );

			return fetch( tkmoAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( response ) {
				return response.json();
			} );
		}

		function updateProgress() {
			var percent = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 100;
			progressBar.style.width = percent + '%';
			progressStatus.textContent =
				'Total: ' + total +
				' | Convertidos: ' + converted +
				' | Erros: ' + errors +
				' | Processados: ' + processed + '/' + total;
		}

		function runBatch() {
			postAjax( 'tkmo_convert_batch' ).then( function ( response ) {
				if ( ! response.success ) {
					progressStatus.textContent = response.data && response.data.message ? response.data.message : 'Erro.';
					startButton.disabled = false;
					return;
				}

				var data = response.data;

				converted += data.converted;
				errors    += data.errors;
				processed  = total - data.remaining;

				updateProgress();
				applyStats( data.stats );

				if ( data.done ) {
					progressStatus.textContent = tkmoAdmin.i18n.done + ' ' + progressStatus.textContent;
					startButton.disabled = false;
					return;
				}

				runBatch();
			} ).catch( function () {
				progressStatus.textContent = 'Erro de conexão.';
				startButton.disabled = false;
			} );
		}

		startButton.addEventListener( 'click', function () {
			startButton.disabled = true;
			converted = 0;
			errors    = 0;
			processed = 0;
			progressWrap.style.display = 'block';
			progressStatus.textContent = tkmoAdmin.i18n.running;

			postAjax( 'tkmo_scan_pending' ).then( function ( response ) {
				if ( ! response.success ) {
					progressStatus.textContent = 'Erro.';
					startButton.disabled = false;
					return;
				}

				total = response.data.total;

				if ( 0 === total ) {
					progressStatus.textContent = tkmoAdmin.i18n.none_found;
					startButton.disabled = false;
					return;
				}

				updateProgress();
				runBatch();
			} ).catch( function () {
				progressStatus.textContent = 'Erro de conexão.';
				startButton.disabled = false;
			} );
		} );
	} );
}() );
