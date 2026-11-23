# SA7 Evidence Map

Project 14: Library Overdue Notification System. Evidence for the nine SA7 deliverables.
File names under `evidence/sa7/` are the recommended names. Rename your screenshots to match.

## Deliverables

| # | SA7 deliverable | Where it is | Status |
|---|---|---|---|
| 1 | Workflow boundary | `docs/workflow-boundary.md` | Done |
| 2 | Trigger decision | `docs/week13-decision-records.md` (ADR-002), `routes/console.php` | Done |
| 3 | Flutter Web screen flow | `docs/screen-flow.md`, `docs/screen-flow.png`, `frontend/lib/` | Flow done. Login screen built. Role screens in progress |
| 4 | API contract | `docs/openapi.yaml`, `routes/api.php` | Done. All 15 operations implemented and tested |
| 5 | Retry and recovery policy | `docs/retry-recovery-policy.md`, `config/notices.php`, `app/Jobs/SendNoticeJob.php` | Done |
| 6 | Structured logs | `config/logging.php` (channel `structured`), `storage/logs/structured-*.log` | Done. See `03-json-log.png` |
| 7 | One recovered transient failure | Loan L-1002, notice 2 | Done. See `01`, `03`, `05` |
| 8 | One permanent failure routed for human action | Loan L-1006, notice 6 | Done. See `01` to `05`, resolved in `09` |
| 9 | Maintainability note | `docs/maintainability-note.md` | Done |

## Screenshots

| File | Shows |
|---|---|
| `01-worker-output.png` | Queue worker: six first attempts, Ben's two retries, Dino's single failed job |
| `02-failed-jobs.png` | One row in `failed_jobs`, the permanent failure |
| `03-json-log.png` | JSON log lines with `+08:00`: retries, `attempt: 3` success, permanent failure, manual review |
| `04-summary.png` | Summary for 2026-10-31: 1 manual review, 1 recovered, 2 escalations |
| `05-audit-trail.png` | Audit trail for notices 2 and 6, row by row |
| `06-notices-table.png` | Final status of all six notices |
| `06-audit-overview.png` | First page of the audit log: sync, notice creation, overdue marking |
| `07-api-auth-tests.png` | Login, role check, invalid and revoked token, validation |
| `08-api-read-tests.png` | Read endpoints: own loans, loan list, loan detail, queue, summary |
| `09-api-workflow-tests-1.png`, `-2.png` | Renewal, dispute, return, resolve, with 403, 409, 422 cases |
| `10-audit-actors.png` | Audit rows with a real actor for every person-made change |
| `11-flutter-login-*.png` | Login screen states (to be captured) |

## Scenarios covered

| Scenario | Evidence |
|---|---|
| Scheduled notices | `routes/console.php`, `schedule:list`, notices created by the daily review |
| Bounded retries | 4 attempts, waits 5 s and 10 s in the demonstration (`01`, `05`) |
| Overdue escalation | Notices 4 and 5 (librarian at 7 days, supervisor at 14 days), in `04` |
| Daily exception summary | `04-summary.png`, `app/Services/ExceptionSummary.php` |
| Duplicate protection | Second `notices:generate` run reports 6 existing, 0 created |
| Invalid input | Two rejected records at sync. 422 responses from the API |
| Unauthorized | 401 and 403 responses in `07` and `09` |
| Unavailable service | MySQL down returns a server error. Loan source missing stops the sync cleanly |
