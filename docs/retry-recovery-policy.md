# Retry and Recovery Policy

Project 14: Library Overdue Notification System. SA7 deliverable 5 of 9.
Implemented in `app/Jobs/SendNoticeJob.php`, `app/Services/NoticeMailer.php`, `app/Console/Commands/RecoverStuckNotices.php`, and `config/notices.php`.

## Principle

Retry only what retrying can fix, retry a bounded number of times, and give everything else to a person with the reason attached.

## 1. Classifying a failure

| Failure | Class | Response |
|---|---|---|
| Mail service timeout or connection error | Transient | Retry with waiting time |
| Mail service busy (5xx) or rate limit | Transient | Retry with waiting time |
| Any unexpected error while sending | Treated as transient | Retry, still bounded |
| Recipient email address invalid | **Permanent** | No retry, straight to manual review |
| Retries used up | Permanent | Manual review |

An invalid address will be invalid on every attempt, so retrying would only delay the person who can fix it.

## 2. Limits

| Setting | Default | Demonstration | Where |
|---|---|---|---|
| Total attempts per notice | 4 (first try plus 3 retries) | 4 | `NOTICE_TRIES` |
| Waiting time before each retry | 60 s, 300 s, 900 s | 5 s, 10 s, 15 s | `NOTICE_BACKOFF` |
| A notice stuck in "sending" is recovered after | 10 minutes | 10 minutes | `NOTICE_STUCK_MINUTES` |

Growing waits give a struggling service time to recover instead of being hit repeatedly. Every value is in `.env`, so changing a limit needs no code change.

## 3. Notice life cycle

```
pending -> sending -> sent
              |
              +-> retry_scheduled -> sending (again, up to the limit)
              |
              +-> manual_review -> resolved   (permanent failure, or retries used up)

pending / retry_scheduled -> cancelled         (return, renewal, or dispute made it unnecessary)
```

Every transition writes an `audit_logs` row, and key events write a structured JSON log line.

## 4. Safeguards

| Safeguard | How it works |
|---|---|
| No duplicate notices | A unique key on (loan, notice type, due date). Running a job twice cannot create a second notice |
| No double sending | The job locks the notice row and moves it to "sending" before sending. A second worker finds it already taken and does nothing |
| Safe re-run | A job that finds a notice already sent, cancelled, or resolved does nothing |
| Crash recovery | `notices:recover` runs every 5 minutes. A notice stuck in "sending" is set back to "pending" and queued again, or sent to manual review if its attempts are used up |
| Missed day | `notices:generate --date=YYYY-MM-DD` recreates that day's notices. The unique key makes it safe to repeat |

## 5. Handing a failure to a person

When a notice reaches `manual_review`:

1. It is recorded with the reason (`permanent_failure`, `retries_exhausted`, or `stuck_no_attempts_left`) and the last error text.
2. The job lands in `failed_jobs`.
3. It appears in the 17:00 daily exception summary and in the manual-review queue (`GET /notices?status=manual_review`).
4. A librarian or supervisor handles it (for example corrects the address) and records a note with `POST /notices/{id}/resolve`. The notice becomes `resolved`, and the audit trail shows who did it and when.

## 6. Evidence

| Scenario | Evidence |
|---|---|
| Recovered transient failure | Loan L-1002, notice 2: attempts 1 and 2 fail (retry waits 5 s and 10 s), attempt 3 succeeds. See the worker output, the audit trail rows 20 to 33, and the JSON log |
| Permanent failure to a human | Loan L-1006, notice 6: invalid address, no retry, `manual_review`, one row in `failed_jobs`, listed in the daily summary, later resolved through the API |

## 7. Known limitations

- A notice stuck by a worker crash gets a fresh job with a fresh Laravel attempt counter. In that rare path it ends in manual review after the next recovery run (up to about 15 minutes) instead of immediately. Not yet tested; it is planned for the Week 15 reliability tests.
- Notices match the exact day. A missed daily run is recovered by hand, not automatically.
- Escalations that fall due while a dispute is open are not created afterwards.
- The simulated mail channel is the log driver, which writes whole emails (including addresses) to the log. Acceptable for fictional data only.
