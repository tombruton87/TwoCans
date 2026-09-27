<?php
/**
 * @var Store $store
 * @var DeviceRepository $devices
 */
$trunk = $store->trunk();
$low = $store->isLowCredit();
?>
<?php /* Two columns on a wide screen: the line itself on the left, how the
         outside world reaches the house on the right. Stacked on a phone. */ ?>
<div class="tc-twocol">
<div class="tc-stack tc-twocol__col">

  <?php if ($trunk['connected']): ?>
    <section class="tc-card tc-card--lg">
      <div class="tc-trunk-head">
        <div class="tc-trunk-mark"><?= $trunk['provider'] === 'SIP.IO' ? 's' : 't' ?></div>
        <div class="tc-grow">
          <div class="tc-trunk-name">
            <h2><?= e($trunk['provider']) ?></h2>
            <span class="tc-pill tc-pill--ok">Connected</span>
            <?php if ($trunk['provider'] === 'Twilio' && $trunk['region'] !== 'us1'): ?>
              <span class="tc-pill"><?= e((string) (Twilio::REGIONS[$trunk['region']]['label'] ?? $trunk['region'])) ?></span>
            <?php endif; ?>
          </div>
          <div class="tc-card__hint" style="font-size:13px">
            <?php if (count($trunk['numbers']) > 1): ?>
              Your line's numbers · <?= e(implode(' · ', $trunk['numbers'])) ?>
              <span class="tc-micro">(<?= e($trunk['number']) ?> is the caller ID for outgoing calls)</span>
            <?php else: ?>
              Your line's number · <?= e($trunk['number']) ?>
            <?php endif; ?>
          </div>
        </div>
        <?php if (Auth::can('billing')): ?>
          <form method="post" action="/">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="trunk_edit">
            <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Edit connection</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="tc-divider tc-divider--fine" style="margin:20px 0"></div>

      <?php if ($trunk['provider'] === 'Twilio'): ?>
        <div class="tc-credit-row">
          <div>
            <div class="tc-card__hint" style="font-weight:700">Call credit left</div>
            <div class="tc-credit <?= $low ? 'is-low' : '' ?>"><?= e(Presenter::money($trunk)) ?></div>
            <?php if ($low): ?>
              <div style="font:800 12px var(--tc-body);color:var(--tc-coral-lip);margin-top:2px">Running low — top up soon</div>
            <?php elseif ($trunk['balance'] === null): ?>
              <span class="tc-micro">Twilio does not report credit in the <?= e((string) (Twilio::REGIONS[$trunk['region']]['label'] ?? $trunk['region'])) ?> region — check it in the Twilio console.</span>
            <?php endif; ?>
          </div>
          <?php if (Auth::can('billing')): ?>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="trunk_topup">
              <button class="tc-btn tc-btn--coral" type="submit" style="padding:13px 22px;font-size:15px">+ Top up $20</button>
            </form>
          <?php else: ?>
            <span class="tc-micro">Only the Owner can top up the line.</span>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="tc-credit-row">
          <div>
            <div class="tc-card__hint" style="font-weight:700">Billing</div>
            <div class="tc-credit">Usage-based</div>
          </div>
          <span class="tc-micro">No credit to top up — SIP.IO bills you monthly.</span>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($trunk['provider'] === 'Twilio'): ?>
      <div class="tc-grid tc-grid--stats">
        <div class="tc-card tc-card--sm">
          <div class="tc-card__hint" style="font-weight:700">This month</div>
          <div style="font:800 24px var(--tc-display);color:var(--tc-teal-deep)"><?= (int) $trunk['minutesThisMonth'] ?> min</div>
        </div>
        <div class="tc-card tc-card--sm">
          <div class="tc-card__hint" style="font-weight:700">Rate</div>
          <div style="font:800 24px var(--tc-display);color:var(--tc-ink)"><?= e($trunk['rate']) ?></div>
        </div>
        <div class="tc-card tc-card--sm">
          <div class="tc-card__hint" style="font-weight:700">Auto top-up</div>
          <div style="font:800 24px var(--tc-display);color:var(--tc-lav)"><?= $trunk['autoTopUp'] ? 'On' : 'Off' ?></div>
        </div>
      </div>
    <?php endif; ?>

    <?php
    /*
     * Where an incoming call lands.
     *
     * The line rings every phone that accepts incoming calls, which is what a
     * household number should do. Pointing it at one handset is for the case
     * where the number belongs to a particular child rather than the house.
     */
    $ringRows = array_map([DeviceRepository::class, 'toView'], $devices->all());
    $ringRows = array_values(array_filter(
        $ringRows,
        static fn(array $d): bool => $d['sipUsername'] !== '' && $d['available']
    ));
    ?>
    <section class="tc-card tc-card--lg">
      <div class="tc-trunk-name" style="margin-bottom:6px">
        <h2><?= count($trunk['numbers']) > 1 ? 'Who these numbers ring' : 'Who this number rings' ?></h2>
      </div>
      <div class="tc-card__hint" style="font-size:13px">
        Each number rings every phone that takes incoming calls, unless you point
        it at one — handy when a number belongs to one child rather than the house.
        A phone with a number of its own also calls out from it, so the people
        it rings see that number; every other phone calls out from
        <?= e($trunk['number']) ?>.
      </div>

      <div class="tc-divider tc-divider--fine" style="margin:14px 0"></div>

      <?php if (!Auth::can('billing')): ?>
        <div class="tc-stack tc-stack--tight">
          <?php foreach ($trunk['numbers'] as $number): ?>
            <?php $ringsId = $trunk['rings'][$number] ?? null; ?>
            <div class="tc-ring-row">
              <span class="tc-ring-row__num"><?= e($number) ?></span>
              <span class="tc-card__hint"><?php
                $only = $ringsId === null ? null : array_values(array_filter($ringRows, static fn(array $d): bool => (int) $d['id'] === $ringsId))[0] ?? null;
                echo e($only === null ? 'Every phone' : $only['name']);
              ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <span class="tc-micro">Only the Owner can change where the numbers ring.</span>
      <?php elseif ($ringRows === []): ?>
        <span class="tc-micro">Add a phone first — there is nothing for the number to ring yet.</span>
      <?php else: ?>
        <div class="tc-stack tc-stack--tight">
          <?php foreach ($trunk['numbers'] as $number): ?>
            <?php $ringsId = $trunk['rings'][$number] ?? null; ?>
            <?php /* One form per number; choosing a phone saves it. */ ?>
            <form method="post" action="/" class="tc-ring-row">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="trunk_ring_device">
              <input type="hidden" name="number" value="<?= e($number) ?>">
              <label class="tc-ring-row__num" for="ring-<?= e(ltrim($number, '+')) ?>">
                <?= e($number) ?>
                <?php if ($number === $trunk['number'] && count($trunk['numbers']) > 1): ?>
                  <span class="tc-micro">caller ID</span>
                <?php endif; ?>
              </label>
              <select class="tc-input tc-input--white tc-ring-row__pick" name="device"
                      id="ring-<?= e(ltrim($number, '+')) ?>" data-tc-autosave>
                <option value="">Every phone</option>
                <?php foreach ($ringRows as $d): ?>
                  <option value="<?= (int) $d['id'] ?>"<?= $ringsId === (int) $d['id'] ? ' selected' : '' ?>>
                    <?= e($d['name']) ?><?= $d['allowIn'] ? '' : ' (incoming calls are off for this phone)' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <noscript><button class="tc-btn tc-btn--teal" type="submit">Save</button></noscript>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  <?php else: ?>
    <div class="tc-trunk-empty">
      <div class="tc-trunk-empty__string"></div>
      <div style="font:800 19px var(--tc-display)">No phone line yet</div>
      <div class="tc-card__hint" style="font-size:13px;max-width:340px">
        Connect a SIP trunk so your cans can actually reach the outside world — Twilio or SIP.IO.
      </div>
      <?php if (Auth::can('billing')): ?>
        <a class="tc-btn tc-btn--coral" href="<?= e(url(['screen' => 'trunk', 'trunkwizard' => 1])) ?>"
           style="padding:14px 24px;font-size:15px">Connect a provider</a>
      <?php else: ?>
        <span class="tc-micro">Only the Owner can connect a phone line.</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>

<div class="tc-stack tc-twocol__col">
  <?php
  /*
   * Where the outside world finds you.
   *
   * Two separate things live here. The external address is the name that points
   * at this house — what away-from-home phones provision against. Cloudflare is
   * an optional layer that keeps that name's record updated; someone with a
   * static IP or another DDNS service sets the name and skips Cloudflare.
   */
  $ddns = Presenter::ddns($store->dynamicDns());
  $ddnsDraft = $store->ddnsDraft();
  $canEditDdns = Auth::can('billing');
  $cloudflareSaved = (bool) $ddns['configured'];
  $cloudflareActive = (bool) $ddns['enabled'] && $cloudflareSaved;
  ?>
  <section class="tc-card tc-card--lg">
    <div class="tc-trunk-name" style="margin-bottom:6px">
      <h2>Where the outside world finds you</h2>
      <?php if ($cloudflareActive): ?>
        <span class="tc-pill tc-pill--<?= e($ddns['statusMod']) ?>"><?= e($ddns['statusLabel']) ?></span>
      <?php endif; ?>
    </div>

    <div class="tc-divider tc-divider--fine" style="margin:14px 0"></div>

    <div style="display:flex;gap:28px;flex-wrap:wrap">
      <div>
        <div class="tc-card__hint" style="font-weight:700">This network's address</div>
        <div style="font:800 19px var(--tc-display);color:var(--tc-teal-deep)">
          <?= $ddns['ip'] !== '' ? e($ddns['ip']) : 'not known yet' ?>
        </div>
      </div>
      <div>
        <div class="tc-card__hint" style="font-weight:700">Last checked</div>
        <div style="font:700 15px var(--tc-body);color:var(--tc-ink)"><?= e($ddns['checkedText']) ?></div>
      </div>
      <div>
        <div class="tc-card__hint" style="font-weight:700">Last updated</div>
        <div style="font:700 15px var(--tc-body);color:var(--tc-ink)"><?= e($ddns['updatedText']) ?></div>
      </div>
    </div>

    <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

    <div class="tc-card__hint" style="font-weight:700">The name that points here</div>
    <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
      Away-from-home phones use this name. Point it at this house however you like —
      Cloudflare below, another DDNS service, or a static IP you keep updated yourself.
    </div>

    <?php if ($canEditDdns): ?>
      <form method="post" action="/" style="margin-top:10px">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="ddns_address">
        <div style="display:flex;gap:8px;align-items:flex-end">
          <label class="tc-label tc-label--sm tc-grow">External address
            <input class="tc-input tc-input--white" type="text" name="hostname" required
                   value="<?= e($ddns['hostname']) ?>" placeholder="e.g. phone.example.com" autocomplete="off">
          </label>
          <button class="tc-btn tc-btn--teal" type="submit" style="padding:13px 18px">Save</button>
        </div>
      </form>
    <?php else: ?>
      <div class="tc-micro" style="margin-top:6px">Only the Owner can change this.</div>
    <?php endif; ?>

    <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

    <div class="tc-card__hint" style="font-weight:700">Keep it updated with Cloudflare <span class="tc-micro">(optional)</span></div>

    <?php if ($cloudflareSaved): ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        <?php if ($cloudflareActive): ?>
          Cloudflare is keeping <strong><?= e($ddns['hostname']) ?></strong> pointed here.
        <?php else: ?>
          Cloudflare is set up but paused, so it is not updating
          <strong><?= e($ddns['hostname']) ?></strong> right now.
        <?php endif; ?>
      </div>

      <?php if ($canEditDdns): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
          <?php if ($cloudflareActive): ?>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="ddns_update">
              <button class="tc-btn tc-btn--coral" type="submit" style="padding:13px 20px;font-size:15px">Update now</button>
            </form>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="ddns_disable">
              <button class="tc-btn tc-btn--ghost" type="submit">Turn off</button>
            </form>
          <?php else: ?>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="ddns_enable">
              <button class="tc-btn tc-btn--coral" type="submit" style="padding:13px 20px;font-size:15px">Turn back on</button>
            </form>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <span class="tc-micro">Only the Owner can change this.</span>
      <?php endif; ?>

      <?php if ($ddns['error'] !== null): ?>
        <div style="font:800 12px var(--tc-body);color:var(--tc-coral-lip);margin-top:12px">
          <?= e($ddns['error']) ?>
        </div>
      <?php elseif ($ddns['stale']): ?>
        <div class="tc-micro" style="margin-top:12px;line-height:1.5">
          No check has happened for a while. The checks run inside the app's own container —
          <code>docker logs twocans-php</code> and <code>docker/php/log/ddns.log</code> will say why.
        </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        If you use Cloudflare for DNS, twocans can keep the name above updated automatically.
        Skip this if you have a static IP or use another DDNS service.
      </div>

      <?php if ($canEditDdns): ?>
        <form method="post" action="/" style="margin-top:12px">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="ddns_connect">

          <div style="display:flex;flex-direction:column;gap:12px">
            <label class="tc-label tc-label--sm">Your domain
              <input class="tc-input tc-input--white" type="text" name="zone" required
                     value="<?= e((string) $ddnsDraft['zone']) ?>" placeholder="e.g. example.com" autocomplete="off">
            </label>
            <label class="tc-label tc-label--sm">Cloudflare API token
              <input class="tc-input tc-input--white" type="password" name="token" required
                     placeholder="••••••••••••" autocomplete="off">
            </label>
          </div>

          <div class="tc-micro" style="margin-top:12px;line-height:1.6">
            In Cloudflare: My Profile → API Tokens → Create Token → Create Custom Token. It needs
            <strong>Zone → Zone → Read</strong> and <strong>Zone → DNS → Edit</strong>, limited to
            this one domain. There is no account ID to enter — twocans finds the zone by name. The
            token is stored encrypted and used for nothing else.
          </div>

          <div class="tc-wizard-actions" style="margin-top:16px">
            <button class="tc-btn tc-btn--coral tc-btn--grow" type="submit" style="padding:13px;font-size:15px">
              Connect Cloudflare →
            </button>
          </div>
        </form>
      <?php else: ?>
        <span class="tc-micro">Only the Owner can set this up.</span>
      <?php endif; ?>
    <?php endif; ?>

    <?php
    $certificates = new Certificates();
    $cert = $certificates->status();
    $certDomain = $certificates->domain();
    $certPending = $certificates->pending();
    $certResult = $certificates->result();
    ?>

    <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

    <div class="tc-card__hint" style="font-weight:700">HTTPS certificate</div>

    <div class="tc-micro" style="margin-top:2px;line-height:1.5">
      Using Cloudflare Tunnel? Your public certificate is already provided by Cloudflare — skip this.
      This section only matters when the box serves HTTPS directly on port 443 with its own certificate.
    </div>

    <?php if (!$cert['exists']): ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        No certificate yet — nginx will generate a default one when it starts.
      </div>
    <?php elseif ($cert['selfSigned']): ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        Serving a self-signed certificate — HTTPS works, but browsers show a warning.
      </div>
    <?php else: ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        Let's Encrypt certificate from <strong><?= e($cert['issuer']) ?></strong>, valid until
        <strong><?= e($cert['validTo']) ?></strong>.
      </div>
    <?php endif; ?>

    <?php if ($canEditDdns): ?>
      <?php if ($certPending): ?>
        <div class="tc-micro" style="margin-top:10px;line-height:1.5">
          Waiting for nginx to obtain the certificate for
          <strong><?= e((string) $certDomain) ?></strong>… this can take a minute.
        </div>
      <?php elseif ($certDomain !== null): ?>
        <form method="post" action="/" style="margin-top:10px">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="cert_request">
          <label class="tc-label tc-label--sm">Email (optional, for expiry notices)
            <input class="tc-input tc-input--white" type="email" name="email" placeholder="you@example.com" autocomplete="off">
          </label>
          <div class="tc-wizard-actions" style="margin-top:12px">
            <button class="tc-btn tc-btn--coral" type="submit" style="padding:13px;font-size:15px">
              Get a Let's Encrypt certificate for <?= e($certDomain) ?>
            </button>
          </div>
        </form>
      <?php else: ?>
        <div class="tc-micro" style="margin-top:10px">
          Set the external address above to choose the certificate's domain.
        </div>
      <?php endif; ?>

      <?php if ($certResult !== null && $certResult !== 'issued'): ?>
        <div style="font:800 12px var(--tc-body);color:var(--tc-coral-lip);margin-top:10px;white-space:pre-wrap">
          <?= e($certResult) ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <span class="tc-micro">Only the Owner can change this.</span>
    <?php endif; ?>

  </section>

  <?php
  /* Opening the router: which ports the outside world needs, and asking the
     router to open them itself (UPnP / NAT-PMP) — see PortOpener. */
  $opener = new PortOpener();
  $portsState = $opener->state();
  $portsAuto = $opener->auto();
  $portsGroups = $opener->groups();
  $allPorts = PortOpener::ports();
  $lan = PortOpener::lanAddress();
  $tried = $portsAuto && $portsState['at'] > 0;
  $portsMod = !$tried ? 'off' : ($portsState['error'] === '' && $portsState['warning'] === '' ? 'ok' : 'bad');
  ?>
  <section class="tc-card tc-card--lg" id="ports">
    <div class="tc-trunk-name" style="margin-bottom:6px">
      <h2>Opening the router</h2>
      <?php if ($tried): ?>
        <span class="tc-pill tc-pill--<?= $portsMod === 'ok' ? 'ok' : 'bad' ?>"><?= $portsMod === 'ok' ? 'Open' : 'Needs a look' ?></span>
      <?php endif; ?>
    </div>
    <p class="tc-card__hint">
      For calls and links from outside to reach twocans, the router has to let them
      through to this box<?= $lan !== '' ? ' (<b>' . e($lan) . '</b>)' : '' ?>. Many routers can
      be asked to do that by themselves — or set these up on the router by hand.
    </p>

    <?php if ($tried): ?>
      <div class="tc-ports-status tc-ports-status--<?= e($portsMod) ?>">
        <?php if ($portsState['error'] !== ''): ?>
          <div><?= e($portsState['error']) ?></div>
        <?php else: ?>
          <div>
            <?= e($portsState['name'] !== '' ? $portsState['name'] : 'The router') ?> opened them
            by <?= $portsState['method'] === 'natpmp' ? 'NAT-PMP' : 'UPnP' ?><?= $portsState['externalIp'] !== '' ? ' — the house is <b>' . e($portsState['externalIp']) . '</b> on the internet' : '' ?>.
          </div>
        <?php endif; ?>
        <?php if ($portsState['warning'] !== ''): ?>
          <div style="margin-top:6px"><?= e($portsState['warning']) ?></div>
        <?php endif; ?>
        <div class="tc-micro" style="margin-top:6px">
          Checked <?= e(date('j M, g:ia', (int) $portsState['at'])) ?> — renewed every half hour.
        </div>
      </div>
    <?php endif; ?>

    <form method="post" action="/" class="tc-stack" style="gap:12px;margin-top:12px">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="ports_save">

      <?php foreach (PortOpener::GROUPS as $key => $group): ?>
        <?php
        $ports = $allPorts[$key];
        $results = array_values(array_filter(array_map(
            static fn(array $p): ?array => $portsState['ports'][$p['proto'] . ' ' . $p['port']] ?? null,
            $ports
        )));
        $bad = array_values(array_filter($results, static fn(array $r): bool => !$r['ok']));
        $on = in_array($key, $portsGroups, true);
        ?>
        <label class="tc-port-row">
          <input type="checkbox" name="groups[]" value="<?= e($key) ?>" <?= $on ? 'checked' : '' ?> <?= $canEditDdns ? '' : 'disabled' ?>>
          <span class="tc-grow">
            <span class="tc-port-row__title"><?= e($group['label']) ?></span>
            <span class="tc-port-row__why"><?= e($group['why']) ?></span>
            <?php if ($tried && $on && $bad !== []): ?>
              <span class="tc-port-row__bad"><?= e(count($bad) === count($results) ? $bad[0]['note'] : count($bad) . ' of ' . count($results) . ' refused — ' . $bad[0]['note']) ?></span>
            <?php endif; ?>
          </span>
          <span class="tc-port-row__ports">
            <b><?= e(PortOpener::describeGroup($ports)) ?></b>
            <?php if ($tried && $on && $results !== []): ?>
              <?= $bad === [] ? '<i class="fa-solid fa-circle-check tc-port-ok" aria-label="open"></i>' : '<i class="fa-solid fa-triangle-exclamation tc-port-bad" aria-label="not open"></i>' ?>
            <?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>

      <?php if ($canEditDdns): ?>
        <label class="tc-ha-toggle">
          <input type="checkbox" name="auto" <?= $portsAuto ? 'checked' : '' ?>>
          <span><b>Ask the router to open the ticked ones</b> (UPnP or NAT-PMP)</span>
        </label>
        <details class="tc-manual-setup">
          <summary>Router address</summary>
          <label class="tc-label tc-label--sm tc-mt-14">Only if it isn't <?= e(PortOpener::guessRouter($lan) ?: 'the usual one') ?>
            <input class="tc-input tc-input--white" type="text" name="router" value="<?= e($opener->routerSetting()) ?>"
                   placeholder="<?= e(PortOpener::guessRouter($lan)) ?>" inputmode="decimal" autocomplete="off">
          </label>
        </details>
        <div class="tc-row" style="gap:8px">
          <button class="tc-btn tc-btn--teal" type="submit">Save</button>
          <?php if ($portsAuto): ?>
            <button class="tc-btn tc-btn--ghost" type="submit" name="action" value="ports_try">Try again now</button>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </form>

    <p class="tc-micro" style="margin-top:12px;line-height:1.6">
      By hand instead: on the router, forward each of these to <b><?= e($lan !== '' ? $lan : 'this box') ?></b>,
      the same port number outside and in. If the router can't be reached from outside at all
      (another router in front, or a provider that shares addresses), a Cloudflare Tunnel still
      gets the app through — calls need the ports.
    </p>
  </section>
</div>
</div>
