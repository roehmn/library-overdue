# Maintainability Note

Project 14: Library Overdue Notification System. SA7 deliverable 9 of 9.
Written so that someone who did not build the project can run, change, and fix it.

## What it is

| Part | Technology | Folder |
|---|---|---|
| Backend API and automation | Laravel (PHP) with MySQL | project root |
| Web client | Flutter Web | `frontend/` |
| API contract | OpenAPI | `docs/openapi.yaml` |

## Where things live

| Concern | Location |
|---|---|
| Daily schedule (01:00 sync, 08:00 review, 08:05 dispatch, every 5 minutes recovery, 17:00 summary) | `routes/console.php` |
| Commands | `app/Console/Commands/` |
| Business rules for return, renewal, dispute, resolve | `app/Services/LoanWorkflow.php` |
| Notice creation (which notice is due today) | `app/Services/NoticeGenerator.php` |
| Sending, retries, hand-off to manual review | `app/Jobs/SendNoticeJob.php`, `app/Services/NoticeMailer.php` |
| Daily exception summary | `app/Services/ExceptionSummary.php` |
| API endpoints | `routes/api.php`, `app/Http/Controllers/Api/` |
| Response shapes (one place) | `app/Support/ApiPresenter.php` |
| Role check | `app/Http/Middleware/EnsureRole.php` |
| Error format for the API | `bootstrap/app.php` |
| Retry and simulation settings | `config/notices.php` and `.env` |
| Structured JSON logs | channel `structured` in `config/logging.php` |
| Flutter API client and error type | `frontend/lib/api/` |
| Flutter state views (loading, empty, error) | `frontend/lib/widgets/state_views.dart` |

## Running it locally

1. Start MySQL (XAMPP) and create the database `library_overdue` (utf8mb4).
2. Copy `.env.example` to `.env`, then set the database values and `APP_TIMEZONE=Asia/Manila`.
3. In the project root: `composer install`, `php artisan key:generate`, `php artisan migrate`, `php artisan db:seed --class=DemoUserSeeder`.
4. Load data: `php artisan loans:sync` then `php artisan notices:generate`.
5. Start three things, each in its own terminal: `php artisan serve`, `php artisan queue:work`, and `php artisan schedule:work` (the scheduler).
6. In `frontend/`: `flutter pub get`, then `flutter run -d web-server --web-port 5000`, and open http://localhost:5000.

Demo accounts use `@example.test` addresses and are created by the seeder. They exist only for the fictional demo.

## Commands

| Command | Purpose |
|---|---|
| `loans:sync` | Pull loans from the simulated source. Safe to repeat |
| `notices:generate [--date=YYYY-MM-DD]` | Create today's notices. The date option recovers a missed day |
| `notices:dispatch` | Queue pending notices for sending |
| `notices:recover` | Rescue notices stuck in "sending" |
| `notices:summary [--date=YYYY-MM-DD]` | Build and email the daily exception summary |

## Common changes

| To do this | Change this |
|---|---|
| Change attempts or waiting times | `NOTICE_TRIES`, `NOTICE_BACKOFF` in `.env`, then `php artisan config:clear` |
| Make a loan's notice fail on purpose (demonstration) | `NOTICE_SIM_TRANSIENT_REF` and `NOTICE_SIM_TRANSIENT_FAILURES` in `.env` |
| Add a notice type | Add a constant in `Notice`, a row in the timeline in `NoticeGenerator`, and subject and body in `NoticeMailer` |
| Change the renewal length or limit | `RENEWAL_DAYS` and `MAX_RENEWALS` in `LoanWorkflow` |
| Add an endpoint | Add it to `docs/openapi.yaml` first, then the route, then the controller and service |

## Conventions

- Controllers validate and delegate. Business rules live in `app/Services/`.
- Every state change writes an `audit_logs` row. That table is append-only, and the model refuses updates and deletes.
- Every API error uses one format: `{"error": {"code", "message", "fields"}}`.
- Logs name records by id and loan reference. They do not contain borrower names or email addresses.
- Demo data is fictional. `.env` is never committed.

## Problems already met and how they were solved

| Symptom | Cause | Fix |
|---|---|---|
| "A facade root has not been set" when Laravel starts | A routes file had been placed in `config/` | Move it to `routes/` |
| Dates and logs in UTC although `.env` says Manila | `config/app.php` had the timezone hard-coded | Use `env('APP_TIMEZONE', 'UTC')` |
| `migrate:fresh` fails on the users table | A migration file was overwritten with another migration's content | Restore the original file |
| "No connection could be made" on every request | MySQL not running | Start MySQL |
| "Unable to connect to the remote server" from test commands | `php artisan serve` not running | Start it |
| Scheduled jobs do nothing | Scheduler or queue worker not running | Start `schedule:work` and `queue:work` |

## Known limitations

See `docs/retry-recovery-policy.md`, section 7. Also: the API answers 500 (not 503) when the database is down, the token is kept in memory in the Flutter app so a refresh signs the user out, and the permissive local CORS setting must be tightened before any real deployment.
