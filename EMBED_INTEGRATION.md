# SafetyFlash – Public Embed Integration

This document explains how to embed SafetyFlash views (carousel or archive) in external websites using a signed token.

---

## Overview

The public embed system exposes a read-only iframe endpoint at `/public.php`.  
Access is controlled by **signed embed tokens** issued from the admin UI (`?page=embed_admin`).

### Views

| View type | Description |
|-----------|-------------|
| `carousel` | Auto-playing slideshow of active (published, non-archived) safety flashes |
| `archive`  | Paginated list with worksite / date / keyword filters |

---

## Token flow

```
Admin UI → Create token → Token stored in sf_embed_tokens → Give token to content editor
Content editor → Paste iframe snippet into CMS → Visitors see SafetyFlash embed
```

### 1. Issue a token

1. Log in as an administrator.
2. Navigate to **?page=embed_admin**.
3. Fill in:
   - **Label** – human-readable name for the token
   - **View type** – carousel or archive
   - **Worksite** – restrict to one site, or leave blank for all sites
   - **Allowed Origin** – exact scheme+host of the embedding website, e.g. `https://intra.company.fi`
   - **Validity (days)** – token lifetime (1–365 days)
   - **Carousel interval** – auto-advance interval in seconds (carousel only)
4. Click **Create token**.
5. **Copy the token immediately** – it is not stored in plain text and cannot be retrieved later.

### 2. Use the token

Paste the generated iframe snippet into your website. Example (carousel):

```html
<div style="position:relative;width:100%;padding-top:56.25%;">
  <iframe src="https://safetyflash.example.com/public.php?t=TOKEN_HERE&interval=15"
          style="position:absolute;inset:0;width:100%;height:100%;border:0;"
          loading="lazy" referrerpolicy="no-referrer"
          sandbox="allow-scripts allow-same-origin"
          title="SafetyFlash"></iframe>
</div>
```

Example (archive with dynamic height):

```html
<iframe id="sfEmbed"
        src="https://safetyflash.example.com/public.php?t=TOKEN_HERE"
        style="width:100%;border:0;" title="SafetyFlash"></iframe>
<script>
window.addEventListener('message', function(e) {
  if (e.origin !== 'https://safetyflash.example.com') return;
  if (e.data && e.data.type === 'sf-embed-height') {
    document.getElementById('sfEmbed').style.height = e.data.height + 'px';
  }
});
</script>
```

### 3. Revoke a token

In **?page=embed_admin**, click **Peruuta** (Revoke) next to the token. The embed will immediately return HTTP 410.

---

## API endpoints

The embed views consume internal JSON APIs. These are accessible only with a valid token.

| Endpoint | Description |
|----------|-------------|
| `GET /app/api/public/active.php?t=TOKEN` | Active (published, non-archived) flashes for carousel |
| `GET /app/api/public/flashes.php?t=TOKEN` | Paginated flash list with filters (archive) |
| `GET /app/api/public/sites.php?t=TOKEN` | Available worksites for filter dropdown |

### flashes.php query parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `site` | – | Filter by worksite name (ignored if token is site-scoped) |
| `q` | – | Full-text search in title / summary |
| `from` | – | Start date `YYYY-MM-DD` |
| `to` | – | End date `YYYY-MM-DD` |
| `p` | 1 | Page number |
| `per_page` | 12 | Items per page (max 50) |

---

## Security headers

`public.php` applies embed-specific headers that replace the default `frame-ancestors 'none'`:

```
Content-Security-Policy: ... frame-ancestors https://intra.company.fi; ...
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: geolocation=(), microphone=(), camera=()
Cache-Control: private, no-store
```

The `X-Frame-Options` and `Cross-Origin-*` headers set by `security_headers.php` are removed for the public endpoint.

---

## Rate limiting

| Scope | Limit |
|-------|-------|
| IP (public.php) | 120 requests / minute |
| IP (API endpoints) | 60 requests / minute |
| Per token (JTI) | 1 000 requests / hour |

Rate limits are tracked in the `sf_public_rate_limit` table.

---

## Configuration

Add to `.env`:

```env
# Required: generate with  openssl rand -hex 32
EMBED_SECRET=your-secret-here

# Optional: multiple keys for rotation
# EMBED_KEYS_JSON={"v1":"secret1","v2":"secret2"}
```

---

## Database tables

| Table | Purpose |
|-------|---------|
| `sf_embed_tokens` | Token registry (metadata, revocation) |
| `sf_public_views_log` | Audit log of all public view events |
| `sf_public_rate_limit` | Rate limit counters |
| `sf_flashes.public_uid` | Stable public identifier for each flash |

Run the migrations:

```bash
mysql -u user -p dbname < migrations/2026_05_embed_tables.sql
mysql -u user -p dbname < migrations/2026_05_add_public_uid_to_flashes.sql
```

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| HTTP 401 – "Invalid or missing embed token" | Token is malformed or EMBED_SECRET is wrong | Verify EMBED_SECRET in .env matches the one used when the token was issued |
| HTTP 401 – "Token has expired" | Token TTL has passed | Revoke and issue a new token |
| HTTP 410 – "Token has been revoked" | Token was manually revoked | Issue a new token |
| HTTP 401 – "Token not found in registry" | Token was deleted from DB or was never saved | Re-issue a token from the admin UI |
| HTTP 403 – "not authorized for this origin" | The embedding page's origin doesn't match the token's `aud` claim | Check that `allowed_origin` in the token matches the exact scheme+host of the embedding page |
| Embed appears but shows "Ei aktiivisia SafetyFlasheja" | No published, non-archived flashes exist (or site filter returns nothing) | Publish at least one flash; check site restriction on token |
| iframe height doesn't auto-adjust (archive) | postMessage listener missing or wrong origin | Add the provided postMessage script and ensure the origin matches |

---

## Security considerations

- Tokens are signed with HMAC-SHA256. They cannot be forged without the `EMBED_SECRET`.
- The `EMBED_SECRET` must be kept confidential. Use `openssl rand -hex 32` to generate a strong secret.
- Token revocation is immediate – revoked tokens are checked on every request against the database.
- The `jti` (unique token ID) prevents token reuse across different installations.
- All public view events are logged in `sf_public_views_log` for audit purposes.
- The embed iframe should always use `sandbox="allow-scripts allow-same-origin"` and `referrerpolicy="no-referrer"` to minimize the attack surface.
- Never embed the token in client-side code that is publicly visible beyond the iframe `src` attribute.
