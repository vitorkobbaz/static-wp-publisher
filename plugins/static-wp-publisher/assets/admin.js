/**
 * Static WP Publisher dashboard enhancements.
 *
 * Every action also works without JavaScript through admin-post/list-table requests;
 * this script runs them in place through the REST API so the page does not reload.
 */
( function () {
	const config = window.swppAdmin;
	if ( ! config || ! window.fetch ) {
		return;
	}
	const i18n = config.i18n;
	const box = document.querySelector( '[data-swpp-progress]' );
	const bar = box ? box.querySelector( '[data-swpp-progress-bar]' ) : null;
	const text = box ? box.querySelector( '[data-swpp-progress-text]' ) : null;
	let busy = false;

	const format = ( template, values ) =>
		template.replace( /%(\d+)\$d/g, ( match, index ) =>
			String( values[ Number( index ) - 1 ] ?? 0 )
		);

	const request = async ( route, method = 'POST' ) => {
		const response = await window.fetch( config.root + route, {
			method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
		} );
		const body = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			throw new Error( body.message || String( response.status ) );
		}
		return body;
	};

	const showProgress = ( message, done, total ) => {
		if ( ! box ) {
			return;
		}
		box.hidden = false;
		text.textContent = message;
		if ( typeof total === 'number' ) {
			bar.max = Math.max( 1, total );
			bar.value = done;
		} else {
			bar.removeAttribute( 'value' );
		}
	};

	const reloadWith = ( message, level ) => {
		const url = new URL( config.pageUrl, window.location.href );
		const current = new URL( window.location.href );
		[ 'swpp_view', 's', 'paged' ].forEach( ( key ) => {
			if ( current.searchParams.has( key ) ) {
				url.searchParams.set( key, current.searchParams.get( key ) );
			}
		} );
		if ( message ) {
			url.searchParams.set( 'swpp_notice', message );
			url.searchParams.set( 'swpp_level', level );
		}
		window.location.assign( url.toString() );
	};

	/* "Generate all pages now" and "Process pending now". */
	const runQueue = async ( form ) => {
		const buttons = document.querySelectorAll(
			'[data-swpp-generate] button'
		);
		buttons.forEach( ( button ) => ( button.disabled = true ) );
		busy = true;
		showProgress( i18n.starting );

		const totals = { published: 0, skipped: 0, failed: 0 };
		try {
			if ( form.dataset.swppGenerate === 'build' ) {
				await request( 'build' );
			}
			for ( let pass = 0; pass < 2000; pass++ ) {
				const report = await request( 'process' );
				totals.published += report.succeeded;
				totals.skipped += report.skipped;
				totals.failed += report.failed;
				const done = totals.published + totals.skipped + totals.failed;
				showProgress(
					format( i18n.progress, [
						totals.published,
						totals.skipped,
						totals.failed,
						report.pending,
					] ),
					done,
					done + report.pending
				);

				if ( report.processed === 0 && ! report.scan_active ) {
					if ( report.pending > 0 ) {
						reloadWith(
							format( i18n.retrying, [
								totals.published,
								report.pending,
							] ),
							'warning'
						);
					} else {
						reloadWith(
							format( i18n.done, [
								totals.published,
								totals.skipped,
								totals.failed,
							] ),
							totals.failed > 0 ? 'warning' : 'success'
						);
					}
					return;
				}
			}
		} catch ( error ) {
			showProgress( i18n.failed );
			buttons.forEach( ( button ) => ( button.disabled = false ) );
			busy = false;
		}
	};

	document.querySelectorAll( '[data-swpp-generate]' ).forEach( ( form ) => {
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			if ( ! busy ) {
				runQueue( form );
			}
		} );
	} );

	/* Row actions and bulk actions, updated in place. */
	const setResult = ( row, message, level ) => {
		const result = row.querySelector( '[data-swpp-result]' );
		if ( result ) {
			result.textContent = message;
			result.className = 'swpp-result swpp-result--' + level;
		}
	};

	const runRowAction = async ( row, action ) => {
		row.classList.add( 'swpp-row--busy' );
		setResult( row, i18n.working, 'info' );
		try {
			const data = await request(
				'pages/' + row.dataset.swppPost + '/' + action
			);
			if ( data.row ) {
				row.querySelectorAll( '[data-swpp-cell="status"]' ).forEach(
					( cell ) => ( cell.innerHTML = data.row.status )
				);
				row.querySelector( '[data-swpp-cell="updated"]' ).innerHTML =
					data.row.updated;
				row.querySelector( '[data-swpp-cell="size"]' ).innerHTML =
					data.row.size;
			}
			setResult( row, data.message, data.level );
			return data.ok ? data.level : 'error';
		} catch ( error ) {
			setResult( row, error.message || i18n.requestError, 'error' );
			return 'error';
		} finally {
			row.classList.remove( 'swpp-row--busy' );
		}
	};

	document.addEventListener( 'click', async ( event ) => {
		const link = event.target.closest( '[data-swpp-row-action]' );
		if ( ! link ) {
			return;
		}
		const row = link.closest( 'tr[data-swpp-post]' );
		if ( ! row || busy ) {
			return;
		}
		event.preventDefault();
		busy = true;
		await runRowAction( row, link.dataset.swppRowAction );
		busy = false;
	} );

	const table = document.querySelector( '[data-swpp-table]' );
	if ( table ) {
		table.addEventListener( 'submit', async ( event ) => {
			const submitter = event.submitter;
			const selectName =
				submitter && submitter.id === 'doaction2'
					? 'action2'
					: 'action';
			const select = table.querySelector(
				'select[name="' + selectName + '"]'
			);
			const choice = select ? select.value : '';
			if ( ! submitter || ! choice.startsWith( 'swpp-' ) ) {
				return;
			}
			event.preventDefault();
			const rows = Array.from(
				table.querySelectorAll( 'input[name="post_ids[]"]:checked' )
			)
				.map( ( input ) => input.closest( 'tr[data-swpp-post]' ) )
				.filter( Boolean );
			if ( rows.length === 0 ) {
				showProgress( i18n.noSelection );
				return;
			}
			if ( busy ) {
				return;
			}
			busy = true;
			const action = choice.slice( 'swpp-'.length );
			const tally = { success: 0, warning: 0, error: 0 };
			for ( let index = 0; index < rows.length; index++ ) {
				showProgress(
					format( i18n.bulkProgress, [ index + 1, rows.length ] ),
					index,
					rows.length
				);
				const level = await runRowAction( rows[ index ], action );
				if ( level === 'success' ) {
					tally.success++;
				} else if ( level === 'error' ) {
					tally.error++;
				} else {
					tally.warning++;
				}
			}
			showProgress(
				format( i18n.bulkDone, [
					tally.success,
					tally.warning,
					tally.error,
				] ),
				rows.length,
				rows.length
			);
			busy = false;
		} );
	}

	/* Home page speed check, measured on the server and rendered in place. */
	const speedForm = document.querySelector( '[data-swpp-speed-form]' );
	const speedBody = document.querySelector( '[data-swpp-speed]' );
	if ( speedForm && speedBody ) {
		speedForm.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			if ( busy ) {
				return;
			}
			const button = speedForm.querySelector( 'button' );
			busy = true;
			button.disabled = true;
			button.textContent = i18n.measuring;
			try {
				const data = await request( 'speed-check' );
				speedBody.innerHTML = data.html;
			} catch ( error ) {
				speedBody.textContent = error.message || i18n.requestError;
			} finally {
				button.disabled = false;
				button.textContent = i18n.measureAgain;
				busy = false;
			}
		} );
	}

	/* Refresh automatically once background processing finishes. */
	const live = document.querySelector( '[data-swpp-activity]' );
	if ( live && Number( config.inProgress ) > 0 ) {
		const poll = async () => {
			try {
				const status = await request( 'status', 'GET' );
				const active = status.latest.queued + status.latest.running;
				const selected = document.querySelector(
					'input[name="post_ids[]"]:checked'
				);
				if ( active === 0 && ! busy && ! selected ) {
					reloadWith( '', '' );
					return;
				}
			} catch ( error ) {
				return;
			}
			window.setTimeout( poll, 10000 );
		};
		window.setTimeout( poll, 10000 );
	}
} )();
