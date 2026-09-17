#!/usr/bin/env python3
"""
UI test for the card payment window (pay/pay_window.php).

The PHP suites prove the payment logic. This one drives the actual window in a
real browser, the way a cashier does: press Card on the till page, watch the
popup go Idle -> Waiting -> Approved/Declined, press the button, and check the
POS window receives the result.

Needs Playwright:
    pip install playwright && playwright install chromium

Run it with the fake Stripe API and the app already serving (tests/run_tests.sh
starts both; or start them yourself), then:

    APP_BASE=http://127.0.0.1:8900 \
    MOCK_BASE=http://127.0.0.1:8899 \
    TEST_DB=/tmp/pos_test.sqlite \
    POS_TOKEN=test-token-abc123 \
    python3 tests/run_window_ui_test.py
"""

import os
import sys
import sqlite3
import urllib.request
import urllib.parse

APP = os.environ.get("APP_BASE", "http://127.0.0.1:8900")
MOCK = os.environ.get("MOCK_BASE", "http://127.0.0.1:8899")
DB = os.environ.get("TEST_DB")
TOKEN = os.environ.get("POS_TOKEN", "test-token-abc123")

try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print("SKIPPED: playwright is not installed (pip install playwright)")
    sys.exit(0)

failures = []


def check(label, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + label + ("" if cond else "   [%s]" % detail))
    if not cond:
        failures.append(label)


def post(url, data=None):
    body = urllib.parse.urlencode(data or {}).encode()
    with urllib.request.urlopen(urllib.request.Request(url, data=body)) as r:
        return r.read()


def mock(**cfg):
    post(MOCK + "/__mock/config", cfg)


def seed(sale_id, total):
    """Give the sale a clean slate, so a rerun cannot pass on last run's payment."""
    if not DB:
        return
    con = sqlite3.connect(DB)
    con.execute("DELETE FROM pos_card_payments WHERE sale_id = ?", (str(sale_id),))
    con.execute("DELETE FROM sales WHERE id = ?", (sale_id,))
    con.execute("INSERT INTO sales (id, total, voided) VALUES (?,?,0)", (sale_id, total))
    con.commit()
    con.close()


def sale_row(sale_id):
    if not DB:
        return None
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    row = con.execute("SELECT * FROM sales WHERE id = ?", (sale_id,)).fetchone()
    con.close()
    return dict(row) if row else None


def run_case(label, sale_id, amount, outcome, decline_code="card_declined"):
    print("\n[%s] sale %s, %s" % (label, sale_id, amount))
    seed(sale_id, float(amount))
    post(MOCK + "/__mock/reset")
    mock(outcome="never", polls="3")          # reader has not answered yet

    with sync_playwright() as p:
        browser = p.chromium.launch()
        ctx = browser.new_context(viewport={"width": 900, "height": 650})
        till = ctx.new_page()
        till.goto("%s/demo/till_button.php?sale_id=%s&amount=%s" % (APP, sale_id, amount))
        till.wait_for_timeout(300)

        with ctx.expect_page() as popup:
            till.click("#btnCard")
        win = popup.value
        win.set_viewport_size({"width": 420, "height": 640})
        win.wait_for_load_state()
        win.wait_for_timeout(1200)

        check(label + ": opens into WAITING", win.inner_text("#badge").strip() == "WAITING",
              win.inner_text("#badge"))
        check(label + ": tells the cashier to present the card",
              "Present card" in win.inner_text("#headline"), win.inner_text("#headline"))
        check(label + ": shows the amount", amount in win.inner_text("#amt"), win.inner_text("#amt"))
        check(label + ": offers Cancel while waiting", win.is_visible("#btnCancel"))
        check(label + ": Cash done is HIDDEN before approval", not till.is_visible("#btnCashDone"))

        # Let the reader answer.
        mock(outcome=outcome, polls="1", decline_code=decline_code)
        win.wait_for_selector(".s-ok" if outcome == "approve" else ".s-bad", timeout=20000)
        win.wait_for_timeout(400)

        expected = "APPROVED" if outcome == "approve" else "DECLINED"
        check(label + ": ends on " + expected, win.inner_text("#badge").strip() == expected,
              win.inner_text("#badge"))

        if outcome == "approve":
            check(label + ": Complete transaction offered", win.is_visible("#btnComplete"))
            check(label + ": no retry offered on success", not win.is_visible("#btnRetry"))
            check(label + ": card details on screen", "4242" in win.inner_text("#cardInfo"),
                  win.inner_text("#cardInfo")[:120])
            win.click("#btnComplete")
        else:
            check(label + ": Try another card offered", win.is_visible("#btnRetry"))
            check(label + ": no Complete button on a decline", not win.is_visible("#btnComplete"))
            check(label + ": reason shown to the cashier",
                  len(win.inner_text("#detail").strip()) > 5, win.inner_text("#detail"))
            win.click("#btnClose")

        till.wait_for_timeout(1000)
        out = till.inner_text("#out")

        if outcome == "approve":
            check(label + ": POS received APPROVED", "APPROVED" in out, out[:160])
            check(label + ": POS received the charge id", "ch_" in out, out[:160])
            check(label + ": POS received brand and last 4", "****4242" in out, out[:160])
            check(label + ": Cash done appears once approved", till.is_visible("#btnCashDone"))
            check(label + ": Card button hidden once paid", not till.is_visible("#btnCard"))
            till.click("#btnCashDone")
            till.wait_for_timeout(200)
            check(label + ": Cash done runs the POS finish step",
                  "Cash done pressed" in till.inner_text("#out"), till.inner_text("#out")[:120])
            row = sale_row(sale_id)
            if row:
                check(label + ": sale row marked paid", row["payment_status"] == "paid", row)
                check(label + ": amount written to the sale",
                      abs(float(row["amount_paid"]) - float(amount)) < 0.001, row)
        else:
            check(label + ": POS received the decline", ("FAILED" in out or "DECLINED" in out), out[:160])
            check(label + ": POS was NOT given a charge id", "ch_" not in out, out[:160])
            check(label + ": Cash done stays hidden after a decline", not till.is_visible("#btnCashDone"))
            row = sale_row(sale_id)
            if row:
                check(label + ": sale left unpaid", row["amount_paid"] is None, row)

        browser.close()


def run_access_checks():
    print("\n[access] the window must not be open to anyone")
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        r = page.goto("%s/pay/pay_window.php?sale_id=1&amount=1.00" % APP)
        check("access: no token is refused", r.status == 401, r.status)
        r = page.goto("%s/pay/pay_window.php?sale_id=1&amount=1.00&token=wrong" % APP)
        check("access: a wrong token is refused", r.status == 401, r.status)
        r = page.goto("%s/pay/pay_window.php?sale_id=1&amount=1.00&token=%s" % (APP, TOKEN))
        check("access: the right token gets in", r.status == 200, r.status)
        browser.close()


def run_idle_check():
    print("\n[idle] window opened without autostart")
    seed(7003, 9.99)
    post(MOCK + "/__mock/reset")
    mock(outcome="never", polls="3")
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        page.set_viewport_size({"width": 420, "height": 640})
        page.goto("%s/pay/pay_window.php?sale_id=7003&amount=9.99&token=%s" % (APP, TOKEN))
        page.wait_for_timeout(700)
        check("idle: shows IDLE", page.inner_text("#badge").strip() == "IDLE", page.inner_text("#badge"))
        check("idle: offers Charge card", page.is_visible("#btnCharge"))
        check("idle: nothing sent to the reader yet",
              "Press Charge card" in page.inner_text("#detail"), page.inner_text("#detail"))
        page.click("#btnCharge")
        page.wait_for_timeout(1500)
        check("idle: pressing Charge starts the payment",
              page.inner_text("#badge").strip() == "WAITING", page.inner_text("#badge"))
        browser.close()


def run_refresh_after_paid_check():
    """A paid sale must still show Cash done after a page refresh."""
    print("\n[refresh] reopening a paid sale keeps the button")
    if not DB:
        print("  SKIPPED (no TEST_DB)")
        return
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        page.set_viewport_size({"width": 900, "height": 650})
        # 7001 was paid by the approve case above.
        page.goto("%s/demo/till_button.php?sale_id=7001&amount=12.50" % APP)
        page.wait_for_timeout(400)
        check("refresh: Cash done visible on a paid sale", page.is_visible("#btnCashDone"))
        check("refresh: Card button hidden on a paid sale", not page.is_visible("#btnCard"))
        # And an unpaid sale must NOT show it.
        seed(7009, 4.00)
        page.goto("%s/demo/till_button.php?sale_id=7009&amount=4.00" % APP)
        page.wait_for_timeout(400)
        check("refresh: Cash done hidden on an unpaid sale", not page.is_visible("#btnCashDone"))
        check("refresh: Card button shown on an unpaid sale", page.is_visible("#btnCard"))
        browser.close()


def run_style_override_check():
    """pay/pay_window_custom.css must win over the built-in sizes/colours."""
    print("\n[style] pay_window_custom.css overrides the look")
    css_path = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                            "pay", "pay_window_custom.css")
    existed = os.path.exists(css_path)
    if existed:
        print("  SKIPPED (you already have a pay_window_custom.css - not touching it)")
        return
    with open(css_path, "w") as fh:
        fh.write(":root { --size-headline: 40px; --ok: rgb(1, 2, 3); }\n")
    try:
        seed(7008, 6.00)
        post(MOCK + "/__mock/reset")
        mock(outcome="approve", polls="1")
        with sync_playwright() as p:
            browser = p.chromium.launch()
            page = browser.new_page()
            page.set_viewport_size({"width": 420, "height": 640})
            page.goto("%s/pay/pay_window.php?sale_id=7008&amount=6.00&autostart=1&token=%s" % (APP, TOKEN))
            page.wait_for_selector(".s-ok", timeout=20000)
            page.wait_for_timeout(300)
            size = page.eval_on_selector("#headline", "e => getComputedStyle(e).fontSize")
            colour = page.eval_on_selector("#headline", "e => getComputedStyle(e).color")
            check("style: headline size follows the override", size == "40px", size)
            check("style: approved colour follows the override", colour == "rgb(1, 2, 3)", colour)
            browser.close()
    finally:
        os.remove(css_path)


run_access_checks()
run_idle_check()
run_case("approve", 7001, "12.50", "approve")
run_case("decline", 7002, "40.00", "decline", "insufficient_funds")
run_refresh_after_paid_check()
run_style_override_check()

print("\n=== WINDOW UI: %d failed ===" % len(failures))
for f in failures:
    print(" -", f)
sys.exit(1 if failures else 0)
