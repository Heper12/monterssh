import Foundation
import Darwin

public struct CommandResult: Sendable { public let status: Int32; public let output: String }
public enum CommandRunner {
    public static func run(_ executable: String, _ arguments: [String], timeout: Double = 25) async throws -> CommandResult {
        try await withCheckedThrowingContinuation { continuation in
            DispatchQueue.global(qos: .utility).async {
                let process = Process(); let pipe = Pipe()
                process.executableURL = URL(fileURLWithPath: executable); process.arguments = arguments
                process.standardOutput = pipe; process.standardError = pipe; process.standardInput = FileHandle.nullDevice
                var env = ProcessInfo.processInfo.environment
                env["PATH"] = "/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"
                process.environment = env
                do {
                    try process.run()
                    let deadline = DispatchWorkItem {
                        if process.isRunning { process.terminate()
                            DispatchQueue.global(qos: .utility).asyncAfter(deadline: .now() + 2) { if process.isRunning { kill(process.processIdentifier, SIGKILL) } }
                        }
                    }
                    DispatchQueue.global(qos: .utility).asyncAfter(deadline: .now() + timeout, execute: deadline)
                    defer { deadline.cancel() }
                    var data = Data()
                    while true {
                        let count = try autoreleasepool {
                            guard let chunk = try pipe.fileHandleForReading.read(upToCount: 16_384), !chunk.isEmpty else { return 0 }
                            let remaining = 128_000 - data.count
                            if remaining > 0 { data.append(chunk.prefix(remaining)) }
                            return chunk.count
                        }
                        if count == 0 { break }
                    }
                    process.waitUntilExit()
                    continuation.resume(returning: CommandResult(status: process.terminationStatus, output: String(decoding: data.prefix(128_000), as: UTF8.self).trimmingCharacters(in: .whitespacesAndNewlines)))
                } catch { continuation.resume(throwing: error) }
            }
        }
    }
}
public struct MountedVolume: Identifiable, Equatable, Sendable {
    public var id: String { path }
    public let source: String; public let path: String; public let filesystem: String
    public var isSSHFS: Bool { filesystem.lowercased().contains("sshfs") || (source.contains(":") && !source.hasPrefix("/dev/") && ["macfuse", "osxfuse", "fusefs"].contains(filesystem.lowercased())) }
    public init(source: String, path: String, filesystem: String) { self.source = source; self.path = path; self.filesystem = filesystem }
}
public enum MountService {
    public static func parseMounts(_ output: String) -> [MountedVolume] {
        output.split(separator: "\n").compactMap { line in
            guard let split = line.range(of: " on "), let options = line.range(of: " (", options: .backwards), options.lowerBound > split.upperBound else { return nil }
            let source = String(line[..<split.lowerBound]); let path = String(line[split.upperBound..<options.lowerBound]).replacingOccurrences(of: "\\040", with: " ").replacingOccurrences(of: "\\011", with: "\t").replacingOccurrences(of: "\\134", with: "\\")
            let fs = line[options.upperBound...].split(whereSeparator: { $0 == "," || $0 == ")" }).first.map(String.init) ?? ""
            return MountedVolume(source: source, path: path, filesystem: fs)
        }
    }
    public static func volumes() async throws -> [MountedVolume] {
        let r = try await CommandRunner.run("/sbin/mount", [])
        guard r.status == 0 else { throw AppError.message(r.output) }; return parseMounts(r.output)
    }
    public static var sshfsExecutable: String? { ["/opt/homebrew/bin/sshfs", "/usr/local/bin/sshfs"].first { FileManager.default.isExecutableFile(atPath: $0) } }
    public static func arguments(for project: Project) throws -> [String] {
        let p = try project.validated()
        var options = ["reconnect", "ServerAliveInterval=30", "ServerAliveCountMax=3", "ConnectTimeout=12", "BatchMode=yes", "StrictHostKeyChecking=yes", "defer_permissions", "volname=" + p.mountPoint.replacingOccurrences(of: "/", with: " · ")]
        if let key = p.identityPath() {
            guard !key.contains(","), FileManager.default.isReadableFile(atPath: key) else { throw AppError.message("Приватный SSH-ключ не найден или недоступен: \(key)") }
            options.append("IdentityFile=" + key)
        }
        return ["\(p.username)@\(p.host):\(p.path)", p.mountURL.path, "-p", String(p.port), "-o", options.joined(separator: ",")]
    }
    // AppleScript invokes only a fixed mkdir/chown recipe with independently escaped paths.
    public static func shellQuote(_ value: String) -> String { "'" + value.replacingOccurrences(of: "'", with: "'\\''") + "'" }
    static func ensureDirectory(_ path: String) async throws {
        let components = path.dropFirst("/Volumes/".count).split(separator: "/")
        var current = "/Volumes"
        for component in components {
            current += "/" + component
            var isDir: ObjCBool = false
            if FileManager.default.fileExists(atPath: current, isDirectory: &isDir) {
                let attrs = try FileManager.default.attributesOfItem(atPath: current)
                guard isDir.boolValue, attrs[.type] as? FileAttributeType != .typeSymbolicLink else { throw AppError.message("Точка монтирования содержит файл или символьную ссылку: \(current)") }
            }
        }
        if FileManager.default.isWritableFile(atPath: path) { return }
        do {
            try FileManager.default.createDirectory(atPath: path, withIntermediateDirectories: true)
            if FileManager.default.isWritableFile(atPath: path) { return }
        } catch { /* /Volumes is normally owned by root; fall through to macOS authorization. */ }
        let command = "/bin/mkdir -p " + shellQuote(path) + " && /usr/sbin/chown " + shellQuote(String(getuid()) + ":" + String(getgid())) + " " + shellQuote(path)
        let escaped = command.replacingOccurrences(of: "\\", with: "\\\\").replacingOccurrences(of: "\"", with: "\\\"")
        let result = try await CommandRunner.run("/usr/bin/osascript", ["-e", "do shell script \"\(escaped)\" with administrator privileges"], timeout: 120)
        guard result.status == 0 else { throw AppError.message(result.output) }
    }
    public static func connect(_ project: Project) async throws {
        let p = try project.validated()
        guard let binary = sshfsExecutable else { throw AppError.message("Установите SSHFS и macFUSE. Нажмите «Диагностика» в настройках.") }
        let args = try arguments(for: p)
        if let volume = try await volumes().first(where: { $0.path == p.mountURL.path }) {
            if volume.isSSHFS { return }; throw AppError.message("Эта точка уже занята другой файловой системой.")
        }
        try await ensureDirectory(p.mountURL.path)
        let r = try await CommandRunner.run(binary, args)
        guard r.status == 0 else { throw AppError.message(r.output.isEmpty ? "SSHFS не смог подключиться. Проверьте SSH-ключ, known_hosts и доступ к серверу." : r.output) }
        guard try await volumes().contains(where: { $0.path == p.mountURL.path && $0.isSSHFS }) else { throw AppError.message("SSHFS завершился, но диск не появился.") }
    }
    public static func disconnect(_ path: String, force: Bool = false) async throws {
        guard let volume = try await volumes().first(where: { $0.path == path }) else { return }
        guard volume.isSSHFS else { throw AppError.message("Отключение разрешено только для SSHFS-дисков.") }
        let args = force ? ["unmount", "force", path] : ["unmount", path]
        let r = try await CommandRunner.run("/usr/sbin/diskutil", args)
        guard r.status == 0 else { throw AppError.message(r.output) }
        guard try await !volumes().contains(where: { $0.path == path }) else { throw AppError.message("Диск всё ещё смонтирован: \(path)") }
    }
    public static func test(_ project: Project) async throws -> String {
        let p = try project.validated()
        var args = ["-p", String(p.port), "-o", "BatchMode=yes", "-o", "ConnectTimeout=10", "-o", "StrictHostKeyChecking=yes"]
        if let key = p.identityPath() { args += ["-i", key] }
        args += [p.username + "@" + p.host, "test -d " + shellQuote(p.path.isEmpty ? "." : p.path)]
        let r = try await CommandRunner.run("/usr/bin/ssh", args, timeout: 15)
        guard r.status == 0 else { throw AppError.message(r.output.isEmpty ? "Удалённая папка недоступна." : r.output) }
        return "SSH-соединение установлено, удалённая папка доступна."
    }
}
