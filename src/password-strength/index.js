/**
 * Password strength meter and show/hide toggle for the set-password forms.
 *
 * Uses the meter WordPress core loads for wp-login.php (zxcvbn through
 * wp.passwordStrength) and shows its result the way core's user-profile.js does.
 */

const CLASSES = [ 'short', 'bad', 'good', 'strong', 'pw-weak' ];

/**
 * Set up the meter and toggle on one form.
 *
 * @param {HTMLFormElement} form Form with a data-hm-password-strength attribute.
 */
function init( form ) {
	const pass1 = form.querySelector( 'input[name=pass1]' );
	const pass2 = form.querySelector( 'input[name=pass2]' );
	const result = form.querySelector( '.hm-password-strength' );
	const toggle = form.querySelector( '.hm-password-toggle' );

	if ( ! pass1 || ! pass2 || ! result || ! toggle ) {
		return;
	}

	let extra = [];
	try {
		extra = JSON.parse( form.dataset.hmPasswordStrength ) || [];
	} catch ( e ) {
		extra = [];
	}

	result.hidden = false;
	toggle.hidden = false;

	let retry;
	let retries = 0;

	/**
	 * Show the strength of the current password.
	 */
	function update() {
		window.clearTimeout( retry );

		const { passwordStrength } = window.wp;
		const l10n = window.pwsL10n;

		result.classList.remove( ...CLASSES );

		if ( ! pass1.value ) {
			result.textContent = '';
			return;
		}

		const strength = passwordStrength.meter(
			pass1.value,
			passwordStrength.userInputDisallowedList().concat( extra ),
			pass2.value
		);

		// zxcvbn may still be loading, so look again shortly.
		if ( strength === -1 && retries++ < 40 ) {
			retry = window.setTimeout( update, 250 );
		}

		let className;
		let text;

		switch ( strength ) {
			case -1:
				className = 'bad';
				text = l10n.unknown;
				break;
			case 2:
				className = 'bad';
				text = l10n.bad;
				break;
			case 3:
				className = 'good';
				text = l10n.good;
				break;
			case 4:
				className = 'strong';
				text = l10n.strong;
				break;
			case 5:
				className = 'short';
				text = l10n.mismatch;
				break;
			default:
				className = 'short';
				text = l10n.short;
		}

		result.classList.add( className );
		if (
			strength !== 5 &&
			( className === 'short' || className === 'bad' )
		) {
			result.classList.add( 'pw-weak' );
		}
		result.textContent = text;
	}

	const onInput = () => {
		retries = 0;
		update();
	};

	pass1.addEventListener( 'input', onInput );
	pass2.addEventListener( 'input', onInput );

	// zxcvbn loads lazily after the page and meter() returns -1 until it is ready.
	window.addEventListener( 'load', update );
	update();

	toggle.addEventListener( 'click', () => {
		const show = pass1.type === 'password';

		pass1.type = show ? 'text' : 'password';
		pass2.type = show ? 'text' : 'password';
		toggle.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
		toggle.textContent = show ? toggle.dataset.hide : toggle.dataset.show;
	} );
}

document.addEventListener( 'DOMContentLoaded', () => {
	document
		.querySelectorAll( 'form[data-hm-password-strength]' )
		.forEach( init );
} );
