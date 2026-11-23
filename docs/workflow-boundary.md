# Workflow Boundary

Project 14: Library Overdue Notification System (PFEBSIT 002). SA7 deliverable 1 of 9.
All data is fictional and controlled. No production systems or real borrower records are used.

## Purpose

The library needs consistent reminders and traceable escalation for overdue resources.
This prototype covers one bounded workflow, from loan synchronization to case closure.

## Actors and approval authority

| Actor | What they can do | What they decide |
|---|---|---|
| Borrower | See their own loans and notices. Request a renewal. Dispute an overdue status | Nothing. Borrowers only make requests |
| Librarian | Review loans. Record returns. Resolve notices that need manual handling | **Renewals, disputes**, and how a failed notice is handled. Receives the 7-day escalation |
| Supervisor | Everything a librarian can do, plus the daily exception summary | Same decisions as a librarian. Receives the 14-day escalation and the 17:00 summary email |
| System (scheduler and queue worker) | Sync loans, review due dates, create and send notices, retry failures, hand permanent failures to a person | Nothing about a person's case. Its actions appear in the audit trail with no actor |

Roles are enforced on the server for every request. Hiding a button in the app is a convenience, never the protection.

## In scope: the normal path

1. **Loan synchronization.** A daily job (01:00 Asia/Manila) reads loans from a simulated library loan source and saves new ones.
2. **Due-date review.** A daily job (08:00) marks late loans overdue and decides which notice is due today.
3. **Reminder.** The borrower is notified 3 days before the due date and on the due date.
4. **Overdue and escalation.** One day overdue the borrower is notified. At 7 days overdue the librarian is notified, and at 14 days the supervisor.
5. **Return, renewal, or dispute.** The borrower returns the item (recorded by a librarian), requests a renewal, or disputes the overdue status.
6. **Decision.** A librarian or supervisor approves or rejects the renewal, or decides the dispute.
7. **Closure.** The loan is closed once it is returned or a dispute upholds the borrower's claim.

## In scope: failure and recovery paths

| Path | What happens |
|---|---|
| Invalid input | A bad loan record is rejected at sync and logged without personal details. The API answers 422 with a message per field |
| Unauthorized | No token or an expired token gives 401. A valid token with the wrong role, or a borrower acting on someone else's loan, gives 403 |
| Unavailable service | If the loan source is down, the sync stops cleanly and logs it. If sending a notice fails temporarily, it is retried. If the database is down, the API answers with a server error |
| Recovery | Temporary failures retry with waiting time between attempts. A notice stuck mid-send is rescued by a recovery job. A permanent failure goes to a person (manual review) |

The retry rules are in `docs/retry-recovery-policy.md`.

## Business rules

| Action | Rule |
|---|---|
| Renewal request | Loan must be active or overdue, under 2 renewals, with no request already waiting |
| Renewal approved | Due date moves 14 days ahead, counted from today or from the old due date if that is still ahead |
| Dispute | Only an overdue loan can be disputed. While it is open, escalation notices that have not been sent are cancelled and new ones are not created |
| Dispute upheld | The library accepts the borrower's claim. The case closes and no more notices go out |
| Dispute rejected | A returned loan closes. Otherwise the loan goes back to active or overdue and the normal notices continue |
| Return | Loan must be open. Unsent notices are cancelled and a waiting renewal request is rejected. The loan closes, or stays "returned" until an open dispute is decided |

## Loan states

`active` becomes `overdue` when the due date passes. `overdue` becomes `disputed` when a dispute is filed. Return, an upheld dispute, or a rejected dispute on a returned loan leads to `closed` (with `returned` as the waiting state when a dispute is still open). An approved renewal returns the loan to `active`.

## Notice states

`pending` then `sending`, then `sent`. A temporary failure goes through `retry_scheduled` and back to `sending`. A permanent failure, or running out of attempts, goes to `manual_review`, and a person moves it to `resolved`. Notices that have not been sent yet can become `cancelled`.

## Out of scope (deliberately)

- Real library databases, real borrower data, and real email delivery. A JSON file stands in for the loan system, and the log mail driver stands in for email.
- SMS. Removed from scope to keep the prototype bounded.
- Fines and payments, which would add financial-reference risk without adding assessment value.
- Catalogue search, new loans, reservations, and a mobile app.

## Assumptions

- All times use Asia/Manila.
- A librarian and a supervisor each exist in the demo data. If a role has nobody assigned, escalations to it are skipped and logged.
- The daily review matches the exact day. A missed run is recovered by hand with `php artisan notices:generate --date=YYYY-MM-DD`.
