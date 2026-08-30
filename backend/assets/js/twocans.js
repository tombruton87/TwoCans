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

  /* Inline edits (device name, hours, blocked message) save on change/blur. */
  document.addEventListener('change', function (ev) {
    var field = ev.target.closest('[data-tc-autosave]');
    if (!field) return;
    var form = field.form;
    if (form) form.requestSubmit();
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

  /* Show which file was picked for the joke line. Without this the button
     still says "Choose a file" after choosing one, which reads as a failure. */
  document.addEventListener('change', function (ev) {
    var picker = ev.target.closest('[data-tc-jokefile]');
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
    var row = play.closest('.tc-call-row');
    var card = row && row.parentElement;
    var audio = row && row.querySelector('[data-audio]');
    var strip = card && card.querySelector('[data-eq]');
    if (!audio || !strip) return;

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
      strip.classList.add('is-open');
    });

    /* The mockup only shows the bars while sound is playing — a paused or
       finished call goes back to the plain row. */
    var quiet = function () {
      play.classList.remove('is-playing');
      play.textContent = '▶';
      strip.classList.remove('is-open');
    };
    audio.addEventListener('pause', quiet);
    audio.addEventListener('ended', function () {
      quiet();
      audio.currentTime = 0;
    });

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
})();
