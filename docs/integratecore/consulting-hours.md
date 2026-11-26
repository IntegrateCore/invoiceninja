# Consulting-hours balance

A client's available balance is adjusted when a billable task time entry is
saved, regardless of the task status. A running timer is counted only after
its end time is saved. Nonbillable entries do not count. Adjusting a billed entry
applies only the difference; closing or reopening the same task does not charge
it twice. Time rounding remains controlled by the normal task settings.

The existing per-task charged amount is retained. The migration only adds a
nullable charged-client identifier; it makes no balance adjustments and scans
no historical tasks. After rollout, editing or otherwise reconciling a task
applies the difference between its saved billable time and the amount already
charged. In particular, existing open tasks with previously uncharged time do
not change balances until their next save or reconciliation. Already charged
legacy amounts are credited to the originally assigned client where known,
otherwise to the client on the existing task, when first reconciled.

No automatic historical backfill is scheduled. If existing untouched open
tasks must be reconciled immediately, review the affected client balances first
and run a separate bounded reconciliation rather than scanning in a migration.
