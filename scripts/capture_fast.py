#!/usr/bin/env python3
"""
Fast screenshot capture — skips heavy external resources for speed.
"""
import os
import asyncio
from playwright.async_api import async_playwright

OUTPUT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'screenshots')

PAGES = [
    ("homepage",        "http://localhost/Apex%20Sports%20Club/public/index.php"),
    ("login",           "http://localhost/Apex%20Sports%20Club/public/login.php"),
    ("register",        "http://localhost/Apex%20Sports%20Club/public/register.php"),
    ("view_sports",     "http://localhost/Apex%20Sports%20Club/public/view_sports.php"),
    ("view_facilities", "http://localhost/Apex%20Sports%20Club/public/view_facilities.php"),
    ("view_fixtures",   "http://localhost/Apex%20Sports%20Club/public/view_fixtures.php"),
    ("booking",         "http://localhost/Apex%20Sports%20Club/public/booking.php"),
    ("admin_login",     "http://localhost/Apex%20Sports%20Club/admin/admin_login.php"),
    ("admin_dashboard", "http://localhost/Apex%20Sports%20Club/admin/admin_dashboard.php"),
]


async def capture():
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, args=[
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
        ])
        context = await browser.new_context(
            viewport={"width": 1440, "height": 900},
            java_script_enabled=True,
        )
        page = await context.new_page()

        # Block heavy external resources for speed
        await page.route("**/*.woff2", lambda route: route.abort())
        await page.route("**/prod.spline.design/**", lambda route: route.abort())
        await page.route("**/unpkg.com/**", lambda route: route.abort())
        await page.route("**/cdn.jsdelivr.net/**", lambda route: route.abort())
        await page.route("**/fonts.googleapis.com/**", lambda route: route.abort())
        await page.route("**/fonts.gstatic.com/**", lambda route: route.abort())

        for name, url in PAGES:
            try:
                print(f"  [{name}] loading ... ", end="", flush=True)
                resp = await page.goto(url, wait_until="domcontentloaded", timeout=15000)
                await page.wait_for_timeout(800)
                path = os.path.join(OUTPUT_DIR, f"{name}.png")
                await page.screenshot(path=path, full_page=False)
                status = resp.status if resp else "?"
                print(f"OK (HTTP {status})  ->  {path}")
            except Exception as e:
                print(f"FAILED ({e})")

        await browser.close()
    print(f"\nDone. Screenshots in: {OUTPUT_DIR}")


if __name__ == "__main__":
    asyncio.run(capture())
