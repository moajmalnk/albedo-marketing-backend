Here’s a message you can send to the marketing developer:

---

**Subject: Public catalog APIs for Marketing (Albedo)**

Hi,

Please use these **public, view-only** catalog APIs from **https://marketing.albedoedu.com**.

### Base URL
`https://api.albedoedu.com/api/v1/`

No login is needed for **GET**.
Create / update / delete are **not** allowed from Marketing (JWT required; do not use those).

Anonymous GET returns **active items only**.

---

### Auth
None for GET.

```http
GET /api/v1/custom_admin/syllabuses/
Accept: application/json
```

Optional dropdown filter (same result for public calls, since inactive rows are already hidden):

`?active_only=true`

---

### Endpoints (GET only)

| Item | List | Detail |
|---|---|---|
| Syllabus | `/custom_admin/syllabuses/` | `/custom_admin/syllabuses/{id}/` |
| Package | `/custom_admin/package-names/` | `/custom_admin/package-names/{id}/` |
| Course | `/custom_admin/courses/` | `/custom_admin/courses/{id}/` |
| Category | `/custom_admin/categories/` | `/custom_admin/categories/{id}/` |
| Standard | `/custom_admin/standards/` | `/custom_admin/standards/{id}/` |
| Lead Source | `/custom_admin/lead-sources/` | `/custom_admin/lead-sources/{id}/` |
| Source Code | `/custom_admin/source-codes/` | `/custom_admin/source-codes/{id}/` |
| Referral Staff | `/custom_admin/referral-staff/` | `/custom_admin/referral-staff/{id}/` |
| School Location | `/custom_admin/school-locations/` | `/custom_admin/school-locations/{id}/` |
| Support Category | `/custom_admin/support-categories/` | `/custom_admin/support-categories/{id}/` |

Full example:

`GET https://api.albedoedu.com/api/v1/custom_admin/syllabuses/`

---

### Nested filters (Lead Source)

- Source codes by lead source:
  `GET /custom_admin/source-codes/?lead_source={leadSourceId}`
- Staff by source code:
  `GET /custom_admin/referral-staff/?source_code={sourceCodeId}`

Lead Source list already includes nested `source_codes` (and staff when `requires_staff` is true).

---

### Response shapes

Most catalogs:

```json
{
  "id": "uuid",
  "name": "CBSE",
  "is_active": true,
  "date_added": "2026-01-01T10:00:00Z"
}
```

Support Category:

```json
{
  "id": "uuid",
  "name": "Payment Issue",
  "is_active": true
}
```

Lead Source:

```json
{
  "id": "uuid",
  "name": "Influence Marketing",
  "is_active": true,
  "date_added": "2026-01-01T10:00:00Z",
  "source_codes": [
    {
      "id": "uuid",
      "lead_source": "uuid",
      "lead_source_name": "Influence Marketing",
      "name": "YouTube",
      "code_type": "discrete",
      "prefix": "",
      "min_count": null,
      "max_count": null,
      "pad_width": 3,
      "requires_staff": false,
      "is_active": true,
      "date_added": "2026-01-01T10:00:00Z",
      "staff_members": []
    }
  ]
}
```

Referral Staff:

```json
{
  "id": "uuid",
  "source_code": "uuid",
  "source_code_name": "YouTube",
  "emp_code": "ALB/EMP/002",
  "name": "Staff Name",
  "is_active": true,
  "date_added": "2026-01-01T10:00:00Z"
}
```

`id` is always a **UUID**. List APIs return a JSON **array**.

---

### JS example (Marketing site)

```js
const BASE = "https://api.albedoedu.com/api/v1";

async function getCatalog(path) {
  const res = await fetch(`${BASE}${path}`);
  if (!res.ok) throw new Error(`Catalog ${res.status}`);
  return res.json();
}

const syllabuses = await getCatalog("/custom_admin/syllabuses/");
const packages = await getCatalog("/custom_admin/package-names/");
const courses = await getCatalog("/custom_admin/courses/");
const categories = await getCatalog("/custom_admin/categories/");
const standards = await getCatalog("/custom_admin/standards/");
const leadSources = await getCatalog("/custom_admin/lead-sources/");
const schoolLocations = await getCatalog("/custom_admin/school-locations/");
const supportCategories = await getCatalog("/custom_admin/support-categories/");
```

---

### Status codes
- `200` list/detail
- `401/403` if POST/PUT/PATCH/DELETE is attempted
- `404` unknown id

Do **not** call POST/PUT/PATCH/DELETE from Marketing.

---

### CORS
`https://marketing.albedoedu.com` is allowed after the backend deploy. If a local Marketing app also calls this, share that origin (`http://localhost:...`) and we can add it.

If anything fails (CORS / 401 on GET), send the exact URL and response.

Thanks.

---
