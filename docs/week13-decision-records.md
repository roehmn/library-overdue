# Week 13 (SA7) Decision Records: Library Overdue Notification System

## ADR-001: Backend stack

**Status:** Accepted

**Decision:** Laravel (PHP) for the backend API, MySQL for the database, Flutter Web for the frontend.

**Reasons:**
- Laravel includes a task scheduler, queued jobs with configurable `tries` and `backoff`, a failed-jobs table, and structured log channels. These map directly to the SA7 requirements.
- The Week 14 security work (authentication, validation, role-based authorization) is supported by built-in features (Sanctum, form requests, policies).
- MySQL is relational, which suits loans, borrowers, notices, and audit records.

**Alternatives rejected:** Node.js + MySQL. It would need separate libraries for scheduling, queues, and logging, adding integration work that does not add assessment value.

**Consequences:** Retry and failure handling use Laravel's queue system. Deployment needs PHP, Composer, and MySQL.

---

## ADR-002: Automation trigger

**Status:** Accepted

**Decision:** Hybrid trigger. Scheduled jobs handle time-based work; events handle state changes.

| Trigger | Type | Action |
|---|---|---|
| Loan sync, 01:00 | Schedule | Pull loan records from the simulated source |
| Due-date review and notices, 08:00 | Schedule | Queue reminders, overdue notices, and escalations |
| Exception summary, 17:00 | Schedule | List permanently failed notices and escalated cases |
| Return recorded | Event | Cancel pending notices, close the case |
| Renewal approved | Event | Reset due date, cancel pending notices |
| Dispute filed | Event | Pause escalation until a librarian decides |

All times are Asia/Manila.

**Notice timeline:** 3 days before due (reminder, borrower); due date (reminder, borrower); 1 day overdue (overdue notice, borrower); 7 days overdue (escalation, librarian); 14 days overdue (escalation, supervisor).

**Reasons:** Being overdue is the absence of an event, so a time-based trigger is required. Returns, renewals, and disputes are user actions that change state, so they are handled as events.

**Alternatives rejected:**
- Purely scheduled: returns and renewals would not stop pending notices until the next run.
- Purely event-driven: nothing would fire for a loan that simply becomes overdue.

**Consequences:** Each notice needs a unique key (loan + notice type) so repeated or restarted runs cannot send duplicates (idempotency).

**Scope note:** Email is the only channel and is simulated. SMS is deliberately out of scope.
