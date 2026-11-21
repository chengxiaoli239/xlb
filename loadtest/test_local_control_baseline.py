import http.server
import json
import threading
import unittest

from local_control_baseline import (
    ENDPOINTS,
    SafetyError,
    parse_origin,
    request_once,
    summarize,
    verify_local_health,
)


class Handler(http.server.BaseHTTPRequestHandler):
    redirect_count = 0
    last_cookie_header = None
    health_body = b'{"status":"ok","mode":"local-development"}'

    def do_GET(self):
        type(self).last_cookie_header = self.headers.get("Cookie")
        if self.path == "/api/health":
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(type(self).health_body)
        elif self.path == "/api/health-array":
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(b'[]')
        elif self.path == "/redirect":
            type(self).redirect_count += 1
            self.send_response(302)
            self.send_header("Location", "http://example.invalid/redirect-target")
            self.end_headers()
        else:
            self.send_response(401 if self.path.endswith("overview") else 200)
            self.send_header("Content-Type", "application/json")
            if self.path == "/cookie":
                self.send_header("Set-Cookie", "session=fixture; HttpOnly")
            self.end_headers()
            self.wfile.write(b'{"data":null}')

    def log_message(self, *_args):
        pass


class LocalControlBaselineTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        cls.port = cls.server.server_address[1]
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=2)

    def test_accepts_only_explicit_loopback_origins_and_reserved_ports_are_blocked(self):
        self.assertEqual(parse_origin(f"http://127.0.0.1:{self.port}"), ("127.0.0.1", self.port))
        for origin in (
            "http://localhost:4173",
            "https://127.0.0.1:4173",
            "http://127.0.0.1:4173/api",
            "http://user@127.0.0.1:4173",
            f"http://127.0.0.1:{self.port}?",
            f"http://127.0.0.1:{self.port}#fragment",
            "http://127.0.0.1:4175",
            "http://127.0.0.1:4181",
        ):
            with self.subTest(origin=origin), self.assertRaises(SafetyError):
                parse_origin(origin)

    def test_health_preflight_requires_local_development_mode(self):
        verify_local_health("127.0.0.1", self.port, 1)

    def test_health_preflight_rejects_non_object_json(self):
        Handler.health_body = b"[]"
        try:
            with self.assertRaises(SafetyError):
                verify_local_health("127.0.0.1", self.port, 1)
        finally:
            Handler.health_body = b'{"status":"ok","mode":"local-development"}'

    def test_request_does_not_follow_redirects(self):
        endpoint = type(ENDPOINTS[0])("GET /redirect", "/redirect", frozenset({200}))
        Handler.redirect_count = 0
        status, _, body = request_once("127.0.0.1", self.port, endpoint, 1)
        self.assertEqual(status, 302)
        self.assertIsNone(body)
        self.assertEqual(Handler.redirect_count, 1)

    def test_requests_never_replay_set_cookie_values(self):
        endpoint = type(ENDPOINTS[0])("GET /cookie", "/cookie", frozenset({200}))
        request_once("127.0.0.1", self.port, endpoint, 1)
        request_once("127.0.0.1", self.port, endpoint, 1)
        self.assertIsNone(Handler.last_cookie_header)

    def test_summary_counts_unexpected_http_status_as_error_without_body_data(self):
        endpoint = ENDPOINTS[0]
        summary = summarize(
            [
                (endpoint, "200", 12.0),
                (endpoint, "200", 20.0),
                (endpoint, "503", 40.0),
                (endpoint, "network_error", 50.0),
            ]
        )[0]
        self.assertEqual(summary["status_counts"], {"200": 2, "503": 1, "network_error": 1})
        self.assertEqual(summary["p50_ms"], 20.0)
        self.assertEqual(summary["p95_ms"], 50.0)
        self.assertEqual(summary["error_rate_pct"], 50.0)
        self.assertEqual(set(summary), {"endpoint", "status_counts", "p50_ms", "p95_ms", "error_rate_pct"})


if __name__ == "__main__":
    unittest.main()
