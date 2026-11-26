import Foundation
import MontersshCore
func XCTAssertEqual<T: Equatable>(_ a: T, _ b: T) { precondition(a == b, "Expected equal: \(a) vs \(b)") }
func XCTAssertNotEqual<T: Equatable>(_ a: T, _ b: T) { precondition(a != b) }
func XCTAssertTrue(_ v: Bool) { precondition(v) }
func XCTAssertThrowsError<T>(_ expression: @autoclosure () throws -> T, _ message: String) { do { _ = try expression(); preconditionFailure("Expected error: " + message) } catch {} }
@main struct CoreTests {
    static func main() async throws {
        if CommandLine.arguments.count == 4 && CommandLine.arguments[1] == "--live-check" {
            let projects = try ProjectFile.decode(Data(contentsOf: URL(fileURLWithPath: CommandLine.arguments[2])))
            let active = try await MountService.volumes().filter(\.isSSHFS)
            guard var project = projects.first(where: { p in active.contains(where: { $0.path == p.mountURL.path }) }) else { throw AppError.message("No matching active project for live verification") }
            let root = URL(fileURLWithPath: CommandLine.arguments[3])
            guard root.path.hasPrefix("/Volumes/"), FileManager.default.isWritableFile(atPath: root.path) else { throw AppError.message("Live test root must be a writable directory inside /Volumes") }
            let folder = root.appendingPathComponent("monterssh-check-" + UUID().uuidString)
            project.mountPoint = String(folder.path.dropFirst(9))
            print("LIVE: testing SSH access without modifying remote files")
            print(try await MountService.test(project))
            try await MountService.connect(project)
            let snapshot = try await MountService.volumes()
            precondition(snapshot.contains(where: { $0.path == folder.path && $0.isSSHFS }))
            try await MountService.disconnect(folder.path)
            try FileManager.default.removeItem(at: folder)
            print("PASS: live SSH test, isolated mount and ordinary unmount; original mount preserved")
            return
        }
        let tests = CoreTests()
        try tests.testLegacyImportAndRoundTrip(); tests.testUnsafePathsRejected()
        tests.testMountParsingAndFilesystemIsolation(); try tests.testArgumentsPreserveShellCharacters()
        try await tests.testProcessRunnerDrainsOutputAndReportsFailure()
        print("PASS: import, storage round-trip, unsafe paths, mount parsing, filesystem isolation, argument quoting, process success/failure")
    }
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
        for path in ["../bad", "folder/../bad", "/", "bad,,", "a//b", "/Volumes/", "/tmp/site"] {
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
        let large = try await CommandRunner.run("/usr/bin/head", ["-c", "10000000", "/dev/zero"])
        XCTAssertEqual(large.output.utf8.count, 128_000)
        let start = Date()
        let timed = try await CommandRunner.run("/bin/sleep", ["5"], timeout: 0.1)
        XCTAssertNotEqual(timed.status, 0)
        XCTAssertTrue(Date().timeIntervalSince(start) < 3)
    }
}
