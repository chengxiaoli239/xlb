#!/usr/bin/env python3
"""Bounded Shadowsocks/Mihomo egress probe; never changes the system proxy."""

import argparse
import base64
import hashlib
import ipaddress
import json
import re
import secrets
import shlex
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path


VERSION = "1.14.4"
ARCHIVE = f"sing-box-{VERSION}-linux-amd64.tar.gz"
ARCHIVE_URL = f"https://github.com/SagerNet/sing-box/releases/download/v{VERSION}/{ARCHIVE}"
ARCHIVE_SHA256 = "699a1b510aa4396b2176d32d1181920399ebf1e267835da6f3b598d0f7c24968"
RUN_PREFIX = "xlb-probe-"
TTL_SECONDS = 600


def run(command, *, input_text=None, timeout=30):
    result = subprocess.run(command, input=input_text, text=True, capture_output=True, timeout=timeout)
    if result.returncode:
        # SSH/config errors can contain sensitive configuration; do not print them.
        raise RuntimeError(f"{Path(command[0]).name} failed with exit code {result.returncode}")
    return result.stdout.strip()


def ssh_command(host):
    if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]{0,80}", host):
        raise ValueError("Use an SSH alias, not a shell command, address or option")
    return ["ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=8", host]


def identity(run_id):
    if not re.fullmatch(r"xlb-probe-[0-9a-f]{12}", run_id):
        raise ValueError("Invalid probe resource identity")
    return f"/var/tmp/{run_id}", run_id


def snapshot(ssh):
    script = """set -eu
python3 - <<'PY'
import json, os, shutil, subprocess
memory = dict(line.split(':', 1) for line in open('/proc/meminfo'))
services = {name: subprocess.run(['systemctl', 'is-active', name], text=True, capture_output=True).stdout.strip() for name in ('nginx', 'mysqld', 'php-fpm')}
http = subprocess.check_output(['curl', '-sS', '--max-time', '5', '-o', '/dev/null', '-w', '%{http_code}', 'http://127.0.0.1/'], text=True)
print(json.dumps({'load_per_cpu': os.getloadavg()[0] / os.cpu_count(), 'available_mib': int(memory['MemAvailable'].split()[0]) // 1024, 'free_disk_mib': shutil.disk_usage('/var/tmp').free // 1048576, 'services': services, 'local_http': http}))
PY
"""
    return json.loads(run(ssh + ["bash -se"], input_text=script))


def check_capacity(state):
    if state["load_per_cpu"] >= 0.5 or state["available_mib"] < 1024 or state["free_disk_mib"] < 512:
        raise RuntimeError("Shared host resource gate failed; no probe was installed")
    if state["local_http"] != "200":
        raise RuntimeError("Existing local website is not healthy; no probe was installed")


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def wait_port(port, process):
    deadline = time.monotonic() + 15
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError("Probe process exited before becoming ready")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                return
        except OSError:
            time.sleep(0.2)
    raise RuntimeError("Probe did not become ready in 15 seconds")


def server_setup(run_id, config, local_archive_hash):
    directory, unit = identity(run_id)
    if local_archive_hash != ARCHIVE_SHA256:
        raise ValueError("Archive checksum mismatch")
    encoded = base64.b64encode(json.dumps(config).encode()).decode()
    return f"""set -eu
umask 077
test "$(uname -m)" = x86_64
test ! -e {directory}
mkdir {directory}
# Independent timers stop the process and remove only this run's directory.
sudo -n systemd-run --quiet --unit={unit}-cleanup --on-active=660s /usr/bin/rm -rf -- {directory}
base64 -d > {directory}/config.json <<'XLB_CONFIG'
{encoded}
XLB_CONFIG
tar -xzf - -C {directory} --strip-components=1
{directory}/sing-box check -c {directory}/config.json >/dev/null 2>&1
sudo -n systemd-run --quiet --collect --unit={unit} --uid="$(id -u)" \
  --property=RuntimeMaxSec={TTL_SECONDS}s --property=CPUQuota=20% \
  --property=MemoryMax=128M --property=MemorySwapMax=0 \
  --property=TasksMax=32 --property=LimitNOFILE=256 --property=Nice=10 \
  --property=NoNewPrivileges=yes --property=ProtectSystem=strict \
  --property=ProtectHome=yes --property=PrivateTmp=no \
  {directory}/sing-box run -c {directory}/config.json
sudo -n systemctl is-active --quiet {unit}
echo started
"""


def cleanup(ssh, run_id):
    directory, unit = identity(run_id)
    script = f"""set -eu
sudo -n systemctl stop {unit}.service >/dev/null 2>&1 || true
if sudo -n systemctl is-active --quiet {unit}.service; then exit 1; fi
rm -rf -- {directory}
test ! -e {directory}
sudo -n systemctl stop {unit}-cleanup.timer >/dev/null 2>&1 || true
echo removed
"""
    return run(ssh + ["bash -se"], input_text=script)


def http_probe(port, url, output):
    metrics = run([
        "curl", "--fail", "--silent", "--show-error", "--noproxy", "",
        "--proxy", f"socks5h://127.0.0.1:{port}", "--connect-timeout", "8",
        "--max-time", "15", "--max-filesize", "262144", "--limit-rate", "64k",
        "--output", str(output), "--write-out", "%{http_code} %{time_total} %{size_download}", url,
    ], timeout=20)
    status, elapsed, size = metrics.split()
    if status != "200":
        raise RuntimeError("HTTPS probe failed")
    return {"status": int(status), "seconds": float(elapsed), "bytes": int(size)}


def server_config(port, password):
    return {
        "log": {"level": "error"},
        "dns": {"servers": [{"type": "local", "tag": "system"}]},
        "route": {"default_domain_resolver": "system"},
        "inbounds": [{
            "type": "shadowsocks", "tag": "probe", "listen": "127.0.0.1",
            "listen_port": port, "method": "2022-blake3-aes-256-gcm", "password": password,
        }],
        "outbounds": [{"type": "direct", "tag": "direct"}],
    }


def probe(args):
    ssh = ssh_command(args.host)
    before = snapshot(ssh)
    check_capacity(before)
    binary = Path(args.mihomo).resolve(strict=True)
    if not binary.is_file():
        raise ValueError("Mihomo binary does not exist")
    # Download only on the developer machine; uploaded archive is checksum pinned.
    if args.archive:
        archive = Path(args.archive).read_bytes()
    else:
        with urllib.request.urlopen(ARCHIVE_URL, timeout=30) as response:
            archive = response.read(40 * 1024 * 1024 + 1)
    if len(archive) > 40 * 1024 * 1024:
        raise RuntimeError("Unexpected binary archive size")
    digest = hashlib.sha256(archive).hexdigest()
    if digest != ARCHIVE_SHA256:
        raise RuntimeError("Release archive failed SHA256 verification")
    check_capacity(snapshot(ssh))
    run_id = RUN_PREFIX + secrets.token_hex(6)
    password = base64.b64encode(secrets.token_bytes(32)).decode()
    # A random nonprivileged loopback port, never a public listener.
    remote_port = secrets.randbelow(12000) + 32000
    forwarded_port, client_port = free_port(), free_port()
    while client_port == forwarded_port:
        client_port = free_port()
    config = server_config(remote_port, password)
    processes = []
    report = {
        "run_id": run_id, "host": args.host, "server_version": VERSION, "before": before,
        "protocol": "Shadowsocks 2022 over a temporary SSH forward",
        "public_direct_path_verified": False, "system_proxy_changed": False, "passed": False,
    }
    try:
        # Setup input includes the config, followed by a pinned tar archive.
        setup = server_setup(run_id, config, digest)
        # Transfer archive separately from bash input; credentials never enter argv.
        prepare = setup.split("tar -xzf -", 1)[0]
        remainder = "tar -xzf -" + setup.split("tar -xzf -", 1)[1]
        run(ssh + ["bash -se"], input_text=prepare)
        result = subprocess.run(
            ssh + ["bash -c " + shlex.quote(remainder)], input=archive, capture_output=True, timeout=40,
        )
        if result.returncode:
            raise RuntimeError("Remote bounded node setup failed")
        forward = subprocess.Popen(
            ssh[:-1] + ["-N", "-o", "ExitOnForwardFailure=yes", "-o", "ServerAliveInterval=10",
                        "-o", "ServerAliveCountMax=2", "-L",
                        f"127.0.0.1:{forwarded_port}:127.0.0.1:{remote_port}", args.host],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        processes.append(forward)
        wait_port(forwarded_port, forward)
        with tempfile.TemporaryDirectory(prefix=run_id) as temporary:
            root = Path(temporary)
            profile = {
                "mixed-port": client_port, "bind-address": "127.0.0.1", "allow-lan": False,
                "ipv6": False, "log-level": "silent", "mode": "rule",
                "proxies": [{
                    "name": "xlb-test", "type": "ss", "server": "127.0.0.1",
                    "port": forwarded_port, "cipher": "2022-blake3-aes-256-gcm",
                    "password": password, "udp": False,
                }],
                "rules": ["MATCH,xlb-test"],
            }
            config_path = root / "profile.json"
            config_path.write_text(json.dumps(profile))
            config_path.chmod(0o600)
            run([str(binary), "-d", str(root), "-f", str(config_path), "-t"])
            client = subprocess.Popen(
                [str(binary), "-d", str(root), "-f", str(config_path)],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            processes.append(client)
            wait_port(client_port, client)
            report["exit_ip_request"] = http_probe(client_port, "https://checkip.amazonaws.com/", root / "exit-ip")
            exit_ip = str(ipaddress.ip_address((root / "exit-ip").read_text().strip()))
            expected_ip = str(ipaddress.ip_address(args.expected_ip))
            if exit_ip != expected_ip:
                raise RuntimeError("Egress address does not match the expected test host")
            report["egress_ip_matches"] = True
            report["web_request"] = http_probe(client_port, "https://example.com/", root / "page")
            if b"Example Domain" not in (root / "page").read_bytes():
                raise RuntimeError("HTTPS body validation failed")
            report["body_validated"] = True
            properties = run(ssh + [
                f"sudo -n systemctl show {run_id}.service "
                "--property=MemoryCurrent,MemoryMax,MemoryPeak,CPUQuotaPerSecUSec,CPUUsageNSec,TasksMax,RuntimeMaxUSec",
            ])
            report["runtime_limits"] = dict(line.split("=", 1) for line in properties.splitlines() if "=" in line)
        report["passed"] = True
    except Exception:
        diagnostic = run(ssh + [f"sudo -n journalctl -u {run_id}.service -n 12 --no-pager -o cat"])
        report["server_diagnostic"] = diagnostic.replace(password, "[redacted]")[-2000:]
        raise
    finally:
        for process in reversed(processes):
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
        report["cleanup"] = cleanup(ssh, run_id)
        report["after"] = snapshot(ssh)
        report["existing_services_unchanged"] = (
            before["services"] == report["after"]["services"]
            and before["local_http"] == report["after"]["local_http"]
        )
        if not report["existing_services_unchanged"]:
            report["passed"] = False
        print(json.dumps(report, ensure_ascii=False, indent=2))
    if not report["passed"]:
        raise RuntimeError("Existing website/service health changed during probe")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--host", required=True, help="Existing SSH alias; only this host is changed")
    parser.add_argument("--expected-ip", required=True, help="Expected egress IPv4 of the authorized host")
    parser.add_argument("--mihomo", required=True, help="Path to an existing local Mihomo binary")
    parser.add_argument("--archive", help="Optional predownloaded archive; pinned SHA256 is always verified")
    args = parser.parse_args()
    ipaddress.ip_address(args.expected_ip)
    probe(args)


if __name__ == "__main__":
    main()
