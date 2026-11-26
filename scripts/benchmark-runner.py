#!/usr/bin/env python3
"""Compare command runner RSS against pre-optimization commit; no server access."""
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="monterssh-bench-") as temp:
    temp = Path(temp)
    project = (root / "Sources/MontersshCore/Project.swift").read_text()
    harness = '''
@main struct Bench {
    static func main() async throws {
        let result = try await CommandRunner.run("/usr/bin/perl", ["-e", "print \\\"x\\\" x 16384 for 1..6104"])
        print(result.output.utf8.count)
    }
}
'''
    for version in ["before", "after"]:
        source = subprocess.check_output(["git", "show", "830aa82:Sources/MontersshCore/MountService.swift"], cwd=root, text=True) if version == "before" else (root / "Sources/MontersshCore/MountService.swift").read_text()
        path = temp / (version + ".swift")
        path.write_text(project + "\n" + source + harness)
        subprocess.run(["swiftc", "-O", "-parse-as-library", str(path), "-o", str(temp / version)], check=True)
        for run in range(3):
            result = subprocess.run(["/usr/bin/time", "-l", str(temp / version)], capture_output=True, text=True, check=True)
            print(version, run + 1, "; ".join(line.strip() for line in result.stderr.splitlines() if "maximum resident" in line or "peak memory footprint" in line))
