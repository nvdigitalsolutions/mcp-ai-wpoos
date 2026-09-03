/* NV oOS Fleet — dashboard UI bundle (IIFE, no build step).
 *
 * Registered as tab "nv-oos-fleet" (Sites / Fleet sub-views) and into the
 * "header-right" shell slot (fleet status badge).
 *
 * Everything comes from window.__HERMES_PLUGIN_SDK__; React is never bundled.
 * Component lookups degrade to plain elements so a missing primitive can
 * never crash the dashboard.
 */
(function () {
	"use strict";

	var SDK = window.__HERMES_PLUGIN_SDK__;
	var registry = window.__HERMES_PLUGINS__;

	if (!SDK || !registry) {
		console.warn("[nv-oos-fleet] Hermes plugin SDK not found; skipping registration.");
		return;
	}

	var React = SDK.React;
	var hooks = SDK.hooks || {};
	var useState = hooks.useState;
	var useEffect = hooks.useEffect;
	var h = React.createElement;

	var C = SDK.components || {};
	var Card = C.Card || "div";
	var CardHeader = C.CardHeader || "div";
	var CardTitle = C.CardTitle || "h3";
	var CardContent = C.CardContent || "div";
	var Badge = C.Badge || "span";
	var Button = C.Button || "button";
	var Input = C.Input || "input";
	var Label = C.Label || "label";
	var Separator = C.Separator || "hr";

	var cn =
		(SDK.utils && SDK.utils.cn) ||
		function () {
			return Array.prototype.filter.call(arguments, Boolean).join(" ");
		};
	var fetchJSON = SDK.fetchJSON;

	var BASE = "/api/plugins/nv-oos-fleet";

	// The documented fetchJSON signature covers GETs; mutations use raw
	// same-origin fetch (dashboard auth is session/cookie-based).
	function api(path, opts) {
		if (!opts || (opts.method || "GET") === "GET") {
			if (typeof fetchJSON === "function") {
				return fetchJSON(BASE + path);
			}
			return fetch(BASE + path, { credentials: "same-origin" }).then(function (res) {
				return res.json();
			});
		}
		return fetch(BASE + path, {
			method: opts.method,
			credentials: "same-origin",
			headers: { "Content-Type": "application/json" },
			body: JSON.stringify(opts.body),
		}).then(function (res) {
			return res.json().then(function (body) {
				if (!res.ok) {
					var err = new Error(
						(body && body.detail) || "HTTP " + res.status
					);
					err.status = res.status;
					throw err;
				}
				return body;
			});
		});
	}

	function errText(err) {
		return err && err.message ? String(err.message) : String(err);
	}

	/* ------------------------------------------------------------------ *
	 * Fleet status badge (header-right slot)
	 * ------------------------------------------------------------------ */

	function FleetBadge() {
		var state = useState(null);
		var status = state[0];
		var setStatus = state[1];

		useEffect(function () {
			var alive = true;
			function load() {
				api("/fleet/status")
					.then(function (data) {
						if (alive) setStatus(data);
					})
					.catch(function () {});
			}
			load();
			var timer = setInterval(load, 30000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, []);

		if (!status || !status.total) {
			return null;
		}
		var className = status.degraded
			? "nvoos-badge nvoos-badge-warn"
			: "nvoos-badge nvoos-badge-ok";
		var label =
			"NV oOS: " +
			status.total +
			" site" +
			(status.total === 1 ? "" : "s") +
			(status.degraded ? " \u00b7 " + status.degraded + " degraded" : " \u00b7 ok");
		return h("span", { className: className, title: label }, label);
	}

	/* ------------------------------------------------------------------ *
	 * Sites view
	 * ------------------------------------------------------------------ */

	function AddSiteForm(props) {
		var onAdded = props.onAdded;
		var labelState = useState("");
		var urlState = useState("");
		var tokenState = useState("");
		var writeState = useState(false);
		var submittingState = useState(false);
		var errorState = useState(null);

		var label = labelState[0], setLabel = labelState[1];
		var url = urlState[0], setUrl = urlState[1];
		var token = tokenState[0], setToken = tokenState[1];
		var write = writeState[0], setWrite = writeState[1];
		var submitting = submittingState[0], setSubmitting = submittingState[1];
		var error = errorState[0], setError = errorState[1];

		function submit(ev) {
			ev.preventDefault();
			setSubmitting(true);
			setError(null);
			api("/sites", {
				method: "POST",
				body: { label: label, url: url, token: token, write: write },
			})
				.then(function () {
					setLabel("");
					setUrl("");
					setToken("");
					setWrite(false);
					if (typeof onAdded === "function") onAdded();
				})
				.catch(function (err) {
					setError(errText(err));
				})
				.then(function () {
					setSubmitting(false);
				});
		}

		return h(
			Card,
			null,
			h(
				CardHeader,
				null,
				h(CardTitle, null, "Add site")
			),
			h(
				CardContent,
				null,
				h(
					"form",
					{ className: "nvoos-form", onSubmit: submit },
					h(Label, null, "Label"),
					h(Input, {
						value: label,
						placeholder: "Victory Store",
						onChange: function (ev) { setLabel(ev.target.value); },
					}),
					h(Label, null, "Site URL (https)"),
					h(Input, {
						value: url,
						placeholder: "https://site.example.com",
						onChange: function (ev) { setUrl(ev.target.value); },
					}),
					h(Label, null, "Operator token (op_ / cred_ \u2026.SECRET)"),
					h(Input, {
						value: token,
						type: "password",
						autoComplete: "off",
						onChange: function (ev) { setToken(ev.target.value); },
					}),
					h(
						"label",
						{ className: "nvoos-row" },
						h("input", {
							type: "checkbox",
							checked: write,
							onChange: function (ev) { setWrite(ev.target.checked); },
						}),
						" Allow write operations (control-plane phases)"
					),
					error ? h("p", { className: "nvoos-error" }, error) : null,
					h(
						Button,
						{ type: "submit", disabled: submitting },
						submitting ? "Adding\u2026" : "Add site"
					)
				)
			)
		);
	}

	function SiteCard(props) {
		var site = props.site;
		var onRemoved = props.onRemoved;
		var testState = useState(null); // null | {running:true} | result
		var test = testState[0];
		var setTest = testState[1];

		function runTest() {
			setTest({ running: true });
			api("/sites/" + site.id + "/test", { method: "POST", body: {} })
				.then(function (data) {
					setTest(data);
				})
				.catch(function (err) {
					setTest({ error: errText(err) });
				});
		}

		function remove() {
			if (
				typeof window.confirm === "function" &&
				!window.confirm("Remove site \u201c" + site.label + "\u201d from the fleet registry?")
			) {
				return;
			}
			api("/sites/" + site.id, { method: "DELETE" })
				.then(function () {
					if (typeof onRemoved === "function") onRemoved();
				})
				.catch(function () {});
		}

		var badges = [];
		if (site.write) badges.push(h(Badge, { key: "w" }, "write"));
		if (site.allow_insecure) badges.push(h(Badge, { key: "i" }, "insecure"));

		var testLine = null;
		if (test && test.running) {
			testLine = h("span", { className: "nvoos-muted" }, "Testing\u2026");
		} else if (test && test.ok) {
			testLine = h(
				"span",
				{ className: "nvoos-ok" },
				"Connected \u00b7 " + test.latency_ms + " ms \u00b7 " + test.assistants + " assistant(s)"
			);
		} else if (test) {
			testLine = h(
				"span",
				{ className: "nvoos-error" },
				test.error ? errText(test) : "Failed"
			);
		}

		return h(
			Card,
			{ className: "nvoos-site-card" },
			h(
				CardHeader,
				null,
				h(
					"div",
					{ className: "nvoos-row" },
					h(CardTitle, null, site.label || site.url),
					badges
				)
			),
			h(
				CardContent,
				null,
				h("p", { className: "nvoos-muted" }, site.url),
				h("p", { className: "nvoos-muted" }, "Token: " + site.token_hint),
				testLine,
				h(Separator, null),
				h(
					"div",
					{ className: "nvoos-row" },
					h(Button, { onClick: runTest }, "Test"),
					h(Button, { variant: "destructive", onClick: remove }, "Remove")
				)
			)
		);
	}

	function SitesView() {
		var sitesState = useState([]);
		var loadingState = useState(true);
		var errorState = useState(null);

		var sites = sitesState[0], setSites = sitesState[1];
		var loading = loadingState[0], setLoading = loadingState[1];
		var error = errorState[0], setError = errorState[1];

		function reload() {
			setLoading(true);
			api("/sites")
				.then(function (data) {
					setSites((data && data.sites) || []);
					setError(null);
				})
				.catch(function (err) {
					setError(errText(err));
				})
				.then(function () {
					setLoading(false);
				});
		}

		useEffect(function () {
			reload();
		}, []);

		return h(
			"div",
			{ className: "nvoos-stack" },
			h(AddSiteForm, { onAdded: reload }),
			error ? h("p", { className: "nvoos-error" }, error) : null,
			loading
				? h("p", { className: "nvoos-muted" }, "Loading sites\u2026")
				: null,
			sites.length
				? h(
						"div",
						{ className: "nvoos-grid" },
						sites.map(function (site) {
							return h(SiteCard, { key: site.id, site: site, onRemoved: reload });
						})
				  )
				: null
		);
	}

	/* ------------------------------------------------------------------ *
	 * Fleet view
	 * ------------------------------------------------------------------ */

	function FleetView() {
		var healthState = useState(null);
		var health = healthState[0];
		var setHealth = healthState[1];

		function load() {
			api("/fleet/health")
				.then(function (data) {
					setHealth(data);
				})
				.catch(function (err) {
					setHealth({ error: errText(err) });
				});
		}

		useEffect(function () {
			load();
			var timer = setInterval(load, 30000);
			return function () {
				clearInterval(timer);
			};
		}, []);

		if (!health) {
			return h("p", { className: "nvoos-muted" }, "Checking fleet\u2026");
		}
		if (health.error) {
			return h("p", { className: "nvoos-error" }, health.error);
		}
		if (!health.total) {
			return h(
				"p",
				{ className: "nvoos-muted" },
				"No sites registered yet \u2014 add one under the Sites tab."
			);
		}

		return h(
			"div",
			{ className: "nvoos-stack" },
			h(
				"p",
				{ className: "nvoos-muted" },
				health.ok +
					" of " +
					health.total +
					" site(s) healthy \u00b7 checked " +
					health.checked_at
			),
			h(
				"div",
				{ className: "nvoos-grid" },
				(health.sites || []).map(function (site) {
					var cls = site.ok ? "nvoos-badge nvoos-badge-ok" : "nvoos-badge nvoos-badge-warn";
					return h(
						Card,
						{ key: site.id, className: "nvoos-site-card" },
						h(
							CardHeader,
							null,
							h(
								"div",
								{ className: "nvoos-row" },
								h(CardTitle, null, site.label || site.id),
								h("span", { className: cls }, site.ok ? "ok" : "degraded")
							)
						),
						h(
							CardContent,
							null,
							site.ok
								? h(
										"p",
										{ className: "nvoos-muted" },
										site.latency_ms + " ms \u00b7 " + site.assistants + " assistant(s)"
								  )
								: h("p", { className: "nvoos-error" }, site.error || "Unreachable")
						)
					);
				})
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Main tab component
	 * ------------------------------------------------------------------ */

	function NavButton(props) {
		var active = props.active;
		return h(
			Button,
			{ variant: active ? "default" : "outline", onClick: props.onClick },
			props.label
		);
	}

	function FleetApp() {
		var viewState = useState("sites");
		var view = viewState[0];
		var setView = viewState[1];

		return h(
			"div",
			{ className: "nvoos-page" },
			h(
				"div",
				{ className: "nvoos-nav" },
				h(NavButton, {
					label: "Sites",
					active: view === "sites",
					onClick: function () { setView("sites"); },
				}),
				h(NavButton, {
					label: "Fleet",
					active: view === "fleet",
					onClick: function () { setView("fleet"); },
				})
			),
			view === "sites" ? h(SitesView) : h(FleetView)
		);
	}

	registry.register("nv-oos-fleet", FleetApp);
	registry.registerSlot("nv-oos-fleet", "header-right", FleetBadge);
})();
