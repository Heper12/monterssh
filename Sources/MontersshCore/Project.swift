import Foundation

public struct Project: Identifiable, Codable, Equatable, Sendable {
    public var id: UUID
    public var name: String
    public var username: String
    public var host: String
    public var path: String
    public var port: Int
    public var rsa: String
    public var mountPoint: String
    public var group: String
    public var favorite: Bool
    public init(id: UUID = UUID(), name: String = "", username: String = "", host: String = "", path: String = "", port: Int = 22, rsa: String = "id_ed25519", mountPoint: String = "", group: String = "Работа", favorite: Bool = false) {
        self.id = id; self.name = name; self.username = username; self.host = host; self.path = path; self.port = port; self.rsa = rsa; self.mountPoint = mountPoint; self.group = group; self.favorite = favorite
    }
    public var mountURL: URL { URL(fileURLWithPath: "/Volumes").appendingPathComponent(mountPoint) }
    public func validated() throws -> Project {
        var p = self
        p.mountPoint = p.mountPoint.trimmingCharacters(in: .whitespacesAndNewlines)
        if p.mountPoint.lowercased().hasPrefix("/volumes/") { p.mountPoint = String(p.mountPoint.dropFirst(9)) }
        else if p.mountPoint.hasPrefix("/") { throw AppError.message("Точка монтирования должна находиться внутри /Volumes.") }
        p.mountPoint = p.mountPoint.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        let parts = p.mountPoint.split(separator: "/", omittingEmptySubsequences: false)
        guard !p.mountPoint.isEmpty, !parts.contains(where: { $0.isEmpty || $0 == "." || $0 == ".." }), !p.mountPoint.contains("\n"), !p.mountPoint.contains(",") else { throw AppError.message("Укажите корректное имя диска внутри /Volumes, без .. и запятых.") }
        guard !p.username.isEmpty, !p.host.isEmpty, !p.host.hasPrefix("-"), !p.username.contains("@"), !p.host.contains("/"), !p.host.contains(where: { $0.isWhitespace }), (1...65535).contains(p.port) else { throw AppError.message("Проверьте пользователя, адрес сервера и порт (1–65535).") }
        if p.name.trimmingCharacters(in: .whitespaces).isEmpty { p.name = p.mountPoint }
        return p
    }
    public func identityPath(home: String = FileManager.default.homeDirectoryForCurrentUser.path) -> String? {
        guard !rsa.isEmpty else { return nil }
        var key = rsa.hasSuffix(".pub") ? String(rsa.dropLast(4)) : rsa
        if key.hasPrefix("~/") { key = home + String(key.dropFirst()) }
        return key.hasPrefix("/") ? key : home + "/.ssh/" + key
    }
}
public enum AppError: Error, LocalizedError { case message(String); public var errorDescription: String? { if case .message(let s) = self { return s }; return nil } }

public struct LegacyProject: Decodable {
    let username: String; let host: String; let path: String; let port: Int; let rsa: String; let mountPoint: String
    enum CodingKeys: String, CodingKey { case username, host, path, port, rsa; case mountPoint = "mount_point" }
    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        username = try c.decode(String.self, forKey: .username); host = try c.decode(String.self, forKey: .host)
        path = try c.decodeIfPresent(String.self, forKey: .path) ?? ""; rsa = try c.decodeIfPresent(String.self, forKey: .rsa) ?? ""
        port = (try? c.decode(Int.self, forKey: .port)) ?? Int((try? c.decode(String.self, forKey: .port)) ?? "22") ?? 22
        mountPoint = try c.decode(String.self, forKey: .mountPoint)
    }
    public var project: Project { Project(name: mountPoint, username: username, host: host, path: path, port: port, rsa: rsa, mountPoint: mountPoint) }
}
public enum ProjectFile {
    public static func decode(_ data: Data) throws -> [Project] {
        if let projects = try? JSONDecoder().decode([Project].self, from: data) { return projects }
        return try JSONDecoder().decode([LegacyProject].self, from: data).map(\.project)
    }
    public static func save(_ projects: [Project], to url: URL) throws {
        try FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
        let encoder = JSONEncoder(); encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        try encoder.encode(projects).write(to: url, options: .atomic)
        try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: url.path)
    }
}
