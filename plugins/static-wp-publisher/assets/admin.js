/**
 * Static WP Publisher dashboard: runs "Generate all pages now" as a sequence of
 * REST worker passes with visible progress. Without JavaScript the form falls back
 * to a single server-side pass.
 */
( function () {
	const config = window.swppAdmin;
	const form = document.querySelector( '[data-swpp-generate]' );
	const box = document.querySelector( '[data-swpp-progress]' );
	if ( ! config || ! form || ! box || ! window.fetch ) {
		return;
	}
	const bar = box.querySelector( '[data-swpp-progress-bar]' );
	const text = box.querySelector( '[data-swpp-progress-text]' );

	const format = ( template, values ) =>
		template.replace( /%(\d+)\$d/g, ( match, index ) =>
			String( values[ Number( index ) - 1 ] ?? 0 )
		);

	const call = async ( route ) => {
		const response = await window.fetch( config.root + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
		} );
		if ( ! response.ok ) {
			throw new Error( String( response.status ) );
		}
		return response.json();
	};

	const finish = ( message, level ) => {
		const url = new URL( config.pageUrl, window.location.href );
		url.searchParams.set( 'swpp_notice', message );
		url.searchParams.set( 'swpp_level', level );
		window.location.assign( url.toString() );
	};

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const button = form.querySelector( 'button' );
		button.disabled = true;
		box.hidden = false;
		text.textContent = config.i18n.starting;

		const totals = { published: 0, skipped: 0, failed: 0 };
		try {
			await call( 'build' );
			for ( let pass = 0; pass < 2000; pass++ ) {
				const report = await call( 'process' );
				totals.published += report.succeeded;
				totals.skipped += report.skipped;
				totals.failed += report.failed;

				const done = totals.published + totals.skipped + totals.failed;
				bar.max = Math.max( 1, done + report.pending );
				bar.value = done;
				text.textContent = format( config.i18n.progress, [
					totals.published,
					totals.skipped,
					totals.failed,
					report.pending,
				] );

				if ( report.processed === 0 && ! report.scan_active ) {
					if ( report.pending > 0 ) {
						finish(
							format( config.i18n.retrying, [
								totals.published,
								report.pending,
							] ),
							'warning'
						);
					} else {
						finish(
							format( config.i18n.done, [
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
			text.textContent = config.i18n.failed;
			button.disabled = false;
		}
	} );
} )();
