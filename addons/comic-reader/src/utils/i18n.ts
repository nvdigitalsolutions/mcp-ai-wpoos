/**
 * NV oOS Comic Reader — i18n Helper
 *
 * Resolves translatable strings from the localized `NVOOS_COMIC_READER`
 * global injected by the shortcode. Falls back to the raw key so the UI
 * stays functional when the bundle is used outside WordPress.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.3.0
 */

export function t(key: string, ...args: (string | number)[]): string {
	const i18n = window.NVOOS_COMIC_READER?.i18n || {};
	let msg = i18n[key] || key;
	args.forEach((arg, i) => {
		msg = msg.replace(`%${i + 1}$d`, String(arg));
	});
	return msg;
}
