( function () {
	function updateResponsePreview() {
		var select = document.getElementById( 'atsdn-variant' );
		var preview = document.querySelector( '[data-atsdn-response-preview]' );

		if ( ! select || ! preview ) {
			return;
		}

		var selected = select.value;
		var cards = preview.querySelectorAll( '[data-atsdn-response-card]' );

		cards.forEach( function ( card ) {
			card.classList.toggle( 'is-hidden', card.getAttribute( 'data-atsdn-response-card' ) !== selected );
		} );
	}

	function updateModePanels() {
		var mode = document.getElementById( 'atsdn-mode' );
		var current = mode ? mode.value : 'observe';

		document.querySelectorAll( '[data-atsdn-mode-panel]' ).forEach( function ( panel ) {
			var target = panel.getAttribute( 'data-atsdn-mode-panel' );
			var visible = ( 'experiment' === target && 'experiment' === current ) || ( 'limit' === target && 'deter_limit' === current );
			panel.classList.toggle( 'is-hidden', ! visible );
		} );

		document.querySelectorAll( '[data-atsdn-mode-help-item]' ).forEach( function ( item ) {
			item.classList.toggle( 'is-hidden', item.getAttribute( 'data-atsdn-mode-help-item' ) !== current );
		} );
	}

	function setupOnboarding() {
		var modal = document.querySelector( '[data-atsdn-onboarding]' );

		if ( ! modal ) {
			return;
		}

		var steps = Array.prototype.slice.call( modal.querySelectorAll( '[data-atsdn-onboarding-step]' ) );
		var dots = Array.prototype.slice.call( modal.querySelectorAll( '.atsdn-onboarding-progress span' ) );
		var back = modal.querySelector( '[data-atsdn-onboarding-back]' );
		var next = modal.querySelector( '[data-atsdn-onboarding-next]' );
		var submit = modal.querySelector( '[data-atsdn-onboarding-submit]' );
		var index = 0;

		function render() {
			steps.forEach( function ( step, stepIndex ) {
				step.classList.toggle( 'is-active', stepIndex === index );
			} );
			dots.forEach( function ( dot, dotIndex ) {
				dot.classList.toggle( 'is-active', dotIndex <= index );
			} );

			if ( back ) {
				back.disabled = 0 === index;
			}
			if ( next ) {
				next.classList.toggle( 'is-hidden', index === steps.length - 1 );
			}
			if ( submit ) {
				submit.classList.toggle( 'is-hidden', index !== steps.length - 1 );
			}
		}

		if ( back ) {
			back.addEventListener( 'click', function () {
				index = Math.max( 0, index - 1 );
				render();
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				index = Math.min( steps.length - 1, index + 1 );
				render();
			} );
		}

		render();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var select = document.getElementById( 'atsdn-variant' );
		var mode = document.getElementById( 'atsdn-mode' );

		updateResponsePreview();
		updateModePanels();
		setupOnboarding();

		if ( select ) {
			select.addEventListener( 'change', updateResponsePreview );
		}
		if ( mode ) {
			mode.addEventListener( 'change', updateModePanels );
		}
	} );
}() );
