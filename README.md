# WorkState API

Personal Laravel API that checks German working-time rules (Arbeitszeitgesetz) against an employee's contract and time logs.

The results are a compliance aid, not a legal opinion. Municipal holidays that do not apply to a whole federal state are not flagged.

## Requirements

- PHP 8.4 or newer
- Composer
- SQLite (used by the steps below) or PostgreSQL 16

## Local installation

```bash
git clone https://github.com/SaraL6/workstate-api.git
cd workstate-api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan test
```

`migrate --seed` creates a local demo user. The default password is for a database on your machine only. Change `SEED_USER_PASSWORD` in `.env` before anyone else can reach the database.

## Authentication

Protected routes use [Laravel Sanctum](https://laravel.com/docs/sanctum) personal access tokens. A token is issued only after a successful email and password check, and it can call the audit and contract-parse endpoints.

```bash
curl -s -H "Content-Type: application/json" \
  -d '{"email":"demo@workstate.test","password":"password","device_name":"laptop"}' \
  http://127.0.0.1:8000/api/v1/auth/token
```

```json
{
  "data": {
    "token_type": "Bearer",
    "abilities": ["compliance:audit", "contracts:parse"],
    "token": "1|replace-with-the-token-from-the-response"
  }
}
```

Send that token on later requests:

```http
Authorization: Bearer 1|replace-with-the-token-from-the-response
```

Login attempts are limited to 5 per minute per email address and IP. A token without the ability required by a route receives `403`.

## Compliance audit

`POST /api/v1/compliance/audit`

Each time log is one shift. Put breaks inside a shift in `break_minutes`. The token ability is `compliance:audit`.

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"employee_id":1,"week_start":"2026-10-12","week_end":"2026-10-18","time_logs":[{"work_date":"2026-10-12","start_time":"2026-10-12 08:00:00","end_time":"2026-10-12 19:00:00","break_minutes":30}]}' \
  http://127.0.0.1:8000/api/v1/compliance/audit
```

```json
{
  "data": {
    "employee_id": 1,
    "week_start": "2026-10-12",
    "week_end": "2026-10-18",
    "compliant": false,
    "summary": {
      "violations": 1,
      "errors": 1,
      "warnings": 0
    },
    "violations": [
      {
        "code": "max_daily_hours",
        "severity": "error",
        "message": "Daily working time exceeds the 10 hour Arbeitszeitgesetz limit.",
        "employee_id": 1,
        "work_date": "2026-10-12",
        "context": {
          "hours": 10.5,
          "limit_hours": 10
        }
      }
    ]
  }
}
```

`compliant` is true when there are no `error` violations. Warnings do not fail the audit.

Checked rules:

- More than 10 hours in a day is an error. Between 8 and 10 hours is a warning that must return to an 8-hour average within 24 weeks.
- The 24 weeks ending on `week_end` may not average more than 8 hours per Monday–Saturday.
- The gap from one shift's `end_time` to the next `start_time` must be at least 11 hours. A longer `custom_rest_period_hours` is a warning when the statutory gap is met and the contract gap is not.
- Sunday work and statewide public holidays for `employees.federal_state` are errors unless `raw_rules.sunday_holiday_exempt` is true.
- Weekly totals are compared with `max_weekly_hours`, `contract_hours_per_week`, and `allowed_overtime_cap`.

Federal state codes: `BW`, `BY`, `BE`, `BB`, `HB`, `HH`, `HE`, `MV`, `NI`, `NW`, `RP`, `SL`, `SN`, `ST`, `SH`, `TH`.

## Contract parsing

`POST /api/v1/contracts/parse`

The token ability is `contracts:parse`. Set `OPENAI_API_KEY` before calling it. The contract text is sent as the user message with `response_format` set to `json_object`. The model output is rejected unless it matches the four fields below.

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"contract_text":"The employee works 40 hours per week in Germany. Overtime is capped at 8 hours. Rest between shifts is 11 hours. Remote work is permitted in Germany."}' \
  http://127.0.0.1:8000/api/v1/contracts/parse
```

```json
{
  "data": {
    "max_weekly_hours": 40,
    "allowed_overtime_cap": 8,
    "custom_rest_period_hours": 11,
    "remote_work_country": "DE"
  }
}
```

Save that object on `employees.raw_rules` when the audit should enforce it. Add `"sunday_holiday_exempt": true` only when the contract allows Sunday and public-holiday work.

## PostgreSQL

The default `.env` uses SQLite. For PostgreSQL 16, start the database and switch the connection:

```bash
docker compose up -d
```

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=workstate
DB_USERNAME=workstate
DB_PASSWORD=workstate
```

Change the password in `.env` and `docker-compose.yml` before the database port is reachable from another machine. Then run `php artisan migrate --seed` again.
