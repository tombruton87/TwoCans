<?php
/**
 * How to point a Grandstream phone or adapter, a Cisco adapter, a Poly VVX, or a Yealink base, at twocans:
 * where its web page is, and what to type in. Used by the add-a-phone wizard
 * and the phone page.
 *
 * @var array $d DeviceRepository::toView()
 */
$pass = (new SettingsRepository())->provisionPass();
$base = 'http://' . PjsipConfig::domain() . ':' . (int) (getenv('HTTP_PORT') ?: 8083);
?>
<?php if ($d['brand'] === 'fanvil'): ?>
  <ol class="tc-qr__steps">
    <li>Plug the adapter into your router, and a touch-tone corded phone into its <b>FXS</b> port.</li>
    <li>Find its address: pick up the phone and dial <b>#*111</b> — it reads the IP address out. (Or look for it in your
      router.) Open it in a browser and sign in with <b>admin</b> / <b>admin</b>.</li>
    <li>Under <b>System → Auto Provision</b>, in <b>Static Provisioning Server</b>: set <b>Server Address</b> to
      <code><?= e(FanvilProvisioning::serverAddress()) ?></code>, <b>Protocol Type</b> to <b>HTTP</b>, and leave
      <b>Configuration File Name</b> empty.</li>
    <li>Set <b>Authentication Name</b> to <b>twocans</b> and <b>Authentication Password</b> to <code><?= e($pass) ?></code></li>
    <li>Set <b>Update Mode</b> to update after reboot, <b>Apply</b>, and restart the adapter. When the phone gives a dial tone,
      it's on the line.</li>
  </ol>
<?php elseif ($d['brand'] === 'poly'): ?>
  <ol class="tc-qr__steps">
    <li>Plug the phone into your router (or a PoE switch, which powers it too) and let it start up.</li>
    <li>On the phone, press <b>Home</b> (or <b>Menu</b>), then <b>Settings → Advanced</b> — the password is
      <b>456</b> unless it's been changed — then <b>Administration Settings → Network Configuration → Provisioning Server</b>.</li>
    <li>Set <b>Server Type</b> to <b>HTTP</b>, <b>Server Address</b> to <code><?= e(PolyProvisioning::serverAddress()) ?></code>,
      <b>Server User</b> to <b>twocans</b> and <b>Server Password</b> to <code><?= e($pass) ?></code></li>
    <li>Back out, choose <b>Save Configuration</b>, and let it restart. (Its web page, at its IP address, has the same
      settings under <b>Settings → Provisioning Server</b>.)</li>
    <li>When its screen shows <?= e($d['name']) ?> on its first line key, it's on the line.</li>
  </ol>
<?php elseif ($d['dect']): ?>
  <ol class="tc-qr__steps">
    <li>Plug the <?= e($d['model']) ?> base into your router and the mains, and put the handset on its charger.
      The handset that came with it is registered already, as handset 1.</li>
    <li>Find the base's address: on the handset, press <b>OK</b>, then <b>Status → Base</b> — it shows the IPv4 address.
      (Or look for it in your router.)</li>
    <li>Open that address in a browser and sign in — <b>admin</b> / <b>admin</b> unless it's been changed.</li>
    <li>Under <b><?= $d['type'] === 'w52p' ? 'Phone → Auto Provision' : 'Settings → Auto Provision' ?></b>, set <b>Server URL</b> to <code><?= e(YealinkProvisioning::serverUrl()) ?></code></li>
    <li>Set <b>User Name</b> to <b>twocans</b> and <b>Password</b> to <code><?= e($pass) ?></code></li>
    <li>Press <b>Confirm</b>, then <b>Auto Provision Now</b>. When the handset shows its name, it's on the line.</li>
  </ol>
<?php elseif ($d['brand'] === 'yealink'): ?>
  <ol class="tc-qr__steps">
    <li>Plug the phone into your router (or a PoE switch, which powers it too)<?= in_array($d['type'], ['t53w', 't54w', 't57w', 't58w'], true) ? ' — or join it to your Wi-Fi from its menu' : '' ?>, and let it start up.</li>
    <li>Find its address: press <b>OK</b> on the phone (or <b>Menu → Status</b>) — it shows the IPv4 address.</li>
    <li>Open that address in a browser and sign in — <b>admin</b> / <b>admin</b> unless it's been changed.</li>
    <li>Under <b>Settings → Auto Provision</b>, set <b>Server URL</b> to <code><?= e(YealinkProvisioning::serverUrl()) ?></code></li>
    <li>Set <b>User Name</b> to <b>twocans</b> and <b>Password</b> to <code><?= e($pass) ?></code></li>
    <li>Press <b>Confirm</b>, then <b>Auto Provision Now</b>. When its screen shows <?= e($d['name']) ?> on its first line key, it's on the line.</li>
  </ol>
<?php elseif ($d['brand'] === 'cisco'): ?>
  <ol class="tc-qr__steps">
    <?php if (CiscoProvisioning::isAta19x($d['type'])): ?>
      <li>It must be the <b>Multiplatform</b> version (sold as <b><?= e(strtoupper(str_replace(' ', '', $d['model']))) ?>-3PW</b>) —
        the Enterprise one only works with Cisco's own call manager.</li>
      <li>Plug its <b>NETWORK</b> port into your router, and a touch-tone corded phone into its <b>PHONE 1</b> port
        (and <b>PHONE 2</b> if you set one up). It can't hear a rotary dial.</li>
      <li>Find its address in your router's list of devices, and open <code>https://</code> and that address in a
        browser — it uses https, and its own certificate, so the browser will warn you first.
        <?php if ($d['type'] === 'ata192'): ?>
          (If it won't open, plug a computer into the adapter's <b>ETHERNET</b> port and open
          <code>https://192.168.15.1</code> instead.)
        <?php endif; ?></li>
      <li>Sign in with <b>admin</b> / <b>admin</b> — it makes you choose a new password the first time.</li>
    <?php elseif ($d['type'] === 'spa122'): ?>
      <li>Plug the adapter's <b>INTERNET</b> port into your router, and a touch-tone corded phone into its
        <b>PHONE 1</b> port (and <b>PHONE 2</b> if you set one up). It can't hear a rotary dial.</li>
      <li>Its web page is on its own little network: plug a computer into the adapter's <b>ETHERNET</b> port
        and open <code>192.168.15.1</code> in a browser. Sign in — <b>admin</b> / <b>admin</b> unless it's been
        changed. (Unplug the computer again afterwards.)</li>
    <?php else: ?>
      <li>Plug the adapter into your router, and a touch-tone corded phone into its <b>PHONE 1</b> port
        (and <b>PHONE 2</b> if you set one up). It can't hear a rotary dial.</li>
      <li>Find its address: pick up the phone, press <b>*</b> four times (<b>****</b>), then dial <b>110#</b> —
        it reads the IP address out. (Or look for it in your router.)</li>
      <li>Open that address in a browser and sign in — <b>admin</b> / <b>admin</b> unless it's been changed.</li>
    <?php endif; ?>
    <li>Under <b>Voice → Provisioning</b>, set <b>Profile Rule</b> to
      <code><?= e(CiscoProvisioning::profileRule()) ?></code> — all of it, including the part in square brackets.</li>
    <li>Make sure <b>Provision Enable</b> is <b>yes</b>, then <b>Submit</b><?= CiscoProvisioning::isAta19x($d['type']) ? ' (<b>Submit All Changes</b>)' : '' ?> and restart the adapter.
      When the phone gives a dial tone, it's on the line.</li>
  </ol>
<?php elseif ($d['ata']): ?>
  <ol class="tc-qr__steps">
    <li>Plug the adapter into your router, and a corded phone into
      <?= $d['type'] === 'ht802' ? 'its <b>Phone 1</b> socket (and <b>Phone 2</b> if you set one up)' : 'its <b>Phone</b> socket' ?>.</li>
    <li>Find its address: pick up the phone, dial <b>***</b>, then <b>02</b> — it reads the IP address out.
      (Or look for it in your router.)</li>
    <li>Open that address in a browser and sign in — the password is on the label under the adapter
      (older ones use <b>admin</b>).</li>
    <li>Under <b>Maintenance → Upgrade and Provisioning</b>: set <b>Config Upgrade Via</b> to <b>HTTP</b>,
      and <b>Config Server Path</b> to <code><?= e(substr($base, 7)) ?>/grandstream</code></li>
    <li>If it asks for a <b>Firmware Server Path</b> too, put Grandstream's own: <code>fm.grandstream.com/gs</code></li>
    <li>Set <b>HTTP/HTTPS User Name</b> to <b>twocans</b> and <b>HTTP/HTTPS Password</b> to <code><?= e($pass) ?></code></li>
    <li>Save, then reboot the adapter. When the phone gives a dial tone, it's on the line.</li>
  </ol>
<?php else: ?>
  <ol class="tc-qr__steps">
    <li>Plug the phone in and find its IP address (check your router, or the phone's menu).</li>
    <li>Open that IP in a browser to reach the phone's web UI.</li>
    <li>Under <b>Maintenance → Upgrade and Provisioning</b>, set <b>Config Upgrade Via</b> to <b>HTTP</b>,
      and <b>Config Server Path</b> to <code><?= e(substr($base, 7)) ?>/grandstream</code> — without
      <code>http://</code>, which the phone refuses.</li>
    <li>If it asks for a <b>Firmware Server Path</b> too, put Grandstream's own: <code>fm.grandstream.com/gs</code></li>
    <li>Set the HTTP username to <b>twocans</b> and the password to <code><?= e($pass) ?></code></li>
    <li>Set the remote phonebook to <code>http://twocans:<?= e($pass) ?>@<?= e(substr($base, 7)) ?>/phonebook/grandstream.xml</code></li>
    <li>Save, then reboot the phone — it fetches its settings on boot.</li>
  </ol>
<?php endif; ?>
