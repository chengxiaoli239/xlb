#!/usr/bin/env python3
"""Bounded standard-library smoke measurements for an isolated loopback API."""

from __future__ import annotations

import argparse
import concurrent.futures
import http.client
import json
import math
import sys
import time
from collections import Counter
from dataclasses import dataclass
from typing import Iterable
from urllib.parse import urlsplit

MAX_REQUESTS = 100
MAX_TIMEOUT_SECONDS = 5.0
FORBIDDEN_PORTS = {4175, 4181}
MAX_RESPONSE_BYTES = 256 * 1024


@dataclass(frozen=True)
class Endpoint:
    name: str
    path: str
    expected_statuses: frozenset[int]


ENDPOINTS = (
    Endpoint("GET /api/health", "/api/health", frozenset({200})),
    Endpoint("GET /api/v1/catalog/plans", "/api/v1/catalog/plans", frozenset({200})),
    Endpoint("GET /api/v1/auth/session (anonymous)", "/api/v1/auth/session", frozenset({200})),
    Endpoint("GET /api/v1/me/overview (anonymous denial)", "/api/v1/me/overview", frozenset({401})),
)


class SafetyError(ValueError):
    pass


def parse_origin(value: str) -> tuple[str, int]:
    try:
        parsed = urlsplit(value)
        port = parsed.port
    except ValueError as error:
        raise SafetyError("origin must be an explicit http://127.0.0.1:<port> origin") from error

    if (
        parsed.scheme != "http"
        or parsed.hostname != "127.0.0.1"
        or parsed.username is not None
        or parsed.password is not None
        or parsed.path not in ("", "/")
        or "?" in value
        or "#" in value
        or parsed.query
        or parsed.fragment
        or port is None
        or not 1 <= port <= 65535
        or port in FORBIDDEN_PORTS
    ):
        raise SafetyError(
            "origin must be an explicit http://127.0.0.1:<port> origin; 4175 and 4181 are reserved"
        )
    return "127.0.0.1", port


def _read_bounded(response: http.client.HTTPResponse, limit: int = MAX_RESPONSE_BYTES) -> bytes:
    body = bytearray()
    while True:
        chunk = response.read(min(8192, limit + 1 - len(body)))
        if not chunk:
            return bytes(body)
        body.extend(chunk)
        if len(body) > limit:
            raise SafetyError("API response exceeded the bounded size limit")


def request_once(
    host: str,
    port: int,
    endpoint: Endpoint,
    timeout: float,
    retain_body: bool = False,
) -> tuple[int, float, bytes | None]:
    started = time.perf_counter()
    connection = http.client.HTTPConnection(host, port, timeout=timeout)
    try:
        connection.request(
            "GET",
            endpoint.path,
            headers={"Accept": "application/json", "Connection": "close"},
        )
        response = connection.getresponse()
        status = response.status
        body = _read_bounded(response) if retain_body else None
        if not retain_body:
            _drain_bounded(response)
        elapsed_ms = (time.perf_counter() - started) * 1000
        return status, elapsed_ms, body
    finally:
        connection.close()


def _drain_bounded(response: http.client.HTTPResponse) -> None:
    total = 0
    while True:
        chunk = response.read(min(8192, MAX_RESPONSE_BYTES + 1 - total))
        if not chunk:
            return
        total += len(chunk)
        if total > MAX_RESPONSE_BYTES:
            raise SafetyError("API response exceeded the bounded size limit")


def verify_local_health(host: str, port: int, timeout: float) -> None:
    endpoint = ENDPOINTS[0]
    status, _, body = request_once(host, port, endpoint, timeout, retain_body=True)
    if status != 200 or body is None:
        raise SafetyError("health preflight must return HTTP 200")
    try:
        health = json.loads(body)
    except (json.JSONDecodeError, UnicodeDecodeError) as error:
        raise SafetyError("health preflight must return JSON local-development mode") from error
    if not isinstance(health, dict):
        raise SafetyError("health preflight must return a JSON object")
    if health.get("status") != "ok" or health.get("mode") != "local-development":
        raise SafetyError("health preflight must report status=ok and mode=local-development")


def percentile(values: Iterable[float], quantile: float) -> float:
    ordered = sorted(values)
    if not ordered:
        return 0.0
    index = max(0, math.ceil(quantile * len(ordered)) - 1)
    return ordered[index]


def summarize(results: list[tuple[Endpoint, str, float]]) -> list[dict[str, object]]:
    summaries = []
    for endpoint in ENDPOINTS:
        endpoint_results = [row for row in results if row[0] == endpoint]
        if not endpoint_results:
            continue
        counts = Counter(status for _, status, _ in endpoint_results)
        errors = sum(
            1
            for _, status, _ in endpoint_results
            if not status.isdigit() or int(status) not in endpoint.expected_statuses
        )
        durations = [elapsed_ms for _, _, elapsed_ms in endpoint_results]
        summaries.append(
            {
                "endpoint": endpoint.name,
                "status_counts": dict(sorted(counts.items())),
                "p50_ms": round(percentile(durations, 0.50), 2),
                "p95_ms": round(percentile(durations, 0.95), 2),
                "error_rate_pct": round(errors * 100 / len(endpoint_results), 2),
            }
        )
    return summaries


def run_measurement(host: str, port: int, requests: int, concurrency: int, timeout: float) -> dict[str, object]:
    verify_local_health(host, port, timeout)
    jobs = [ENDPOINTS[index % len(ENDPOINTS)] for index in range(requests)]

    def run(endpoint: Endpoint) -> tuple[Endpoint, str, float]:
        started = time.perf_counter()
        try:
            status, elapsed_ms, _ = request_once(host, port, endpoint, timeout)
            return endpoint, str(status), elapsed_ms
        except (OSError, http.client.HTTPException, SafetyError, TimeoutError):
            return endpoint, "network_error", (time.perf_counter() - started) * 1000

    with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as executor:
        results = list(executor.map(run, jobs))
    return {"endpoints": summarize(results)}


def positive_int(value: str) -> int:
    try:
        parsed = int(value)
    except ValueError as error:
        raise argparse.ArgumentTypeError("must be an integer") from error
    if parsed <= 0 or parsed > MAX_REQUESTS:
        raise argparse.ArgumentTypeError(f"must be between 1 and {MAX_REQUESTS}")
    return parsed


def bounded_timeout(value: str) -> float:
    try:
        parsed = float(value)
    except ValueError as error:
        raise argparse.ArgumentTypeError("must be a number") from error
    if not 0.1 <= parsed <= MAX_TIMEOUT_SECONDS:
        raise argparse.ArgumentTypeError(f"must be between 0.1 and {MAX_TIMEOUT_SECONDS} seconds")
    return parsed


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--requests", required=True, type=positive_int, help="total requests across all endpoints, max 100")
    parser.add_argument("--concurrency", required=True, type=int, choices=(1, 4))
    parser.add_argument("--timeout", type=bounded_timeout, default=2.0)
    parser.add_argument("--confirm-isolated", action="store_true", help="confirm disposable local/testing API and database")
    args = parser.parse_args(argv)

    if not args.confirm_isolated:
        parser.error("--confirm-isolated is required; this tool is only for a disposable local/testing API")
    try:
        host, port = parse_origin(args.origin)
        result = run_measurement(host, port, args.requests, args.concurrency, args.timeout)
    except SafetyError as error:
        print(f"measurement refused: {error}", file=sys.stderr)
        return 2

    print(json.dumps(result, ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
