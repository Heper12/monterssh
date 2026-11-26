import XCTest
@testable import MontersshCore
final class CoreTests: XCTestCase {
    func testLegacyImportAndRoundTrip() throws {
        let json = """
        [{"username":"demo","host":"example.test","path":"/www","port":"2222","rsa":"id_ed25519.pub","mount_point":"/Volumes/site"}]
        """
        let imported = try ProjectFile.decode(Data(json.utf8))
        XCTAssertEqual(imported.count, 1); XCTAssertEqual(imported[0].port, 2222)
        let p = try imported[0].validated(); XCTAssertEqual(p.mountPoint, "site"); XCTAssertTrue(p.identityPath()!.hasSuffix("/id_ed25519"))
        let url = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString).appendingPathComponent("projects.json")
        defer { try? FileManager.default.removeItem(at: url.deletingLastPathComponent()) }
        try ProjectFile.save([p], to: url); XCTAssertEqual(try ProjectFile.decode(Data(contentsOf: url)), [p])
    }
    func testUnsafePathsRejected() {
        for path in ["../bad", "folder/../bad", "/", "bad,,", "a//b", "/Volumes/"] {
            XCTAssertThrowsError(try Project(username: "demo", host: "example.test", mountPoint: path).validated(), path)
        }
    }
    func testMountParsingAndFilesystemIsolation() {
        let output = """
        /dev/disk3s1 on / (apfs, local)
        demo@host:/www on /Volumes/My Site (macfuse, synchronous)
        demo@host:/www on /Volumes/Other\\040Site (osxfuse, synchronous)
        bucket on /Volumes/Bucket (macfuse, synchronous)
        server:/nfs on /Volumes/NFS (nfs, synchronous)
        """
        let mounts = MountService.parseMounts(output)
        XCTAssertEqual(mounts.count, 5); XCTAssertEqual(mounts.filter(\.isSSHFS).count, 2)
        XCTAssertEqual(mounts[2].path, "/Volumes/Other Site")
    }
    func testArgumentsPreserveShellCharacters() throws {
        let p = Project(username: "demo", host: "example.test", path: "/www/a b; echo danger", rsa: "", mountPoint: "My Site")
        let args = try MountService.arguments(for: p)
        XCTAssertEqual(args[0], "demo@example.test:/www/a b; echo danger")
        XCTAssertEqual(args[1], "/Volumes/My Site")
        XCTAssertTrue(args.contains(where: { $0.contains("BatchMode=yes") }))
        XCTAssertEqual(MountService.shellQuote("a'b"), "'a'\\''b'")
    }
    func testProcessRunnerDrainsOutputAndReportsFailure() async throws {
        let good = try await CommandRunner.run("/usr/bin/printf", ["hello"])
        XCTAssertEqual(good.status, 0); XCTAssertEqual(good.output, "hello")
        let bad = try await CommandRunner.run("/usr/bin/false", [])
        XCTAssertNotEqual(bad.status, 0)
    }
}
