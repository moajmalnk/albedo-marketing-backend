# Backend Deployment Checklist

## ZIP packaging

Run from repository root:

```bash
zip -r backend-production.zip backend -x "backend/tests/*" "backend/node_modules/*" "backend/.git/*"
```

## Local vs production (CORS)

The marketing frontend repo uses `VITE_API_BASE_URL=/api/v1` in `.env.local` and Vite proxies `/api` and `/sanctum` to this API. Requests from the browser are same-origin to the dev server, so **CORS is not exercised locally the same way as production**.

Production hosts the SPA at `https://marketing.albedoedu.com` and calls this API at `https://marketingapi.albedoedu.com`. The API **must** allow that origin and treat the host as stateful for Sanctum cookies.

## Required production env values

On **marketingapi** (Hostinger or your API host), set at least:

- `APP_URL=https://marketingapi.albedoedu.com`
- `FORCE_HTTPS=true`
- `DB_HOST=127.0.0.1`
- `DB_DATABASE=u262074081_albedo_market`
- `DB_USERNAME=u262074081_albedo_market`
- `DB_PASSWORD=<set on server>`
- `SESSION_DOMAIN=.albedoedu.com`
- `CORS_ALLOWED_ORIGINS=https://marketing.albedoedu.com,https://albedoedu.com,https://www.albedoedu.com`
- `SANCTUM_STATEFUL_DOMAINS=marketing.albedoedu.com,albedoedu.com,www.albedoedu.com`

Include `http://localhost:8080,http://127.0.0.1:8080` in `CORS_ALLOWED_ORIGINS` (and matching hosts in `SANCTUM_STATEFUL_DOMAINS`) only if you need direct browser calls from local dev **without** the Vite proxy.

After changing `.env` on the server:

```bash
php artisan config:clear
php artisan config:cache
```

(Use `config:cache` only if you normally cache config in production.)

## Smoke tests

- `GET /up` returns 200.
- `POST /api/v1/auth/login` returns token/user.
- `GET /api/v1/auth/me` returns 200 with valid token or stateful session.
- `GET /api/v1/leads` returns paginated payload.
- `PATCH /api/v1/leads/{id}/stage` updates stage and returns transition.
- `POST /api/v1/leads/import` returns per-row results.
- Telecaller access without check-in returns 423 and frontend redirects to check-in.

### CORS (production SPA)

From DevTools on `https://marketing.albedoedu.com`, open Network and confirm responses from `marketingapi.albedoedu.com` include:

- `Access-Control-Allow-Origin: https://marketing.albedoedu.com` (must match the page origin exactly when using credentials).
- `Access-Control-Allow-Credentials: true`

Check at least `GET /sanctum/csrf-cookie` (often 204) and `GET /api/v1/auth/me` (401 when logged out is fine; the response must still expose the CORS headers so the browser does not block the client).

Command-line sanity check (replace host if needed; local `php artisan serve` uses `http://127.0.0.1:8000`):

```bash
curl -sI -H "Origin: https://marketing.albedoedu.com" "https://marketingapi.albedoedu.com/sanctum/csrf-cookie" | tr -d '\r' | grep -i access-control
curl -sI -H "Origin: https://marketing.albedoedu.com" "https://marketingapi.albedoedu.com/api/v1/auth/me" | tr -d '\r' | grep -i access-control
```

You should see `access-control-allow-origin` and `access-control-allow-credentials` on those responses.
