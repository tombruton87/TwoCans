// twocans' window in DSM: what panel.html does. It asks api.cgi how twocans
// is, every few seconds, and draws it; the setup container carries out what
// it asks for. Its words are English here, and come from lang/<DSM's code>.json
// in other languages: t("…") for a sentence, tp("…", n) for one that counts.
(function () {
  "use strict";

  // ------------------------------------------------------------ language
  // DSM's language codes, for those twocans speaks, and what browsers call them.
  var LANGS = { enu: "English", ger: "Deutsch", fre: "Français", spn: "Español", nld: "Nederlands", ita: "Italiano", ptb: "Português", ptg: "Português", plk: "Polski" };
  var BCP = { enu: "en-GB", ger: "de", fre: "fr", spn: "es", nld: "nl", ita: "it", ptb: "pt-BR", ptg: "pt-PT", plk: "pl" };
  var FROM_BROWSER = { de: "ger", fr: "fre", es: "spn", nl: "nld", it: "ita", pt: "ptb", pl: "plk", en: "enu" };
  var words = {}, lang = "enu", plurals = new Intl.PluralRules("en");

  function stored() { try { return localStorage.getItem("twocans-lang") || ""; } catch (e) { return ""; } }
  function dsmLang() {
    var l = "";
    try { l = window.parent._S && window.parent._S("lang"); } catch (e) { /* not inside DSM */ }
    try { l = l || window.parent.SYNO.SDS.Session.lang; } catch (e) { /* not inside DSM */ }
    if (l && LANGS[l]) return l;
    var b = (navigator.language || "en").slice(0, 2).toLowerCase();   // DSM's "def": the browser's
    return FROM_BROWSER[b] || "enu";
  }
  // A sentence in this language, with {name}s filled in.
  function t(s, vars) {
    var out = words[s] != null && typeof words[s] === "string" ? words[s] : s;
    if (vars) Object.keys(vars).forEach(function (k) { out = out.split("{" + k + "}").join(vars[k]); });
    return out;
  }
  // One that counts: the English key is the "other" form; each language's
  // file has its own forms ({one, few, many, other}), chosen by its rules.
  function tp(s, n, vars) {
    var forms = words[s], v = Object.assign({ n: Number(n).toLocaleString(BCP[lang]) }, vars || {});
    var form = forms && typeof forms === "object" ? (forms[plurals.select(n)] || forms.other) : null;
    if (!form) form = n === 1 && ENGLISH_ONE[s] ? ENGLISH_ONE[s] : s;
    return t.call(null, form, v);
  }
  // English's own singular forms, for tp().
  var ENGLISH_ONE = {
    "{n} parts not working": "{n} part not working",
    "{n} calls are going on now.": "A call is going on now.",
    "{n} days": "{n} day",
    "{n} minutes": "{n} minute",
    "{n} hours": "{n} hour",
    "{n} recordings not written down": "{n} recording not written down",
    "{n} backups kept": "{n} backup kept",
    "{n} passkeys removed.": "{n} passkey removed.",
    "{n} problems": "{n} problem",
    "{n} phones paused": "{n} phone paused",
    "{n} hours ago": "{n} hour ago",
    "{n} days ago": "{n} day ago",
    "{n} calls": "{n} call",
    "{n} calls are going on now, and will be cut off.": "A call is going on now, and will be cut off."
  };
  function translatePage() {
    document.documentElement.lang = BCP[lang];
    document.querySelectorAll("[data-t]").forEach(function (e) {
      if (!e.dataset.en) e.dataset.en = e.textContent.trim();
      e.textContent = t(e.dataset.en);
    });
    document.querySelectorAll("[data-t-placeholder]").forEach(function (e) { e.placeholder = t(e.dataset.tPlaceholder); });
  }
  function loadLanguage(code) {
    lang = LANGS[code] ? code : "enu";
    plurals = new Intl.PluralRules(BCP[lang]);
    if (lang === "enu") { words = {}; return Promise.resolve(); }
    return fetch("lang/" + (lang === "ptg" ? "ptb" : lang) + ".json", { credentials: "same-origin" })
      .then(function (r) { return r.ok ? r.json() : {}; })
      .then(function (w) { words = w || {}; })
      .catch(function () { words = {}; });
  }

  // ------------------------------------------------------------- talking
  // DSM's own session token, from the DSM page around this one: DSM checks it
  // with every request, so another site can't make them in your name.
  function token() {
    try { return window.parent.SYNO.SDS.Session.SynoToken || ""; } catch (e) { return ""; }
  }
  function withToken(url) { var k = token(); return k ? url + "&SynoToken=" + encodeURIComponent(k) : url; }
  function api(query, body) {
    var opts = { credentials: "same-origin", headers: { "X-SYNO-TOKEN": token() } };
    if (body) {
      opts.method = "POST";
      opts.headers["Content-Type"] = "application/x-www-form-urlencoded";
      opts.body = new URLSearchParams(body).toString();
    }
    return fetch(withToken("api.cgi?" + query), opts).then(function (r) {
      var json = (r.headers.get("Content-Type") || "").indexOf("json") >= 0;
      return (json ? r.json() : r.text()).then(function (data) {
        if (!r.ok) throw new Error((data && data.error) || t("twocans didn't answer ({status}).", { status: r.status }));
        return data;
      });
    });
  }

  // ------------------------------------------------------------- helpers
  var $ = function (id) { return document.getElementById(id); };
  function el(tag, text, cls) { var e = document.createElement(tag); if (text != null) e.textContent = text; if (cls) e.className = cls; return e; }
  function icon(name, cls) {
    var s = document.createElementNS("http://www.w3.org/2000/svg", "svg"), u = document.createElementNS("http://www.w3.org/2000/svg", "use");
    u.setAttribute("href", "#i-" + name); s.appendChild(u); if (cls) s.setAttribute("class", cls); return s;
  }
  function svgEl(tag, attrs) { var e = document.createElementNS("http://www.w3.org/2000/svg", tag); Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); }); return e; }
  function cell(value, cls) { var td = el("td", null, cls); if (value instanceof Node) td.appendChild(value); else td.textContent = value == null ? "" : value; return td; }
  function row(tbody, cells) { var tr = el("tr"); cells.forEach(function (c) { tr.appendChild(c instanceof Node && c.tagName === "TD" ? c : cell(c)); }); tbody.appendChild(tr); return tr; }
  function kv(tbody, label, value, num) { return row(tbody, [cell(label, "k"), cell(value, num ? "n" : "")]); }
  function chip(text, cls) { var c = el("span", null, "chip " + (cls || "")); c.appendChild(el("i")); c.appendChild(el("span", text)); return c; }
  function number(n) { return n == null ? "–" : Number(n).toLocaleString(BCP[lang]); }
  function bytes(kb) {
    var b = kb * 1024, u = ["B", "KB", "MB", "GB", "TB"], i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (b >= 10 || i === 0 ? Math.round(b) : b.toFixed(1)).toLocaleString(BCP[lang]) + " " + u[i];
  }
  function ago(seconds) {
    var s = Math.max(0, seconds);
    if (s < 90) return t("just now");
    if (s < 5400) return t("{n} min ago", { n: Math.round(s / 60) });
    if (s < 129600) return tp("{n} hours ago", Math.round(s / 3600));
    return tp("{n} days ago", Math.round(s / 86400));
  }
  function upFor(iso) {
    var ts = Date.parse(iso); if (!ts || ts < 0) return "";
    var s = (Date.now() - ts) / 1000;
    if (s < 90) return t("just now");
    if (s < 5400) return t("{n} min", { n: Math.round(s / 60) });
    if (s < 129600) return tp("{n} hours", Math.round(s / 3600));
    return tp("{n} days", Math.round(s / 86400));
  }
  function clock(s) { s = Math.max(0, Math.round(s)); var m = Math.floor(s / 60), h = Math.floor(m / 60); return (h ? h + ":" + String(m % 60).padStart(2, "0") : m) + ":" + String(s % 60).padStart(2, "0"); }
  function when(sqlTime) {   // "2026-10-03 11:57:21", the household's time
    var d = new Date(String(sqlTime).replace(" ", "T"));
    if (isNaN(d)) return "";
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var time = d.toLocaleTimeString(BCP[lang], { hour: "2-digit", minute: "2-digit" });
    if (d >= today) return time;
    if (d >= today - 86400000) return t("Yesterday, {time}", { time: time });
    return d.toLocaleDateString(BCP[lang], { day: "numeric", month: "short" }) + ", " + time;
  }
  function meter(pct) { var m = el("div", null, "meter"), i = el("i", null, pct > 90 ? "bad" : pct > 75 ? "warn" : ""); i.style.width = Math.min(100, pct) + "%"; m.appendChild(i); return m; }
  function newer(a, b) {   // "0.1.9" over "0.1.8", by each number in turn
    var x = String(a).split(/[.-]/), y = String(b).split(/[.-]/);
    for (var i = 0; i < Math.max(x.length, y.length); i++) {
      var p = parseInt(x[i] || "0", 10), q = parseInt(y[i] || "0", 10);
      if (p !== q) return p > q;
    }
    return false;
  }
  function message(text, cls) { var m = $("message"); m.hidden = !text; m.textContent = text || ""; m.className = "note " + (cls || ""); }

  // ---------------------------------------------------------------- tabs
  var TITLES = { overview: "Overview", live: "Live calls", phones: "Phones", activity: "Activity", line: "Phone line", resources: "Resources", settings: "Settings", logs: "Logs" };
  var tab = "overview";
  var tabs = document.querySelectorAll("aside nav button");
  tabs.forEach(function (b) {
    b.addEventListener("click", function () {
      tab = b.dataset.tab;
      tabs.forEach(function (o) { o.setAttribute("aria-selected", o === b ? "true" : "false"); $(o.dataset.tab).hidden = o !== b; });
      $("title").textContent = t(TITLES[tab]);
      if (tab === "logs") loadLog();
      if (tab === "live") poll();
    });
  });
  function showTab(name) { document.querySelector('aside nav button[data-tab="' + name + '"]').click(); }
  document.addEventListener("click", function (e) {   // menus open on their button, close on anything else
    var opener = e.target.closest("[data-menu]");
    document.querySelectorAll(".menu > div").forEach(function (m) { if (!opener || m.parentNode !== opener.parentNode) m.hidden = true; });
    if (opener) { var m = opener.parentNode.querySelector("div"); m.hidden = !m.hidden; }
  });

  // ------------------------------------------------------------ overview
  var PARTS = {
    "twocans-asterisk": "Phone service", "twocans-mariadb": "Database", "twocans-web": "Web app",
    "twocans-transcriber": "Transcription", "twocans-whisper": "Speech-to-text", "twocans-pager": "Announcements",
    "twocans-setup": "Setup"
  };
  function partName(name) { return PARTS[name] ? t(PARTS[name]) : name; }
  function partState(p, off) {
    if (off && (p.name === "twocans-whisper" || p.name === "twocans-transcriber") && p.state !== "running") return [t("Off"), ""];
    if (p.state === "running" && (p.health === "healthy" || p.health === "-")) return [t("Running"), "ok"];
    if (p.state === "running" && p.health === "starting") return [t("Starting"), "warn"];
    if (p.state === "running") return [t("Not answering"), "bad"];
    if (p.state === "restarting") return [t("Restarting"), "warn"];
    if (p.state === "missing") return [t("Not set up"), ""];
    if (p.state === "exited") return [t("Stopped"), "bad"];
    return [p.state, "bad"];
  }
  function hero(cls, iconName, title, text) {
    var h = $("hero-icon"); h.className = "icon " + cls; h.innerHTML = ""; h.appendChild(icon(iconName));
    $("hero-title").textContent = title; $("hero-text").textContent = text || "";
  }
  var BUSY = {
    "restarting": "Restarting", "setting up again": "Setting up again", "applying new settings": "Applying the new settings",
    "making a support report": "Making a support report", "resetting the Owner account": "Resetting the Owner account",
    "turning speech-to-text on": "Turning speech-to-text on", "turning speech-to-text off": "Turning speech-to-text off",
    "making an export": "Making an export", "setting up HTTPS": "Setting up HTTPS"
  };
  var DONE = {
    https: "Setting up HTTPS", restart: "Restarting", setup: "Setting up again", settings: "Applying the new settings",
    report: "Making the support report", owner: "Resetting the Owner account", check: "Looking for updates",
    hangup: "Ending the call", retry: "Sending transcriptions back", reregister: "Registering the phone line",
    transcription: "Switching speech-to-text", "export": "Making the export", ring: "Ringing the phone",
    pause: "Pausing", resume: "Turning phones back on"
  };

  var data = {}, filled = false, last = null;
  function activeCalls() { return data.live && data.live.calls ? data.live.calls.length : (data.stats && data.stats.active_calls > 0 ? data.stats.active_calls : 0); }
  function cutOff() {
    var n = activeCalls();
    return n ? tp("{n} calls are going on now, and will be cut off.", n) : t("No calls are going on now.");
  }
  function tile(parent, iconName, value, label) {
    var tl = el("div", null, "tile"), ic = el("div", null, "ic"); ic.appendChild(icon(iconName)); tl.appendChild(ic);
    var d = el("div"); d.appendChild(el("b", value)); d.appendChild(el("span", label)); tl.appendChild(d); parent.appendChild(tl);
  }

  function renderOverview(d) {
    var s = d.status, busy = s && s.busy, stale = s && d.now - s.updated > 40, off = d.stats && d.stats.transcription === "off";
    var admin = d.role === "admin";
    document.querySelectorAll("[data-action]").forEach(function (b) { b.disabled = !!(busy || d.pending) || d.state !== "running"; });

    if (d.state === "failed") hero("bad", "alert", t("Setting twocans up stopped"), t("The Logs say why. Once it's sorted, start it again in Package Center (twocans → Run)."));
    else if (d.state === "stopped") hero("idle", "pause", t("twocans is stopped"), t("Start it in Package Center (twocans → Run)."));
    else if (d.state === "starting") hero("warn", "alert", t("Setting twocans up…"), t("The first time takes a while — the Logs show how it's going."));
    else if (busy) hero("warn", "alert", t(BUSY[busy] || busy) + "…", t("This takes a moment."));
    else if (d.pending) hero("warn", "alert", t("Starting your request…"), "");
    else if (stale || !s) hero("warn", "alert", t("twocans isn't answering"), t("Its setup container may be busy, or stopped — Package Center shows which."));
    else {
      var core = s.parts.filter(function (p) { return p.name !== "twocans-setup"; });
      var down = core.filter(function (p) { return partState(p, off)[1] === "bad"; });
      var waiting = core.filter(function (p) { return partState(p, off)[1] === "warn"; });
      if (down.length) hero("bad", "alert", tp("{n} parts not working", down.length), down.map(function (p) { return partName(p.name); }).join(", ") + (d.prefs && d.prefs.watchdog ? " — " + t("twocans will try starting it again.") : "."));
      else if (waiting.length) hero("warn", "alert", t("twocans is starting"), t("Nearly there."));
      else hero("ok", "check", t("twocans is running well"), activeCalls() ? tp("{n} calls are going on now.", activeCalls()) : t("Every part is working."));
    }

    if (s && s.last && (!last || s.last.at !== last.at)) {
      var first = !last; last = s.last;
      var what = t(DONE[s.last.action] || "That");
      if (!first || d.now - s.last.at < 60) message(s.last.ok ? t("{what} finished.", { what: what }) : t("{what} didn't finish — the Logs say why.", { what: what }), s.last.ok ? "good" : "bad");
    }

    if (!s) return;
    $("version").textContent = t("Version {v}", { v: s.version });
    $("foot").textContent = d.user ? t("Signed in as {user}", { user: d.user }) : "";
    var cfg = s.settings, open = $("open");
    open.href = cfg.app_url || "#"; open.hidden = !cfg.app_url;

    var sum = $("summary"); sum.innerHTML = "";
    var a = d.stats && d.stats.app;
    if (a && a.phones) tile(sum, "phone", t("{on} of {all}", { on: a.phones.filter(function (p) { return p.online; }).length, all: a.phones.length }), t("phones online"));
    tile(sum, "live", number(activeCalls()), t("calls now"));
    if (a && a.calls) tile(sum, "activity", number(a.calls.today), t("calls today"));
    if (a && a.voicemail) tile(sum, "voicemail", number(a.voicemail.unheard), t("unheard voicemails"));

    var parts = $("parts"); parts.innerHTML = "";
    s.parts.forEach(function (p) {
      var st = partState(p, off);
      row(parts, [partName(p.name), chip(st[0], st[1]), cell(p.state === "running" ? upFor(p.started) : "", "n muted")]);
    });

    var c = $("connect"); c.innerHTML = "";
    var link = el("a", cfg.app_url); link.href = cfg.app_url; link.target = "_blank"; link.rel = "noopener";
    kv(c, t("Web app"), link);
    if (cfg.name) kv(c, t("On your network, by name"), "http://" + cfg.name + ".local" + (cfg.web_port === "80" ? "" : ":" + cfg.web_port));
    kv(c, t("Phones register to"), el("code", cfg.address + ":" + cfg.sip_port));
    kv(c, t("HTTPS"), t("port {port}", { port: cfg.https_port }));

    if (admin && !filled) {
      ["address", "web_port", "https_port", "tz", "country", "model"].forEach(function (k) { if (cfg[k]) $(k).value = cfg[k]; });
      filled = true;
    }
  }

  // The getting-started list: what a new household does first, ticked off as
  // twocans sees it done. Hidden once it's all done, or when asked.
  var STEPS = [
    ["account", "Create your account", "Open twocans, and sign up as its Owner."],
    ["phone", "Add a phone", "In twocans: Phones → Add a phone."],
    ["online", "Get a phone online", "Follow its setup steps, then check it shows Online here."],
    ["people", "Add the people they can call", "In twocans: People."],
    ["line", "Connect a phone line", "Optional — for calls to and from outside. In twocans: Phone line."],
    ["https", "Set up HTTPS", "For Face ID sign-in — Settings → HTTPS, here."],
    ["backup", "Make a backup", "In twocans: Backups. Hyper Backup can then copy the twocans shared folder off the Synology."]
  ];
  function stepsHidden() { try { return localStorage.getItem("twocans-steps") === "hidden"; } catch (e) { return false; } }
  function renderChecklist(d) {
    var g = d.stats && d.stats.app && d.stats.app.getting_started, box = $("checklist");
    if (!g || d.role !== "admin" || stepsHidden()) { box.hidden = true; return; }
    var done = STEPS.filter(function (s) { return g[s[0]]; }).length;
    box.hidden = done === STEPS.length;
    $("steps-done").textContent = t("{done} of {all} done", { done: done, all: STEPS.length });
    $("steps-bar").style.width = Math.round(100 * done / STEPS.length) + "%";
    var list = $("steps"); list.innerHTML = "";
    STEPS.forEach(function (s) {
      var li = el("li", null, g[s[0]] ? "done" : ""), tick = el("span", null, "tick"); tick.appendChild(icon("check")); li.appendChild(tick);
      var txt = el("div"); txt.appendChild(el("b", t(s[1]))); if (!g[s[0]]) txt.appendChild(el("small", t(s[2]))); li.appendChild(txt);
      list.appendChild(li);
    });
  }
  $("steps-hide").addEventListener("click", function (e) {
    e.preventDefault(); try { localStorage.setItem("twocans-steps", "hidden"); } catch (err) { /* only for now, then */ }
    $("checklist").hidden = true;
  });

  // The newest release, and whether the app's images are behind the package.
  function renderUpdate(d) {
    var u = d.update, s = d.status, box = $("update");
    box.innerHTML = ""; box.hidden = true;
    if (!s) return;
    var app = d.stats && d.stats.app_version;
    if (u && u.latest && newer(u.latest, s.version)) {
      box.className = "note good"; box.hidden = false;
      var tx = el("div"); tx.appendChild(el("b", t("twocans {v} is out.", { v: u.latest }) + " "));
      if (u.spk && d.role === "admin") { var a = el("a", t("Download its package")); a.href = u.spk; tx.appendChild(a); tx.appendChild(el("span", ", " + t("then install it in Package Center → Manual Install.") + " ")); }
      if (u.page) { var p = el("a", t("What's new")); p.href = u.page; p.target = "_blank"; p.rel = "noopener"; tx.appendChild(p); }
      box.appendChild(tx);
    } else if (app && newer(s.version, app) && d.role === "admin") {
      box.className = "note warn"; box.hidden = false;
      box.textContent = t("The app is running {app}, older than this package ({pkg}). Run setup again to fetch the newest images, once they've been published.", { app: app, pkg: s.version });
    }
    var line = $("checked"); line.innerHTML = "";
    line.appendChild(el("span", !u ? t("Not looked for updates yet.") + " " : u.error ? t("Couldn't look for updates.") + " " : t("Looked for updates {when}.", { when: ago(d.now - u.checked) }) + (box.hidden ? " " + t("This is the newest.") + " " : " ")));
    if (d.role === "admin") {
      var look = el("a", t("Look now")); look.href = "#";
      look.addEventListener("click", function (e) { e.preventDefault(); ask({ action: "check" }); });
      line.appendChild(look);
    }
  }

  // ---------------------------------------------------------------- live
  function renderLive(d) {
    var calls = (d.live && d.live.calls) || [], box = $("calls"), badge = $("live-badge");
    badge.hidden = !calls.length; badge.textContent = calls.length;
    box.innerHTML = "";
    if (!calls.length) {
      var e = el("div", null, "empty"); e.appendChild(icon("live")); e.appendChild(el("div", t("No calls right now.")));
      box.appendChild(e); return;
    }
    var age = d.live.updated ? d.now - d.live.updated : 0;
    calls.forEach(function (c) {
      var r = el("div", null, "call");
      r.appendChild(el("div", (c.phone || "?").charAt(0).toUpperCase(), "avatar"));
      var who = el("div", null, "who"), top = el("div");
      top.appendChild(el("b", c.phone)); top.appendChild(icon(c.dir === "out" ? "out" : "in", "dir")); top.appendChild(el("b", c["with"]));
      who.appendChild(top);
      who.appendChild(el("div", (c.dir === "out" ? t("Calling out") : t("Called in")) + (c.number && c.number !== c["with"] ? " · " + c.number : "") + (c.connected ? "" : " · " + t("ringing"))));
      r.appendChild(who);
      var tm = el("span", clock(c.seconds + age), "time"); tm.dataset.since = String(Date.now() / 1000 - c.seconds - age); r.appendChild(tm);
      if (d.role === "admin") {
        var b = el("button", t("Hang up"), "btn danger"); b.style.marginLeft = "14px";
        b.addEventListener("click", function () { ask({ action: "hangup", channel: c.channel }, t("End {phone}'s call with {with}?", { phone: c.phone, "with": c["with"] })); });
        r.appendChild(b);
      }
      box.appendChild(r);
    });
  }
  setInterval(function () {   // the call clocks tick between polls
    document.querySelectorAll(".call .time").forEach(function (tm) { tm.textContent = clock(Date.now() / 1000 - parseFloat(tm.dataset.since)); });
  }, 1000);

  // -------------------------------------------------------------- phones
  function renderPhones(d) {
    var a = d.stats && d.stats.app, list = $("phone-list"), admin = d.role === "admin";
    var can = d.stats && d.stats.app && d.stats.app.can_pause;
    list.innerHTML = "";
    var warn = $("pause-unsupported");
    warn.hidden = !admin || can !== false;
    warn.textContent = t("Pausing phones needs twocans 0.1.7 or later, and this Synology runs an older app. It comes with setup, once newer images have been published.");
    $("phones-all").hidden = !admin || !can;
    if (!a || !a.phones) return;
    if (!a.phones.length) { row(list, [cell(t("No phones yet — add one in twocans, on its Phones screen."), "muted")]).firstChild.colSpan = 4; return; }
    var busy = !!(d.status && d.status.busy) || !!d.pending;
    a.phones.forEach(function (p) {
      var paused = p.pausedUntil && p.pausedUntil > d.now;
      var st = paused ? chip(t("Paused until {time}", { time: new Date(p.pausedUntil * 1000).toLocaleTimeString(BCP[lang], { hour: "2-digit", minute: "2-digit" }) }), "warn")
        : chip(p.online ? t("Online") : p.registered ? t("Offline") + (p.lastSeenAt ? " · " + t("last seen {when}", { when: ago(d.now - p.lastSeenAt) }) : "") : t("Not set up yet"), p.online ? "ok" : p.registered ? "bad" : "");
      var cells = [p.name, cell(p.extension, "muted"), cell(st)];
      if (admin) {
        var acts = el("div", null, "actions");
        var ring = el("button", t("Ring"), "btn small"); ring.disabled = busy || !p.online;
        ring.title = p.online ? t("Rings it; answered, it says “hello world”.") : t("It's offline.");
        ring.addEventListener("click", function () { ask({ action: "ring", phone: p.id }); });
        acts.appendChild(ring);
        if (can) {
          if (paused) {
            var back = el("button", t("Back on"), "btn small"); back.disabled = busy;
            back.addEventListener("click", function () { ask({ action: "resume", phone: p.id }); });
            acts.appendChild(back);
          } else {
            var menu = el("span", null, "menu"), opener = el("button", t("Pause") + " ▾", "btn small"); opener.setAttribute("data-menu", ""); opener.disabled = busy;
            var drop = el("div"); drop.hidden = true;
            [["60", "For an hour"], ["120", "For two hours"], ["morning", "Until the morning"]].forEach(function (o) {
              var b = el("button", t(o[1])); b.addEventListener("click", function () { ask({ action: "pause", phone: p.id, "for": o[0] }); }); drop.appendChild(b);
            });
            menu.appendChild(opener); menu.appendChild(drop); acts.appendChild(menu);
          }
        }
        cells.push(cell(acts, "n"));
      }
      row(list, cells);
    });
  }
  document.querySelectorAll("[data-pause-all]").forEach(function (b) {
    b.addEventListener("click", function () { ask({ action: "pause", phone: 0, "for": b.dataset.pauseAll }, t("Pause every phone? No calls in or out until the time's up.")); });
  });
  $("resume-all").addEventListener("click", function () { ask({ action: "resume", phone: 0 }); });

  // ------------------------------------------------------------ activity
  var STATUS = { done: ["Answered", "ok"], missed: ["Missed", "warn"], blocked: ["Blocked", "bad"] };
  var playing = null;
  function renderActivity(d) {
    var a = d.stats && d.stats.app, has = a && a.calls;
    $("activity-note").hidden = !!has;
    var tiles = $("call-tiles"), detail = $("call-detail"), home = $("household"), recent = $("recent");
    tiles.innerHTML = ""; detail.innerHTML = ""; home.innerHTML = "";
    if (!a) return;
    if (a.calls) {
      var c = a.calls;
      tile(tiles, "live", number(activeCalls()), t("going on now"));
      tile(tiles, "activity", number(c.today), t("today"));
      tile(tiles, "activity", number(c.week), t("last 7 days"));
      tile(tiles, "activity", number(c.month), t("last 30 days"));
      kv(detail, t("Calls, all told"), number(c.total), true);
      kv(detail, t("Answered"), number(c.answered), true);
      kv(detail, t("Missed"), number(c.missed), true);
      kv(detail, t("Blocked by the rules"), number(c.blocked), true);
      kv(detail, t("Made from the house"), number(c.outgoing), true);
      kv(detail, t("Came in"), number(c.incoming), true);
      kv(detail, t("Time on the phone"), c.minutes >= 120 ? tp("{n} hours", Math.round(c.minutes / 60)) : tp("{n} minutes", c.minutes), true);
      if (c.transcribing) kv(detail, t("Waiting to be written down"), number(c.transcribing), true);
    }
    if (a.per_day) dayChart(a.per_day);
    if (a.recent && (!recent.dataset.sig || recent.dataset.sig !== JSON.stringify(a.recent).length + ":" + lang)) {
      recent.dataset.sig = JSON.stringify(a.recent).length + ":" + lang;
      recent.innerHTML = "";
      if (!a.recent.length) row(recent, [cell(t("No calls yet."), "muted")]).firstChild.colSpan = 6;
      a.recent.forEach(function (r) {
        var st = STATUS[r.status] || [r.status, ""];
        var with_ = el("span"); with_.appendChild(icon(r.dir === "out" ? "out" : "in", "dir")); with_.appendChild(document.createTextNode(" " + (r["with"] || t("Unknown"))));
        var cells = [cell(when(r.at), "muted"), r.phone || "–", cell(with_), cell(chip(t(st[0]), st[1])), cell(r.seconds ? clock(r.seconds) : "", "n muted")];
        if (d.role === "admin") {
          var play = el("span");
          if (r.recording) {
            var b = el("button", null, "play"); b.title = t("Play the recording"); b.appendChild(icon("play"));
            b.addEventListener("click", function () { toggle(b, r.recording); });
            play.appendChild(b);
          }
          cells.push(cell(play, "n"));
        }
        var tr = row(recent, cells);
        if (r.transcript && d.role === "admin") { var tt = el("tr", null, "transcript"); var td = cell("“" + r.transcript + "”"); td.colSpan = 6; tt.appendChild(td); recent.appendChild(tt); tr.style.borderBottom = "0"; }
      });
    }
    if (a.voicemail) kv(home, t("Voicemails"), a.voicemail.unheard ? t("{unheard} not listened to, of {all}", { unheard: a.voicemail.unheard, all: a.voicemail.total }) : t("{all}, all listened to", { all: number(a.voicemail.total) }));
    if (a.contacts != null) kv(home, t("People on the list"), number(a.contacts));
    if ("backup" in a) kv(home, t("Last backup"), a.backup ? ago(Date.now() / 1000 - a.backup.at) + " · " + tp("{n} backups kept", a.backup.kept) : t("None yet — twocans makes them on its Backups screen."));
  }
  function toggle(button, name) {
    var p = $("player");
    if (playing === button) { p.pause(); return; }
    if (playing) { playing.innerHTML = ""; playing.appendChild(icon("play")); }
    playing = button; button.innerHTML = ""; button.appendChild(icon("stop"));
    p.src = withToken("api.cgi?action=recording&name=" + encodeURIComponent(name));
    p.play().catch(function () { message(t("That recording couldn't be played."), "bad"); });
    p.onended = p.onpause = function () { if (playing) { playing.innerHTML = ""; playing.appendChild(icon("play")); playing = null; } };
  }

  // A bar for each of the last 30 days: calls, the missed ones in red.
  function dayChart(days) {
    var svg = $("day-chart"), W = svg.clientWidth || 600, H = 150, pad = 22, top = 8;
    svg.setAttribute("viewBox", "0 0 " + W + " " + H); svg.innerHTML = "";
    var max = Math.max(4, Math.max.apply(null, days.map(function (x) { return x.calls; })));
    var step = Math.pow(10, Math.floor(Math.log10(max))), nice = Math.ceil(max / step) * step;
    var bw = (W - pad) / days.length;
    [0, 0.5, 1].forEach(function (f) {
      var y = H - pad - f * (H - pad - top);
      svg.appendChild(svgEl("line", { x1: pad, x2: W, y1: y, y2: y, "class": "axis" }));
      var lbl = svgEl("text", { x: pad - 4, y: y + 3, "text-anchor": "end" }); lbl.textContent = Math.round(f * nice); svg.appendChild(lbl);
    });
    days.forEach(function (x, i) {
      var g = svgEl("g", { "class": "bar-g" }), h = (H - pad - top) * x.calls / nice, hm = (H - pad - top) * x.missed / nice;
      var xx = pad + i * bw + bw * 0.18, w = bw * 0.64;
      g.appendChild(svgEl("rect", { x: xx, y: H - pad - h, width: w, height: Math.max(0, h - hm), rx: 1.5, "class": "bar-a" }));
      if (hm) g.appendChild(svgEl("rect", { x: xx, y: H - pad - hm, width: w, height: hm, "class": "bar-m" }));
      var d = new Date(x.day + "T12:00:00"), title = svgEl("title");
      title.textContent = d.toLocaleDateString(BCP[lang], { weekday: "short", day: "numeric", month: "short" }) + ": " + tp("{n} calls", x.calls) + (x.missed ? ", " + t("{n} missed", { n: x.missed }) : "");
      g.appendChild(title);
      if (i % 7 === 0 || i === days.length - 1) {
        var lbl = svgEl("text", { x: xx + w / 2, y: H - 6, "text-anchor": "middle" }); lbl.textContent = d.toLocaleDateString(BCP[lang], { day: "numeric", month: "short" }); g.appendChild(lbl);
      }
      svg.appendChild(g);
    });
  }

  // Memory over the last day: the Synology's in use, and twocans' share.
  function memChart(samples, total) {
    var svg = $("mem-chart"), W = svg.clientWidth || 600, H = 150, pad = 34, top = 8;
    svg.setAttribute("viewBox", "0 0 " + W + " " + H); svg.innerHTML = "";
    if (!samples || samples.length < 2 || !total) {
      var t0 = svgEl("text", { x: W / 2, y: H / 2, "text-anchor": "middle" }); t0.textContent = t("Collecting — a point every five minutes."); svg.appendChild(t0); return;
    }
    var t1 = samples[0][0], t2 = samples[samples.length - 1][0], span = Math.max(1, t2 - t1);
    var x = function (ts) { return pad + (W - pad - 4) * (ts - t1) / span; }, y = function (kb) { return H - 20 - (H - 20 - top) * kb / total; };
    [0, 0.5, 1].forEach(function (f) {
      var yy = y(f * total);
      svg.appendChild(svgEl("line", { x1: pad, x2: W, y1: yy, y2: yy, "class": "axis" }));
      var lbl = svgEl("text", { x: pad - 4, y: yy + 3, "text-anchor": "end" }); lbl.textContent = Math.round(f * 100) + "%"; svg.appendChild(lbl);
    });
    var used = samples.map(function (s) { return x(s[0]).toFixed(1) + "," + y(s[1]).toFixed(1); }).join(" ");
    svg.appendChild(svgEl("polygon", { points: x(t1) + "," + y(0) + " " + used + " " + x(t2) + "," + y(0), "class": "area" }));
    svg.appendChild(svgEl("polyline", { points: used, "class": "line" }));
    svg.appendChild(svgEl("polyline", { points: samples.map(function (s) { return x(s[0]).toFixed(1) + "," + y(s[2]).toFixed(1); }).join(" "), "class": "line2" }));
    [t1, t2].forEach(function (ts, i) {
      var lbl = svgEl("text", { x: i ? W - 4 : pad, y: H - 5, "text-anchor": i ? "end" : "start" });
      lbl.textContent = new Date(ts * 1000).toLocaleTimeString(BCP[lang], { hour: "2-digit", minute: "2-digit" }); svg.appendChild(lbl);
    });
  }

  // ---------------------------------------------------------- phone line
  function renderLine(d) {
    var st = d.stats, a = st && st.app, cfg = d.status && d.status.settings;
    var tb = $("line-detail"), r = $("router"), ch = $("checks");
    tb.innerHTML = ""; r.innerHTML = ""; ch.innerHTML = "";
    var regs = (st && st.registrations) || [];
    $("reregister").hidden = !regs.length;
    if (a && a.line) {
      kv(tb, t("Provider"), a.line.connected ? (a.line.provider || t("Connected")) : chip(t("Not connected — calls stay inside the house"), ""));
      if (a.line.numbers && a.line.numbers.length) kv(tb, t("Numbers"), a.line.numbers.join(", "));
      if (a.line.credit) kv(tb, t("Credit"), a.line.credit);
      if (a.line.connected) {
        if (!regs.length) kv(tb, t("Registration"), el("span", t("None — your provider sends calls to this address, so there's nothing to register."), "muted"));
        regs.forEach(function (g) { kv(tb, t("Registration") + " · " + g.name, chip(g.status === "Registered" ? t("Registered") : t(g.status), g.status === "Registered" ? "ok" : "bad")); });
      }
    } else kv(tb, t("Phone line"), el("span", t("Its details appear once twocans is running."), "muted"));
    if (cfg) {
      r.appendChild(el("p", t("For calls from your phone line to reach the house, your router forwards these to this Synology ({address}):", { address: cfg.address }), "muted"));
      var table = el("table"), body = el("tbody"); table.appendChild(body);
      kv(body, t("The phone line"), el("code", cfg.trunk_port + " UDP"));
      kv(body, t("Call audio"), el("code", cfg.rtp_start + "–" + cfg.rtp_end + " UDP"));
      kv(body, t("HTTPS, for the app from outside"), el("code", cfg.https_port + " TCP"));
      r.appendChild(table);
      r.appendChild(el("p", t("Phones inside the house need nothing forwarded. In twocans, Phone line → Opening the router has more."), "muted small"));
    }
    var checks = (a && a.checks) || [], bad = checks.filter(function (c) { return !c.ok; });
    $("checks-title").textContent = checks.length ? t("twocans' own checks") + " — " + (bad.length ? tp("{n} problems", bad.length) : t("all {n} pass", { n: checks.length })) : t("twocans' own checks");
    checks.forEach(function (c) { row(ch, [cell(c.label, "k"), chip(c.ok ? t("OK") : t("Problem"), c.ok ? "ok" : "bad"), cell(c.detail, "muted small")]); });
  }

  // ----------------------------------------------------------- resources
  var kbOf = function (s) {   // "61.45MiB / 14.8GiB" → KB used
    var m = /([\d.]+)\s*([KMGT]i?B|B)/.exec(s || ""); if (!m) return 0;
    return parseFloat(m[1]) * ({ B: 1 / 1024, KiB: 1, KB: 1, MiB: 1024, MB: 1024, GiB: 1048576, GB: 1048576, TiB: 1073741824 }[m[2]] || 1);
  };
  function gauge(parent, pct, big, label) {
    var g = el("div", null, "gauge"), c = 2 * Math.PI * 30;
    g.innerHTML = '<svg viewBox="0 0 72 72"><circle class="track" cx="36" cy="36" r="30" fill="none" stroke-width="8"/><circle class="val ' + (pct > 90 ? "bad" : pct > 75 ? "warn" : "") + '" cx="36" cy="36" r="30" fill="none" stroke-width="8" stroke-linecap="round" stroke-dasharray="' + (c * Math.min(100, pct) / 100).toFixed(1) + " " + c.toFixed(1) + '"/></svg>';
    var d = el("div"); d.appendChild(el("b", big)); d.appendChild(el("span", label)); g.appendChild(d); parent.appendChild(g);
  }
  function renderResources(d) {
    var r = d.resources, gs = $("gauges"), use = $("usage"), space = $("space");
    gs.innerHTML = ""; use.innerHTML = ""; space.innerHTML = "";
    var st = d.stats, off = st && st.transcription === "off";
    $("transcribe").checked = !off; $("transcribe").disabled = d.role !== "admin" || !!(d.status && d.status.busy) || !!d.pending;
    var failed = st && st.app && st.app.transcripts_failed;
    $("retry-row").hidden = !failed || d.role !== "admin"; $("retry-text").textContent = failed ? tp("{n} recordings not written down", failed) : "";
    if (!r) return;
    var total = r.memory_kb.total, used = total - r.memory_kb.available;
    var ours = r.parts.reduce(function (n, p) { return n + kbOf(p.mem); }, 0);
    gauge(gs, total ? 100 * used / total : 0, total ? Math.round(100 * used / total) + "%" : "–", t("of the Synology's {total} memory in use", { total: bytes(total) }));
    gauge(gs, total ? 100 * ours / total : 0, bytes(ours), t("of it taken by twocans"));
    var dt = r.disk_kb.total, df = r.disk_kb.free;
    if (dt) gauge(gs, 100 * (dt - df) / dt, bytes(df), t("free on the volume"));
    $("transcribe-note").textContent = total && total < 4 * 1048576 && !off
      ? t("It uses the most memory of anything in twocans — on a Synology with {total}, the base model is the lighter one. Off, recordings and voicemails are kept, just not written down.", { total: bytes(total) })
      : t("It uses the most memory of anything in twocans. Off, recordings and voicemails are kept, just not written down.");
    memChart(d.memory && d.memory.samples, total);

    r.parts.slice().sort(function (x, y) { return kbOf(y.mem) - kbOf(x.mem); }).forEach(function (p) {
      var kb = kbOf(p.mem);
      row(use, [partName(p.name), cell(p.cpu, "n"), cell(meter(ours ? 100 * kb / ours : 0)), cell(bytes(kb), "n")]);
    });
    var z = r.sizes_kb || {};
    [["twocans' folder, all told", "."], ["Call recordings", "docker/asterisk/recordings"], ["Voicemail", "docker/asterisk/voicemail"], ["Backups", "storage/backups"], ["Photos", "storage/photos"]]
      .forEach(function (s) { if (z[s[1]] != null) kv(space, t(s[0]), bytes(z[s[1]]), true); });
  }

  // ------------------------------------------------------------ settings
  var prefsShown = false, viewersShown = false;
  function renderSettings(d) {
    if (d.prefs && !prefsShown) {
      document.querySelectorAll("[data-pref]").forEach(function (i) { i.checked = !!d.prefs[i.dataset.pref]; });
      prefsShown = true;
    }
    document.querySelector('[data-pref="notify_phones"]').disabled = !document.querySelector('[data-pref="notify"]').checked;
    if (d.viewers && !viewersShown) { $("viewers").value = d.viewers.join(", "); viewersShown = true; }
    var x = d["export"], busy = d.status && d.status.busy === "making an export", get = $("get-export");
    document.querySelectorAll("[data-export]").forEach(function (b) { b.disabled = busy || !!d.pending || d.state !== "running"; });
    $("export-note").textContent = busy ? t("Gathering — with recordings, this takes a while…") : x ? t("Made {when}", { when: ago(d.now - x.at) }) + " · " + bytes(x.bytes / 1024) + (x.audio ? "" : " · " + t("without audio")) : "";
    get.hidden = !x || busy; get.href = withToken("api.cgi?action=export");
  }
  document.querySelectorAll("[data-pref]").forEach(function (i) {
    i.addEventListener("change", function () {
      var body = { action: "prefs" };
      document.querySelectorAll("[data-pref]").forEach(function (o) { body[o.dataset.pref] = o.checked ? "1" : "0"; });
      api("action=request", body).then(poll).catch(function (e) { message(e.message, "bad"); });
    });
  });
  $("viewers-form").addEventListener("submit", function (e) {
    e.preventDefault();
    api("action=request", { action: "viewers", users: $("viewers").value })
      .then(function () { $("viewers-saved").textContent = t("Saved."); viewersShown = false; poll(); })
      .catch(function (err) { $("viewers-saved").textContent = err.message; });
  });
  document.querySelectorAll("[data-export]").forEach(function (b) {
    b.addEventListener("click", function () { ask({ action: "export", audio: b.dataset["export"] }); });
  });
  $("transcribe").addEventListener("change", function () {
    var on = this.checked;
    ask({ action: "transcription", value: on ? "on" : "off" }, on ? "" : t("Turn speech-to-text off? Recordings and voicemails are kept, just not written down.")).then(function (sent) { if (!sent) $("transcribe").checked = !on; });
  });

  // --------------------------------------------------------------- https
  var certChoice = null;
  function certNames(c) { return (c.names || []).filter(function (n) { return n.indexOf("*") < 0; }); }
  function suggestName(c) {
    var plain = certNames(c);
    if (plain.length) return plain[0];
    var wild = (c.names || []).filter(function (n) { return n.indexOf("*.") === 0; })[0];
    return wild ? "twocans." + wild.slice(2) : "";
  }
  function renderHttps(d) {
    var x = d.certs, h = d.stats && d.stats.app && d.stats.app.https;
    var port = d.status && d.status.settings ? d.status.settings.https_port : "443";
    $("https-hint").textContent = t("One the certificate is for. From outside the house, it reaches twocans on port {port}, which your router forwards here.", { port: port });
    var nowBox = $("https-now"); nowBox.innerHTML = "";
    if (h && h.exists) {
      nowBox.className = "note " + (h.selfSigned ? "warn" : h.daysLeft < 14 ? "warn" : "good");
      nowBox.textContent = h.selfSigned
        ? t("twocans serves HTTPS with a certificate of its own making, which browsers warn about — choose one of DSM's below.")
        : t("twocans serves HTTPS with a certificate from {issuer}, valid until {date}", { issuer: h.issuer, date: new Date(h.validToTs * 1000).toLocaleDateString(BCP[lang], { day: "numeric", month: "long", year: "numeric" }) })
          + " (" + tp("{n} days", h.daysLeft) + ")" + (x && x.chosen ? " — " + t("from DSM, followed through its renewals.") : ".");
    } else { nowBox.className = "note"; nowBox.textContent = t("What twocans serves HTTPS with shows once it's running."); }
    $("https-stop").hidden = !(x && x.chosen);
    $("https-note").textContent = x && x.error ? x.error : "";
    var list = $("cert-list");
    if (!x) { list.innerHTML = ""; return; }
    if (certChoice === null) certChoice = x.chosen || "";
    list.innerHTML = "";
    [{ id: "default" }].concat(x.certs).forEach(function (c) {
      var cert = c.id === "default" ? x.certs.filter(function (k) { return k["default"]; })[0] : c;
      var tr = el("tr"), radio = el("input"); radio.type = "radio"; radio.name = "cert"; radio.value = c.id; radio.checked = certChoice === c.id;
      tr.appendChild(cell(radio));
      var info = el("div");
      if (c.id === "default") {
        info.appendChild(el("div", t("Whichever DSM uses as its default"), "names"));
        if (cert) info.appendChild(el("div", t("Today: {names}", { names: (cert.names || []).join(", ") }), "muted small"));
      } else {
        info.appendChild(el("div", (c.names || []).slice(0, 3).join(", ") + ((c.names || []).length > 3 ? " " + t("and {n} more", { n: c.names.length - 3 }) : ""), "names"));
        var tags = el("div", null, "tags");
        tags.appendChild(el("span", c.self_signed ? t("Self-signed") : (c.issuer || t("Unknown issuer")), "tag"));
        tags.appendChild(el("span", c.until < d.now ? t("Expired") : t("Until {date}", { date: new Date(c.until * 1000).toLocaleDateString(BCP[lang]) }), "tag"));
        if (c["default"]) tags.appendChild(el("span", t("DSM's default"), "tag"));
        if (c.desc) tags.appendChild(el("span", c.desc, "tag"));
        info.appendChild(tags);
      }
      tr.appendChild(cell(info));
      tr.addEventListener("click", function () { radio.checked = true; pick(cert, c.id); });
      list.appendChild(tr);
    });
    if (!x.certs.length) row(list, [cell(""), cell(t("DSM has no certificates yet — add one in Control Panel → Security → Certificate."), "muted")]);
    showUrl();
  }
  function pick(cert, id) {
    certChoice = id;
    var dl = $("https-names"); dl.innerHTML = "";
    if (cert) certNames(cert).forEach(function (n) { var o = el("option"); o.value = n; dl.appendChild(o); });
    var name = $("https-name");
    if (cert && !$("https-form").dataset.touched) name.value = (data.certs && data.certs.domain && id === data.certs.chosen) ? data.certs.domain : suggestName(cert);
    showUrl();
  }
  function showUrl() {
    var port = data.status && data.status.settings ? data.status.settings.https_port : "443";
    $("https-make").textContent = t("Make {url} twocans' address", { url: "https://" + ($("https-name").value || "…") + (port === "443" ? "" : ":" + port) });
  }
  $("https-name").addEventListener("input", function () { $("https-form").dataset.touched = "1"; showUrl(); });
  $("https-form").addEventListener("submit", function (e) {
    e.preventDefault();
    if (!certChoice) { $("https-note").textContent = t("Choose a certificate first."); return; }
    var f = new FormData($("https-form"));
    ask({ action: "https", cert: certChoice, domain: String(f.get("domain") || "").trim().toLowerCase(), address: f.get("address") ? "1" : "0" },
      t("Use this certificate for twocans' HTTPS?") + (f.get("address") ? " " + t("twocans restarts briefly with its new address.") : ""));
  });
  $("https-stop").addEventListener("click", function () {
    ask({ action: "https", cert: "none" }, t("Stop using DSM's certificate? twocans goes back to its own, and its address to what it was.")).then(function (sent) { if (sent) certChoice = ""; });
  });

  // ---------------------------------------------------------------- poll
  function poll() {
    return api("action=status" + (tab === "live" ? "&live=1" : "")).then(function (d) {
      data = d;
      var admin = d.role === "admin";
      document.querySelectorAll("[data-admin]").forEach(function (e) { e.hidden = !admin; });
      $("viewonly").hidden = admin;
      if (!admin && (tab === "settings" || tab === "logs")) showTab("overview");
      renderOverview(d); renderChecklist(d); renderUpdate(d); renderLive(d); renderPhones(d); renderActivity(d); renderLine(d); renderResources(d);
      if (admin) { renderSettings(d); renderReport(d); renderHttps(d); }
    }).catch(function (e) { hero("bad", "alert", t("Can't reach twocans"), e.message); });
  }

  // ------------------------------------------------------------- actions
  function ask(body, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return Promise.resolve(false);
    document.querySelectorAll("[data-action]").forEach(function (b) { b.disabled = true; });
    return api("action=request", body).then(function () { poll(); return true; })
      .catch(function (e) { message(e.message, "bad"); poll(); return false; });
  }
  document.querySelectorAll("[data-action]").forEach(function (b) {
    b.addEventListener("click", function () {
      var a = b.dataset.action;
      ask({ action: a },
        a === "restart" ? t("Restart twocans?") + "\n\n" + cutOff()
        : a === "setup" ? t("Set twocans up again? It fetches the newest images and restarts.") + "\n\n" + cutOff() : "");
    });
  });
  $("form").addEventListener("submit", function (e) {
    e.preventDefault();
    var body = { action: "settings" };
    new FormData($("form")).forEach(function (v, k) { body[k] = String(v).trim(); });
    ask(body, t("Save these settings and set twocans up again with them? It restarts.") + "\n\n" + cutOff()).then(function (sent) { if (sent) showTab("overview"); });
  });

  // ---------------------------------------------------------- owner reset
  $("owner-form").addEventListener("submit", function (e) {
    e.preventDefault();
    var f = new FormData($("owner-form")), out = $("owner-result");
    var body = { action: "owner", email: String(f.get("email") || "").trim(), passkeys: f.get("passkeys") ? "1" : "0", signout: f.get("signout") ? "1" : "0" };
    ask(body, t("Give the Owner account a new password?")).then(function (sent) {
      if (!sent) return;
      out.hidden = false; out.className = "note"; out.textContent = t("Resetting…");
      var tries = 0, wait = setInterval(function () {
        api("action=owner").then(function (r) {
          clearInterval(wait); out.innerHTML = "";
          if (!r.ok) { out.className = "note bad"; out.textContent = r.error || t("That didn't work."); return; }
          out.className = "note good";
          var d = el("div");
          d.appendChild(el("div", t("{name} can now sign in as {email} with this password — shown once, so note it now:", { name: r.name, email: r.email })));
          d.appendChild(el("div", r.password, "secret"));
          d.appendChild(el("div", t("Then change it in twocans, under your account.") + (r.passkeysRemoved ? " " + tp("{n} passkeys removed.", r.passkeysRemoved) : "") + (r.signedOut ? " " + t("Every browser was signed out.") : "")));
          out.appendChild(d);
          $("owner-form").reset();
        }).catch(function () { if (++tries > 30) { clearInterval(wait); out.className = "note bad"; out.textContent = t("No answer from twocans — the Logs may say why."); } });
      }, 2000);
    });
  });

  // ----------------------------------------------------------------- logs
  var logPart = "setup";
  document.querySelectorAll("#log-parts button").forEach(function (b) {
    b.addEventListener("click", function () {
      logPart = b.dataset.part;
      document.querySelectorAll("#log-parts button").forEach(function (o) { o.setAttribute("aria-pressed", o === b ? "true" : "false"); });
      $("logtext").dataset.loaded = ""; $("logtext").textContent = "…";
      $("download").hidden = logPart !== "setup";
      loadLog();
    });
  });
  function loadLog() {
    var pre = $("logtext");
    api("action=log&part=" + logPart).then(function (txt) {
      var atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 20;
      pre.textContent = txt || t("Nothing logged yet.");
      if (atEnd || pre.dataset.loaded !== "1") pre.scrollTop = pre.scrollHeight;
      pre.dataset.loaded = "1";
    }).catch(function (e) { pre.textContent = e.message; });
  }
  $("download").addEventListener("click", function () { this.href = withToken("api.cgi?action=download"); });
  setInterval(function () { if (tab === "logs") loadLog(); }, 4000);
  $("make-report").addEventListener("click", function () { ask({ action: "report" }); });
  function renderReport(d) {
    var busy = d.status && d.status.busy === "making a support report", get = $("get-report");
    $("make-report").disabled = busy || !!d.pending || d.state !== "running";
    $("report-note").textContent = busy ? t("Gathering — this takes a minute…") : d.report ? t("Made {when}", { when: ago(d.now - d.report) }) + "." : "";
    get.hidden = !d.report || busy;
    get.href = withToken("api.cgi?action=report");
  }

  // ---------------------------------------------------------------- start
  function fillLanguages() {
    var sel = $("lang"); sel.innerHTML = "";
    var follow = el("option", t("Follow DSM ({lang})", { lang: LANGS[dsmLang()] })); follow.value = ""; sel.appendChild(follow);
    ["enu", "ger", "fre", "spn", "nld", "ita", "ptb", "plk"].forEach(function (c) { var o = el("option", LANGS[c]); o.value = c; sel.appendChild(o); });
    sel.value = stored();
  }
  $("lang").addEventListener("change", function () {
    try { if (this.value) localStorage.setItem("twocans-lang", this.value); else localStorage.removeItem("twocans-lang"); } catch (e) { /* this time only */ }
    start();
  });
  function start() {
    return loadLanguage(stored() || dsmLang()).then(function () {
      translatePage(); fillLanguages();
      $("title").textContent = t(TITLES[tab]);
      $("hero-title").textContent = t("Checking…");
      document.querySelectorAll("#recent").forEach(function (r) { r.dataset.sig = ""; });
      return poll();
    });
  }
  start();
  setInterval(poll, 3000);
})();
