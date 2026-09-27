-- Passkeys: signing in with Face ID, Touch ID or a fingerprint.
--
-- A passkey is a key pair made on the grown-up's own phone; the private half
-- never leaves it and is unlocked by the phone's biometrics. The box keeps the
-- public half and checks each sign-in against it (see WebAuthn.php). A
-- guardian can have one per device, and removing a guardian removes theirs.
CREATE TABLE IF NOT EXISTS guardian_passkeys (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    guardian_id    INT UNSIGNED NOT NULL,
    credential_id  VARCHAR(512) NOT NULL,          -- base64url, as the browser gives it
    public_key     TEXT         NOT NULL,          -- PEM
    sign_count     INT UNSIGNED NOT NULL DEFAULT 0,
    label          VARCHAR(80)  NOT NULL DEFAULT '',
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at   DATETIME     NULL,
    UNIQUE KEY uq_guardian_passkeys_cred (credential_id(255)),
    KEY ix_guardian_passkeys_guardian (guardian_id),
    CONSTRAINT fk_guardian_passkeys_guardian FOREIGN KEY (guardian_id)
        REFERENCES guardians (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
