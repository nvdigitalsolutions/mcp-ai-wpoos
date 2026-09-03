/* NV oOS Fleet — dashboard UI bundle (IIFE, no build step).
 *
 * Registered as tab "nv-oos-fleet" with sub-views: Sites, Fleet, Overview,
 * Logs, Jobs, Analytics, Security, Tools, Tokens, Paper Store, Workflows,
 * Mesh. Also registers into shell slots: header-right (fleet badge),
 * chat:bottom (ask-the-fleet), sessions:bottom (fleet costs).
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
	var useRef = hooks.useRef;
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
	 * Shared helpers
	 * ------------------------------------------------------------------ */

	function useSites() {
		var state = useState([]);
		var sites = state[0];
		var setSites = state[1];
		useEffect(function () {
			var alive = true;
			api("/sites")
				.then(function (data) {
					if (alive) setSites((data && data.sites) || []);
				})
				.catch(function () {});
			return function () {
				alive = false;
			};
		}, []);
		return sites;
	}

	function SiteSelect(props) {
		var sites = props.sites || [];
		return h(
			"select",
			{
				className: "nvoos-select",
				value: props.value || "",
				onChange: function (ev) {
					props.onChange(ev.target.value);
				},
			},
			h("option", { value: "", disabled: true }, "Select site"),
			sites.map(function (s) {
				return h("option", { key: s.id, value: s.id }, s.label + " - " + s.url);
			})
		);
	}

	function SectionTitle(props) {
		return h("h3", { className: "nvoos-section" }, props.children);
	}

	function Empty(props) {
		return h("p", { className: "nvoos-muted" }, props.text || "Nothing here yet.");
	}

	function Err(props) {
		return props.text ? h("p", { className: "nvoos-error" }, props.text) : null;
	}

	function KV(props) {
		return h(
			"div",
			{ className: "nvoos-kv" },
			h("span", { className: "nvoos-muted" }, props.k),
			h("span", null, String(props.v))
		);
	}

	function JsonView(props) {
		var text;
		try {
			text = JSON.stringify(props.value, null, 2);
		} catch (err) {
			text = String(props.value);
		}
		return h("pre", { className: "nvoos-json" }, text);
	}

	function copyText(text, onDone) {
		function done(ok) {
			if (typeof onDone === "function") onDone(!!ok);
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
			return;
		}
		var ta = document.createElement("textarea");
		ta.value = text;
		document.body.appendChild(ta);
		ta.select();
		var ok = false;
		try {
			ok = document.execCommand("copy");
		} catch (err) {}
		document.body.removeChild(ta);
		done(ok);
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
			(status.degraded ? " - " + status.degraded + " degraded" : " - ok");
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
			h(CardHeader, null, h(CardTitle, null, "Add site")),
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
					h(Label, null, "Operator token (op_ / cred_ ...SECRET)"),
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
						" Allow write operations (control-plane actions)"
					),
					Err({ text: error }),
					h(
						Button,
						{ type: "submit", disabled: submitting },
						submitting ? "Adding..." : "Add site"
					)
				)
			)
		);
	}

	function SiteCard(props) {
		var site = props.site;
		var onRemoved = props.onRemoved;
		var testState = useState(null);
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

		function applyConfig() {
			api("/sites/" + site.id + "/mcp-config/apply", { method: "POST", body: {} })
				.then(function (data) {
					window.alert(
						"MCP config written to " + data.config_file + " and " + data.env_file + ". Restart the Hermes gateway to connect."
					);
				})
				.catch(function (err) {
					window.alert("Could not apply config: " + errText(err));
				});
		}

		var badges = [];
		if (site.write) badges.push(h(Badge, { key: "w" }, "write"));
		if (site.allow_insecure) badges.push(h(Badge, { key: "i" }, "insecure"));

		var testLine = null;
		if (test && test.running) {
			testLine = h("span", { className: "nvoos-muted" }, "Testing...");
		} else if (test && test.ok) {
			testLine = h(
				"span",
				{ className: "nvoos-ok" },
				"Connected - " + test.latency_ms + " ms - " + test.assistants + " assistant(s)"
			);
		} else if (test) {
			testLine = h("span", { className: "nvoos-error" }, test.error ? errText(test) : "Failed");
		}

		return h(
			Card,
			{ className: "nvoos-site-card" },
			h(
				CardHeader,
				null,
				h("div", { className: "nvoos-row" }, h(CardTitle, null, site.label || site.url), badges)
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
					h(Button, { variant: "outline", onClick: applyConfig }, "Apply MCP config"),
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
			Err({ text: error }),
			loading ? h("p", { className: "nvoos-muted" }, "Loading sites...") : null,
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

		useEffect(function () {
			var alive = true;
			function load() {
				api("/fleet/health")
					.then(function (data) {
						if (alive) setHealth(data);
					})
					.catch(function (err) {
						if (alive) setHealth({ error: errText(err) });
					});
			}
			load();
			var timer = setInterval(load, 30000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, []);

		if (!health) {
			return h("p", { className: "nvoos-muted" }, "Checking fleet...");
		}
		if (health.error) {
			return h("p", { className: "nvoos-error" }, health.error);
		}
		if (!health.total) {
			return Empty({ text: "No sites registered yet - add one under the Sites tab." });
		}

		return h(
			"div",
			{ className: "nvoos-stack" },
			h(
				"p",
				{ className: "nvoos-muted" },
				health.ok + " of " + health.total + " site(s) healthy - checked " + health.checked_at
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
										site.latency_ms + " ms - " + site.assistants + " assistant(s)"
								  )
								: h("p", { className: "nvoos-error" }, site.error || "Unreachable")
						)
					);
				})
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Overview view (per-site deep dive)
	 * ------------------------------------------------------------------ */

	function OverviewView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];

		useEffect(function () {
			if (!siteId) {
				setData(null);
				return;
			}
			var alive = true;
			function load() {
				api("/fleet/overview?site=" + encodeURIComponent(siteId))
					.then(function (d) {
						if (alive) setData(d);
					})
					.catch(function (err) {
						if (alive) setData({ error: errText(err) });
					});
			}
			load();
			var timer = setInterval(load, 60000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, [siteId]);

		if (!sites.length) {
			return Empty({ text: "No sites registered yet." });
		}
		var siteEntry = null;
		sites.forEach(function (s) {
			if (s.id === siteId) siteEntry = s;
		});

		return h(
			"div",
			{ className: "nvoos-stack" },
			h("div", { className: "nvoos-row" }, h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId })),
			!data
				? h("p", { className: "nvoos-muted" }, siteId ? "Loading overview..." : "Select a site to inspect.")
				: h("div", { className: "nvoos-stack" },
					Err({ text: data.error }),
					h(Card, null,
						h(CardHeader, null, h(CardTitle, null, "Health & status")),
						h(CardContent, null,
							Err({ text: data.errors && data.errors.health }),
							data.health ? h(JsonView, { value: data.health }) : null,
							Err({ text: data.errors && data.errors.status }),
							data.status ? h(JsonView, { value: data.status }) : null
						)
					),
					h(Card, null,
						h(CardHeader, null, h(CardTitle, null, "Site summary & updates")),
						h(CardContent, null,
							Err({ text: data.errors && data.errors.summary }),
							data.summary ? h(JsonView, { value: data.summary }) : null,
							Err({ text: data.errors && data.errors.updates }),
							data.updates ? h(JsonView, { value: data.updates }) : null
						)
					),
					siteEntry ? h("p", { className: "nvoos-muted" }, "Site: " + siteEntry.url + " - write " + (siteEntry.write ? "enabled" : "disabled")) : null
				)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Logs view
	 * ------------------------------------------------------------------ */

	function LogsView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var kindState = useState("activity");
		var kind = kindState[0];
		var setKind = kindState[1];
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];

		useEffect(function () {
			if (!siteId) {
				setData(null);
				return;
			}
			var alive = true;
			function load() {
				api("/logs?site=" + encodeURIComponent(siteId) + "&limit=30")
					.then(function (d) {
						if (alive) setData(d);
					})
					.catch(function (err) {
						if (alive) setData({ error: errText(err) });
					});
			}
			load();
			var timer = setInterval(load, 15000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, [siteId]);

		if (!sites.length) return Empty({ text: "No sites registered yet." });

		var rows = data
			? kind === "activity"
				? data.activity || []
				: data.errors || []
			: [];

		return h(
			"div",
			{ className: "nvoos-stack" },
			h(
				"div",
				{ className: "nvoos-row" },
				h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId }),
				h(Button, { variant: kind === "activity" ? "default" : "outline", onClick: function () { setKind("activity"); } }, "Activity"),
				h(Button, { variant: kind === "errors" ? "default" : "outline", onClick: function () { setKind("errors"); } }, "Errors")
			),
			Err({ text: data && data.error }),
			!data || !rows.length
				? h("p", { className: "nvoos-muted" }, "No " + kind + " entries.")
				: h(
						"div",
						{ className: "nvoos-log-list" },
						rows.map(function (row, i) {
							var line =
								typeof row === "string"
									? row
									: (row.message || row.type || row.action || "") +
									  (row.timestamp || row.time ? " - " + (row.timestamp || row.time) : "");
							return h("div", { key: i, className: "nvoos-log-line" }, String(line));
						})
				  )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Jobs view (async tool jobs + WP cron + live stream)
	 * ------------------------------------------------------------------ */

	function JobsView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];
		var liveState = useState(false);
		var live = liveState[0];
		var setLive = liveState[1];
		var liveLogState = useState([]);
		var liveLog = liveLogState[0];
		var setLiveLog = liveLogState[1];
		var esRef = useRef(null);

		useEffect(function () {
			if (!siteId) {
				setData(null);
				return;
			}
			var alive = true;
			function load() {
				api("/jobs?site=" + encodeURIComponent(siteId))
					.then(function (d) {
						if (alive) setData(d);
					})
					.catch(function (err) {
						if (alive) setData({ error: errText(err) });
					});
			}
			load();
			var timer = setInterval(load, 5000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, [siteId]);

		useEffect(function () {
			if (esRef.current) {
				esRef.current.close();
				esRef.current = null;
			}
			setLiveLog([]);
			if (!live || !siteId) return;
			var es = new EventSource(BASE + "/jobs/stream?site=" + encodeURIComponent(siteId));
			esRef.current = es;
			es.onmessage = function (ev) {
				setLiveLog(function (prev) {
					var next = prev.slice(-199);
					next.push({ ts: new Date().toLocaleTimeString(), text: ev.data });
					return next;
				});
			};
			return function () {
				es.close();
				esRef.current = null;
			};
		}, [live, siteId]);

		var siteEntry = null;
		sites.forEach(function (s) {
			if (s.id === siteId) siteEntry = s;
		});
		var canWrite = !!(siteEntry && siteEntry.write);

		function act(action) {
			return function (jobId) {
				api("/jobs/" + siteId + "/" + action, { method: "POST", body: { job_id: jobId } })
					.then(function () {
						api("/jobs?site=" + encodeURIComponent(siteId)).then(setData).catch(function () {});
					})
					.catch(function (err) {
						window.alert(errText(err));
					});
			};
		}
		var cancelJob = act("cancel");
		var retryJob = act("retry");
		var deleteCron = function (jobId) {
			api("/jobs/" + siteId + "/wp-cron/delete", { method: "POST", body: { job_id: jobId } })
				.then(function () {
					api("/jobs?site=" + encodeURIComponent(siteId)).then(setData).catch(function () {});
				})
				.catch(function (err) {
					window.alert(errText(err));
				});
		};

		if (!sites.length) return Empty({ text: "No sites registered yet." });

		var asyncJobs = data && data.async_jobs ? data.async_jobs : [];
		var wpCron = data && data.wp_cron ? data.wp_cron : [];

		function jobLabel(job) {
			return (
				(job.job_id || job.id || "-") +
				" - " +
				(job.tool || job.hook || job.action || "-") +
				(job.status ? " [" + job.status + "]" : "")
			);
		}

		return h(
			"div",
			{ className: "nvoos-stack" },
			h(
				"div",
				{ className: "nvoos-row" },
				h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId }),
				h(
					Button,
					{ variant: live ? "default" : "outline", onClick: function () { setLive(!live); } },
					live ? "Live: on" : "Live: off"
				)
			),
			Err({ text: data && data.error }),
			h(SectionTitle, null, "Async tool jobs"),
			Err({ text: data && data.errors && data.errors.async_jobs }),
			!asyncJobs.length
				? Empty({ text: "No async tool jobs." })
				: h(
						"div",
						{ className: "nvoos-stack" },
						asyncJobs.map(function (job) {
							return h(
								Card,
								{ key: job.job_id || job.id || Math.random(), className: "nvoos-site-card" },
								h(
									CardContent,
									null,
									h("p", { className: "nvoos-muted" }, jobLabel(job)),
									h(
										"div",
										{ className: "nvoos-row" },
										h(Button, { disabled: !canWrite, title: canWrite ? "" : "Write disabled for this site", onClick: function () { cancelJob(job.job_id || job.id); } }, "Cancel"),
										h(Button, { disabled: !canWrite, title: canWrite ? "" : "Write disabled for this site", onClick: function () { retryJob(job.job_id || job.id); } }, "Retry")
									)
								)
							);
						})
				  ),
			h(SectionTitle, null, "WP cron"),
			Err({ text: data && data.errors && data.errors.wp_cron }),
			!wpCron.length
				? Empty({ text: "No WP cron jobs." })
				: h(
						"div",
						{ className: "nvoos-stack" },
						wpCron.map(function (job) {
							return h(
								Card,
								{ key: job.job_id || job.id || Math.random(), className: "nvoos-site-card" },
								h(
									CardContent,
									null,
									h("p", { className: "nvoos-muted" }, jobLabel(job)),
									h(
										"div",
										{ className: "nvoos-row" },
										h(Button, { variant: "destructive", disabled: !canWrite, title: canWrite ? "" : "Write disabled for this site", onClick: function () { deleteCron(job.job_id || job.id); } }, "Delete")
									)
								)
							);
						})
				  ),
			live
				? h(Card, null,
					h(CardHeader, null, h(CardTitle, null, "Live events")),
					h(CardContent, null,
						!liveLog.length
							? h("p", { className: "nvoos-muted" }, "Waiting for events...")
							: h(
									"div",
									{ className: "nvoos-log-list" },
									liveLog.map(function (entry, i) {
										return h("div", { key: i, className: "nvoos-log-line" }, entry.ts + " " + entry.text);
									})
							  )
					)
				  )
				: null
		);
	}

	/* ------------------------------------------------------------------ *
	 * Analytics view
	 * ------------------------------------------------------------------ */

	function AnalyticsView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];

		useEffect(function () {
			if (!siteId) {
				setData(null);
				return;
			}
			var alive = true;
			function load() {
				api("/analytics/summary?site=" + encodeURIComponent(siteId))
					.then(function (d) {
						if (alive) setData(d);
					})
					.catch(function (err) {
						if (alive) setData({ error: errText(err) });
					});
			}
			load();
			var timer = setInterval(load, 300000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, [siteId]);

		if (!sites.length) return Empty({ text: "No sites registered yet." });

		return h(
			"div",
			{ className: "nvoos-stack" },
			h("div", { className: "nvoos-row" }, h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId })),
			Err({ text: data && data.error }),
			!data
				? h("p", { className: "nvoos-muted" }, "Select a site to view analytics.")
				: h(
						"div",
						{ className: "nvoos-grid" },
						h(Card, null,
							h(CardHeader, null, h(CardTitle, null, "Cost dashboard")),
							h(CardContent, null,
								Err({ text: data.errors && data.errors.dashboard }),
								data.dashboard ? h(JsonView, { value: data.dashboard }) : Empty({ text: "No data." })
							)
						),
						h(Card, null,
							h(CardHeader, null, h(CardTitle, null, "Cost total")),
							h(CardContent, null,
								Err({ text: data.errors && data.errors.total }),
								data.total ? h(JsonView, { value: data.total }) : Empty({ text: "No data." })
							)
						),
						h(Card, null,
							h(CardHeader, null, h(CardTitle, null, "By provider")),
							h(CardContent, null,
								Err({ text: data.errors && data.errors.by_provider }),
								data.by_provider ? h(JsonView, { value: data.by_provider }) : Empty({ text: "No data." })
							)
						)
				  )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Security view
	 * ------------------------------------------------------------------ */

	function SecurityView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];

		useEffect(function () {
			if (!siteId) {
				setData(null);
				return;
			}
			var alive = true;
			function load() {
				api("/security/posture?site=" + encodeURIComponent(siteId))
					.then(function (d) {
						if (alive) setData(d);
					})
					.catch(function (err) {
						if (alive) setData({ error: errText(err) });
					});
			}
			load();
			var timer = setInterval(load, 60000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, [siteId]);

		function refresh() {
			api("/security/posture?site=" + encodeURIComponent(siteId) + "&refresh=true")
				.then(setData)
				.catch(function (err) {
					setData({ error: errText(err) });
				});
		}

		if (!sites.length) return Empty({ text: "No sites registered yet." });

		var score = data && data.score;
		var scoreBadge =
			score !== undefined
				? h(
						"span",
						{
							className:
								score >= 80
									? "nvoos-badge nvoos-badge-ok"
									: score >= 50
									? "nvoos-badge nvoos-badge-warn"
									: "nvoos-badge nvoos-badge-warn",
						},
						"Score: " + score
				  )
				: null;

		return h(
			"div",
			{ className: "nvoos-stack" },
			h(
				"div",
				{ className: "nvoos-row" },
				h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId }),
				h(Button, { variant: "outline", onClick: refresh }, "Refresh")
			),
			Err({ text: data && data.error }),
			!data
				? h("p", { className: "nvoos-muted" }, "Select a site to check its security posture.")
				: h(Card, null,
					h(CardHeader, null, h("div", { className: "nvoos-row" }, h(CardTitle, null, "Security posture"), scoreBadge)),
					h(CardContent, null, h(JsonView, { value: data }))
				  )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Tools view
	 * ------------------------------------------------------------------ */

	function ToolsView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];
		var queryState = useState("");
		var query = queryState[0];
		var setQuery = queryState[1];
		var openState = useState(null);
		var open = openState[0];
		var setOpen = openState[1];
		var argsState = useState("{}");
		var args = argsState[0];
		var setArgs = argsState[1];
		var resultState = useState(null);
		var result = resultState[0];
		var setResult = resultState[1];

		useEffect(function () {
			if (!siteId) {
				setData(null);
				return;
			}
			var alive = true;
			function load() {
				api("/tools?site=" + encodeURIComponent(siteId))
					.then(function (d) {
						if (alive) setData(d);
					})
					.catch(function (err) {
						if (alive) setData({ error: errText(err) });
					});
			}
			load();
			var timer = setInterval(load, 120000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, [siteId]);

		var siteEntry = null;
		sites.forEach(function (s) {
			if (s.id === siteId) siteEntry = s;
		});
		var canWrite = !!(siteEntry && siteEntry.write);

		function callTool() {
			var parsed;
			try {
				parsed = JSON.parse(args || "{}");
			} catch (err) {
				setResult({ error: "Invalid JSON arguments: " + errText(err) });
				return;
			}
			setResult({ running: true });
			api("/tools/call", {
				method: "POST",
				body: { site: siteId, tool: open, arguments: parsed },
			})
				.then(function (d) {
					setResult(d);
				})
				.catch(function (err) {
					setResult({ error: errText(err) });
				});
		}

		if (!sites.length) return Empty({ text: "No sites registered yet." });

		var tools = data && data.tools ? data.tools : [];
		var q = query.toLowerCase();
		var filtered = tools.filter(function (t) {
			var name = (t && (t.name || t.slug)) || "";
			var desc = (t && (t.description || t.summary)) || "";
			return !q || name.toLowerCase().indexOf(q) !== -1 || desc.toLowerCase().indexOf(q) !== -1;
		});

		return h(
			"div",
			{ className: "nvoos-stack" },
			h(
				"div",
				{ className: "nvoos-row" },
				h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId }),
				h(Input, { value: query, placeholder: "Filter tools...", onChange: function (ev) { setQuery(ev.target.value); } }),
				h("span", { className: "nvoos-muted" }, filtered.length + " tool(s)")
			),
			Err({ text: data && data.error }),
			!data
				? h("p", { className: "nvoos-muted" }, "Select a site to browse its tool registry.")
				: h(
						"div",
						{ className: "nvoos-tool-list" },
						filtered.slice(0, 200).map(function (t) {
							var name = (t && (t.name || t.slug)) || "-";
							var desc = (t && (t.description || t.summary)) || "";
							return h(
								Card,
								{ key: name, className: "nvoos-site-card" },
								h(
									CardContent,
									null,
									h("div", { className: "nvoos-row" },
										h("strong", null, name),
										open === name
											? h(Button, { variant: "outline", onClick: function () { setOpen(null); } }, "Close")
											: h(Button, { variant: "outline", onClick: function () { setOpen(name); setResult(null); setArgs("{}"); } }, "Open")
									),
									h("p", { className: "nvoos-muted" }, desc),
									open === name
										? h("div", { className: "nvoos-stack" },
											h("p", { className: "nvoos-muted" }, "JSON arguments (sent to tools/call on the site):"),
											h("textarea", { className: "nvoos-textarea", value: args, onChange: function (ev) { setArgs(ev.target.value); }, rows: 4 }),
											h(Button, { disabled: !canWrite, title: canWrite ? "" : "Write disabled for this site", onClick: callTool }, "Call tool"),
											result
												? result.running
													? h("p", { className: "nvoos-muted" }, "Running...")
													: h(JsonView, { value: result })
												: null
										  )
										: null
								)
							);
						})
				  )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Tokens view (read-only usage passthrough)
	 * ------------------------------------------------------------------ */

	function TokensView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var userState = useState("");
		var userId = userState[0];
		var setUserId = userState[1];
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];

		function load() {
			if (!siteId || !userId) {
				setData(null);
				return;
			}
			api("/tokens/usage?site=" + encodeURIComponent(siteId) + "&user_id=" + encodeURIComponent(userId))
				.then(setData)
				.catch(function (err) {
					setData({ error: errText(err) });
				});
		}

		if (!sites.length) return Empty({ text: "No sites registered yet." });

		return h(
			"div",
			{ className: "nvoos-stack" },
			h(
				"div",
				{ className: "nvoos-row" },
				h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId }),
				h(Input, { value: userId, placeholder: "WordPress user ID", onChange: function (ev) { setUserId(ev.target.value); } }),
				h(Button, { variant: "outline", onClick: load }, "Load usage")
			),
			h("p", { className: "nvoos-muted" }, "Read-only view. Issuing and revoking operator credentials happens on the WordPress side (Settings - External Operators, or wp mcp-ai operator)."),
			data ? h(JsonView, { value: data }) : null
		);
	}

	/* ------------------------------------------------------------------ *
	 * Paper Store view
	 * ------------------------------------------------------------------ */

	function PaperStoreView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var collectionsState = useState(null);
		var collections = collectionsState[0];
		var setCollections = collectionsState[1];
		var collState = useState("");
		var collection = collState[0];
		var setCollection = collState[1];
		var recordsState = useState(null);
		var records = recordsState[0];
		var setRecords = recordsState[1];
		var titleState = useState("");
		var title = titleState[0];
		var setTitle = titleState[1];
		var contentState = useState("");
		var content = contentState[0];
		var setContent = contentState[1];

		var siteEntry = null;
		sites.forEach(function (s) {
			if (s.id === siteId) siteEntry = s;
		});
		var canWrite = !!(siteEntry && siteEntry.write);

		function loadCollections() {
			api("/paper-store?site=" + encodeURIComponent(siteId))
				.then(setCollections)
				.catch(function (err) {
					setCollections({ error: errText(err) });
				});
		}

		function loadRecords() {
			api("/paper-store/records?site=" + encodeURIComponent(siteId) + "&collection=" + encodeURIComponent(collection))
				.then(setRecords)
				.catch(function (err) {
					setRecords({ error: errText(err) });
				});
		}

		useEffect(function () {
			if (!siteId) return;
			loadCollections();
			setCollection("");
			setRecords(null);
		}, [siteId]);

		useEffect(function () {
			if (!siteId || !collection) return;
			loadRecords();
		}, [collection, siteId]);

		function create() {
			api("/paper-store/records", {
				method: "POST",
				body: { site: siteId, collection: collection, record: { title: title, content: content } },
			})
				.then(function () {
					setTitle("");
					setContent("");
					loadRecords();
				})
				.catch(function (err) {
					window.alert(errText(err));
				});
		}

		function remove(recordId) {
			if (!window.confirm("Delete this record?")) return;
			api("/paper-store/records", {
				method: "DELETE",
				body: { site: siteId, collection: collection, record_id: recordId },
			})
				.then(loadRecords)
				.catch(function (err) {
					window.alert(errText(err));
				});
		}

		if (!sites.length) return Empty({ text: "No sites registered yet." });

		var colls = (collections && collections.collections) || [];
		var recs = (records && records.records) || [];

		return h(
			"div",
			{ className: "nvoos-stack" },
			h("div", { className: "nvoos-row" }, h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId })),
			h(SectionTitle, null, "Collections"),
			Err({ text: collections && collections.error }),
			!colls.length
				? Empty({ text: "No collections." })
				: h(
						"div",
						{ className: "nvoos-row" },
						colls.map(function (c) {
							var name = typeof c === "string" ? c : c.name || c.slug || c.id || "-";
							return h(Button, {
								key: name,
								variant: collection === name ? "default" : "outline",
								onClick: function () { setCollection(name); },
							}, name);
						})
				  ),
			collection
				? h("div", { className: "nvoos-stack" },
					h(SectionTitle, null, "Records in " + collection),
					Err({ text: records && records.error }),
					!recs.length
						? Empty({ text: "No records." })
						: h(
								"div",
								{ className: "nvoos-stack" },
								recs.map(function (r) {
									var id = r.id || r.record_id || "-";
									var t = r.title || r.name || id;
									return h(
										Card,
										{ key: id, className: "nvoos-site-card" },
										h(CardContent, null,
											h("div", { className: "nvoos-row" },
												h("strong", null, t),
												h(Button, { variant: "destructive", disabled: !canWrite, onClick: function () { remove(id); } }, "Delete")
											)
										)
									);
								})
						  ),
					h(SectionTitle, null, "Add record"),
					!canWrite
						? h("p", { className: "nvoos-muted" }, "Write disabled for this site.")
						: h("div", { className: "nvoos-form" },
							h(Label, null, "Title"),
							h(Input, { value: title, onChange: function (ev) { setTitle(ev.target.value); } }),
							h(Label, null, "Content"),
							h("textarea", { className: "nvoos-textarea", rows: 4, value: content, onChange: function (ev) { setContent(ev.target.value); } }),
							h(Button, { onClick: create }, "Create")
						  )
				  )
				: h("p", { className: "nvoos-muted" }, "Select a collection to browse records.")
		);
	}

	/* ------------------------------------------------------------------ *
	 * Workflows view (Pro orchestration runs)
	 * ------------------------------------------------------------------ */

	function WorkflowsView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];
		var eventsState = useState(null);
		var events = eventsState[0];
		var setEvents = eventsState[1];
		var openRunState = useState(null);
		var openRun = openRunState[0];
		var setOpenRun = openRunState[1];

		useEffect(function () {
			if (!siteId) {
				setData(null);
				return;
			}
			var alive = true;
			function load() {
				api("/workflows/runs?site=" + encodeURIComponent(siteId))
					.then(function (d) {
						if (alive) setData(d);
					})
					.catch(function (err) {
						if (alive) setData({ error: errText(err) });
					});
			}
			load();
			var timer = setInterval(load, 30000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, [siteId]);

		function toggleEvents(runId) {
			if (openRun === runId) {
				setOpenRun(null);
				setEvents(null);
				return;
			}
			setOpenRun(runId);
			setEvents({ loading: true });
			api("/workflows/runs/" + encodeURIComponent(siteId) + "/" + runId + "/events")
				.then(setEvents)
				.catch(function (err) {
					setEvents({ error: errText(err) });
				});
		}

		if (!sites.length) return Empty({ text: "No sites registered yet." });

		var runs = (data && data.runs) || [];

		return h(
			"div",
			{ className: "nvoos-stack" },
			h("div", { className: "nvoos-row" }, h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId })),
			Err({ text: data && data.error }),
			!runs.length
				? Empty({ text: "No workflow runs (Pro orchestration runs endpoint)." })
				: h(
						"div",
						{ className: "nvoos-stack" },
						runs.map(function (r) {
							var id = r.id || r.run_id || "-";
							return h(
								Card,
								{ key: id, className: "nvoos-site-card" },
								h(CardContent, null,
									h("div", { className: "nvoos-row" },
										h("strong", null, "Run #" + id),
										h("span", { className: "nvoos-badge" }, r.status || "-"),
										h(Button, { variant: "outline", onClick: function () { toggleEvents(id); } }, openRun === id ? "Hide events" : "Events")
									),
									h("p", { className: "nvoos-muted" }, (r.workflow || r.workflow_id || r.title || "-") + (r.started_at || r.created_at ? " - " + (r.started_at || r.created_at) : "")),
									openRun === id
										? events && events.loading
											? h("p", { className: "nvoos-muted" }, "Loading events...")
											: events && events.error
											? Err({ text: events.error })
											: h(JsonView, { value: events })
										: null
								)
							);
						})
				  )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Mesh view (federation directory)
	 * ------------------------------------------------------------------ */

	function MeshView() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];

		useEffect(function () {
			if (!siteId) {
				setData(null);
				return;
			}
			var alive = true;
			function load() {
				api("/mesh/peers?site=" + encodeURIComponent(siteId))
					.then(function (d) {
						if (alive) setData(d);
					})
					.catch(function (err) {
						if (alive) setData({ error: errText(err) });
					});
			}
			load();
			var timer = setInterval(load, 60000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, [siteId]);

		var siteEntry = null;
		sites.forEach(function (s) {
			if (s.id === siteId) siteEntry = s;
		});
		var canWrite = !!(siteEntry && siteEntry.write);

		function reverify(peerId) {
			api("/mesh/peers/reverify", { method: "POST", body: { site: siteId, peer_id: peerId } })
				.then(function () {
					window.alert("Reverification requested.");
				})
				.catch(function (err) {
					window.alert(errText(err));
				});
		}

		function report(peerId) {
			var reason = window.prompt("Reason for reporting this peer?") || "";
			api("/mesh/peers/report", { method: "POST", body: { site: siteId, peer_id: peerId, reason: reason } })
				.then(function () {
					window.alert("Report filed.");
				})
				.catch(function (err) {
					window.alert(errText(err));
				});
		}

		if (!sites.length) return Empty({ text: "No sites registered yet." });

		var peers = (data && data.peers) || [];

		return h(
			"div",
			{ className: "nvoos-stack" },
			h("div", { className: "nvoos-row" }, h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId })),
			Err({ text: data && data.error }),
			!peers.length
				? Empty({ text: "No federation peers (enable the directory service on the site)." })
				: h(
						"div",
						{ className: "nvoos-grid" },
						peers.map(function (p) {
							var id = p.id || p.peer_id || "-";
							var name = p.name || p.site_name || p.url || "Peer #" + id;
							return h(
								Card,
								{ key: id, className: "nvoos-site-card" },
								h(CardHeader, null, h(CardTitle, null, name)),
								h(CardContent, null,
									h("p", { className: "nvoos-muted" }, p.url || p.wellknown_url || ""),
									h(JsonView, { value: p }),
									h("div", { className: "nvoos-row" },
										h(Button, { variant: "outline", disabled: !canWrite, onClick: function () { reverify(id); } }, "Reverify"),
										h(Button, { variant: "destructive", disabled: !canWrite, onClick: function () { report(id); } }, "Report")
									)
								)
							);
						})
				  )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Slot: chat:bottom — Ask the fleet
	 * ------------------------------------------------------------------ */

	function AskTheFleet() {
		var sites = useSites();
		var siteState = useState("");
		var siteId = siteState[0];
		var setSiteId = siteState[1];
		var copiedState = useState(false);
		var copied = copiedState[0];
		var setCopied = copiedState[1];

		function copyConfig() {
			if (!siteId) return;
			api("/sites/" + siteId + "/mcp-config")
				.then(function (data) {
					copyText(data.yaml, function (ok) {
						setCopied(ok);
						setTimeout(function () { setCopied(false); }, 2000);
					});
				})
				.catch(function () {});
		}

		if (!sites.length) return null;

		return h(
			Card,
			null,
			h(CardHeader, null, h(CardTitle, null, "NV oOS Fleet - Ask the fleet")),
			h(CardContent, null,
				h("p", { className: "nvoos-muted" }, "Mount your WordPress sites as MCP servers and ask the Hermes agent to work across the fleet."),
				h("div", { className: "nvoos-row" },
					h(SiteSelect, { sites: sites, value: siteId, onChange: setSiteId }),
					h(Button, { variant: "outline", onClick: copyConfig }, copied ? "Copied" : "Copy MCP config")
				)
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Slot: sessions:bottom — fleet cost summary
	 * ------------------------------------------------------------------ */

	function FleetCosts() {
		var dataState = useState(null);
		var data = dataState[0];
		var setData = dataState[1];

		useEffect(function () {
			var alive = true;
			function load() {
				api("/costs/summary")
					.then(function (d) {
						if (alive) setData(d);
					})
					.catch(function () {});
			}
			load();
			var timer = setInterval(load, 300000);
			return function () {
				alive = false;
				clearInterval(timer);
			};
		}, []);

		if (!data || !data.sites || !data.sites.length) return null;

		return h(
			Card,
			null,
			h(CardHeader, null, h(CardTitle, null, "NV oOS Fleet - costs")),
			h(CardContent, null,
				data.sites.map(function (s) {
					return h(
						"div",
						{ key: s.id, className: "nvoos-row" },
						h("strong", null, s.label || s.id),
						s.ok
							? h("span", { className: "nvoos-muted" }, JSON.stringify(s.cost))
							: h("span", { className: "nvoos-error" }, s.error || "unavailable")
					);
				})
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Main tab component
	 * ------------------------------------------------------------------ */

	var VIEWS = [
		["sites", "Sites", SitesView],
		["fleet", "Fleet", FleetView],
		["overview", "Overview", OverviewView],
		["logs", "Logs", LogsView],
		["jobs", "Jobs", JobsView],
		["analytics", "Analytics", AnalyticsView],
		["security", "Security", SecurityView],
		["tools", "Tools", ToolsView],
		["tokens", "Tokens", TokensView],
		["paper", "Paper Store", PaperStoreView],
		["workflows", "Workflows", WorkflowsView],
		["mesh", "Mesh", MeshView],
	];

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

		var active = null;
		VIEWS.forEach(function (entry) {
			if (entry[0] === view) active = entry[2];
		});
		if (!active) active = SitesView;

		return h(
			"div",
			{ className: "nvoos-page" },
			h(
				"div",
				{ className: "nvoos-nav" },
				VIEWS.map(function (entry) {
					return h(NavButton, {
						key: entry[0],
						label: entry[1],
						active: view === entry[0],
						onClick: function () { setView(entry[0]); },
					});
				})
			),
			h(active)
		);
	}

	registry.register("nv-oos-fleet", FleetApp);
	registry.registerSlot("nv-oos-fleet", "header-right", FleetBadge);
	registry.registerSlot("nv-oos-fleet", "chat:bottom", AskTheFleet);
	registry.registerSlot("nv-oos-fleet", "sessions:bottom", FleetCosts);
})();
