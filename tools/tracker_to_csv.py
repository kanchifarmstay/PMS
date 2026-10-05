#!/usr/bin/env python3
"""Turn the "Kanchi Farm stay Tracker" Google Sheet export (.xlsx) into two
clean CSVs that channel-manager/import-tracker.php loads into the PMS.

    python3 tools/tracker_to_csv.py "Kanchi Farm stay Tracker.xlsx" --out tracker-import/

Writes  bookings.csv  one row per sheet row; `rooms` lists every PMS room it
                      covers (a "villa + cottage" row becomes two bookings)
        expenses.csv  the "Raw Material Expenditure" list

Nothing here touches the PMS. Every row it could not read with confidence is
written with status=review and a reason; the importer skips those until the
CSV is corrected by hand, so a guess never reaches the books.

The output holds guest names: keep it out of Git (tracker-import/ is ignored)
and delete it once the import is done.

Needs openpyxl.
"""
from __future__ import annotations

import argparse
import csv
import datetime as dt
import re
import sys
from pathlib import Path

import openpyxl

YEAR = 2026
MONTH_TABS = {"Jan": 1, "Feb": 2, "Mar": 3, "Apr": 4, "May": 5, "June": 6, "July": 7,
              "Aug": 8, "Sept": 9, "OCT": 10, "Nov": 11, "Dec": 12}
EXPENSE_TAB = "Copy of Food & Dining"
MAX_NIGHTS = 21

# Spellings found in the sheet -> PMS room id (channel-manager/config.php ROOM_IDS).
# Matched against the text with spaces and punctuation removed.
ROOM_ALIASES = [
    (r"^(wooden|wdan|wood)villa+$", "wooden-villa"),
    (r"^(wooden|wdan|wood|wooden?)?(cottage|cot)$", "wooden-cottage"),
    (r"^woodecottage$", "wooden-cottage"),
    (r"^whitevilla(r|room)?1$", "white-villa"),
    (r"^whitevilla(r|room)?2$", "white-villa-room-2"),
    (r"^white(villa)?$", "white-villa"),
    (r"^tranquil(retreat)?$", "tranquil-retreat"),
    (r"^natures?nest$", "natures-nest"),
    (r"^farm(stay|booking)$", "kanchi-farm-stay"),
]
# White Villa has three rooms; before R1/R2 was written down the sheet just said "white villa".
UNNUMBERED_WHITE = {"white", "whitevilla"}

SOURCE_ALIASES = {
    "direct": "manual", "direct booking": "manual", "referral": "manual", "": "manual",
    "website": "direct",
    "booking": "booking.com", "booking.com": "booking.com",
    "agoda": "agoda", "akoda": "agoda",
    "airbnb": "airbnb",
    "makemytrip": "makemytrip", "make my trip": "makemytrip", "mmt": "makemytrip",
}
OTA_SOURCES = {"booking.com", "agoda", "airbnb", "makemytrip"}
MODE_ALIASES = {"upi": "upi", "bank transfer": "bank_transfer", "cash": "cash", "card": "card"}

EXPENSE_CATEGORY = [
    (r"chicken|mutton|fish|meat|egg", "meat_fish"),
    (r"gas|diesel|petrol", "gas_fuel"),
    (r"grocer|bazar|bazaar|milk|curd|vegetable|batter|dhal|dal|masala", "groceries"),
]


def date_candidates(value) -> list[dt.date]:
    """Every date the cell could mean, most likely first. Sheets stored many
    as M/D when they were typed D/M, so the day/month swap is always tried."""
    out: list[dt.date] = []

    def add(y: int, m: int, d: int) -> None:
        try:
            day = dt.date(y, m, d)
        except ValueError:
            return
        if day not in out:
            out.append(day)

    if isinstance(value, dt.datetime):
        value = value.date()
    if isinstance(value, dt.date):
        add(value.year, value.month, value.day)
        add(value.year, value.day, value.month)
        return out
    if not isinstance(value, str):
        return out
    parts = [p for p in re.split(r"[-/. ]+", value.strip()) if p]
    if len(parts) != 3 or not all(p.isdigit() for p in parts):
        return out
    a, b, c = (int(p) for p in parts)
    if len(parts[2]) == 2:          # 30/5/26
        c += 2000
    if len(parts[0]) == 4:          # 2026-13-9: year first, then either order
        add(a, b, c)
        add(a, c, b)
    else:                           # 21/9/2026: Indian day-first, the swap as a fallback
        add(c, b, a)
        add(c, a, b)
    return out


def pick_stay(ci_raw, co_raw, tab_month: int) -> tuple[dt.date, dt.date, str] | None:
    """The check-in/check-out reading that fits the month tab it sits on: the
    check-in must fall in that month (or the month before - a 31 Dec arrival
    sits on the Jan tab), and the stay must be 1..MAX_NIGHTS nights.

    A check-out typed the same as the check-in (a day visit, or a date never
    filled in) is stored as one night and says so; the third value is that note."""
    tab = (YEAR, tab_month)
    prev = (YEAR - 1, 12) if tab_month == 1 else (YEAR, tab_month - 1)
    outs = date_candidates(co_raw)
    # A wrong year on the check-out (2025-01-06 for 1 Jun 2026) - same day and month, this year.
    outs += [d.replace(year=YEAR) for d in outs if d.year != YEAR and not (d.month == 2 and d.day == 29)]
    best = None
    same_day = None
    for ci in date_candidates(ci_raw):
        ym = (ci.year, ci.month)
        if ym not in (tab, prev):
            continue
        for co in outs:
            nights = (co - ci).days
            if 1 <= nights <= MAX_NIGHTS:
                score = (0 if ym == tab else 1, nights)
                if best is None or score < best[0]:
                    best = (score, ci, co)
            elif nights == 0 and ym == tab and same_day is None:
                same_day = ci
    if best:
        return best[1], best[2], ""
    if same_day:
        return same_day, same_day + dt.timedelta(days=1), "sheet had the same check-in and check-out (day visit?): stored as one night"
    return None


def map_rooms(raw: str) -> tuple[list[str], list[str]]:
    """Room ids for a free-text property cell, and any warnings."""
    text = raw.lower().strip()
    warnings: list[str] = []
    if "stayed in" in text:                     # "booked whitevilla stayed in woodenvilla"
        text = text.split("stayed in", 1)[1]
        warnings.append("moved room: imported as where they stayed")
    pieces = [p for p in re.split(r",|/|\\|\band\b", text) if p.strip()]
    rooms: list[str] = []
    for piece in pieces:
        key = re.sub(r"[^a-z0-9]", "", piece)
        room = next((rid for pat, rid in ROOM_ALIASES if re.match(pat, key)), None)
        if room is None:
            return [], [f"unknown room '{piece.strip()}'"]
        if key in UNNUMBERED_WHITE:
            warnings.append("White Villa room number not recorded: filed under Room 1")
        if room not in rooms:
            rooms.append(room)
    return rooms, warnings


def money(value) -> float | None:
    """A number, or None when the cell is empty or is words ("cancelled", "nil")."""
    if value is None or value == "":
        return None
    if isinstance(value, (int, float)):
        return float(value)
    text = str(value).strip().replace(",", "")
    return float(text) if re.fullmatch(r"\d+(\.\d+)?", text) else None


def header_row(rows: list[tuple]) -> int:
    return next(i for i, r in enumerate(rows) if r and str(r[0] or "").strip() in ("Booking", "Booking ID"))


def read_bookings(wb) -> list[dict]:
    out = []
    for tab, month in MONTH_TABS.items():
        if tab not in wb.sheetnames:
            continue
        rows = list(wb[tab].iter_rows(values_only=True))
        hi = header_row(rows)
        head = [str(h).strip() if h else "" for h in rows[hi]]
        for line, values in enumerate(rows[hi + 1:], start=hi + 2):
            row = dict(zip(head, values))
            guest = str(row.get("Guest Name") or "").strip()
            if not guest:
                continue
            issues: list[str] = []
            notes: list[str] = []
            source_raw = str(row.get("Booking Source") or row.get("Source") or "").strip()
            source = SOURCE_ALIASES.get(source_raw.lower())
            if source is None:
                issues.append(f"unknown source '{source_raw}'")
            elif source_raw.lower() in ("", "referral"):
                notes.append("source: " + (source_raw or "not recorded"))

            rooms, warn = map_rooms(str(row.get("Property") or ""))
            if not rooms:
                issues.extend(warn or ["no room"])
            else:
                notes.extend(warn)

            stay = pick_stay(row.get("Check-in Date"), row.get("Check-out Date"), month)
            if stay is None:
                issues.append(f"dates unreadable: {row.get('Check-in Date')!s} -> {row.get('Check-out Date')!s}")
            elif stay[2]:
                notes.append(stay[2])

            total_raw = row.get("Total Amount")
            status = "confirmed"
            amount: float | None
            if isinstance(total_raw, str) and "cancel" in total_raw.lower():
                status, amount = "cancelled", 0.0
            else:
                amount = money(total_raw)
                if amount is None:
                    issues.append(f"amount unreadable: {total_raw!s}")
            # Only Jan, Sept and Oct have the column, and Jan left it empty: empty is "not recorded", not zero.
            received = money(row.get("Amt Received")) if status == "confirmed" else None
            food = money(row.get("Food Bill")) or 0.0
            mode_raw = str(row.get("Payment Mode") or "").strip().lower()
            method = MODE_ALIASES.get(mode_raw) or ("ota" if source in OTA_SOURCES else "other")
            if str(row.get("Payment Status") or "").strip().lower() == "no show":
                notes.append("no show")

            out.append({
                "key": f"{tab}-r{line}",
                "status": "review" if issues else status,
                "issues": "; ".join(issues),
                "guest_name": guest,
                "rooms": ";".join(rooms),
                "check_in": stay[0].isoformat() if stay else "",
                "check_out": stay[1].isoformat() if stay else "",
                "source": source or "",
                "amount": f"{amount:.2f}" if amount is not None else "",
                "received": f"{received:.2f}" if received is not None else "",
                "food": f"{food:.2f}",
                "method": method,
                "entered_by": str(row.get("Updated By") or "").strip(),
                "notes": "; ".join(notes),
                "sheet_property": str(row.get("Property") or "").strip(),
                "sheet_check_in": str(row.get("Check-in Date") or ""),
                "sheet_check_out": str(row.get("Check-out Date") or ""),
            })
    return out


def read_expenses(wb, today: dt.date) -> list[dict]:
    if EXPENSE_TAB not in wb.sheetnames:
        return []
    out, last = [], None
    for n, r in enumerate(wb[EXPENSE_TAB].iter_rows(values_only=True), start=1):
        if len(r) < 4 or not isinstance(r[0], (int, float)) or not r[2]:
            continue
        raw_date = r[1]
        issues = []
        if isinstance(raw_date, str) and raw_date.strip() in (",,", '"', "do"):   # ditto: same day as above
            day = last
        else:
            # A date in the future was typed D/M and stored M/D (12/9 became 9 Dec).
            options = [d for d in date_candidates(raw_date) if d <= today]
            day = options[0] if options else None
        if day is None:
            issues.append(f"date unreadable: {raw_date!s}")
        last = day or last
        item = str(r[2]).strip()
        amount = money(r[3])
        if amount is None or amount <= 0:
            issues.append(f"amount unreadable: {r[3]!s}")
        out.append({
            "key": f"exp-r{n}",
            "status": "review" if issues else "ok",
            "issues": "; ".join(issues),
            "spent_on": day.isoformat() if day else "",
            "category": next((c for pat, c in EXPENSE_CATEGORY if re.search(pat, item.lower())), "other"),
            "description": item,
            "amount": f"{amount:.2f}" if amount else "",
        })
    return out


def write(path: Path, rows: list[dict]) -> None:
    if not rows:
        return
    with path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=list(rows[0]))
        w.writeheader()
        w.writerows(rows)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("xlsx")
    ap.add_argument("--out", default="tracker-import")
    ap.add_argument("--today", default=dt.date.today().isoformat(), help="expense dates after this are read swapped")
    a = ap.parse_args()
    wb = openpyxl.load_workbook(a.xlsx, data_only=True)
    out = Path(a.out)
    out.mkdir(parents=True, exist_ok=True)

    bookings = read_bookings(wb)
    expenses = read_expenses(wb, dt.date.fromisoformat(a.today))
    write(out / "bookings.csv", bookings)
    write(out / "expenses.csv", expenses)

    print(f"{'tab':6} {'rows':>4} {'review':>6} {'rooms':>5} {'sheet total':>12} {'read total':>11}")
    for tab in MONTH_TABS:
        mine = [b for b in bookings if b["key"].startswith(tab + "-")]
        if not mine:
            continue
        read_total = sum(float(b["amount"] or 0) for b in mine)
        sheet_total = next((r[0] for r in wb[tab].iter_rows(values_only=True)
                            if r and isinstance(r[0], (int, float)) and r[0] > 100), None)
        print(f"{tab:6} {len(mine):>4} {sum(b['status'] == 'review' for b in mine):>6} "
              f"{sum(len(b['rooms'].split(';')) for b in mine if b['rooms']):>5} "
              f"{sheet_total if sheet_total is not None else '-':>12} {read_total:>11.0f}")
    print(f"expenses: {len(expenses)} rows, Rs {sum(float(e['amount'] or 0) for e in expenses):,.0f}, "
          f"{sum(e['status'] == 'review' for e in expenses)} to review")
    review = [b for b in bookings + expenses if b["status"] == "review"]
    if review:
        print("\nTo fix by hand in the CSV (then set status to confirmed / cancelled / ok):")
        for b in review:
            print(f"  {b['key']:12} {b['issues']}")
    print(f"\nWrote {out / 'bookings.csv'} and {out / 'expenses.csv'}. They contain guest names: do not commit them.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
