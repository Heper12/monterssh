import SwiftUI
import AppKit
import MontersshCore
import ServiceManagement
import Combine

struct Event: Identifiable, Codable { var id = UUID(); var date = Date(); var title: String; var detail: String; var failed = false }
@MainActor final class Store: ObservableObject {
    @Published var projects: [Project] = []
    @Published var volumes: [MountedVolume] = []
    @Published var events: [Event] = []
    @Published var busy: Set<UUID> = []
    @Published var working = false
    @Published var error: String?
    @Published var notice: String?
    @Published var page = "Проекты"
    @Published var group: String?
    @Published var query = ""
    @Published var filter = "Все"
    @Published var openFinder = UserDefaults.standard.object(forKey: "openFinder") as? Bool ?? true
    @Published var autoConnect = UserDefaults.standard.bool(forKey: "autoConnect")
    let directory = FileManager.default.homeDirectoryForCurrentUser.appendingPathComponent("Library/Application Support/Monterssh")
    var configURL: URL { directory.appendingPathComponent("projects.json") }
    var active: [MountedVolume] { volumes.filter(\.isSSHFS) }
    var refreshing = false
    private var observers = Set<AnyCancellable>()
    init() {
        do {
            if FileManager.default.fileExists(atPath: configURL.path) { projects = try ProjectFile.decode(Data(contentsOf: configURL)) }
            if let data = try? Data(contentsOf: directory.appendingPathComponent("events.json")), let saved = try? JSONDecoder().decode([Event].self, from: data) { events = saved }
        } catch { self.error = "Не удалось загрузить конфигурацию: \(error.localizedDescription). Исходный файл сохранён." }
        for name in [NSWorkspace.didMountNotification, NSWorkspace.didUnmountNotification, NSWorkspace.didWakeNotification] {
            NSWorkspace.shared.notificationCenter.publisher(for: name).sink { [weak self] _ in
                Task { @MainActor [weak self] in await self?.refresh() }
            }.store(in: &observers)
        }
    }
    func start() async { await refresh(); if autoConnect { for p in projects where p.favorite && !connected(p) { await connect(p) } } }
    func connected(_ p: Project) -> Bool { active.contains { $0.path == p.mountURL.path } }
    var visible: [Project] {
        projects.filter { p in
            (page != "Подключения" || connected(p)) && (group == nil || p.group == group) && (filter != "Избранные" || p.favorite) && (filter != "Активные" || connected(p)) && (query.isEmpty || (p.name + " " + p.host + " " + p.mountPoint).localizedCaseInsensitiveContains(query))
        }.sorted { $0.name.localizedStandardCompare($1.name) == .orderedAscending }
    }
    func record(_ title: String, _ detail: String = "", failed: Bool = false) {
        events.insert(Event(title: title, detail: detail, failed: failed), at: 0)
        events = Array(events.prefix(200))
        do { try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true); try JSONEncoder().encode(events).write(to: directory.appendingPathComponent("events.json"), options: .atomic) } catch { self.error = error.localizedDescription }
    }
    func save(_ project: Project) throws {
        let p = try project.validated()
        guard !projects.contains(where: { $0.id != p.id && $0.mountPoint == p.mountPoint }) else { throw AppError.message("Такое имя диска уже используется другим проектом.") }
        if let old = projects.first(where: { $0.id == p.id }), connected(old), old != p { throw AppError.message("Сначала отключите проект перед изменением настроек.") }
        var updated = projects
        if let i = updated.firstIndex(where: { $0.id == p.id }) { updated[i] = p } else { updated.append(p) }
        try ProjectFile.save(updated, to: configURL); projects = updated; record("Проект сохранён", p.name)
    }
    func remove(_ p: Project) {
        guard !connected(p), !busy.contains(p.id) else { error = "Сначала отключите проект."; return }
        do { let updated = projects.filter { $0.id != p.id }; try ProjectFile.save(updated, to: configURL); projects = updated; record("Проект удалён", p.name) } catch { self.error = error.localizedDescription }
    }
    func favorite(_ p: Project) {
        do { var updated = projects; if let i = updated.firstIndex(where: { $0.id == p.id }) { updated[i].favorite.toggle() }; try ProjectFile.save(updated, to: configURL); projects = updated } catch { self.error = error.localizedDescription }
    }
    func refresh() async {
        guard !refreshing else { return }; refreshing = true; defer { refreshing = false }
        do { let fresh = try await MountService.volumes(); if fresh != volumes { volumes = fresh } } catch { self.error = error.localizedDescription }
    }
    func connect(_ p: Project) async {
        guard !busy.contains(p.id), !working else { return }; busy.insert(p.id); defer { busy.remove(p.id) }
        do { try await MountService.connect(p); await refresh(); record("Подключён: " + p.name, p.mountURL.path); if openFinder { NSWorkspace.shared.open(p.mountURL) } }
        catch { self.error = error.localizedDescription; record("Ошибка подключения: " + p.name, error.localizedDescription, failed: true); await refresh() }
    }
    func disconnect(_ path: String, project: Project? = nil, force: Bool = false) async {
        guard !working else { return }; if let p = project { guard !busy.contains(p.id) else { return }; busy.insert(p.id) }
        defer { if let p = project { busy.remove(p.id) } }
        do { try await MountService.disconnect(path, force: force); record("Диск отключён", path) } catch { self.error = error.localizedDescription; record("Не удалось отключить диск", error.localizedDescription, failed: true) }
        await refresh()
    }
    func disconnectAll() async {
        guard !working, busy.isEmpty else { return }; working = true; defer { working = false }
        do {
            let paths = try await MountService.volumes().filter(\.isSSHFS).map(\.path)
            for path in paths { do { try await MountService.disconnect(path); record("Диск отключён", path) } catch { self.error = error.localizedDescription; record("Не удалось отключить диск", path + ": " + error.localizedDescription, failed: true) } }
        } catch { self.error = error.localizedDescription }
        await refresh()
    }
    func importFile() {
        let panel = NSOpenPanel(); panel.allowedContentTypes = [.json]; panel.allowsMultipleSelection = false
        guard panel.runModal() == .OK, let url = panel.url else { return }
        do {
            let imported = try ProjectFile.decode(Data(contentsOf: url)); var updated = projects; var count = 0
            for item in imported { let p = try item.validated(); if !updated.contains(where: { $0.mountPoint == p.mountPoint }) { updated.append(p); count += 1 } }
            try ProjectFile.save(updated, to: configURL); projects = updated; record("Импортировано проектов: \(count)"); notice = "Добавлено \(count) проектов. Существующие точки пропущены."
        } catch { self.error = "Импорт отменён без изменения данных: \(error.localizedDescription)" }
    }
    func exportFile(log: Bool = false) {
        let panel = NSSavePanel(); panel.allowedContentTypes = [.json]; panel.nameFieldStringValue = log ? "monterssh-events.json" : "monterssh-projects.json"
        guard panel.runModal() == .OK, let url = panel.url else { return }
        do { if log { try JSONEncoder().encode(events).write(to: url, options: .atomic) } else { try ProjectFile.save(projects, to: url) } } catch { self.error = error.localizedDescription }
    }
    func diagnostics() async {
        let result = try? await CommandRunner.run("/usr/bin/ssh", ["-V"])
        notice = "SSHFS: \(MountService.sshfsExecutable ?? "не найден")\nmacFUSE: \(FileManager.default.fileExists(atPath: "/Library/Filesystems/macfuse.fs") ? "установлен" : "не найден")\n\(result?.output ?? "SSH недоступен")\nДля новых серверов сначала подтвердите ключ хоста через ssh в Терминале. Ключи с паролем добавьте в SSH Agent (ssh-add)."
    }
}
