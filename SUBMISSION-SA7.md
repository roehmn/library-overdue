# SA7 Submission: Project 14, Library Overdue Notification System

Course: PFEBSIT 002 (individual project). Assessment: SA7 (Week 13).
Stack: Laravel (PHP) with MySQL backend, Flutter Web client.
Repository: private GitHub repository `library-overdue` (link to be added by the student).
Data: fictional and controlled only. No production secrets or real borrower data. `.env` is not committed.

## Deliverables

| # | Deliverable | File |
|---|---|---|
| 1 | Workflow boundary | docs/workflow-boundary.md |
| 2 | Trigger decision | docs/week13-decision-records.md (ADR-002), schedule in routes/console.php |
| 3 | Flutter Web screen flow | docs/screen-flow.md, docs/screen-flow.png, frontend/lib/ (working login screen) |
| 4 | API contract | docs/openapi.yaml (15 operations, all implemented), routes/api.php |
| 5 | Retry and recovery policy | docs/retry-recovery-policy.md |
| 6 | Structured logs | JSON log channel `structured` (config/logging.php); evidence screenshot 03 |
| 7 | One recovered transient failure | Loan L-1002, notice 2: two failed attempts, success on attempt 3 (evidence 01, 03, 05) |
| 8 | One permanent failure routed to a human | Loan L-1006, notice 6: invalid address, manual review, failed_jobs row, daily summary, resolved via API (evidence 01 to 05, 09) |
| 9 | Maintainability note | docs/maintainability-note.md |

Evidence screenshots are in `evidence/sa7/`. `docs/sa7-evidence-map.md` lists what each file shows.

## Summary of the work

- Hybrid trigger: daily scheduled jobs (01:00 sync, 08:00 review, 08:05 dispatch, every 5 minutes recovery, 17:00 summary) plus event-driven return, renewal and dispute, all in Asia/Manila time.
- Reliability: duplicate protection by a unique key, row locking to prevent double sending, bounded retries with growing waits, crash recovery job, hand-off to manual review.
- Traceability: append-only audit log and structured JSON logs that avoid personal details.
- API: token login, server-side role checks (borrower, librarian, supervisor), one error format with status codes 401, 403, 404, 409, 422 and 500. 19 of 19 workflow tests passed.
- Flutter Web: shared API client, error-to-screen-state mapping, working login screen verified in the browser (empty fields, wrong password, success, network error).

## Known limitations (disclosed)

- Role screens beyond login are designed in the screen flow but not yet built.
- The API answers 500 instead of the promised 503 when the database is down.
- Crash recovery for a notice stuck mid-send is untested; planned for Week 15.
- Notice delivery is simulated with the log mail driver.
- The submission is late. The original due date had passed before this package was prepared.
