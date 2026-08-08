#!/usr/bin/env python3
import json
import os
from pathlib import Path
import queue
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit


MAX_FRAME_BYTES = 3 * 1024 * 1024
MAX_REQUEST_BYTES = 4096
ALLOWED_KEYS = {
    "Backspace",
    "Delete",
    "End",
    "Enter",
    "Escape",
    "Home",
    "PageDown",
    "PageUp",
    "Space",
    "Tab",
    "ArrowDown",
    "ArrowLeft",
    "ArrowRight",
    "ArrowUp",
}


class BrokerError(Exception):
    def __init__(self, error, status=400):
        super().__init__(error)
        self.error = error
        self.status = status


def facebook_navigation_allowed(url):
    if url == "about:blank":
        return True
    parts = urlsplit(url)
    host = (parts.hostname or "").lower()
    return parts.scheme == "https" and (host == "facebook.com" or host.endswith(".facebook.com"))


def facebook_cookie_domain_allowed(domain):
    host = str(domain).lstrip(".").lower()
    return host == "facebook.com" or host.endswith(".facebook.com")


def route_browser_request(route, request):
    if request.is_navigation_request() and not facebook_navigation_allowed(request.url):
        route.abort()
    else:
        route.continue_()


class LoginBroker:
    def __init__(self, page, context, state_path=Path("/profile/storage_state.json")):
        self.page = page
        self.context = context
        self.state_path = Path(state_path)

    def _logged_in(self):
        return any(
            cookie.get("name") == "c_user"
            and cookie.get("value")
            and facebook_cookie_domain_allowed(cookie.get("domain", ""))
            for cookie in self.context.cookies(["https://www.facebook.com/"])
        )

    def status(self):
        logged_in = self._logged_in()
        return {
            "ok": True,
            "state": "logged_in" if logged_in else "waiting_for_login",
            "logged_in": logged_in,
        }

    def frame(self):
        frame = self.page.screenshot(type="png", animations="disabled", caret="hide")
        if not isinstance(frame, bytes) or not frame.startswith(b"\x89PNG\r\n\x1a\n"):
            raise BrokerError("invalid_frame", 502)
        if len(frame) > MAX_FRAME_BYTES:
            raise BrokerError("frame_too_large", 502)
        return frame

    def input(self, payload):
        if not isinstance(payload, dict):
            raise BrokerError("invalid_input")
        operation = payload.get("type")
        if operation == "click" and set(payload) <= {"type", "x", "y"}:
            x = payload.get("x")
            y = payload.get("y")
            if not self._bounded_int(x, 0, 4096) or not self._bounded_int(y, 0, 4096):
                raise BrokerError("invalid_input")
            self.page.mouse.click(x, y)
        elif operation == "text" and set(payload) <= {"type", "text"}:
            value = payload.get("text")
            if not isinstance(value, str) or len(value) > 256:
                raise BrokerError("invalid_input")
            self.page.keyboard.type(value)
        elif operation == "key" and set(payload) <= {"type", "key"}:
            value = payload.get("key")
            if not isinstance(value, str) or len(value) > 32 or value not in ALLOWED_KEYS:
                raise BrokerError("invalid_input")
            self.page.keyboard.press(value)
        elif operation == "scroll" and set(payload) <= {"type", "delta_x", "delta_y"}:
            delta_x = payload.get("delta_x", 0)
            delta_y = payload.get("delta_y", 0)
            if not self._bounded_int(delta_x, -4096, 4096) or not self._bounded_int(delta_y, -4096, 4096):
                raise BrokerError("invalid_input")
            self.page.mouse.wheel(delta_x, delta_y)
        else:
            raise BrokerError("invalid_input")
        return self.status()

    @staticmethod
    def _bounded_int(value, minimum, maximum):
        return isinstance(value, int) and not isinstance(value, bool) and minimum <= value <= maximum

    def credentials(self, payload):
        if not isinstance(payload, dict) or set(payload) != {"username", "password"}:
            raise BrokerError("invalid_credentials")
        username = payload.get("username")
        password = payload.get("password")
        if (
            not isinstance(username, str)
            or not isinstance(password, str)
            or not username
            or not password
            or len(username) > 256
            or len(password) > 512
        ):
            raise BrokerError("invalid_credentials")
        self.page.locator('input[name="email"]').fill(username)
        self.page.locator('input[name="pass"]').fill(password)
        self.page.locator('button[name="login"]').click()
        return self.status()

    def close(self):
        result = self.status()
        if result["logged_in"]:
            self._save_state()
        return result

    def _save_state(self):
        parent = self.state_path.parent
        if not parent.is_dir() or parent.is_symlink():
            raise BrokerError("profile_unavailable", 500)
        descriptor, temporary = tempfile.mkstemp(prefix=".storage_state.", dir=parent)
        os.close(descriptor)
        try:
            os.chmod(temporary, 0o600)
            self.context.storage_state(path=temporary)
            os.chmod(temporary, 0o600)
            os.replace(temporary, self.state_path)
            os.chmod(self.state_path, 0o600)
        finally:
            try:
                os.unlink(temporary)
            except FileNotFoundError:
                pass


class BrokerWorker:
    def __init__(self, factory):
        self._requests = queue.Queue()
        self._ready = threading.Event()
        self._error = None
        self._thread = threading.Thread(target=self._run, args=(factory,), daemon=True)
        self._thread.start()
        self._ready.wait()
        if self._error is not None:
            raise self._error

    def _run(self, factory):
        cleanup = None
        try:
            broker, cleanup = factory()
            self._broker = broker
        except BaseException as error:
            self._error = error
            self._ready.set()
            return
        self._ready.set()
        while True:
            request = self._requests.get()
            if request is None:
                break
            method, args, complete = request
            try:
                complete["result"] = getattr(self._broker, method)(*args)
            except BaseException as error:
                complete["error"] = error
            complete["event"].set()
        if cleanup is not None:
            cleanup()

    def _call(self, method, *args):
        complete = {"event": threading.Event()}
        self._requests.put((method, args, complete))
        complete["event"].wait()
        if "error" in complete:
            raise complete["error"]
        return complete["result"]

    def status(self):
        return self._call("status")

    def frame(self):
        return self._call("frame")

    def input(self, payload):
        return self._call("input", payload)

    def credentials(self, payload):
        return self._call("credentials", payload)

    def close(self):
        return self._call("close")

    def stop(self):
        self._requests.put(None)
        self._thread.join()


class BrokerRequestHandler(BaseHTTPRequestHandler):
    server_version = "FacebookLoginBroker/1"

    def do_GET(self):
        try:
            if self.path == "/health":
                self._json(200, {"ok": True})
            elif self.path == "/status":
                self._json(200, self.server.broker.status())
            elif self.path == "/frame":
                self._png(self.server.broker.frame())
            elif self.path in {"/input", "/credentials", "/close"}:
                self._json(405, {"ok": False, "error": "method_not_allowed"})
            else:
                self._json(404, {"ok": False, "error": "not_found"})
        except BrokerError as error:
            self._error(error)
        except Exception:
            self._json(502, {"ok": False, "error": "broker_error"})

    def do_POST(self):
        if self.path not in {"/input", "/credentials", "/close"}:
            self._json(404, {"ok": False, "error": "not_found"})
            return
        try:
            payload = self._read_json()
            if self.path == "/input":
                result = self.server.broker.input(payload)
            elif self.path == "/credentials":
                result = self.server.broker.credentials(payload)
            else:
                if payload not in ({}, None):
                    raise BrokerError("invalid_request")
                result = self.server.broker.close()
            self._json(200, result)
            if self.path == "/close":
                threading.Thread(target=self.server.shutdown, daemon=True).start()
        except BrokerError as error:
            self._error(error)
        except (json.JSONDecodeError, UnicodeDecodeError):
            self._json(400, {"ok": False, "error": "invalid_json"})
        except Exception:
            self._json(502, {"ok": False, "error": "broker_error"})

    def _read_json(self):
        length = self.headers.get("Content-Length", "")
        if not length.isdigit() or int(length) > MAX_REQUEST_BYTES:
            raise BrokerError("invalid_request", 413 if length.isdigit() else 400)
        body = self.rfile.read(int(length))
        return json.loads(body.decode("utf-8"))

    def _error(self, error):
        self._json(error.status, {"ok": False, "error": error.error})

    def _json(self, status, payload):
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(body)

    def _png(self, body):
        self.send_response(200)
        self.send_header("Content-Type", "image/png")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, _format, *_args):
        pass


def create_server(address, broker):
    server = ThreadingHTTPServer(address, BrokerRequestHandler)
    server.broker = broker
    return server


def create_browser_broker():
    from playwright.sync_api import sync_playwright

    playwright = sync_playwright().start()
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={"width": 1280, "height": 720})
    page = context.new_page()

    page.route("**/*", route_browser_request)
    page.goto("https://www.facebook.com/login/", wait_until="domcontentloaded")

    def cleanup():
        browser.close()
        playwright.stop()

    return LoginBroker(page, context), cleanup


def main():
    broker = BrokerWorker(create_browser_broker)
    server = create_server(("0.0.0.0", 8765), broker)
    try:
        server.serve_forever()
    finally:
        server.server_close()
        broker.stop()


if __name__ == "__main__":
    main()
