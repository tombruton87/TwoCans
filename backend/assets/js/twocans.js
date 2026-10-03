/**
 * twocans — progressive enhancement only.
 *
 * Every control works without JavaScript (plain forms + POST/redirect/GET).
 * This file adds the small niceties the design calls for on top.
 */
(function () {
  'use strict';

  /* Logo "tug": replay the SMIL spring on click, as the design specifies. */
  document.addEventListener('click', function (ev) {
    var logo = ev.target.closest('[data-tc-logo]');
    if (!logo) return;
    var svg = logo.querySelector('svg');
    if (!svg) return;
    svg.querySelectorAll('animate, animateTransform').forEach(function (a) {
      try { a.beginElement(); } catch (_) { /* browser without SMIL */ }
    });
  });

  /* Inline edits (device name, hours, message wording) save on change/blur. */
  document.addEventListener('change', function (ev) {
    var field = ev.target.closest('[data-tc-autosave]');
    if (!field) return;
    var form = field.form;
    if (form) form.requestSubmit();
  });

  /*
   * Icon picker — see views/partials/icon_picker.php. The list is loaded once,
   * on first open, and shared by every picker on the page. Only the first
   * 240 matches are drawn: enough to scroll, few enough to stay quick.
   */
  var iconList = null;
  var iconListLoading = null;
  var ICON_LIMIT = 240;
  function loadIcons() {
    if (iconList) return Promise.resolve(iconList);
    if (!iconListLoading) {
      iconListLoading = fetch('/assets/vendor/fontawesome/icons.json')
        .then(function (r) { return r.json(); })
        .then(function (data) { iconList = data; return data; });
    }
    return iconListLoading;
  }
  var STYLE = { s: 'fa-solid', r: 'fa-regular', b: 'fa-brands' };
  function drawIcons(picker) {
    var grid = picker.querySelector('[data-tc-iconpick-grid]');
    var count = picker.querySelector('[data-tc-iconpick-count]');
    var q = picker.querySelector('[data-tc-iconpick-search]').value.trim().toLowerCase();
    var cat = picker.querySelector('[data-tc-iconpick-cat]').value;
    var current = picker.querySelector('[data-tc-iconpick-value]').value;
    var words = q ? q.split(/\s+/) : [];
    var matches = iconList.icons.filter(function (i) {
      if (cat && i.c.indexOf(cat) === -1) return false;
      if (!words.length) return true;
      var hay = (i.n + ' ' + i.l + ' ' + i.t).toLowerCase();
      return words.every(function (w) { return hay.indexOf(w) !== -1; });
    });
    // Brand logos are rarely what a family wants; list them after the rest.
    matches.sort(function (a, b) { return (a.s === 'b') - (b.s === 'b'); });
    var html = '';
    matches.slice(0, ICON_LIMIT).forEach(function (i) {
      var cls = STYLE[i.s] + ' fa-' + i.n;
      html += '<button type="button" class="tc-iconpick__opt' + (cls === current ? ' is-on' : '') +
        '" data-tc-icon="' + cls + '" title="' + i.l.replace(/"/g, '&quot;') + '" role="option">' +
        '<i class="' + cls + '" aria-hidden="true"></i></button>';
    });
    grid.innerHTML = html || '<span class="tc-micro">Nothing matches — try another word.</span>';
    count.textContent = matches.length > ICON_LIMIT
      ? 'Showing ' + ICON_LIMIT + ' of ' + matches.length + ' — search to narrow it down'
      : matches.length + ' icon' + (matches.length === 1 ? '' : 's');
  }
  document.addEventListener('click', function (ev) {
    var toggle = ev.target.closest('[data-tc-iconpick-toggle]');
    if (toggle) {
      var picker = toggle.closest('[data-tc-iconpick]');
      var panel = picker.querySelector('[data-tc-iconpick-panel]');
      var opening = panel.hidden;
      panel.hidden = !opening;
      toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) {
        loadIcons().then(function (data) {
          var sel = picker.querySelector('[data-tc-iconpick-cat]');
          if (sel.options.length === 1) {
            data.categories.forEach(function (c) {
              var o = document.createElement('option');
              o.value = c.k; o.textContent = c.l; sel.appendChild(o);
            });
          }
          drawIcons(picker);
          picker.querySelector('[data-tc-iconpick-search]').focus();
        }).catch(function () {
          picker.querySelector('[data-tc-iconpick-grid]').innerHTML =
            '<span class="tc-micro">Couldn\'t load the icons — the emoji above still work.</span>';
        });
      }
      return;
    }
    var opt = ev.target.closest('[data-tc-icon]');
    if (opt && opt.closest('[data-tc-iconpick]')) {
      var p = opt.closest('[data-tc-iconpick]');
      var value = opt.getAttribute('data-tc-icon');
      p.querySelector('[data-tc-iconpick-value]').value = value;
      p.querySelector('[data-tc-iconpick-shown]').innerHTML = value.indexOf('fa-') === 0
        ? '<i class="' + value + '" aria-hidden="true"></i>'
        : '<span aria-hidden="true">' + value + '</span>';
      p.querySelector('[data-tc-iconpick-panel]').hidden = true;
      p.querySelector('[data-tc-iconpick-toggle]').setAttribute('aria-expanded', 'false');
    }
  });
  document.addEventListener('input', function (ev) {
    var picker = ev.target.closest('[data-tc-iconpick]');
    if (picker && iconList && (ev.target.matches('[data-tc-iconpick-search]'))) drawIcons(picker);
  });
  document.addEventListener('change', function (ev) {
    var picker = ev.target.closest('[data-tc-iconpick]');
    if (picker && iconList && ev.target.matches('[data-tc-iconpick-cat]')) drawIcons(picker);
  });

  /*
   * Passkeys — Face ID, Touch ID or a fingerprint. The box speaks base64url;
   * the browser's WebAuthn API speaks ArrayBuffers. See src/WebAuthn.php.
   */
  var hasPasskeys = !!(window.PublicKeyCredential && window.isSecureContext && navigator.credentials);
  function toBuf(b64url) {
    var s = b64url.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out.buffer;
  }
  function toB64(buf) {
    if (!buf) return '';
    var bytes = new Uint8Array(buf), bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function passkeyPost(action, fields) {
    var body = new FormData();
    var token = document.querySelector('input[name="_csrf"]');
    body.append('_csrf', token ? token.value : '');
    body.append('action', action);
    Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
    return fetch('/', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }
  function passkeyMessage(el, text) {
    if (!el) return;
    el.textContent = text;
    el.hidden = !text;
  }
  function passkeyError(err) {
    // Cancelling the Face ID prompt is not an error worth a red box.
    if (err && err.name === 'NotAllowedError') return '';
    if (err && err.name === 'InvalidStateError') return 'This device is already set up.';
    return (err && err.message) || 'That didn\'t work — try again.';
  }

  var loginBtn = document.querySelector('[data-tc-passkey-login]');
  if (loginBtn && hasPasskeys) {
    loginBtn.hidden = false;
    loginBtn.addEventListener('click', function () {
      var status = document.querySelector('[data-tc-passkey-status]');
      passkeyMessage(status, '');
      passkeyPost('passkey_login_options').then(function (res) {
        if (!res.ok) throw new Error(res.error);
        var o = res.options;
        return navigator.credentials.get({ publicKey: {
          challenge: toBuf(o.challenge), rpId: o.rpId, timeout: o.timeout,
          userVerification: o.userVerification, allowCredentials: []
        } });
      }).then(function (cred) {
        return passkeyPost('passkey_login', { credential: JSON.stringify({
          id: toB64(cred.rawId),
          response: {
            clientDataJSON: toB64(cred.response.clientDataJSON),
            authenticatorData: toB64(cred.response.authenticatorData),
            signature: toB64(cred.response.signature),
            userHandle: toB64(cred.response.userHandle)
          }
        }) });
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error);
        window.location.href = res.redirect;
      }).catch(function (err) { passkeyMessage(status, passkeyError(err)); });
    });
  }

  var addBtn = document.querySelector('[data-tc-passkey-add]');
  if (addBtn) {
    var addStatus = document.querySelector('[data-tc-passkey-status]');
    if (!hasPasskeys) {
      addBtn.disabled = true;
      passkeyMessage(addStatus, 'This browser can\'t use passkeys.');
    }
    addBtn.addEventListener('click', function () {
      passkeyMessage(addStatus, '');
      addBtn.disabled = true;
      passkeyPost('passkey_register_options').then(function (res) {
        if (!res.ok) throw new Error(res.error);
        var o = res.options;
        o.challenge = toBuf(o.challenge);
        o.user.id = toBuf(o.user.id);
        o.excludeCredentials = (o.excludeCredentials || []).map(function (c) {
          return { type: c.type, id: toBuf(c.id) };
        });
        return navigator.credentials.create({ publicKey: o });
      }).then(function (cred) {
        return passkeyPost('passkey_register', { credential: JSON.stringify({
          id: toB64(cred.rawId),
          response: {
            clientDataJSON: toB64(cred.response.clientDataJSON),
            attestationObject: toB64(cred.response.attestationObject)
          }
        }) });
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error);
        window.location.href = res.redirect;
      }).catch(function (err) {
        addBtn.disabled = false;
        passkeyMessage(addStatus, passkeyError(err));
      });
    });
  }

  /*
   * Text boxes that grow to fit their words (jokes' titles): one line when the
   * words fit, more when they don't. Enter saves instead of adding a line.
   */
  function autogrow(el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }
  document.querySelectorAll('[data-tc-autogrow]').forEach(autogrow);
  window.addEventListener('resize', function () { document.querySelectorAll('[data-tc-autogrow]').forEach(autogrow); });
  document.addEventListener('input', function (ev) { if (ev.target.matches('[data-tc-autogrow]')) autogrow(ev.target); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.matches('[data-tc-autogrow]')) { ev.preventDefault(); ev.target.blur(); }
  });

  /* Confirmation dialogs: a button names the <dialog> it opens. */
  document.addEventListener('click', function (ev) {
    var opener = ev.target.closest('[data-tc-dialog-open]');
    if (opener) {
      var dlg = document.getElementById(opener.getAttribute('data-tc-dialog-open'));
      if (dlg && dlg.showModal) dlg.showModal();
      return;
    }
    var closer = ev.target.closest('[data-tc-dialog-close]');
    if (closer) {
      var d = closer.closest('dialog');
      if (d) d.close();
      return;
    }
    // A click on the dimmed backdrop (the dialog itself, outside its body) closes it.
    if (ev.target.tagName === 'DIALOG' && ev.target.classList.contains('tc-dialog')) ev.target.close();
  });

  /* Installable app: the service worker only runs on HTTPS (or localhost). */
  if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('/sw.js').catch(function () { /* the site still works without it */ });
  }

  /* Schedule editor: add a row of days and times, or take one away. */
  document.addEventListener('click', function (ev) {
    var add = ev.target.closest('[data-tc-sched-add]');
    if (add) {
      var box = add.closest('[data-tc-schedule]');
      var tpl = box.querySelector('[data-tc-sched-template]');
      var rows = box.querySelector('[data-tc-sched-rows]');
      var index = 'n' + Date.now();
      var holder = document.createElement('div');
      holder.innerHTML = tpl.innerHTML.split('__i__').join(index);
      var row = holder.firstElementChild;
      rows.appendChild(row);
      var first = row.querySelector('input');
      if (first) first.focus();
      return;
    }
    var remove = ev.target.closest('[data-tc-sched-remove]');
    if (remove) {
      var r = remove.closest('[data-tc-sched-row]');
      var all = r.parentNode.querySelectorAll('[data-tc-sched-row]');
      if (all.length > 1) {
        r.remove();
      } else {
        // The last row stays, emptied: a schedule needs somewhere to start.
        r.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
        r.querySelectorAll('input[type=time]').forEach(function (t) { t.value = ''; });
      }
    }
  });

  /* GHP621 hotkeys: the phone's face shows each key's label as it's picked,
     and lights the key whose picker has focus. */
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest('[data-tc-gskey]');
    if (!sel) return;
    var key = document.querySelector('[data-tc-gsface-key="' + sel.getAttribute('data-tc-gskey') + '"]');
    if (!key) return;
    var label = sel.options[sel.selectedIndex].getAttribute('data-label') || '';
    key.querySelector('.tc-gsface__label').textContent = label || sel.getAttribute('data-tc-gskey');
    key.classList.toggle('is-empty', label === '');
  });
  ['focusin', 'focusout'].forEach(function (type) {
    document.addEventListener(type, function (ev) {
      var sel = ev.target.closest && ev.target.closest('[data-tc-gskey]');
      if (!sel) return;
      var key = document.querySelector('[data-tc-gsface-key="' + sel.getAttribute('data-tc-gskey') + '"]');
      if (key) key.classList.toggle('is-active', type === 'focusin');
    });
  });

  /* The faceplate preview beside a desk phone's hotkeys: drawn from what each
     key dials now, and redrawn as a key's picker changes — see
     views/partials/faceplate_preview.php. */
  var fpShrink = function (el) {
    el.style.fontSize = '';
    var size = parseFloat(getComputedStyle(el).fontSize), min = size * 0.55;
    while (el.scrollWidth > el.clientWidth && size > min) { size -= 0.25; el.style.fontSize = size + 'px'; }
  };
  var fpDraw = function (view, index, number) {
    var choices = view._tcChoices || {};
    var slot = view.querySelector('[data-tc-fpview-key="' + index + '"]');
    if (!slot) return;
    var k = number ? choices[number] : null;
    slot.hidden = !k;
    if (!k) return;
    var photo = slot.querySelector('.tc-fpview__photo');
    var name = slot.querySelector('.tc-fpview__name');
    photo.textContent = '';
    photo.style.background = k.color || 'var(--tc-ink-4)';
    if (k.photo) {
      var img = document.createElement('img');
      img.src = k.photo; img.alt = '';
      photo.appendChild(img);
    } else if (k.icon) {
      var icon = document.createElement('i');
      icon.className = k.icon;
      photo.appendChild(icon);
    } else {
      photo.textContent = k.initial || '';
    }
    name.textContent = k.name;
    fpShrink(name);
  };
  document.querySelectorAll('[data-tc-fpview]').forEach(function (view) {
    try {
      view._tcChoices = JSON.parse(view.querySelector('[data-tc-fpview-choices]').textContent);
      var now = JSON.parse(view.querySelector('[data-tc-fpview-now]').textContent);
      Object.keys(now).forEach(function (i) { fpDraw(view, i, now[i]); });
    } catch (e) { /* no preview, the page still works */ }
  });
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest && ev.target.closest('[data-tc-gskey]');
    if (!sel) return;
    document.querySelectorAll('[data-tc-fpview]').forEach(function (view) {
      fpDraw(view, sel.getAttribute('data-tc-gskey'), sel.value);
    });
  });

  /* Faceplate: print it, and shrink any name too long for its key to fit. */
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-tc-print]')) window.print();
  });
  var fitNames = function () {
    document.querySelectorAll('[data-tc-fit]').forEach(function (el) {
      el.style.fontSize = '';
      var size = parseFloat(getComputedStyle(el).fontSize);
      var min = size * 0.6;
      while (el.scrollWidth > el.clientWidth && size > min) {
        size -= 0.25;
        el.style.fontSize = size + 'px';
      }
    });
  };
  if (document.querySelector('[data-tc-fit]')) {
    fitNames();
    if (document.fonts) document.fonts.ready.then(fitNames);
  }

  /* Account / settings dropdown in the header. Closes on outside click and Escape. */
  document.querySelectorAll('[data-tc-menu]').forEach(function (menu) {
    var toggle = menu.querySelector('[data-tc-menu-toggle]');
    var panel = menu.querySelector('[data-tc-menu-panel]');
    if (!toggle || !panel) return;

    var setOpen = function (open) {
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', function (ev) {
      ev.stopPropagation();
      setOpen(panel.hidden);
    });

    document.addEventListener('click', function (ev) {
      if (!menu.contains(ev.target)) setOpen(false);
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') setOpen(false);
    });
  });

  /* Show which file was picked for an audio upload — the joke line and the
     message a refused caller hears use the same picker, and what is played back
     is what was converted, not what was chosen. Without this the button still
     says "Choose a file" after choosing one, which reads as a failure. */
  document.addEventListener('change', function (ev) {
    var picker = ev.target.closest('[data-tc-audiofile]');
    if (!picker) return;
    var label = picker.closest('label');
    var name = label && label.querySelector('[data-tc-filename]');
    if (name && picker.files && picker.files.length) {
      name.textContent = picker.files[0].name;
    }
  });

  /* Live call timer, ticking from the server-supplied start time. */
  var timers = document.querySelectorAll('[data-tc-elapsed]');
  if (timers.length) {
    var startedAt = Number(timers[0].getAttribute('data-tc-elapsed')) * 1000;
    var tick = function () {
      var secs = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
      var text = Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');
      timers.forEach(function (el) { el.textContent = text; });
    };
    tick();
    setInterval(tick, 1000);
  }

  /**
   * Wizard "waiting" steps auto-advance.
   * TODO(wire): replace the timer with polling the real provisioning /
   * credential-verification endpoint, and advance when it reports success.
   */
  var advance = document.querySelector('[data-tc-advance]');
  if (advance) {
    var delay = Number(advance.getAttribute('data-tc-delay')) || 2400;
    setTimeout(function () { advance.requestSubmit ? advance.requestSubmit() : advance.submit(); }, delay);
  }

  /* Esc closes whichever modal is open. */
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    var close = document.querySelector('[data-tc-close]');
    if (close) window.location.href = close.getAttribute('data-tc-close');
  });

  /* Clicking the dimmed backdrop closes the modal; clicks inside do not. */
  document.addEventListener('click', function (ev) {
    var modal = ev.target.closest('[data-tc-modal]');
    if (!modal || ev.target !== modal) return;
    window.location.href = modal.getAttribute('data-tc-modal');
  });

  /* Copy-to-clipboard for the SIP credentials. */
  document.addEventListener('click', function (ev) {
    var button = ev.target.closest('[data-tc-copy]');
    if (!button) return;
    var text = button.getAttribute('data-tc-copy');

    var done = function () {
      button.classList.add('is-copied');
      var was = button.textContent;
      button.textContent = '✓';
      setTimeout(function () {
        button.classList.remove('is-copied');
        button.textContent = was;
      }, 1200);
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done);
      return;
    }
    /* Plain http on a LAN has no clipboard API — fall back to a hidden field. */
    var field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();
    try { document.execCommand('copy'); done(); } catch (_) { /* user can select it */ }
    field.remove();
  });

  /*
   * Live device status.
   *
   * A phone can take a few seconds to sign in, and a parent watching the setup
   * screen shouldn't have to reload to find out whether it worked. Polls while
   * the tab is visible and backs off when it isn't, so a page left open on a
   * spare screen doesn't hammer Asterisk all day.
   */
  var statusBoxes = document.querySelectorAll('[data-tc-device-status]');
  if (statusBoxes.length) {
    /* One card marked up per phone on the list page, one header on the detail
       page — the same code drives both. */
    var boxes = {};
    statusBoxes.forEach(function (box) {
      boxes[box.getAttribute('data-tc-device-status')] = box;
    });

    /* The detail page is about one phone, and its bits are spread across the
       header, so it updates the whole page and asks about that phone only. The
       list page asks about every phone in one go and updates card by card. */
    var detail = document.querySelector('[data-tc-device-status][data-tc-device-detail]');
    var endpoint = detail
      ? '/?api=device_status&id=' + encodeURIComponent(detail.getAttribute('data-tc-device-status'))
      : '/?api=device_statuses';
    var inFlight = false;

    var applyStatus = function (data) {
      var box = boxes[String(data.id)];
      if (!box) return;
      var scope = detail ? document : box;

      var pill = scope.querySelector('[data-tc-status-pill]');
      if (pill && pill.textContent.trim() !== data.statusText) {
        pill.textContent = data.statusText;
        /* Swap only the colour, so tc-pill--lg and friends survive. */
        pill.classList.remove('tc-pill--' + pill.getAttribute('data-tc-status-mod'));
        pill.classList.add('tc-pill--' + data.statusMod);
        pill.setAttribute('data-tc-status-mod', data.statusMod);
      }

      var can = scope.querySelector('[data-tc-can]');
      if (can) can.classList.toggle('is-offline', !data.online);

      var seen = scope.querySelector('[data-tc-status-seen]');
      if (seen && seen.textContent.trim() !== data.lastSeenText) {
        seen.textContent = data.lastSeenText;
      }

      /* Detail page only: the wording in the sub-heading, and the test-call
         button, which cannot be pressed while the phone is unreachable. */
      if (detail) {
        document.querySelectorAll('[data-tc-status-text]').forEach(function (el) {
          el.textContent = data.statusText;
        });
        var testCall = document.querySelector('[data-tc-test-call]');
        if (testCall) {
          testCall.disabled = !data.canTestCall;
          testCall.title = data.canTestCall ? '' : 'This phone is not online';
        }
      }
    };

    var poll = function () {
      if (inFlight || document.hidden) return;
      inFlight = true;
      fetch(endpoint, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || data.error) return;
          (data.devices || [data]).forEach(applyStatus);
        })
        .catch(function () { /* transient — the next tick will retry */ })
        .finally(function () { inFlight = false; });
    };

    setInterval(poll, 2000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  }

  /*
   * Recording playback, matching the voicemail design: the circle plays and
   * turns solid teal, the equalizer appears underneath while sound is coming
   * out, and pausing freezes the bars. Clicking the bars seeks by position.
   */
  function bindPlayers(root) {
  root.querySelectorAll('[data-play]').forEach(function (play) {
    if (play.dataset.tcBound) return;
    play.dataset.tcBound = '1';
    /* The audio sits beside the button in the row that owns it. That row is a
       different wrapper on every screen — .tc-call-row in the call log,
       .tc-vm-row on voicemail, the joke line and the phones' refusal messages,
       .tc-request__clip on the dashboard — so find the nearest ancestor holding
       exactly one recording rather than naming one wrapper and leaving every
       other play button silently dead. */
    var row = play.parentElement;
    while (row && row.querySelectorAll('[data-audio]').length !== 1) {
      row = row.parentElement;
    }
    var audio = row && row.querySelector('[data-audio]');
    if (!audio) return;

    /* Full-width rows carry the equalizer; the small dashboard clips do not, so
       it is optional rather than required. */
    var card = row.parentElement;
    var strip = card ? card.querySelector('[data-eq]') : null;

    play.addEventListener('click', function () {
      if (audio.paused) {
        /* One conversation at a time. */
        document.querySelectorAll('[data-audio]').forEach(function (other) {
          if (other !== audio) other.pause();
        });
        audio.play().catch(function () {
          play.disabled = true;
          play.title = 'This recording could not be loaded';
        });
      } else {
        audio.pause();
      }
    });

    audio.addEventListener('playing', function () {
      play.classList.add('is-playing');
      play.textContent = '❚❚';
      if (strip) strip.classList.add('is-open');
    });

    /* The mockup only shows the bars while sound is playing — a paused or
       finished call goes back to the plain row. */
    var quiet = function () {
      play.classList.remove('is-playing');
      play.textContent = '▶';
      if (strip) strip.classList.remove('is-open');
    };
    audio.addEventListener('pause', quiet);
    audio.addEventListener('ended', function () {
      quiet();
      audio.currentTime = 0;
    });

    if (!strip) return;

    strip.addEventListener('click', function (ev) {
      if (!isFinite(audio.duration) || !audio.duration) return;
      var box = strip.getBoundingClientRect();
      audio.currentTime = ((ev.clientX - box.left) / box.width) * audio.duration;
      if (audio.paused) audio.play().catch(function () {});
    });
  });
  }
  bindPlayers(document);

  /* The confirmation pill, for a message that arrives without a page load. */
  function showToast(text) {
    if (!text) return;
    var old = document.querySelector('[data-tc-toast]');
    if (old) old.remove();
    var pill = document.createElement('div');
    pill.className = 'tc-toast';
    pill.setAttribute('role', 'status');
    pill.setAttribute('data-tc-toast', '');
    pill.textContent = text;
    document.body.appendChild(pill);
    setTimeout(function () {
      pill.style.transition = 'opacity .3s';
      pill.style.opacity = '0';
      setTimeout(function () { pill.remove(); }, 320);
    }, 2600);
  }

  /* Something still happening on the server (a scan for phones): look again
     in a few seconds. */
  var reloadAfter = document.querySelector('[data-tc-reload-after]');
  if (reloadAfter) {
    setTimeout(function () { location.reload(); }, 1000 * Number(reloadAfter.getAttribute('data-tc-reload-after') || 3));
  }

  /* A button that does something hard to take back asks first
     (data-tc-confirm="…"). Capture, so it runs before a form is sent. */
  document.addEventListener('submit', function (ev) {
    var by = ev.submitter;
    var ask = by && by.getAttribute('data-tc-confirm');
    if (ask && !window.confirm(ask)) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
    }
  }, true);

  /* An upload shows how it's going: a bar while the file goes up, then a
     wheel while the server prepares it (converting audio takes a moment),
     both read out to a screen reader. Leaving the page while the file is
     still going up would lose it, so the browser asks first. */
  var uploading = 0;
  window.addEventListener('beforeunload', function (ev) {
    if (uploading > 0) { ev.preventDefault(); ev.returnValue = ''; }
  });

  function hasFile(form) {
    return Array.prototype.some.call(form.querySelectorAll('input[type=file]'), function (i) {
      return i.files && i.files.length > 0;
    });
  }

  function uploadStatus(form) {
    var box = document.createElement('div');
    box.className = 'tc-upload';
    box.innerHTML = '<span class="tc-upload__track"><span class="tc-upload__fill"></span></span>'
      + '<span class="tc-upload__spin" aria-hidden="true"></span>'
      + '<span class="tc-upload__text" aria-hidden="true">Uploading…</span>'
      + '<span class="tc-sr-only" role="status" aria-live="polite">Uploading</span>';
    form.appendChild(box);
    var fill = box.querySelector('.tc-upload__fill');
    var text = box.querySelector('.tc-upload__text');
    var said = box.querySelector('[role=status]');
    var quarter = 0;
    var sent = false;
    uploading++;
    var finishUpload = function () { if (!sent) { sent = true; uploading--; } };
    return {
      progress: function (pct) {
        fill.style.width = pct + '%';
        text.textContent = 'Uploading ' + pct + '%';
        // A screen reader hears it a quarter at a time, not every percent.
        if (Math.floor(pct / 25) > quarter && pct < 100) {
          quarter = Math.floor(pct / 25);
          said.textContent = 'Uploading, ' + quarter * 25 + ' percent';
        }
      },
      preparing: function () {
        finishUpload();
        box.classList.add('is-preparing');
        fill.style.width = '100%';
        var audio = form.querySelector('input[type=file][accept*="audio"]');
        text.textContent = said.textContent = form.getAttribute('data-tc-preparing')
          || (audio ? 'Uploaded — preparing the audio…' : 'Uploaded — saving…');
      },
      done: function () { finishUpload(); box.remove(); }
    };
  }

  /* Send a form as the page's script: with progress when there's a file in
     it, plain fetch otherwise. Resolves to the server's answer, or null. */
  function sendForm(form, status) {
    var body = new FormData(form);
    var url = form.getAttribute('action') || '/';
    if (!status) {
      return fetch(url, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Twocans-Ajax': '1', 'Accept': 'application/json' }
      }).then(function (r) { return r.ok ? r.json() : null; });
    }
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', url);
      xhr.withCredentials = true;
      xhr.setRequestHeader('X-Twocans-Ajax', '1');
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) status.progress(Math.min(100, Math.round(e.loaded / e.total * 100)));
      };
      xhr.upload.onload = function () { status.preparing(); };
      xhr.onload = function () {
        if (xhr.status < 200 || xhr.status >= 300) { resolve(null); return; }
        try { resolve(JSON.parse(xhr.responseText)); } catch (e) { resolve(null); }
      };
      xhr.onerror = reject;
      xhr.onabort = reject;
      xhr.send(body);
    });
  }

  /* Forms marked data-tc-ajax save without leaving the page: the server
     answers with its message and where the fresh page is, and only the part of
     the page around the form (data-tc-ajax-region) is swapped for the new one.
     data-tc-ajax-go instead goes to the fresh page once it's saved — for a
     form whose result shows somewhere else on it. Without JavaScript they are
     ordinary forms. */
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.matches || !form.matches('[data-tc-ajax]') || !window.fetch || !window.FormData) return;
    ev.preventDefault();
    if (form.dataset.tcSending) return;
    form.dataset.tcSending = '1';
    form.classList.add('is-sending');
    form.setAttribute('aria-busy', 'true');
    var region = form.closest('[data-tc-ajax-region]');
    var name = form.querySelector('[data-tc-filename]');
    var status = hasFile(form) ? uploadStatus(form) : null;
    // After the picker has shown the file's name, which it does on the same change.
    if (name && status) setTimeout(function () { name.textContent = 'Sending…'; }, 0);

    var failed = function () {
      showToast("Couldn't save that — try again.");
      if (name) name.textContent = 'Choose a file';
    };
    sendForm(form, status)
      .then(function (data) {
        if (!data || !data.ok) { failed(); return null; }
        if (form.hasAttribute('data-tc-ajax-go') && data.location) {
          try { sessionStorage.setItem('tcToast', data.toast || ''); } catch (e) { /* the page still works */ }
          location.href = data.location;
          return null;
        }
        showToast(data.toast);
        if (!region || !region.id || !data.location) return null;
        return fetch(data.location, { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.text() : null; })
          .then(function (html) {
            if (!html) return;
            var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById(region.id);
            if (!fresh) {
              // Saved and finished with (a contact's Save closes its sheet):
              // go where the server said, and say it there.
              try { sessionStorage.setItem('tcToast', data.toast || ''); } catch (e) { /* the page still works */ }
              location.href = data.location;
              return;
            }
            // Keep it as it was on screen: open if it was open.
            if (region.tagName === 'DETAILS') fresh.open = region.open;
            region.replaceWith(fresh);
            bindPlayers(fresh);
            addRecordButtons(fresh);
          });
      })
      .catch(failed)
      .finally(function () {
        if (status) status.done();
        delete form.dataset.tcSending;
        form.classList.remove('is-sending');
        form.removeAttribute('aria-busy');
      });
  });

  /* A list in an order of the household's choosing (data-tc-sortable, naming
     the form that saves it): drag an item by its handle — with a mouse or a
     finger, so pointer events rather than the browser's own drag and drop,
     which a phone doesn't do — or focus the handle and use the up and down
     arrows. The new order is saved as soon as it's let go. */
  var sortOrder = function (list) {
    return Array.prototype.map.call(list.querySelectorAll('[data-tc-sort-id]'), function (el) {
      return el.getAttribute('data-tc-sort-id');
    });
  };
  var sortSave = function (list) {
    var form = document.getElementById(list.getAttribute('data-tc-sortable'));
    if (!form) return;
    var body = new FormData(form);
    sortOrder(list).forEach(function (id) { body.append('ids[]', id); });
    fetch(form.getAttribute('action') || '/', {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Twocans-Ajax': '1', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) { showToast(data && data.ok ? data.toast : "Couldn't save the order — try again."); })
      .catch(function () { showToast("Couldn't save the order — try again."); });
  };
  var sortSay = function (item) {
    var said = document.querySelector('[data-tc-sort-said]');
    var list = item.parentElement;
    if (!said || !list) return;
    var all = list.querySelectorAll('[data-tc-sort-id]');
    var at = Array.prototype.indexOf.call(all, item) + 1;
    var handle = item.querySelector('[data-tc-sort-handle]');
    var name = handle ? handle.getAttribute('aria-label').replace(/^Move /, '').replace(/ — .*$/, '') : 'It';
    said.textContent = name + ', ' + at + ' of ' + all.length;
  };

  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Animate items from where they were to where they are now ("FLIP"): for a
     keyboard move, and for the drop at the end of a drag. */
  var sortGlide = function (items, before) {
    if (calm) return;
    items.forEach(function (el) {
      var was = before.get(el);
      if (was === undefined) return;
      var shift = was - el.getBoundingClientRect().top;
      if (!shift) return;
      el.style.transition = 'none';
      el.style.transform = 'translateY(' + shift + 'px)';
      requestAnimationFrame(function () {
        el.style.transition = 'transform .2s ease';
        el.style.transform = '';
      });
    });
  };
  var sortTops = function (items) {
    var tops = new Map();
    items.forEach(function (el) { tops.set(el, el.getBoundingClientRect().top); });
    return tops;
  };

  /* Dragging: the song is lifted and follows the pointer, and the others
     slide out of its way — nothing in the page is moved until it's let go,
     when it settles into its place. */
  document.addEventListener('pointerdown', function (ev) {
    var handle = ev.target.closest('[data-tc-sort-handle]');
    if (!handle || ev.button > 0) return;
    var item = handle.closest('[data-tc-sort-id]');
    var list = item && item.closest('[data-tc-sortable]');
    if (!list) return;
    ev.preventDefault();
    // Followed on the whole document: the handle moves under the pointer.
    var pointer = ev.pointerId;
    var items = Array.prototype.slice.call(list.querySelectorAll('[data-tc-sort-id]'));
    var from = items.indexOf(item);
    var others = items.filter(function (el) { return el !== item; });
    // Where each item sits, in page terms, and how far one slot is.
    var tops = items.map(function (el) { return el.getBoundingClientRect().top + window.scrollY; });
    var heights = items.map(function (el) { return el.getBoundingClientRect().height; });
    var gap = items.length > 1 ? tops[1] - tops[0] - heights[0] : 0;
    var slot = heights[from] + gap;
    var startY = ev.clientY + window.scrollY;
    var to = from;
    var before = sortOrder(list).join(',');

    item.classList.add('is-dragging');
    list.classList.add('is-sorting');
    others.forEach(function (el) { el.style.transition = calm ? 'none' : 'transform .18s ease'; });
    item.style.transition = 'none';

    var scroller = null;
    var lastY = ev.clientY;
    var follow = function () {
      var dy = lastY + window.scrollY - startY;
      item.style.transform = 'translateY(' + dy + 'px)';
      // Its middle, against the others' middles where they started.
      var middle = tops[from] + heights[from] / 2 + dy;
      to = 0;
      items.forEach(function (el, i) {
        if (el !== item && middle > tops[i] + heights[i] / 2) to++;
      });
      items.forEach(function (el, i) {
        if (el === item) return;
        var shift = 0;
        if (from < to && i > from && i <= to) shift = -slot;
        else if (from > to && i >= to && i < from) shift = slot;
        el.style.transform = shift ? 'translateY(' + shift + 'px)' : '';
      });
    };
    var move = function (e) {
      if (e.pointerId !== pointer) return;
      e.preventDefault();
      lastY = e.clientY;
      follow();
      // Near the top or bottom of the window, scroll it along, and keep up.
      var edge = e.clientY < 70 ? -12 : (e.clientY > window.innerHeight - 70 ? 12 : 0);
      if (edge && !scroller) {
        scroller = setInterval(function () { window.scrollBy(0, edge); follow(); }, 16);
      } else if (!edge && scroller) {
        clearInterval(scroller);
        scroller = null;
      }
    };
    var up = function (e) {
      if (e.pointerId !== pointer) return;
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      document.removeEventListener('pointercancel', up);
      if (scroller) clearInterval(scroller);
      // Where everything is on screen now, then the real order, then glide.
      var seen = sortTops(items);
      items.forEach(function (el) { el.style.transition = 'none'; el.style.transform = ''; });
      if (to !== from) {
        var rest = others.slice();
        rest.splice(to, 0, item);
        rest.forEach(function (el) { list.appendChild(el); });
      }
      item.classList.remove('is-dragging');
      list.classList.remove('is-sorting');
      sortGlide(items, seen);
      if (sortOrder(list).join(',') !== before) {
        sortSay(item);
        sortSave(list);
      }
    };
    document.addEventListener('pointermove', move, { passive: false });
    document.addEventListener('pointerup', up);
    document.addEventListener('pointercancel', up);
  });

  var sortTimer = null;
  document.addEventListener('keydown', function (ev) {
    var handle = ev.target.closest && ev.target.closest('[data-tc-sort-handle]');
    if (!handle || (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown')) return;
    var item = handle.closest('[data-tc-sort-id]');
    var list = item && item.closest('[data-tc-sortable]');
    if (!list) return;
    ev.preventDefault();
    var sibling = ev.key === 'ArrowUp' ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling || !sibling.hasAttribute('data-tc-sort-id')) return;
    var seen = sortTops([item, sibling]);
    if (ev.key === 'ArrowUp') sibling.before(item); else sibling.after(item);
    sortGlide([item, sibling], seen);
    handle.focus();
    sortSay(item);
    // A few presses in a row are one change: saved once they stop.
    clearTimeout(sortTimer);
    sortTimer = setTimeout(function () { sortSave(list); }, 700);
  });

  /* A MAC address box (data-tc-mac): typed or pasted any way — dashes,
     spaces, lower case — it becomes 00:0B:82:C1:23:45 as you go, and can't
     be more than the twelve characters a MAC has. The server takes either. */
  var macBox = function (input) {
    input.setAttribute('maxlength', '17');
    input.setAttribute('spellcheck', 'false');
    input.setAttribute('autocapitalize', 'characters');
    input.setAttribute('pattern', '([0-9A-Fa-f]{2}[:\\-]?){5}[0-9A-Fa-f]{2}');
    input.setAttribute('title', "Twelve characters, 0–9 and A–F — like 00:0B:82:C1:23:45, from the label under the phone");
  };
  document.querySelectorAll('[data-tc-mac]').forEach(macBox);
  document.addEventListener('input', function (ev) {
    var input = ev.target.closest && ev.target.closest('[data-tc-mac]');
    if (!input) return;
    // Where the caret is, counted in hex characters, so it stays put.
    var before = input.value.slice(0, input.selectionStart || 0).replace(/[^0-9a-f]/gi, '').length;
    var hex = input.value.replace(/[^0-9a-f]/gi, '').toUpperCase().slice(0, 12);
    var shown = hex.replace(/(.{2})(?=.)/g, '$1:');
    if (shown === input.value) return;
    input.value = shown;
    // After k hex characters: k, plus the colons between them.
    var at = Math.min(before > 0 ? before + Math.floor((before - 1) / 2) : 0, shown.length);
    try { input.setSelectionRange(at, at); } catch (e) { /* not every input type can */ }
  });

  /* Notifications on this device (data-tc-push, the box's public key): ask
     the browser, subscribe through the service worker, and hand twocans what
     it needs to push here. Needs HTTPS, and on an iPhone, twocans added to
     the Home Screen first. */
  var pushCard = document.querySelector('[data-tc-push]');
  if (pushCard) {
    var pushOn = pushCard.querySelector('[data-tc-push-on]');
    var pushSaid = pushCard.querySelector('[data-tc-push-status]');
    var pushKey = function (b64) {
      var raw = atob((b64 + '==='.slice((b64.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
      var out = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
      return out;
    };
    var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
    var isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
    if (!window.isSecureContext) {
      pushSaid.textContent = 'Notifications need twocans opened over https:// — not this address.';
    } else if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      pushSaid.textContent = isIOS && !standalone
        ? 'Add twocans to your Home Screen first (Share → Add to Home Screen), then open it from there.'
        : "This browser can't show notifications from websites.";
    } else {
      // The helper that shows notifications (sw.js) has to be running; if it
      // can't start in this browser, say so rather than leave a blank card.
      var notReady = setTimeout(function () {
        pushSaid.textContent = "This browser couldn't start twocans' notification helper — try another browser, or the app on your Home Screen.";
      }, 6000);
      navigator.serviceWorker.ready.then(function (reg) {
        clearTimeout(notReady);
        return reg.pushManager.getSubscription().then(function (sub) {
          var listed = sub && pushCard.querySelector('[data-tc-push-endpoint="' + CSS.escape(sub.endpoint) + '"]');
          if (listed) {
            listed.classList.add('is-this');
            pushSaid.textContent = 'This device is getting notifications.';
            return;
          }
          if (Notification.permission === 'denied') {
            pushSaid.textContent = "Notifications are blocked for twocans in this browser's settings.";
            return;
          }
          pushOn.hidden = false;
          pushOn.addEventListener('click', function () {
            pushOn.disabled = true;
            pushSaid.textContent = 'Asking…';
            Notification.requestPermission().then(function (answer) {
              if (answer !== 'granted') throw new Error('not allowed');
              return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: pushKey(pushCard.getAttribute('data-tc-push')) });
            }).then(function (fresh) {
              var keys = fresh.toJSON().keys || {};
              var body = new FormData(pushCard.querySelector('[data-tc-push-form]'));
              body.append('endpoint', fresh.endpoint);
              body.append('p256dh', keys.p256dh || '');
              body.append('auth', keys.auth || '');
              return fetch('/', { method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Twocans-Ajax': '1', 'Accept': 'application/json' } }).then(function (r) { return r.json(); });
            }).then(function (data) {
              try { sessionStorage.setItem('tcToast', (data && data.toast) || ''); } catch (e) { /* fine */ }
              location.reload();
            }).catch(function () {
              pushOn.disabled = false;
              pushSaid.textContent = Notification.permission === 'denied'
                ? "Notifications were turned down — allow them for twocans in the browser's settings."
                : "Couldn't turn them on — try again.";
            });
          });
        });
      });
    }
  }

  /* Picking a photo submits straight away — nobody expects to choose a
     picture and then have to press Save as well. */
  document.addEventListener('change', function (ev) {
    var input = ev.target.closest('[data-tc-photo]');
    if (!input || !input.files || !input.files.length) return;

    /* In the contact editor the picker is part of the main form, so let the
       normal Save carry it; only stand-alone pickers self-submit. */
    var form = input.form;
    if (form && form.id === 'device-photo-form') {
      form.requestSubmit ? form.requestSubmit() : form.submit();
      return;
    }

    /* Show the picked photo in the circle straight away, so it's plain it was
       taken — and say that Save is what keeps it. Nothing is uploaded yet. */
    var file = input.files[0];
    if (!/^image\//.test(file.type)) return;
    /* A picker outside the circle names it; the other pickers for the same
       circle are emptied, so only the latest photo is sent. */
    var target = input.getAttribute('data-tc-photo-for');
    if (target) {
      document.querySelectorAll('[data-tc-photo-for="' + target + '"]').forEach(function (other) {
        if (other !== input) other.value = '';
      });
    }
    var pick = input.closest('.tc-photo-pick') || (target && document.getElementById(target));
    var avatar = pick && pick.querySelector('.tc-avatar');
    if (avatar) {
      var img = avatar.querySelector('img');
      if (!img) {
        // Replace the initial with a picture, keeping any badge (SOS) after it.
        Array.prototype.slice.call(avatar.childNodes).forEach(function (n) {
          if (n.nodeType === 3) n.remove();
        });
        img = document.createElement('img');
        img.alt = '';
        avatar.insertBefore(img, avatar.firstChild);
        avatar.classList.add('tc-avatar--photo');
      }
      if (img.dataset.tcPreview) URL.revokeObjectURL(img.dataset.tcPreview);
      img.src = URL.createObjectURL(file);
      img.dataset.tcPreview = img.src;
      img.removeAttribute('loading');
    }
    var note = document.querySelector('[data-tc-photo-note]');
    if (note) {
      note.textContent = note.getAttribute('data-tc-photo-note') || 'New photo picked — press Save to keep it.';
      note.classList.add('tc-photo-note--pending');
    }
  });

  /* A message carried over from a save that ended by moving page (see
     the data-tc-ajax handler), shown once it has loaded. */
  try {
    var carried = sessionStorage.getItem('tcToast');
    if (carried !== null) {
      sessionStorage.removeItem('tcToast');
      if (carried) setTimeout(function () { showToast(carried); }, 0);
    }
  } catch (e) { /* the page still works */ }

  /* Toast fades itself out after ~2.6s, matching the prototype. */
  var toast = document.querySelector('[data-tc-toast]');
  if (toast) {
    setTimeout(function () {
      toast.style.transition = 'opacity .3s';
      toast.style.opacity = '0';
      setTimeout(function () { toast.remove(); }, 320);
    }, 2600);
  }

  /* Record a clip in the browser — the self-service page, where a grandparent
     says their own name. The recording is put into the form's ordinary file
     input, so it is sent exactly like a chosen file and the page still works
     (by choosing a file) where the browser can't record. */
  var recButton = document.querySelector('[data-tc-rec]');
  if (recButton) {
    var recBox = recButton.closest('[data-tc-rec-box]');
    var recForm = recButton.closest('form');
    var recFile = recForm && recForm.querySelector('[data-tc-rec-file]');
    var recLabel = recButton.querySelector('[data-tc-rec-label]');
    var recTake = recBox.querySelector('[data-tc-rec-take]');
    var recAudio = recBox.querySelector('[data-tc-rec-audio]');
    var recError = recBox.querySelector('[data-tc-rec-error]');
    var recMax = Number(recButton.getAttribute('data-tc-rec-max')) || 7;
    var recorder = null;
    var recTimer = null;
    var recUrl = null;

    var canRecord = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia
      && window.MediaRecorder && window.DataTransfer && window.isSecureContext);
    recButton.hidden = !canRecord;

    var showError = function (text) {
      recError.textContent = text;
      recError.hidden = false;
    };

    var pickType = function () {
      var types = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm'];
      for (var i = 0; i < types.length; i++) {
        if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
      }
      return '';
    };

    var stop = function () {
      if (recorder && recorder.state !== 'inactive') recorder.stop();
    };

    recButton.addEventListener('click', function () {
      if (recorder && recorder.state === 'recording') { stop(); return; }
      recError.hidden = true;

      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        var type = pickType();
        var chunks = [];
        recorder = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream);
        recorder.addEventListener('dataavailable', function (e) { if (e.data && e.data.size) chunks.push(e.data); });
        recorder.addEventListener('stop', function () {
          clearInterval(recTimer);
          stream.getTracks().forEach(function (t) { t.stop(); });
          recButton.classList.remove('is-recording');
          recLabel.textContent = 'Record again';

          var mime = (recorder.mimeType || type || 'audio/webm').split(';')[0];
          var blob = new Blob(chunks, { type: mime });
          if (!blob.size) { showError("Nothing was recorded — try again."); return; }
          var ext = mime.indexOf('mp4') !== -1 ? 'm4a' : (mime.indexOf('ogg') !== -1 ? 'ogg' : 'webm');
          var file = new File([blob], 'my-name.' + ext, { type: mime });
          var dt = new DataTransfer();
          dt.items.add(file);
          recFile.files = dt.files;

          var name = recFile.closest('label').querySelector('[data-tc-filename]');
          if (name) name.textContent = 'Using your recording — or choose a file';
          if (recUrl) URL.revokeObjectURL(recUrl);
          recUrl = URL.createObjectURL(blob);
          recAudio.src = recUrl;
          recTake.hidden = false;
        });

        recorder.start();
        recButton.classList.add('is-recording');
        var left = recMax;
        recLabel.textContent = 'Stop (' + left + ')';
        recTimer = setInterval(function () {
          left -= 1;
          if (left <= 0) { stop(); return; }
          recLabel.textContent = 'Stop (' + left + ')';
        }, 1000);
      }).catch(function () {
        showError("We couldn't use your microphone. Allow it when your browser asks, or choose a recording below.");
      });
    });

    /* Choosing a file instead replaces the recording. */
    recFile.addEventListener('change', function () {
      if (recFile.files.length && recFile.files[0].name.indexOf('my-name.') !== 0) {
        recTake.hidden = true;
        recLabel.textContent = 'Record';
      }
    });
  }

  /* Record straight into any audio upload — an announcement, a greeting, a
     phone's message, a joke, hold music. A Record button goes beside each
     "choose a file" button; what's recorded is put into that same file input,
     so the form sends and saves it exactly like a chosen file (straight away,
     where the form saves on choosing). Browsers only allow the microphone on
     a secure page (https://, or localhost), so elsewhere the button says so. */
  var canUseMic = function () {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia
      && window.MediaRecorder && window.DataTransfer && window.isSecureContext);
  };
  var micType = function () {
    var types = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm'];
    for (var i = 0; i < types.length; i++) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
  };
  var clock = function (secs) { return Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0'); };

  function addRecordButtons(root) {
    root.querySelectorAll('input[type=file][data-tc-audiofile]:not([multiple])').forEach(function (input) {
      var label = input.closest('label');
      // The self-service page has a recorder of its own.
      if (!label || input.dataset.tcRec || input.closest('[data-tc-rec-box]')) return;
      input.dataset.tcRec = '1';

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tc-btn tc-btn--ghost tc-rec-btn' + (label.classList.contains('tc-btn--sm') ? ' tc-btn--sm' : '');
      btn.innerHTML = '<i class="fa-solid fa-microphone" aria-hidden="true"></i> <span>Record</span>';
      btn.setAttribute('aria-pressed', 'false');
      label.insertAdjacentElement('afterend', btn);
      var text = btn.querySelector('span');
      var max = Number(input.getAttribute('data-tc-rec-max')) || 60;
      var recorder = null, timer = null, secs = 0;

      var stop = function () { if (recorder && recorder.state !== 'inactive') recorder.stop(); };

      btn.addEventListener('click', function () {
        if (recorder && recorder.state === 'recording') { stop(); return; }
        if (!canUseMic()) {
          showToast("Recording here needs twocans' secure address (https://…) — or choose a file instead.");
          return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
          var type = micType();
          var chunks = [];
          recorder = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream);
          recorder.addEventListener('dataavailable', function (e) { if (e.data && e.data.size) chunks.push(e.data); });
          recorder.addEventListener('stop', function () {
            clearInterval(timer);
            stream.getTracks().forEach(function (t) { t.stop(); });
            btn.classList.remove('is-recording');
            btn.setAttribute('aria-pressed', 'false');
            text.textContent = 'Record again';
            var mime = (recorder.mimeType || type || 'audio/webm').split(';')[0];
            var blob = new Blob(chunks, { type: mime });
            if (!blob.size || secs < 1) { showToast('Nothing was recorded — try again.'); return; }
            var ext = mime.indexOf('mp4') !== -1 ? 'm4a' : (mime.indexOf('ogg') !== -1 ? 'ogg' : 'webm');
            var dt = new DataTransfer();
            dt.items.add(new File([blob], 'recording.' + ext, { type: mime }));
            input.files = dt.files;
            // As if a file had been chosen: forms that save on choosing, save.
            input.dispatchEvent(new Event('change', { bubbles: true }));
            var name = label.querySelector('[data-tc-filename]');
            if (name && !(input.form && input.form.dataset.tcSending)) name.textContent = 'Your recording (' + clock(secs) + ')';
          });
          secs = 0;
          recorder.start();
          btn.classList.add('is-recording');
          btn.setAttribute('aria-pressed', 'true');
          text.textContent = 'Stop · 0:00';
          timer = setInterval(function () {
            secs += 1;
            if (secs >= max) { stop(); return; }
            text.textContent = 'Stop · ' + clock(secs);
          }, 1000);
        }).catch(function () {
          showToast("We couldn't use the microphone — allow it when the browser asks, or choose a file.");
        });
      });
    });
  }
  addRecordButtons(document);

  /* "Take a photo" on a computer. A phone's browser opens its camera for the
     capture input by itself; a computer's ignores capture and shows a file
     picker, so there the webcam is shown in the page instead, and the shot is
     put into the same input — sent like any other photo. */
  var cameraButton = document.querySelector('[data-tc-camera]');
  var webcam = document.querySelector('[data-tc-webcam]');
  if (cameraButton && webcam && navigator.mediaDevices && navigator.mediaDevices.getUserMedia
      && window.DataTransfer && window.isSecureContext
      && !(window.matchMedia && matchMedia('(pointer: coarse)').matches)) {
    var cameraInput = cameraButton.querySelector('input[type=file]');
    var video = webcam.querySelector('[data-tc-webcam-video]');
    var cameraError = document.querySelector('[data-tc-camera-error]');
    var camStream = null;

    var closeCamera = function () {
      if (camStream) camStream.getTracks().forEach(function (t) { t.stop(); });
      camStream = null;
      video.srcObject = null;
      webcam.hidden = true;
    };

    cameraButton.addEventListener('click', function (ev) {
      ev.preventDefault();
      cameraError.hidden = true;
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }).then(function (stream) {
        camStream = stream;
        video.srcObject = stream;
        webcam.hidden = false;
        video.play();
      }).catch(function () {
        cameraError.textContent = "We couldn't use your camera. Allow it when your browser asks, or choose a photo instead.";
        cameraError.hidden = false;
      });
    });

    webcam.querySelector('[data-tc-webcam-cancel]').addEventListener('click', closeCamera);

    webcam.querySelector('[data-tc-webcam-snap]').addEventListener('click', function () {
      var w = video.videoWidth, h = video.videoHeight;
      if (!w || !h) return;
      /* Square from the middle, mirrored back to how the person sees
         themselves in the preview. */
      var side = Math.min(w, h);
      var canvas = document.createElement('canvas');
      canvas.width = canvas.height = Math.min(side, 1024);
      var g = canvas.getContext('2d');
      g.translate(canvas.width, 0);
      g.scale(-1, 1);
      g.drawImage(video, (w - side) / 2, (h - side) / 2, side, side, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(function (blob) {
        if (!blob) return;
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'photo.jpg', { type: 'image/jpeg' }));
        cameraInput.files = dt.files;
        cameraInput.dispatchEvent(new Event('change', { bubbles: true }));
        closeCamera();
      }, 'image/jpeg', 0.9);
    });
  }
})();
