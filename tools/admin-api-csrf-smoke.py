import re
import os
import sys
import time

try:
    import requests
except ImportError as exc:  # pragma: no cover
    print(f"requests import failed: {exc}")
    sys.exit(2)


BASE_URL = os.environ.get("SUPEROPTIC_TEST_BASE_URL", "http://127.0.0.1/superopticnet").rstrip("/")
LOGIN_URL = f"{BASE_URL}/staff-login.php"
IMPORT_URL = f"{BASE_URL}/api/import-users.php"
SYNC_URL = f"{BASE_URL}/api/olt-sync-users.php"
LOGIN = os.environ.get("SUPEROPTIC_TEST_LOGIN", "")
PASSWORD = os.environ.get("SUPEROPTIC_TEST_PASSWORD", "")


def fail(message: str, code: int = 1) -> None:
    print(message)
    sys.exit(code)


def csrf_from_html(html: str) -> str:
    match = re.search(r'name="csrf_token"\s+value="([^"]+)"', html)
    if not match:
        fail("csrf token not found on login page", 3)
    return match.group(1)


def human_challenge_from_html(html: str) -> dict[str, str]:
    nonce_match = re.search(r'name="human_nonce"\s+value="([^"]+)"', html)
    question_match = re.search(r'<strong>\s*(\d+)\s*\+\s*(\d+)\s*=\s*\?\s*</strong>', html)
    if not nonce_match or not question_match:
        fail("local human-verification challenge not found", 3)
    time.sleep(1.1)
    return {
        "human_nonce": nonce_match.group(1),
        "human_answer": str(int(question_match.group(1)) + int(question_match.group(2))),
        "human_confirm": "1",
        "website": "",
    }


session = requests.Session()
if not LOGIN or not PASSWORD:
    print("Skipped: SUPEROPTIC_TEST_LOGIN and SUPEROPTIC_TEST_PASSWORD are required.")
    sys.exit(0)
login_page = session.get(LOGIN_URL, timeout=20)
if login_page.status_code != 200:
    fail(f"login page status unexpected: {login_page.status_code}", 4)

csrf = csrf_from_html(login_page.text)
human_challenge = human_challenge_from_html(login_page.text)
login_response = session.post(
    LOGIN_URL,
    data={
        "csrf_token": csrf,
        "portal_role": "employee",
        "login": LOGIN,
        "password": PASSWORD,
        **human_challenge,
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
