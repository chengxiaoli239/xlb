#!/usr/bin/env python3

import argparse
from datetime import date
import json
from pathlib import Path


def load_result(path: Path) -> dict:
    result = json.loads(path.read_text(encoding="utf-8"))
    endpoints = result.get("endpoints")
    if not isinstance(endpoints, list) or not endpoints:
        raise ValueError("measurement result is missing endpoint summaries")
    return result


def render_section(result: dict, concurrency: int) -> list[str]:
    lines = [f"### Concurrency {concurrency}", "", "| Endpoint | Status counts | P50 ms | P95 ms | Error rate |", "| --- | --- | ---: | ---: | ---: |"]
    for endpoint in result["endpoints"]:
        counts = ", ".join(f"{status}: {count}" for status, count in endpoint["status_counts"].items())
        lines.append(
            f"| {endpoint['endpoint']} | {counts} | {endpoint['p50_ms']:.2f} | "
            f"{endpoint['p95_ms']:.2f} | {endpoint['error_rate_pct']:.2f}% |"
        )
    return lines


def main() -> int:
    parser = argparse.ArgumentParser(description="Write a short report from sanitized local baseline summaries.")
    parser.add_argument("--single", required=True, type=Path)
    parser.add_argument("--quad", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()

    if args.output.exists():
        parser.error("refusing to overwrite an existing report")
    single = load_result(args.single)
    quad = load_result(args.quad)
    content = [
        "# Local control-plane smoke measurement",
        "",
        f"Measurement date: {date.today().isoformat()}.",
        "",
        "This report contains only endpoint names, HTTP status counts, latency percentiles, and error rates. No response bodies, cookies, account data, or credentials are recorded.",
        "",
        *render_section(single, 1),
        "",
        *render_section(quad, 4),
        "",
        "## Scope and limitations",
        "",
        "This is a bounded local PHP development-server + disposable SQLite smoke measurement with 80 total requests per concurrency setting. It is not a production capacity benchmark, a 3,000-concurrent-user result, a node stability result, or evidence of production VPN availability. The tool calls only loopback health, public plan catalog, anonymous session, and anonymous overview-denial endpoints; it does not log in, create orders, invoke payment, or contact cloud services.",
        "",
    ]
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("x", encoding="utf-8") as report:
        report.write("\n".join(content))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
