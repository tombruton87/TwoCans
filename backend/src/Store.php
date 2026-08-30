<?php
declare(strict_types=1);

/**
 * Session-backed store holding the demo data from the design handoff.
 *
 * Every read and write the views perform goes through here, so wiring the real
 * system later means replacing these method bodies with MariaDB queries and
 * Asterisk ARI/AMI calls — the views and actions stay as they are.
 */
final class Store
{
    private array $data;

    public function __construct()
    {
        if (!isset($_SESSION['tc'])) {
            $_SESSION['tc'] = self::seed();
        }
        $this->data = &$_SESSION['tc'];
    }

    public function reset(): void
    {
        $_SESSION['tc'] = self::seed();
        $this->data = &$_SESSION['tc'];
    }

    // --------------------------------------------------------------- settings

    // Quiet hours live in the database (SettingsRepository): the generated
    // dialplan is built from them, so a session copy could never stop a call.

    public function settings(): array
    {
        return (new SettingsRepository())->toView();
    }

    public function toggleQuietHours(): bool
    {
        return (new SettingsRepository())->toggleQuietHours();
    }

    // ---------------------------------------------------------------- devices
    //
    // Devices moved to the database (DeviceRepository) — Asterisk config is
    // generated from them, so they must outlive the session.

    // --------------------------------------------------------------- contacts
    //
    // The allowlist lives in the database (ContactRepository) — the generated
    // dialplan is built from it, so it must outlive the session.

    // ------------------------------------------------------- ask-to-call queue

    public function requests(): array
    {
        return $this->data['requests'];
    }

    public function removeRequest(string $id): void
    {
        $this->data['requests'] = array_values(array_filter(
            $this->data['requests'],
            static fn($r) => $r['id'] !== $id
        ));
    }

    // ------------------------------------------------------------------ calls
    //
    // The call log is built from Asterisk's CDR output (CallRepository).

    // -------------------------------------------------------------- voicemail
    //
    // Messages live in Asterisk's spool and are imported by
    // VoicemailRepository; app_voicemail owns the audio.

    // -------------------------------------------------------------- guardians
    //
    // Guardian records themselves live in MariaDB (GuardianRepository) — they
    // must survive a restart. Only the invite form's UI state is session-held.

    public function inviteRole(): string
    {
        return $this->data['inviteRole'];
    }

    public function setInviteRole(string $role): void
    {
        if (in_array($role, ['Admin', 'Viewer'], true)) {
            $this->data['inviteRole'] = $role;
        }
    }

    // ------------------------------------------------------------- SIP trunk
    //
    // The trunk now lives in the database (TrunkRepository) — it must survive a
    // restart and its auth token is encrypted. The wizard's draft stays here.

    public function trunk(): array
    {
        return (new TrunkRepository())->get();
    }

    public function isLowCredit(): bool
    {
        return (new TrunkRepository())->isLowCredit();
    }

    // ---------------------------------------------------------- dynamic DNS
    //
    // The setup lives in the database (DynamicDnsRepository) — its API token is
    // encrypted and the once-a-minute check runs outside any session. Only the
    // rejected form's non-secret fields stay here.

    public function dynamicDns(): array
    {
        return (new DynamicDnsRepository())->get();
    }

    /** What a rejected dynamic DNS form should show again. Never the token. */
    public function ddnsDraft(): array
    {
        return array_merge(['zone' => '', 'hostname' => ''], $this->data['ddnsDraft'] ?? []);
    }

    public function setDdnsDraft(array $fields): void
    {
        $this->data['ddnsDraft'] = array_merge($this->ddnsDraft(), $fields);
    }

    public function resetDdnsDraft(): void
    {
        $this->data['ddnsDraft'] = ['zone' => '', 'hostname' => ''];
    }

    // ------------------------------------------------------------ live call
    //
    // Real calls come from Asterisk (LiveCalls). Only the chosen listening
    // mode is UI state.

    public function listenMode(): string
    {
        return $this->data['listenMode'];
    }

    public function setListenMode(string $mode): void
    {
        if (in_array($mode, ['listen', 'whisper', 'join'], true)) {
            $this->data['listenMode'] = $mode;
        }
    }

    // ---------------------------------------------------------------- drafts

    public function deviceDraft(): array
    {
        return $this->data['deviceDraft'];
    }

    public function setDeviceDraft(array $fields): void
    {
        $this->data['deviceDraft'] = array_merge($this->data['deviceDraft'], $fields);
    }

    public function resetDeviceDraft(): void
    {
        $this->data['deviceDraft'] = ['model' => null, 'name' => ''];
    }

    public function trunkDraft(): array
    {
        return array_merge(
            ['provider' => 'Twilio', 'sid' => '', 'token' => '', 'number' => '', 'termination' => '', 'apiKey' => '', 'proxy' => ''],
            $this->data['trunkDraft']
        );
    }

    public function setTrunkDraft(array $fields): void
    {
        $this->data['trunkDraft'] = array_merge($this->data['trunkDraft'], $fields);
    }

    public function resetTrunkDraft(): void
    {
        $this->data['trunkDraft'] = ['provider' => 'Twilio', 'sid' => '', 'token' => '', 'number' => '', 'termination' => '', 'apiKey' => '', 'proxy' => ''];
    }

    // ------------------------------------------------------------------ seed

    /**
     * Demo content lifted verbatim from the design prototype. Replace with
     * repository lookups once the database is wired.
     */
    private static function seed(): array
    {
        return [
            'listenMode' => 'listen',
            'inviteRole' => 'Admin',
            'deviceDraft' => ['model' => null, 'name' => ''],
            'trunkDraft' => ['provider' => 'Twilio', 'sid' => '', 'token' => '', 'number' => '', 'termination' => '', 'apiKey' => '', 'proxy' => ''],
            'ddnsDraft' => ['zone' => '', 'hostname' => ''],





            'requests' => [
                ['id' => 'r1', 'label' => 'Maybe "Sam from school"?', 'number' => '+1 (628) 555-0117', 'note' => 'Dialled 3 times today', 'when' => '4:12pm'],
            ],

            'trunk' => [
                'connected' => true, 'provider' => 'Twilio', 'number' => '+1 (628) 555-0100',
                'balance' => 4.20, 'currency' => '$', 'lowThreshold' => 5,
                'minutesThisMonth' => 142, 'rate' => '$0.013/min', 'autoTopUp' => false,
            ],


        ];
    }
}
