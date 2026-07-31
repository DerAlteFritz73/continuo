# Visitor comments

A "Leave a comment" card sits at the bottom of the realizer page (`/`). Every
comment is stored in the database, then a notification e-mail is sent to the
site owner.

## Flow

1. `templates/continuo/_comment_form.html.twig` renders the form (e-mail +
   comment, both mandatory), included from `templates/continuo/index.html.twig`.
2. `public/js/comment.js` posts it to `POST /comment` with `fetch`, so the page
   — and any realization already on screen — survives the submission.
3. `App\Controller\CommentController::post()` validates, rate-limits, persists.
4. `App\Service\CommentNotifier` mails the comment to `COMMENT_NOTIFY_TO`.

The comment is **persisted before the mail is attempted**, and `CommentNotifier`
never throws: a dead SMTP relay loses the notification, never the comment. The
outcome is recorded on the row itself (`notified`, `notify_error`), so failures
are visible in phpMyAdmin instead of only in the log.

## Validation

The e-mail address is mandatory and must be a valid address. Constraints live on
the entity (`App\Entity\Comment`) and are checked server-side; `comment.js`
repeats a looser version of the same checks purely to save a round trip.

| Field   | Rules                                          |
|---------|------------------------------------------------|
| `email` | required, RFC-strict e-mail, ≤ 180 chars       |
| `body`  | required, ≤ 5000 chars                         |

Abuse guards on this public endpoint: a `website` honeypot field (hidden
off-screen; if filled, the request is silently discarded with a success
response) and a cap of 5 comments per IP per hour (HTTP 429).

There is no CSRF token — the endpoint is unauthenticated and carries no session
state worth forging, and no other form in this app uses one.

## Configuration

```dotenv
# .env — committed defaults
MAILER_DSN=null://null                          # swallows everything
COMMENT_NOTIFY_TO=jm.kreilos@outlook.fr
COMMENT_MAIL_FROM=noreply@continuo.kreilos.fr
```

Override `MAILER_DSN` in `.env.local` (gitignored) to actually deliver:

```dotenv
MAILER_DSN=smtp://user:pass@smtp-mail.outlook.com:587
```

`COMMENT_MAIL_FROM` must be an address the relay is allowed to send as. The
commenter's own address goes into `Reply-To`, so replying answers them directly;
using it as `From` would get the message rejected or spam-filed.

### Dev

`docker-compose.override.yml` runs [mailpit](https://mailpit.axllent.org/) as
the `mailer` service — an SMTP sink with a web UI on <http://localhost:8025>.
`.env.local` points `MAILER_DSN` at it, so comments never leave the machine
during development. Remove the service and the DSN line if you don't want it.

## Schema

`migrations/Version20260731000000.php` creates the `comment` table:

| Column         | Notes                                        |
|----------------|----------------------------------------------|
| `email`        | commenter's address                          |
| `body`         | the comment                                  |
| `created_at`   | indexed                                      |
| `locale`       | UI language at submission time               |
| `ip_address`   | used by the per-IP rate limit                |
| `notified`     | `null` = not attempted, `0` = mail failed    |
| `notify_error` | transport error when `notified = 0`          |

Run it inside the container:

```bash
docker exec continuo-app-1 php bin/console doctrine:migrations:migrate --no-interaction
```
