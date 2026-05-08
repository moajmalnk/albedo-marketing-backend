# Backend Deployment Checklist

## ZIP packaging

Run from repository root:

```bash
zip -r backend-production.zip backend -x "backend/tests/*" "backend/node_modules/*" "backend/.git/*"
```

## Required production env values

- `APP_URL=https://marketingapi.albedoedu.com`
- `FORCE_HTTPS=true`
- `DB_HOST=127.0.0.1`
- `DB_DATABASE=u262074081_albedo_market`
- `DB_USERNAME=u262074081_albedo_market`
- `DB_PASSWORD=<set on server>`
- `SESSION_DOMAIN=.albedoedu.com`
- `CORS_ALLOWED_ORIGINS=https://albedoedu.com,https://www.albedoedu.com,https://<frontend-origin>`
- `SANCTUM_STATEFUL_DOMAINS=albedoedu.com,www.albedoedu.com,<frontend-host>`

## Smoke tests

- `GET /up` returns 200.
- `POST /api/v1/auth/login` returns token/user.
- `GET /api/v1/auth/me` returns 200 with valid token or stateful session.
- `GET /api/v1/leads` returns paginated payload.
- `PATCH /api/v1/leads/{id}/stage` updates stage and returns transition.
- `POST /api/v1/leads/import` returns per-row results.
- Telecaller access without check-in returns 423 and frontend redirects to check-in.
