// ---------------------------------------------------------------------------
// mail.ts — transactional email delivery for the DIRECT form mode.
//
// Why an ESP HTTP API and not SMTP: WordPress already has an SMTP setting, so
// the bridge mode speaks SMTP there (WP Mail SMTP / the provider's plugin) and
// keeps credentials off the static site. When a site has NO bridge, the managed
// runtime sends directly — and for a custom app the documented choice is the
// provider's API, not raw SMTP: JSON over TLS, scoped keys, per-request
// idempotency, and no socket lifecycle to babysit in a serverless/edge runtime.
//
// Secrets NEVER live in the profile or the manifest. The generated endpoint
// reads them from the environment at request time:
//   BREVO_API_KEY                (provider: brevo)
//   MAILJET_API_KEY              (provider: mailjet)
//   MAILJET_SECRET_KEY           (provider: mailjet)
//
// Deliverability is a DOMAIN concern, not a code concern (2026 sender rules):
// SPF, DKIM and DMARC must be published for the `from` domain, and DMARC must
// pass on alignment — a perfect API call from an unauthenticated domain still
// lands in spam. `from` must therefore be an address on the authenticated
// provider domain, never a mailbox that only exists on the site's own MX.
// ---------------------------------------------------------------------------

/** The provider payloads this module can build. */
export type MailProvider = 'brevo' | 'mailjet';

export interface MailConfig {
	provider: MailProvider;
	/** Authenticated sender (must align with the domain's SPF/DKIM). */
	from: string;
	fromName?: string;
	/** Where the submission is delivered. */
	to: string;
	subject: string;
	/** Secrets, read from the environment by the caller. */
	brevoKey?: string;
	mailjetKey?: string;
	mailjetSecret?: string;
}

export interface MailMessage {
	/** Plain-text body (always sent — a text part improves deliverability). */
	text: string;
	/** Optional reply target (the visitor). */
	replyTo?: string;
	/** Idempotency key, forwarded where the provider supports it. */
	idempotencyKey?: string;
}

/** What a provider call needs, independent of the transport. */
export interface MailRequest {
	url: string;
	headers: Record< string, string >;
	body: string;
}

/** Trim and collapse a header-ish value (no CR/LF injection). */
function clean( value: string | undefined ): string {
	return ( value ?? '' ).replace( /[\r\n]+/g, ' ' ).trim();
}

/** `Name <email@example.com>` when a display name is set. */
export function formatFrom( config: Pick< MailConfig, 'from' | 'fromName' > ): string {
	const name = clean( config.fromName );
	return name ? `${ name } <${ clean( config.from ) }>` : clean( config.from );
}

/** Brevo transactional endpoint + JSON body. */
export function brevoRequest( config: MailConfig, message: MailMessage ): MailRequest {
	const body = {
		sender: { email: clean( config.from ), ...( config.fromName ? { name: clean( config.fromName ) } : {} ) },
		to: [ { email: clean( config.to ) } ],
		subject: clean( config.subject ),
		textContent: message.text,
		...( message.replyTo ? { replyTo: { email: clean( message.replyTo ) } } : {} ),
		...( message.idempotencyKey ? { headers: { 'idempotencyKey': message.idempotencyKey } } : {} ),
	};
	return {
		url: 'https://api.brevo.com/v3/smtp/email',
		headers: {
			'api-key': config.brevoKey ?? '',
			'content-type': 'application/json',
			accept: 'application/json',
		},
		body: JSON.stringify( body ),
	};
}

/** Mailjet v3.1 send endpoint + JSON body. */
export function mailjetRequest( config: MailConfig, message: MailMessage ): MailRequest {
	const body = {
		Messages: [
			{
				From: { Email: clean( config.from ), ...( config.fromName ? { Name: clean( config.fromName ) } : {} ) },
				To: [ { Email: clean( config.to ) } ],
				Subject: clean( config.subject ),
				TextPart: message.text,
				...( message.replyTo ? { ReplyTo: { Email: clean( message.replyTo ) } } : {} ),
			},
		],
	};
	return {
		url: 'https://api.mailjet.com/v3.1/send',
		headers: {
			// Mailjet uses HTTP Basic: key:secret.
			authorization: `Basic ${ Buffer.from( `${ config.mailjetKey ?? '' }:${ config.mailjetSecret ?? '' }` ).toString( 'base64' ) }`,
			'content-type': 'application/json',
		},
		body: JSON.stringify( body ),
	};
}

/** Build the provider request for the configured provider. */
export function buildMailRequest( config: MailConfig, message: MailMessage ): MailRequest {
	return config.provider === 'mailjet' ? mailjetRequest( config, message ) : brevoRequest( config, message );
}

/**
 * The missing-secret message for a provider — surfaced at request time (never
 * at build time, so a scaffold never needs the key to succeed).
 */
export function missingSecret( config: MailConfig ): string | undefined {
	if ( config.provider === 'mailjet' ) {
		return config.mailjetKey && config.mailjetSecret
			? undefined
			: 'MAILJET_API_KEY and MAILJET_SECRET_KEY must be set for provider "mailjet".';
	}
	return config.brevoKey ? undefined : 'BREVO_API_KEY must be set for provider "brevo".';
}

/** Read the provider config from the environment (secrets never from disk). */
export function readMailConfig( env: Record< string, string | undefined > ): MailConfig {
	const provider: MailProvider = env.MAIL_PROVIDER === 'mailjet' ? 'mailjet' : 'brevo';
	return {
		provider,
		from: clean( env.MAIL_FROM ),
		fromName: env.MAIL_FROM_NAME,
		to: clean( env.MAIL_TO ),
		subject: clean( env.MAIL_SUBJECT ) || 'New website enquiry',
		brevoKey: env.BREVO_API_KEY,
		mailjetKey: env.MAILJET_API_KEY,
		mailjetSecret: env.MAILJET_SECRET_KEY,
	};
}

/**
 * The environment at REQUEST time.
 *
 * `import.meta.env` is a BUILD-time snapshot: Vite inlines what it can see, so
 * a secret injected into the running process after the build — the managed
 * tier's normal delivery mechanism — is invisible to it. Read `process.env`
 * first and fall back to the build-time object so `astro dev` with a `.env`
 * file still works. Runtime wins on conflict.
 */
export function runtimeEnv(): Record< string, string | undefined > {
	// `import.meta.env` is typed by Vite/Astro, not by this library's own
	// tsconfig — read it structurally so both trees compile.
	const build =
		( import.meta as unknown as { env?: Record< string, string | undefined > } ).env ?? {};
	const runtime =
		typeof process !== 'undefined' && process.env
			? ( process.env as Record< string, string | undefined > )
			: {};
	return { ...build, ...runtime };
}

/** Send through the provider. Returns the HTTP status (never throws). */
export async function sendMail(
	config: MailConfig,
	message: MailMessage,
	fetchImpl: typeof fetch = fetch
): Promise< { ok: boolean; status: number; detail?: string } > {
	const secret = missingSecret( config );
	if ( secret ) {
		return { ok: false, status: 500, detail: secret };
	}
	if ( ! config.from || ! config.to ) {
		return { ok: false, status: 500, detail: 'MAIL_FROM and MAIL_TO must be set.' };
	}

	const request = buildMailRequest( config, message );
	try {
		const response = await fetchImpl( request.url, {
			method: 'POST',
			headers: request.headers,
			body: request.body,
		} );
		if ( response.ok ) {
			return { ok: true, status: response.status };
		}
		const detail = await response.text().catch( () => '' );
		return { ok: false, status: response.status, detail: detail.slice( 0, 300 ) };
	} catch ( error ) {
		return { ok: false, status: 502, detail: error instanceof Error ? error.message : String( error ) };
	}
}
