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
  document.querySelectorAll('[data-play]').forEach(function (play) {
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
