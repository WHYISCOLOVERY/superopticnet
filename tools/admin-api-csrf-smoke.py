import re
import sys

try:
    import requests
except ImportError as exc:  # pragma: no cover
    print(f"requests import failed: {exc}")
    sys.exit(2)


BASE_URL = "http://127.0.0.1/superopticnet"
LOGIN_URL = f"{BASE_URL}/staff-login.php"
IMPORT_URL = f"{BASE_URL}/api/import-users.php"
SYNC_URL = f"{BASE_URL}/api/olt-sync-users.php"
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
if login_response.status_code not in {302, 303} or "portal.php" not in login_response.headers.get("Location", ""):
    fail("employee login failed", 5)

payload = {"users": []}
without_csrf = session.post(IMPORT_URL, json=payload, timeout=20)
if without_csrf.status_code not in {403, 419}:
    fail(f"import-users without CSRF should be blocked, got {without_csrf.status_code}", 6)

with_csrf = session.post(IMPORT_URL, json=payload, headers={"X-CSRF-Token": csrf}, timeout=20)
if with_csrf.status_code >= 500:
    fail(f"import-users with CSRF returned server error {with_csrf.status_code}", 7)

without_csrf_sync = session.post(SYNC_URL, timeout=20)
if without_csrf_sync.status_code not in {403, 419}:
    fail(f"olt-sync-users without CSRF should be blocked, got {without_csrf_sync.status_code}", 8)

with_csrf_sync = session.post(SYNC_URL, headers={"X-CSRF-Token": csrf}, timeout=20)
if with_csrf_sync.status_code >= 500:
    fail(f"olt-sync-users with CSRF returned server error {with_csrf_sync.status_code}", 9)

print("ok")
