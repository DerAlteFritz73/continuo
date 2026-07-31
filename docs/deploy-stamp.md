# Last-deploy date

The footer of `/` and the search card of `/imslp` show when this copy of the app
was last put in place. `App\Service\DeployInfo` resolves it, exposed to Twig as
the global `deploy` (`config/packages/twig.yaml`), rendered by
`templates/_deploy_stamp.html.twig`.

## Where the date comes from

Three sources, tried in order:

| Source | Used by | Set how |
|--------|---------|---------|
| `APP_DEPLOYED_AT` env var | production | Baked into the Docker image at build time |
| `var/deploy-stamp` mtime | installs deployed without rebuilding the image | `touch var/deploy-stamp` in the deploy script |
| `.git/logs/HEAD` last reflog entry | development | Nothing — it is just there |

If none answers, `getDeployedAt()` returns `null` and the line is not rendered
at all. Showing no date is better than showing an invented one, so nothing here
falls back to "now".

The reflog is read as a plain file — the last line carries the commit timestamp
before the tab — rather than shelling out to `git` from PHP-FPM. It is a
development-only path anyway: `.dockerignore` excludes `.git/`.

## Building with the stamp

`BUILD_DATE` is a build arg wired through both compose files:

```bash
BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)" docker compose build app
docker compose up -d app
```

Omitting it leaves `APP_DEPLOYED_AT` empty and the app falls through to the next
source — no build breaks, the line just may not appear in production.

The `ARG`/`ENV` pair is the **last** thing in the production stage on purpose: it
changes on every build, and any layer below it would be invalidated each time. As
placed, restamping only re-runs those two instructions.

Times are displayed in UTC and labelled as such; the `<time datetime>` attribute
carries the full ISO-8601 value.
