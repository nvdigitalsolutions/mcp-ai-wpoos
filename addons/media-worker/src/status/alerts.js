/**
 * Alert bus for the status monitor.
 *
 * Transition events (site.down, site.recovered, site.degraded) are
 * dispatched to adapter functions: HMAC-signed webhooks and email via the
 * worker's existing SMTP env plumbing. A per-event cooldown caps
 * notification storms, and event envelopes never carry payload details.
 */

import { createHmac } from 'crypto';
import axios from 'axios';

/**
 * HMAC-SHA256 signature for a webhook body (hex digest).
 *
 * @param {string} body   JSON string body.
 * @param {string} secret Shared secret.
 * @return {string} Signature, or '' when no secret is configured.
 */
export function signWebhook( body, secret ) {
	if ( ! secret ) {
		return '';
	}
	return createHmac( 'sha256', secret ).update( body ).digest( 'hex' );
}

/**
 * POST an event to a webhook URL. Failures are logged, never thrown.
 *
 * @param {string} url    Webhook URL.
 * @param {Object} event  Event envelope.
 * @param {string} secret Shared secret.
 * @param {number} timeoutMs Request timeout.
 * @return {Promise<boolean>} True when delivered.
 */
export async function sendWebhook( url, event, secret, timeoutMs ) {
	const body = JSON.stringify( event );
	try {
		await axios.post( url, body, {
			timeout: timeoutMs,
			headers: {
				'Content-Type': 'application/json',
				...( secret ? { 'X-Nvoos-Signature': `sha256=${ signWebhook( body, secret ) }` } : {} ),
			},
		} );
		return true;
	} catch ( err ) {
		console.warn( '[Status] Webhook delivery failed:', err.message );
		return false;
	}
}

/**
 * Send a plain-text alert email via the worker's SMTP env plumbing.
 * Failures are logged, never thrown.
 *
 * @param {string} to      Recipient.
 * @param {string} subject Subject line.
 * @param {string} text    Body.
 * @return {Promise<boolean>} True when sent.
 */
export async function sendEmail( to, subject, text ) {
	if ( ! to ) {
		return false;
	}
	try {
		const nodemailer = ( await import( 'nodemailer' ) ).default;
		const port = parseInt( process.env.SMTP_PORT || '587', 10 );
		const transporter = nodemailer.createTransport( {
			host: process.env.SMTP_HOST || 'localhost',
			port,
			secure: port === 465,
			auth: process.env.SMTP_USER
				? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS || '' }
				: undefined,
		} );
		await transporter.sendMail( {
			from: process.env.SMTP_FROM || 'noreply@designstudio.local',
			to,
			subject,
			text,
		} );
		return true;
	} catch ( err ) {
		console.warn( '[Status] Email alert failed:', err.message );
		return false;
	}
}

/**
 * Build a minimal event envelope (never carries payload details/secrets).
 *
 * @param {string} event Event name (site.down, site.recovered, site.degraded).
 * @param {string} slug  Site slug.
 * @param {Object} state Computed site state.
 * @return {Object} Envelope.
 */
export function eventEnvelope( event, slug, state ) {
	return {
		event,
		slug,
		status: state.status,
		since: state.since || null,
		last_seen_at: state.lastSeenAt || null,
		sent_at: Date.now(),
	};
}

/**
 * Alert bus with cooldown suppression.
 */
export class AlertBus {
	/**
	 * @param {Object} cfg Site/base config ({ alertWebhooks, alertWebhookSecret, alertEmailTo, alertCooldownMs }).
	 * @param {Object} [adapters] Injectable adapters for tests.
	 */
	constructor( cfg, adapters = {} ) {
		this.cfg = cfg;
		this.webhook = adapters.webhook || sendWebhook;
		this.email = adapters.email || sendEmail;
		this.now = adapters.now || Date.now;
		this.lastFired = new Map();
		this.counters = { webhookOk: 0, webhookFail: 0, emailOk: 0, emailFail: 0 };
	}

	/**
	 * Whether an event is within the cooldown window for a site.
	 *
	 * @param {string} key Cooldown key (`${event}:${slug}`).
	 * @return {boolean} True when suppressed.
	 */
	withinCooldown( key ) {
		const last = this.lastFired.get( key ) || 0;
		return this.now() - last < this.cfg.alertCooldownMs;
	}

	/**
	 * Dispatch an event to every configured adapter.
	 *
	 * @param {string} event Event name.
	 * @param {string} slug  Site slug.
	 * @param {Object} state Computed site state.
	 * @return {Promise<Object>} Delivery summary.
	 */
	async dispatch( event, slug, state ) {
		const key = `${ event }:${ slug }`;
		const summary = { suppressed: false, webhook: false, email: false };

		if ( this.withinCooldown( key ) ) {
			summary.suppressed = true;
			return summary;
		}
		this.lastFired.set( key, this.now() );

		const envelope = eventEnvelope( event, slug, state );
		const text = `[NV oOS Status] ${ event } — site "${ slug }" is ${ state.status } since ${ new Date( state.since || Date.now() ).toISOString() }.`;

		const webhookResults = await Promise.all(
			this.cfg.alertWebhooks.map( ( url ) => this.webhook( url, envelope, this.cfg.alertWebhookSecret, 5000 ) )
		);
		summary.webhook = webhookResults.some( Boolean );
		if ( webhookResults.some( ( ok ) => ok ) ) {
			this.counters.webhookOk += 1;
		} else if ( webhookResults.length ) {
			this.counters.webhookFail += 1;
		}

		const emailOk = await this.email( this.cfg.alertEmailTo, `[NV oOS] ${ event }: ${ slug }`, text );
		summary.email = emailOk;
		if ( emailOk ) {
			this.counters.emailOk += 1;
		} else if ( this.cfg.alertEmailTo ) {
			this.counters.emailFail += 1;
		}

		return summary;
	}
}
