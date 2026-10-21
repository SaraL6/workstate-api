# WorkState API

Personal Laravel API for checking German working-time rules (Arbeitszeitgesetz) against an employee's contract and time logs.

This is a compliance aid, not a legal opinion. Municipal holidays that do not apply to a whole federal state are not flagged.

## Requirements

- PHP 8.4+
- Composer
- PostgreSQL 16 (Docker Compose file included)
- An OpenAI API key for contract parsing

## Setup

```bash
cp .env.example .env
php artisan key:generate
docker compose up -d
php artisan migrate
```

Set `WORKSTATE_API_TOKEN` and `OPENAI_API_KEY` in `.env`. The local Postgres credentials in `.env.example` match `docker-compose.yml`. Change them before exposing the database.

Tests use SQLite in memory and do not need Postgres:

```bash
php artisan test
```

## Authentication

Every `/api/*` route requires:

```http
Authorization: Bearer <WORKSTATE_API_TOKEN>
```

The token identifies this deployment. There are no per-user accounts; a valid token can audit the employees stored in this database.

## Endpoints

### `POST /api/v1/compliance/audit`

Checks submitted time logs for one employee. Each time log is one shift. Put breaks inside a shift in `break_minutes`.

Stored logs outside the submitted dates still count toward the 24-week average and the rest gap before the first shift.

```json
{
  "employee_id": 1,
  "week_start": "2026-10-12",
  "week_end": "2026-10-18",
  "time_logs": [
    {
      "work_date": "2026-10-12",
      "start_time": "2026-10-12T08:00:00",
      "end_time": "2026-10-12T16:30:00",
      "break_minutes": 30,
      "raw_payload": { "work_country": "DE" }
    }
  ]
}
```

`compliant` is true when there are no `error` violations. `warning` entries do not fail the audit.

### `POST /api/v1/contracts/parse`

Sends `contract_text` to OpenAI and returns only the four rule fields below, after a server-side schema check. The contract text is the user message; it is not logged.

```json
{
  "contract_text": "The weekly working time is 40 hours..."
}
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

Save that object on `employees.raw_rules` when you want the audit to enforce it. Add `"sunday_holiday_exempt": true` only when the contract actually allows Sunday and public-holiday work.

## Rules that are checked

- Daily work above 10 hours is an error. Between 8 and 10 hours is a warning that must be averaged back to 8 hours.
- The 24 weeks ending on `week_end` may not average more than 8 hours per Monday–Saturday.
- The gap from one shift's `end_time` to the next `start_time` must be at least 11 hours. A longer `custom_rest_period_hours` is a warning when the statutory gap is met but the contract gap is not.
- Sunday work and statewide public holidays for `employees.federal_state` are errors unless `raw_rules.sunday_holiday_exempt` is true.
- Weekly totals are compared with `max_weekly_hours`, `contract_hours_per_week`, and `allowed_overtime_cap`.
- `raw_payload.work_country` is compared with `remote_work_country` when both are present.

Federal state codes: `BW`, `BY`, `BE`, `BB`, `HB`, `HH`, `HE`, `MV`, `NI`, `NW`, `RP`, `SL`, `SN`, `ST`, `SH`, `TH`.
