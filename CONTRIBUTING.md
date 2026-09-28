# Contributing to TwoCans

Thanks for wanting to help! TwoCans is a parent-run phone line — contributions
that keep it simple, safe and self-hostable are welcome.

## Getting set up

1. `./install.sh --check` to confirm Docker, Compose and the ports are OK.
2. `./install.sh` to write `.env` and bring the stack up.
3. Open the printed URL and complete first-run setup.

## Common workflows

```bash
# start everything
make up

# re-apply schema changes after editing migrations/
make migrate

# run the PHP test suite
docker compose exec web php /var/www/html/bin/test.php
```

(see `make help` for the rest)

## Conventions

- PHP, server-rendered, no framework. Views live in `backend/views/`, logic in
  `backend/src/`.
- Any new user-facing action needs a matching entry in `backend/src/actions.php`
  where roles are enforced (deny by default).
- Keep the security properties listed in `SECURITY.md` intact — don't weaken
  them.
- Run `php -l` on changed PHP files, and add a test under `backend/tests/` where
  it makes sense.

## Making a release

1. Set the version in `backend/VERSION` (it's baked into the web image, and
   `./twocans version` and `./twocans update` compare it with GitHub's latest).
2. Move the changelog's **Unreleased** entries under a new version heading.
3. Commit, tag `vX.Y.Z`, push both, and publish the GitHub release.
4. Build the web image from the tag and push it as `X.Y.Z` and `latest`.

## Opening a PR

- One logical change per PR. Fill in the issue template.
- Rebase onto `main` before submitting; keep the commit history tidy.
- If you're changing how the phone system behaves, test on a real handset/ATA
  before asking for review.
