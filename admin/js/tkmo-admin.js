( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var startButton    = document.getElementById( 'tkmo-start-batch' );
		var progressWrap   = document.getElementById( 'tkmo-progress-wrap' );
		var progressBar    = document.getElementById( 'tkmo-progress-bar' );
		var progressStatus = document.getElementById( 'tkmo-progress-status' );

		var ringProgress  = document.getElementById( 'tkmo-ring-progress' );
		var ringPercent   = document.getElementById( 'tkmo-ring-percent' );
		var statTotal     = document.getElementById( 'tkmo-stat-total' );
		var statConverted = document.getElementById( 'tkmo-stat-converted' );
		var statPending   = document.getElementById( 'tkmo-stat-pending' );
		var statErrors    = document.getElementById( 'tkmo-stat-errors' );
		var savingsValue  = document.getElementById( 'tkmo-savings-value' );
		var tableBody     = document.getElementById( 'tkmo-table-body' );

		if ( ! startButton || typeof tkmoAdmin === 'undefined' ) {
			return;
		}

		var i18n = tkmoAdmin.i18n || {};

		var total     = 0;
		var processed = 0;
		var converted = 0;
		var failed    = 0;

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

		function formatBytes( bytes ) {
			bytes = parseInt( bytes, 10 ) || 0;

			if ( bytes < 1024 ) {
				return bytes + ' B';
			}

			var units = [ 'KB', 'MB', 'GB', 'TB' ];
			var value = bytes / 1024;
			var i     = 0;

			while ( value >= 1024 && i < units.length - 1 ) {
				value /= 1024;
				i++;
			}

			return value.toFixed( 1 ) + ' ' + units[ i ];
		}

		function escapeHtml( text ) {
			var div = document.createElement( 'div' );
			div.textContent = text;
			return div.innerHTML;
		}

		function rebuildTable( groups ) {
			if ( ! tableBody ) {
				return;
			}

			var folders = groups ? Object.keys( groups ) : [];

			if ( 0 === folders.length ) {
				return;
			}

			folders.sort();

			var rows = '';

			folders.forEach( function ( folder ) {
				var counts = groups[ folder ];
				var label  = '/' === folder ? ( i18n.root || '(raiz)' ) : folder;

				rows +=
					'<tr>' +
					'<td class="tkmo-col-folder">' + escapeHtml( label ) + '</td>' +
					'<td class="tkmo-col-converted"><span class="tkmo-badge tkmo-badge-converted">✓ ' + ( counts.converted || 0 ) + '</span></td>' +
					'<td class="tkmo-col-pending"><span class="tkmo-badge tkmo-badge-pending">⚠ ' + ( counts.pending || 0 ) + '</span></td>' +
					'<td class="tkmo-col-errors"><span class="tkmo-badge tkmo-badge-errors">✗ ' + ( counts.errors || 0 ) + '</span></td>' +
					'</tr>';
			} );

			tableBody.innerHTML = rows;
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
			if ( savingsValue ) {
				savingsValue.textContent = formatBytes( stats.saved_bytes );
			}

			rebuildTable( stats.groups );
		}

		function updateProgress() {
			var percent = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 100;
			progressBar.style.width = percent + '%';
			progressStatus.textContent =
				'Processados: ' + processed + '/' + total +
				' | Convertidos: ' + converted +
				' | Erros: ' + failed;
		}

		function runBatch() {
			postAjax( 'tkmo_convert_batch' ).then( function ( response ) {
				if ( ! response.success ) {
					progressStatus.textContent = response.data && response.data.message ? response.data.message : i18n.error;
					startButton.disabled = false;
					return;
				}

				var data = response.data;

				converted += data.converted;
				failed    += data.failed;
				processed  = total - data.remaining;

				updateProgress();
				applyStats( data.stats );

				if ( data.message ) {
					progressStatus.textContent = data.message;
					startButton.disabled = false;
					return;
				}

				if ( data.done ) {
					progressStatus.textContent = i18n.done + ' ' + progressStatus.textContent;
					startButton.disabled = false;
					return;
				}

				runBatch();
			} ).catch( function () {
				progressStatus.textContent = i18n.conn_error;
				startButton.disabled = false;
			} );
		}

		startButton.addEventListener( 'click', function () {
			startButton.disabled = true;
			converted = 0;
			failed    = 0;
			processed = 0;
			progressWrap.style.display = 'block';
			progressStatus.textContent = i18n.running;

			postAjax( 'tkmo_scan_pending' ).then( function ( response ) {
				if ( ! response.success ) {
					progressStatus.textContent = i18n.error;
					startButton.disabled = false;
					return;
				}

				total = response.data.total;

				if ( 0 === total ) {
					progressStatus.textContent = i18n.none_found;
					startButton.disabled = false;
					return;
				}

				updateProgress();
				runBatch();
			} ).catch( function () {
				progressStatus.textContent = i18n.conn_error;
				startButton.disabled = false;
			} );
		} );
	} );
}() );
