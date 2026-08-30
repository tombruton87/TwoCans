# Security Policy

## Reporting a vulnerability

TwoCans is a self-hosted home phone line, so the issues that matter are ones
that could let an outsider listen to your kids' calls, read the call log, or
take over the box. If you find one, **please don't post it publicly.**

Email the maintainers (Hamlet Digital) privately before opening an issue.
Include a short description, the affected version, and how to reproduce. We'll
acknowledge and work with you to get it fixed, and we're happy to credit you in
the changelog if you'd like.

## What the app already does

- Passwords are hashed with **argon2id** at OWASP's recommended parameters.
- **Lockout** after 5 failed attempts per email (20 per IP) in a 15-minute
  window.
- **No user enumeration** — unknown email, wrong password, and a guardian with
  no password set all return the same message, and timing is matched.
- **Sessions are regenerated** on every privilege change.
- Roles are enforced **server-side** on a deny-by-default action map.

## Scope & things to be careful about when self-hosting

- Don't expose the database or **Adminer** to the internet (they aren't
  published by default). Only the PHP container talks to MariaDB.
- Keep `.env` out of version control — it's git-ignored and holds secrets.
- TLS is supported but not enabled by default — see the README's HTTPS section.
- Asterisk's SIP (5060) and RTP (10000–10100) ports must be reachable by your
  phones; on the public internet keep those firewalled to your network.
