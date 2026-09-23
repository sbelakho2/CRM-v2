# Data Recovery — Raihana Lembardi (user #19)

**Date:** 2026-09-23
**Incident:** The account of Raihana Lembardi (raihana@apexmediation.ee, `ROLE_SALES`, user #19)
was deleted on 2026-09-22 21:03:36. The delete action relied on the database's
`ON DELETE CASCADE` rules, so the activities she had logged were destroyed and the
audit trail was orphaned (`user_id = NULL`).

## What was recovered

| Source | Rows | Fidelity |
| --- | --- | --- |
| `starz_crm_backup_20260825.sql` (pre-deletion dump) | 58 activities (ids 40–103) | Exact — full row data including company and contact links |
| `audit_logs` create payloads | 149 activities (ids 104–252) | Exact content (type, subject, notes, status, outcome, outcome_detail, dates); company link reconstructed (see below) |
| `audit_logs` (NULL `user_id`, created ≥ 2026-08-05) | 399 rows | Exact — reassigned to Khawla Touati |
| Result | **207 activities restored**, all reassigned to Khawla Touati (user #6) | Activities total 45 → 252 |

The ids she never owned (46–51, another representative's activities) were left untouched.
Nothing was deleted or overwritten: the restore ran in a single transaction after
validating that every referenced company (70), contact (96) and user existed, and that
all 207 target ids were free.

## Company attribution for the 149 reconstructed activities

The audit payloads do not include relation ids, so each company was derived from evidence:

| Tier | Rows | Evidence |
| --- | --- | --- |
| `tier1` | 77 | Contact/company created in the same session (same IP, ±60 min) — the contact's company |
| `tier1-tie` | 29 | Two candidate companies in the window; the closest in time chosen |
| `tier2` | 24 | Same session's already-resolved activity |
| `note-E2ip` | 1 | Company named in the note ("E2IP's current manufacturing needs") → E2ip (#908) |
| `tier4-continuity` | 18 | Nearest resolved activity in her timeline (work continuity) |

Independent validation: the reconstruction placed activity #220 (note: "the meeting with
**Lola from Xiezhong** was successful") on **Xiezhong Morocco (#980)** — matching the note.

## Safeguards added (so this cannot happen again)

`app_admin_user_delete` (`src/Controller/Admin/UserController.php`) no longer lets the
database cascade destroy data. Before the account is removed, every reference is
reassigned inside one transaction:

- integer keys: `activities.user_id`, `tasks.created_by_id` / `assigned_to_id`,
  `calendar_events.organizer_id`, `calendar_event_attendees.user_id`,
  `meeting_slots.owner_id`, `notification.user_id`, `report_definitions.created_by_id`,
  `custom_field_definitions.created_by_id`, `audit_logs.user_id`
- display-name references: `leads.owner_rep`, `email_segment.created_by`,
  `email_template.created_by`, `rfq_versions.created_by`

Target user: the `reassign_to` request parameter when present, otherwise the acting
administrator. The flash message names both the deleted user and the recipient.
Covered by `tests/Functional/Regression/UserDeleteReassignTest.php`.
