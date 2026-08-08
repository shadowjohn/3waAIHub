import importlib.util
import json
import os
from pathlib import Path
import tempfile
import threading
import unittest
from urllib.error import HTTPError
from urllib.request import Request, urlopen


MODULE_PATH = Path(__file__).resolve().parents[1] / "login_broker.py"
SPEC = importlib.util.spec_from_file_location("login_broker", MODULE_PATH)
login_broker = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(login_broker)


PNG = b"\x89PNG\r\n\x1a\nfixture"


class FakeKeyboard:
    def __init__(self):
        self.calls = []

    def type(self, value):
        self.calls.append(("type", value))

    def press(self, value):
        self.calls.append(("press", value))


class FakeMouse:
    def __init__(self):
        self.calls = []

    def click(self, x, y):
        self.calls.append(("click", x, y))

    def wheel(self, x, y):
        self.calls.append(("wheel", x, y))


class FakeLocator:
    def __init__(self, page, selector):
        self.page = page
        self.selector = selector

    def fill(self, value):
        if self.page.fail_on_fill:
            raise RuntimeError(f"driver rejected {value} at /private/host/path")
        self.page.calls.append(("fill", self.selector, value))

    def click(self):
        self.page.calls.append(("locator_click", self.selector))


class FakePage:
    def __init__(self, screenshot=PNG):
        self.keyboard = FakeKeyboard()
        self.mouse = FakeMouse()
        self.screenshot_bytes = screenshot
        self.calls = []
        self.fail_on_fill = False

    def screenshot(self, **kwargs):
        self.calls.append(("screenshot", kwargs))
        return self.screenshot_bytes

    def goto(self, url, **kwargs):
        self.calls.append(("goto", url, kwargs))

    def locator(self, selector):
        return FakeLocator(self, selector)


class FakeContext:
    def __init__(self, logged_in=False, cookie_domain=".facebook.com"):
        self.logged_in = logged_in
        self.cookie_domain = cookie_domain
        self.saved_paths = []

    def cookies(self, urls=None):
        if not self.logged_in:
            return []
        return [{"name": "c_user", "value": "123", "domain": self.cookie_domain}]

    def storage_state(self, path):
        self.saved_paths.append(path)
        Path(path).write_text('{"cookies":[]}', encoding="utf-8")


class LoginBrokerUnitTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.state_path = Path(self.temp.name) / "storage_state.json"
        self.page = FakePage()
        self.context = FakeContext()
        self.broker = login_broker.LoginBroker(self.page, self.context, self.state_path)

    def tearDown(self):
        self.temp.cleanup()

    def test_status_has_exact_contract_and_detects_c_user(self):
        self.assertEqual(
            self.broker.status(),
            {"ok": True, "state": "waiting_for_login", "logged_in": False},
        )
        self.context.logged_in = True
        self.assertEqual(
            self.broker.status(),
            {"ok": True, "state": "logged_in", "logged_in": True},
        )

    def test_status_rejects_lookalike_cookie_domains(self):
        self.context.logged_in = True
        self.context.cookie_domain = ".evilfacebook.com"

        self.assertEqual(
            self.broker.status(),
            {"ok": True, "state": "waiting_for_login", "logged_in": False},
        )

    def test_frame_is_png_and_bounded(self):
        self.assertEqual(self.broker.frame(), PNG)
        self.page.screenshot_bytes = PNG + b"x" * (3 * 1024 * 1024)
        with self.assertRaisesRegex(login_broker.BrokerError, "frame_too_large"):
            self.broker.frame()

    def test_input_operations_call_only_fixed_page_actions(self):
        for payload in [
            {"type": "click", "x": 128, "y": 72},
            {"type": "text", "text": "user@example.test"},
            {"type": "key", "key": "Tab"},
            {"type": "scroll", "delta_x": 0, "delta_y": 720},
        ]:
            self.assertEqual(self.broker.input(payload), self.broker.status())

        self.assertEqual(self.page.mouse.calls, [("click", 128, 72), ("wheel", 0, 720)])
        self.assertEqual(
            self.page.keyboard.calls,
            [("type", "user@example.test"), ("press", "Tab")],
        )

    def test_input_validation_rejects_unbounded_or_unknown_values(self):
        invalid = [
            {"type": "click", "x": -1, "y": 1},
            {"type": "click", "x": 1.5, "y": 1},
            {"type": "click", "x": 1, "y": 4097},
            {"type": "text", "text": "x" * 257},
            {"type": "key", "key": "Control+Alt+Delete"},
            {"type": "key", "key": "x" * 33},
            {"type": "scroll", "delta_x": 0, "delta_y": 4097},
            {"type": "evaluate", "script": "document.cookie"},
        ]
        for payload in invalid:
            with self.subTest(payload=payload):
                with self.assertRaises(login_broker.BrokerError):
                    self.broker.input(payload)

    def test_credentials_are_used_only_for_page_fill_and_not_returned(self):
        username = "person@example.test"
        password = "very-private-password"
        result = self.broker.credentials({"username": username, "password": password})

        self.assertEqual(result, self.broker.status())
        self.assertEqual(
            self.page.calls,
            [
                ("fill", 'input[name="email"]', username),
                ("fill", 'input[name="pass"]', password),
                ("locator_click", 'button[name="login"]'),
            ],
        )
        self.assertNotIn(username, json.dumps(result))
        self.assertNotIn(password, json.dumps(result))

    def test_close_saves_atomically_with_mode_0600_only_after_login(self):
        self.assertEqual(self.broker.close(), self.broker.status())
        self.assertFalse(self.state_path.exists())
        self.assertEqual(self.context.saved_paths, [])

        self.context.logged_in = True
        result = self.broker.close()
        self.assertEqual(result, {"ok": True, "state": "logged_in", "logged_in": True})
        self.assertEqual(self.state_path.read_text(encoding="utf-8"), '{"cookies":[]}')
        self.assertEqual(os.stat(self.state_path).st_mode & 0o777, 0o600)
        self.assertEqual(len(self.context.saved_paths), 1)
        self.assertNotEqual(Path(self.context.saved_paths[0]), self.state_path)


class LoginBrokerHttpTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.context = FakeContext()
        self.broker = login_broker.LoginBroker(
            FakePage(), self.context, Path(self.temp.name) / "storage_state.json"
        )
        self.server = login_broker.create_server(("127.0.0.1", 0), self.broker)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.base_url = f"http://127.0.0.1:{self.server.server_port}"

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)
        self.temp.cleanup()

    def request(self, method, path, payload=None):
        data = None if payload is None else json.dumps(payload).encode()
        request = Request(
            self.base_url + path,
            data=data,
            method=method,
            headers={"Content-Type": "application/json"},
        )
        try:
            with urlopen(request, timeout=2) as response:
                return response.status, response.headers, response.read()
        except HTTPError as error:
            return error.code, error.headers, error.read()

    def test_http_routes_enforce_methods_types_and_bounded_responses(self):
        status, headers, body = self.request("GET", "/health")
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body), {"ok": True})
        self.assertEqual(headers["X-Content-Type-Options"], "nosniff")

        status, _, body = self.request("GET", "/status")
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body), self.broker.status())

        status, headers, body = self.request("GET", "/frame")
        self.assertEqual((status, headers.get_content_type(), body), (200, "image/png", PNG))

        for path in ["/input", "/credentials", "/close"]:
            status, _, body = self.request("GET", path)
            self.assertEqual(status, 405)
            self.assertEqual(json.loads(body)["error"], "method_not_allowed")

        status, _, body = self.request("POST", "/input", {"type": "key", "key": "Enter"})
        self.assertEqual(status, 200)
        self.assertEqual(set(json.loads(body)), {"ok", "state", "logged_in"})

    def test_http_never_echoes_credentials_or_unknown_request_bodies(self):
        username = "private-user@example.test"
        password = "private-password"
        status, _, body = self.request(
            "POST", "/credentials", {"username": username, "password": password}
        )
        self.assertEqual(status, 200)
        self.assertNotIn(username.encode(), body)
        self.assertNotIn(password.encode(), body)

        marker = "private-body-marker"
        status, _, body = self.request("POST", "/unknown", {"value": marker})
        self.assertEqual(status, 404)
        self.assertNotIn(marker.encode(), body)

    def test_http_normalizes_browser_errors_without_logging_secrets(self):
        password = "driver-secret-password"
        self.broker.page.fail_on_fill = True
        status, _, body = self.request(
            "POST",
            "/credentials",
            {"username": "person@example.test", "password": password},
        )

        self.assertEqual(status, 502)
        self.assertEqual(json.loads(body), {"ok": False, "error": "broker_error"})
        self.assertNotIn(password.encode(), body)
        self.assertNotIn(b"/private/host/path", body)


class NavigationTests(unittest.TestCase):
    def test_only_facebook_top_level_navigation_is_allowed(self):
        for url in [
            "https://www.facebook.com/",
            "https://m.facebook.com/login/",
            "about:blank",
        ]:
            self.assertTrue(login_broker.facebook_navigation_allowed(url), url)
        for url in [
            "http://facebook.com/",
            "https://facebook.com.evil.test/",
            "https://example.test/",
            "file:///etc/passwd",
        ]:
            self.assertFalse(login_broker.facebook_navigation_allowed(url), url)

    def test_context_route_blocks_every_non_facebook_navigation(self):
        class Route:
            def __init__(self):
                self.action = None

            def abort(self):
                self.action = "abort"

            def continue_(self):
                self.action = "continue"

        class Request:
            def __init__(self, url, navigation):
                self.url = url
                self.navigation = navigation

            def is_navigation_request(self):
                return self.navigation

        cases = [
            ("https://facebook.com/login", True, "continue"),
            ("https://example.test/popup", True, "abort"),
            ("https://example.test/image.png", False, "continue"),
        ]
        for url, navigation, expected in cases:
            route = Route()
            login_broker.route_browser_request(route, Request(url, navigation))
            self.assertEqual(route.action, expected)


if __name__ == "__main__":
    unittest.main()
