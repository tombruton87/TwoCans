# Contributing to TwoCans

Thanks for wanting to help! TwoCans is a parent-run phone line — contributions
that keep it simple, safe and self-hostable are welcome.

## Getting set up

1. `./install.sh --check` to confirm Docker, Compose and the ports are OK.
2. `./install.sh` to write `.env` and bring the stack up.
3. Open the printed URL and complete first-run setup.

## Working on the code

`make dev` runs the same stack a household does (`compose.yaml`), with
`compose.dev.yml` on top: the web image is built here and `./backend` is
mounted into it, so a change to a PHP file shows on the next page load — no
rebuild. The transcriber and pager see the same code. Nothing else differs:
same containers, volumes and ports, so the pager, the once-a-minute jobs and
certificates run as they would anywhere. Rebuild (`make dev` again) only after
changing something under `docker/`. `./install.sh` and `./twocans` recognise a
development setup and keep it one.

## Common workflows

```bash
# start everything, from the code in this folder
make dev

# re-apply schema changes after editing migrations/
make migrate

# run the PHP test suite
make test
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

## Checks

Every push and pull request runs `.github/workflows/checks.yml`: the shell
scripts parse, and the whole test suite runs inside the web image against a
fresh MariaDB with every migration applied.

## Making a release

1. Set the version in `backend/VERSION` (it's baked into the web image, and
   `./twocans version` and `./twocans update` compare it with GitHub's latest).
2. Move the changelog's **Unreleased** entries under a new version heading.
3. Commit, tag `vX.Y.Z`, and push both.
4. Pushing the tag runs `.github/workflows/release.yml`: it builds the web and
   speech-to-text images natively for Intel/AMD and ARM, publishes each pair
   under one name as `X.Y.Z` and `latest` (not for a pre-release like
   `v1.0.0-rc1`), then pulls them on both chip types and checks they start. It
   refuses a tag that doesn't match `backend/VERSION`.
5. Publish the GitHub release, with the changelog section as its notes.

To rebuild the images for a tag that already exists, run the workflow by hand
(Actions → Release images → Run workflow). It needs two repository secrets:
`DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` (a Docker Hub access token with read
and write).

## Opening a PR

- One logical change per PR. Fill in the issue template.
- Rebase onto `main` before submitting; keep the commit history tidy.
- If you're changing how the phone system behaves, test on a real handset/ATA
  before asking for review.
