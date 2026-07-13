import re
import sys
from pathlib import Path

try:
    import requests
except ImportError as exc:  # pragma: no cover
    print(f"requests import failed: {exc}")
    sys.exit(2)


BASE_URL = "http://127.0.0.1/superopticnet"
LOGIN_URL = f"{BASE_URL}/staff-login.php"
SEARCH_URL = f"{BASE_URL}/api/customer-search.php"
LOGIN = "serdar@sp.az"
PASSWORD = "serdar@sp.az"


def fail(message: str, code: int = 1) -> None:
    print(message)
    sys.exit(code)


def csrf_from_html(html: str) -> str:
    match = re.search(r'name="csrf_token"\s+value="([^"]+)"', html)
    if not match:
        fail("csrf token not found on login page", 3)
    return match.group(1)


session = requests.Session()
login_page = session.get(LOGIN_URL, timeout=20)
if login_page.status_code != 200:
    fail(f"login page status unexpected: {login_page.status_code}", 4)

csrf = csrf_from_html(login_page.text)
login_response = session.post(
    LOGIN_URL,
    data={
        "csrf_token": csrf,
        "portal_role": "employee",
        "login": LOGIN,
        "password": PASSWORD,
    },
    allow_redirects=False,
    timeout=20,
)
if login_response.status_code not in {302, 303}:
    fail(f"login response status unexpected: {login_response.status_code}", 5)

redirect_target = login_response.headers.get("Location", "")
if "portal.php" not in redirect_target:
    fail(f"login redirect unexpected: {redirect_target}", 6)

rate_limited = None
for _ in range(70):
    response = session.get(SEARCH_URL, params={"q": "SO-100245"}, timeout=20)
    if response.status_code == 429:
        rate_limited = response
        break

if rate_limited is None:
    fail("customer-search rate limit did not trigger within 70 requests", 7)

retry_after = rate_limited.headers.get("Retry-After", "").strip()
if retry_after == "":
    fail("rate-limited customer-search response missing Retry-After header", 8)

print("ok")
