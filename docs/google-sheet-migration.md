# Moving off the Google Sheet tracker

The "Kanchi Farm stay Tracker" sheet did four jobs. Each now has a home in the PMS:

| Sheet tab | In the PMS |
|---|---|
| Jan … Nov (bookings) | Bookings, as before. Payments go on **Payments** (the ledger) |
| Food Bill column, Food & Dining | **Food & extras** on each booking's Payments page. Unpaid charges go on the bill |
| Raw Material Expenditure | **Expenses** (sidebar). Front desk staff can add; voiding needs Accounts |
| Master Monthly Summary | **Accounts → Monthly summary**, CSV export included |

The sheet's summary formulas were wrong: "Total Expenses" was revenue plus food, and
"Net Profit" was minus the food bill. The PMS calculates revenue = room + food, and
net = revenue − expenses.

## One-time import

Both files hold guest names. This repo is public, so keep them out of Git
(`*.xlsx` and `tracker-import/` are ignored) and delete them afterwards.

```bash
# 1. Sheet -> CSV (needs openpyxl). Prints per-tab totals against the sheet's own.
python3 tools/tracker_to_csv.py "Kanchi Farm stay Tracker.xlsx" --out tracker-import/

# 2. Fix any rows it lists as `review` in tracker-import/bookings.csv, then set their
#    status to confirmed / cancelled, or to skip to leave them out.

# 3. On the server, after backing up the database: a dry run first, then apply.
php channel-manager/import-tracker.php tracker-import/bookings.csv tracker-import/expenses.csv
php channel-manager/import-tracker.php tracker-import/bookings.csv tracker-import/expenses.csv --apply
```

What the importer does, and why:

- **Past stays only.** A stay whose check-out is after today is skipped. Future
  bookings are entered in the PMS, where availability and the OTA calendars are checked.
- **Multi-room rows are split** into one booking per room. The amount is shared
  equally and the parts add up to the paisa. The food bill goes on the first room.
- **Already in the PMS, same guest:** only blanks are filled: a zero amount, the
  food bill, and money received when none is recorded.
- **Already in the PMS, different guest:** a *conflict*. Nothing is written and both names are printed.
- **Money received:** the sheet's figure where it has one (Sept, Oct). Where it
  has none, a finished stay is recorded as paid in full, and the ledger entry says
  "Assumed paid in full". Leaving those stays unpaid would show months of finished
  stays as money owed.
- **No messages.** Imported bookings are stamped as already confirmed and checked
  out. The sheet has no phone numbers anyway.
- **Re-runnable.** Every row is keyed by its tab and row, so a second run skips what is
  already in. The dry run executes the same code and rolls it back.

Older live databases already have an `expenses` table: empty, left over from an
earlier version, with different columns. So the PMS uses `farm_expenses`.

## Cutover

1. Pick a cut-off date. From then on, enter bookings, food and expenses only in the PMS.
2. Set the Google Sheet to view-only (Share → change editors to viewers).
3. For 1–2 weeks, compare Accounts → Monthly summary with what you expected, then archive the sheet.
