



## Albedo CRM Marketing API

Production base URL: `https://marketingapi.albedoedu.com`

### Environment variables

Set in production `.env` (do not commit):

- `APP_URL=https://marketingapi.albedoedu.com`
- `FORCE_HTTPS=true`
- `CORS_ALLOWED_ORIGINS=https://albedoedu.com,https://www.albedoedu.com,https://<your-vercel-prod-domain>`
- `DB_CONNECTION=mysql`
- `DB_HOST=...`
- `DB_DATABASE=u262074081_albedo_market`
- `DB_USERNAME=u262074081_albedo_market`
- `DB_PASSWORD=...`
- `SANCTUM_STATEFUL_DOMAINS=albedoedu.com,www.albedoedu.com,<frontend-host>`

### Deploy runbook (Nginx + PHP-FPM)

- **Install**: PHP 8.2+, Composer, Nginx, MySQL client.
- **Deploy** (from `backend/`):

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=LeadStageSeeder --force
```

- **Post-deploy checks**
  - `GET /up` returns 200
  - login token issuance (`POST /api/v1/auth/login`)
  - CORS preflight from allowlisted origins only
  - webhook endpoint reachable (`POST /api/v1/telephony/webhook`)

### Shared hosting fallback (no SSH)

- Set a one-time token in `.env`:
  - `STORAGE_LINK_TOKEN=<long-random-token>`
- Open:
  - `https://marketingapi.albedoedu.com/storage-link.php?token=<long-random-token>`
- Delete `public/storage-link.php` immediately after successful execution.

# albedo-marketing-backend

