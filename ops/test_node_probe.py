import unittest
from unittest.mock import patch

from node_probe import ARCHIVE_SHA256, check_capacity, cleanup, http_probe, identity, server_config, server_setup, ssh_command


class ProbeSafetyTest(unittest.TestCase):
    def setUp(self):
        self.healthy = {"load_per_cpu": 0.1, "available_mib": 1640, "free_disk_mib": 32000, "local_http": "200"}

    def test_resource_pressure_blocks_all_deployment_before_mutation(self):
        for field, value in (("load_per_cpu", 0.5), ("available_mib", 1023), ("free_disk_mib", 511), ("local_http", "503")):
            with self.subTest(field=field), self.assertRaises(RuntimeError):
                check_capacity({**self.healthy, field: value})
        check_capacity(self.healthy)

    def test_host_arguments_and_cleanup_paths_cannot_escape_task_scope(self):
        for value in ("-oProxyCommand=id", "pd01;id", "ec2-user@host", "pd01\nid"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                ssh_command(value)
        for value in ("xlb-probe-../../../www", "xlb-probe-123", "nginx", "xlb-probe-" + "0" * 12 + ";id"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                identity(value)
        with patch("node_probe.run") as remote:
            with self.assertRaises(ValueError):
                cleanup(["ssh", "pd01"], "nginx")
            remote.assert_not_called()

    def test_failed_https_requests_are_never_reported_as_success(self):
        with patch("node_probe.run", return_value="503 0.5 100"):
            with self.assertRaises(RuntimeError):
                http_probe(17777, "https://example.com/", "/tmp/probe")

    def test_https_transport_keeps_tls_validation_and_resource_bounds(self):
        with patch("node_probe.run", return_value="200 0.5 300") as command:
            result = http_probe(17777, "https://example.com/", "/tmp/probe")
            self.assertEqual(result, {"status": 200, "seconds": 0.5, "bytes": 300})
            args = command.call_args.args[0]
            self.assertNotIn("--insecure", args)
            self.assertIn("--max-time", args)
            self.assertIn("--max-filesize", args)
            self.assertIn("--limit-rate", args)
            self.assertIn("socks5h://127.0.0.1:17777", args)

    def test_temporary_unit_can_access_its_private_run_directory(self):
        # PrivateTmp=yes hides /var/tmp from the service and breaks EXEC.
        command = server_setup("xlb-probe-000000000000", server_config(32000, "test-secret"), ARCHIVE_SHA256)
        self.assertIn("--property=PrivateTmp=no", command)
        self.assertIn("--property=CPUQuota=20%", command)
        self.assertIn("--property=RuntimeMaxSec=600s", command)
        self.assertIn("--property=MemoryMax=128M", command)
        self.assertEqual(server_config(32000, "test-secret")["inbounds"][0]["listen"], "127.0.0.1")


if __name__ == "__main__":
    unittest.main()
