import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[1]
ANDROID_GRADLE_FILE = REPOSITORY_ROOT / "apps/android/app/build.gradle"


class AndroidNativeBuildInputTests(unittest.TestCase):
    def test_debug_and_release_native_build_track_local_node_agent_module(self):
        source = ANDROID_GRADLE_FILE.read_text(encoding="utf-8")
        task_registration_start = source.index(
            'tasks.register("buildMihomoArm64${variantName}", Exec) {'
        )
        task_registration_end = source.index("\n    }\n}", task_registration_start)
        task_registration = source[task_registration_start:task_registration_end]

        self.assertIn("def goNodeAgentDir = file('../../node-agent')", source)
        self.assertIn(
            "inputs.files(fileTree(goNodeAgentDir) { include '**/*.go', 'go.mod', 'go.sum' })",
            task_registration,
        )
        self.assertIn("registerMihomoArm64Build('debug', true)", source)
        self.assertIn("registerMihomoArm64Build('release', false)", source)


if __name__ == "__main__":
    unittest.main()
