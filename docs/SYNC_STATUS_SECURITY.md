# IMSLP Sync Status Page Security

## Overview

The `/imslp/sync-status` page (import status, fetch controls, logs) is now password-protected to prevent unauthorized access to sensitive operations like triggering large data imports or accessing the fetch logs.

## Configuration

### Setting a Password

Set the `IMSLP_SYNC_PASSWORD` environment variable to enable password protection:

```bash
export IMSLP_SYNC_PASSWORD="your-secure-password-here"
```

**In Docker (`docker-compose.override.yml`):**

```yaml
services:
  app:
    environment:
      IMSLP_SYNC_PASSWORD: "your-secure-password"
```

**In production (`.env.local`):**

```
IMSLP_SYNC_PASSWORD=production-secret-password
```

### Disabling Password Protection

If the environment variable is **not set** or is **empty**, the sync-status page is **publicly accessible** without authentication.

```bash
# Disable protection (no env var set)
unset IMSLP_SYNC_PASSWORD
```

## How It Works

1. **First Visit**: User tries to access `/imslp/sync-status`
2. **Not Authenticated**: Redirects to `/imslp/sync-status/login`
3. **Login Form**: User enters password
4. **Verify**: Password verified using `hash_equals()` (timing-safe comparison)
5. **Session**: Sets `imslp_sync_authenticated=true` in session
6. **Access Granted**: Can now access sync-status page

## Session Details

- **Session Key**: `imslp_sync_authenticated`
- **Value**: `true` (boolean)
- **Duration**: Session lifetime (same as other session data)
- **Scope**: Separate from other IMSLP browser authentication

## Security Considerations

### Password Best Practices

1. **Strong passwords**: Use 16+ characters with mixed case, numbers, symbols
2. **Unique passwords**: Don't reuse passwords from other systems
3. **Environment variable**: Use secure config management (Vault, AWS Secrets, etc.) in production
4. **HTTPS only**: Always serve over HTTPS in production to prevent password interception

### Timing-Safe Comparison

Passwords are compared using `hash_equals()` to prevent timing-based attacks:

```php
hash_equals($correctPassword, $password)
```

This ensures comparison time doesn't leak information about password characters.

### No Account Management

- Single password (not per-user)
- No password change mechanism built-in
- Authentication is session-based (not token-based)

To change password: restart app with new `IMSLP_SYNC_PASSWORD` value.

## API Access

The JSON API endpoint (`/imslp/sync-status?json`) also requires authentication:

```bash
# Without authentication: redirects to login
curl http://localhost:8080/imslp/sync-status?json

# After logging in via browser: uses session cookie
curl -b "PHPSESSID=..." http://localhost:8080/imslp/sync-status?json
```

## Logging

- **Login attempts**: Not logged (no failed-attempt tracking)
- **Session creation**: Standard session log (if configured)
- **Access to sync-status**: No separate logging

For audit trails, configure Symfony's security event listeners or web server logging.

## Troubleshooting

### Lost Access

If you forget the password:

1. Set a new password via environment variable
2. Restart the application
3. Clear browser session (cookies will become invalid)

### Password Not Working

Check:
1. **Environment variable set**: `echo $IMSLP_SYNC_PASSWORD`
2. **Case-sensitive**: Passwords are case-sensitive
3. **No extra whitespace**: Ensure no leading/trailing spaces
4. **App restarted**: Environment changes require app restart

### Session Issues

If persistent login problems:

1. Clear browser cookies for the domain
2. Clear server session storage (`var/sessions/` if using file-based sessions)
3. Verify `IMSLP_SYNC_PASSWORD` is actually set

## Example Configurations

### Docker development (no password)

```yaml
# docker-compose.override.yml — unset for development
services:
  app:
    # IMSLP_SYNC_PASSWORD not set — public access
```

### Docker staging (with password)

```yaml
services:
  app:
    environment:
      IMSLP_SYNC_PASSWORD: "staging-sync-pass-2026"
```

### Kubernetes (from secret)

```yaml
apiVersion: v1
kind: Pod
metadata:
  name: continuo-app
spec:
  containers:
  - name: app
    env:
    - name: IMSLP_SYNC_PASSWORD
      valueFrom:
        secretKeyRef:
          name: imslp-secrets
          key: sync-password
```

## Related Routes

- **Login page**: `GET /imslp/sync-status/login`
- **Login submit**: `POST /imslp/sync-status/login`
- **Sync status**: `GET /imslp/sync-status` (protected)
- **Sync status JSON**: `GET /imslp/sync-status?json` (protected)
- **Fetch details start**: `POST /imslp/fetch-details/start` (protected via sync-status)

All routes that write (POST) to fetch operations also require authentication through session checks.
