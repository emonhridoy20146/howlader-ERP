# Field Operations API (`/api/field/v1`)

This module is the API that the Howlader Field Operations backend (field-api) uses to read customers and post field collections. It was built against a copy of the real data first and tested there. It is **off by default**: every route answers 404 until `FIELD_API_ENABLED=true`.

The full contract is `erp-integration-spec.md` in the field-api repository (docs/). The field-by-field mapping is `erp-mapping.md` in the same folder.

## What it adds

Everything is a new file, except for one line in `bootstrap/providers.php` and the new block in `.env.example`. No existing route, table, model or service is changed. The only new table is `field_payment_requests`.

| File | Purpose |
|---|---|
| `config/field_api.php` | On/off switch, token hashes, integration user, back-date window, rate limit |
| `app/Providers/FieldApiServiceProvider.php` | Registers the routes under `/api/field/v1` and the rate limiter |
| `app/Http/Middleware/AuthenticateFieldApiToken.php` | Checks the bearer token against a SHA-256 hash and logs in the integration user. Answers 404 while the API is off |
| `routes/field-api.php` | The 9 endpoints |
| `app/Http/Controllers/Api/Field/FieldDirectoryController.php` | `/health`, `/branches`, `/employees`, `/payment-methods` |
| `app/Http/Controllers/Api/Field/FieldCustomerController.php` | `/customers` (paged delta sync), `/customers/{id}`, `/customers/{id}/payments` |
| `app/Http/Controllers/Api/Field/FieldPaymentController.php` | `POST /payments`, `GET /payments/by-idempotency-key/{key}` |
| `app/Services/FieldApi/FieldCustomerPresenter.php` | Builds the customer, invoice, plan and payment JSON. Never returns NID numbers or images |
| `app/Services/FieldApi/FieldPaymentIntake.php` | Checks a field collection in the ERP's own order, then records it through the existing `PaymentService::recordAndAllocate` |
| `app/Services/FieldApi/FieldApiError.php` | Error codes (spec §6) |
| `app/Models/FieldPaymentRequest.php` | Idempotency record for each field collection |
| `database/migrations/2026_11_20_000001_create_field_payment_requests_table.php` | Creates `field_payment_requests` |
| `app/Console/Commands/FieldApiSetupCommand.php` | `php artisan field-api:setup`: creates the integration role and user (not an admin, `payment.create` only) and prints a new token once |
| `tests/Feature/FieldApi/FieldApiTest.php` | 6 feature tests |

A field collection becomes a normal ERP payment, made by the integration user, with the staff member's ERP employee as the collector. The office sees it like any other payment and can remove it the usual way; field-api then shows it as removed.

## Turn it on (production)

1. Back up the database.
2. Deploy this code as usual.
3. Run `php artisan migrate`. This creates only `field_payment_requests`.
4. Run `php artisan field-api:setup`. Add `--allow-same-day` only if the owner decides that one installment invoice may take two payments on the same day (OD-27; the default is no, and field-api then shows the second one to the office as a conflict).
5. Put the three printed lines (`FIELD_API_USER_ID`, `FIELD_API_TOKEN_SHA256`, `FIELD_API_ENABLED=true`) into `.env`. Keep the printed token: it goes to field-api as `ERP_API_TOKEN` and is never stored in the ERP.
6. Run `php artisan config:cache && php artisan route:cache`.
7. Check `curl -H "Authorization: Bearer <token>" https://<erp-host>/api/field/v1/health`. It should answer `{"status":"ok", ...}`.

The API must be reached over HTTPS. If the ERP sits behind a firewall, allow field-api's server address.

## `.env` names

```dotenv
FIELD_API_ENABLED=false
FIELD_API_USER_ID=
FIELD_API_TOKEN_SHA256=
FIELD_API_TOKEN_NEXT_SHA256=
FIELD_API_MAX_BACKDATE_DAYS=
FIELD_API_RATE_PER_MINUTE=300
```

`FIELD_API_MAX_BACKDATE_DAYS` empty means a collection of any past date is accepted (owner decision OD-28, as the office can with the back-date permission); a number limits it to that many days back. A date in the future is always refused.

`FIELD_API_TOKEN_NEXT_SHA256` lets a new token work alongside the old one while field-api is switched over (run `field-api:setup` again to get a new token).

## Switch off

Set `FIELD_API_ENABLED=false` and run `php artisan config:cache`. Every route then answers 404. The table can stay.

## Tests

```bash
php artisan test tests/Feature/FieldApi
```

All 6 pass on a copy of the real data, and the rest of the suite gives the same result with and without this module.
