import SwiftUI
import AppKit
import ServiceManagement
import MontersshCore

let ink = Color(red: 0.25, green: 0.36, blue: 0.49)
struct ContentView: View {
    @EnvironmentObject var store: Store
    @State private var editor: Project?
    @State private var deleteProject: Project?
    @State private var confirmAll = false
    @FocusState private var searching: Bool
    @Environment(\.scenePhase) private var phase
    let pages = [("Проекты", "square.grid.2x2"), ("Подключения", "externaldrive.badge.wifi"), ("Журнал событий", "clock.arrow.circlepath"), ("Настройки", "gearshape")]
    var body: some View {
        NavigationSplitView {
            VStack(alignment: .leading, spacing: 24) {
                Label("monterssh", systemImage: "externaldrive.badge.wifi").font(.title2.bold()).foregroundStyle(ink).padding(.top, 22)
                VStack(spacing: 5) {
                    ForEach(pages, id: \.0) { title, icon in
                        Button { store.page = title; store.group = nil } label: {
                            HStack { Label(title, systemImage: icon); Spacer(); if title == "Подключения" { Text("\(store.active.count)").font(.caption).monospacedDigit() } }.padding(10).frame(maxWidth: .infinity, alignment: .leading).background(store.page == title && store.group == nil ? Color.primary.opacity(0.07) : .clear, in: RoundedRectangle(cornerRadius: 10))
                        }.buttonStyle(.plain).accessibilityIdentifier(title)
                    }
                }
                VStack(alignment: .leading, spacing: 12) {
                    Text("ПРОСТРАНСТВА").font(.caption2.weight(.semibold)).foregroundStyle(.secondary)
                    ForEach(["Работа", "Личное", "Архив"], id: \.self) { group in
                        Button { store.page = "Проекты"; store.group = group } label: { Label(group, systemImage: "circle.fill").font(.callout).foregroundStyle(store.group == group ? ink : .secondary) }.buttonStyle(.plain)
                    }
                }.padding(.horizontal, 10)
                Spacer()
                VStack(alignment: .leading, spacing: 7) { Label("\(store.active.count) дисков подключено", systemImage: "circle.fill").font(.caption).foregroundStyle(.secondary); Text("Monterssh 0.1 · macOS").font(.caption2).foregroundStyle(.tertiary) }
            }.padding(16).navigationSplitViewColumnWidth(220)
        } detail: {
            VStack(spacing: 0) {
                HStack { Text("Рабочее пространство / \(store.page)").font(.caption).foregroundStyle(.secondary); Spacer(); Button { Task { await store.refresh() } } label: { Image(systemName: "arrow.clockwise") }.help("Обновить статусы").accessibilityIdentifier("refresh") }.padding(.horizontal, 28).padding(.vertical, 16)
                Divider()
                ScrollView {
                    VStack(alignment: .leading, spacing: 22) {
                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 8) { Text(store.group ?? store.page).font(.largeTitle.weight(.semibold)); Text(subtitle).font(.callout).foregroundStyle(.secondary) }
                            Spacer()
                            if store.page == "Проекты" || store.page == "Подключения" { Button { editor = Project() } label: { Label("Добавить проект", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(ink).controlSize(.large) }
                            if store.page == "Журнал событий" { Button("Экспорт") { store.exportFile(log: true) } }
                        }
                        switch store.page {
                        case "Настройки": settings
                        case "Журнал событий": activity
                        default: projects
                        }
                    }.padding(28)
                }
            }.background(Color(nsColor: .windowBackgroundColor))
        }
        .sheet(item: $editor) { ProjectEditor(project: $0).environmentObject(store) }
        .alert("Не удалось выполнить действие", isPresented: Binding(get: { store.error != nil }, set: { if !$0 { store.error = nil } })) { Button("Понятно") { store.error = nil } } message: { Text(store.error ?? "") }
        .alert("Monterssh", isPresented: Binding(get: { store.notice != nil }, set: { if !$0 { store.notice = nil } })) { Button("OK") { store.notice = nil } } message: { Text(store.notice ?? "") }
        .confirmationDialog("Отключить все SSHFS-диски? Сохраните открытые файлы.", isPresented: $confirmAll, titleVisibility: .visible) { Button("Отключить все") { Task { await store.disconnectAll() } }; Button("Отмена", role: .cancel) {} }
        .confirmationDialog("Удалить проект из приложения? Файлы сервера останутся на месте.", isPresented: Binding(get: { deleteProject != nil }, set: { if !$0 { deleteProject = nil } }), titleVisibility: .visible) { Button("Удалить", role: .destructive) { if let p = deleteProject { store.remove(p) }; deleteProject = nil } }
        .onChange(of: phase) { _, value in if value == .active { Task { await store.refresh() } } }
        .onReceive(NotificationCenter.default.publisher(for: NSWorkspace.didMountNotification)) { _ in Task { await store.refresh() } }
        .background(Button("") { searching = true }.keyboardShortcut("k").hidden())
    }
    var subtitle: String { switch store.page { case "Настройки": "Удобное поведение приложения, без лишних действий."; case "Журнал событий": "История подключений и понятные сообщения об ошибках."; case "Подключения": "Все смонтированные SSHFS-диски, включая подключённые вне приложения."; default: "Ваши удалённые проекты. Всегда под рукой." } }
    var projects: some View {
        VStack(spacing: 18) {
            HStack(spacing: 14) {
                stat("Активные подключения", String(format: "%02d", store.active.count), "из \(store.projects.count) проектов", "externaldrive.badge.wifi")
                stat("SSHFS", MountService.sshfsExecutable == nil ? "Не найден" : "Готов", "Системный SSH Agent", "key")
                stat("Фоновая работа", "Без опроса", "Обновление по событиям", "leaf")
            }
            HStack { TextField("Найти проект или сервер…", text: $store.query).textFieldStyle(.roundedBorder).focused($searching); Picker("Фильтр", selection: $store.filter) { ForEach(["Все", "Избранные", "Активные"], id: \.self) { Text($0) } }.pickerStyle(.segmented).frame(width: 270) }
            if store.projects.isEmpty { ContentUnavailableView { Label("Добавьте первый проект", systemImage: "externaldrive.badge.plus") } description: { Text("Импортируйте data.json из прежнего скрипта или добавьте сервер вручную.") } actions: { Button("Импортировать конфигурацию…") { store.importFile() } } }
            LazyVStack(spacing: 9) { ForEach(store.visible) { project in row(project) } }
            if !store.projects.isEmpty && store.visible.isEmpty { ContentUnavailableView.search(text: store.query) }
            if store.page == "Подключения" {
                ForEach(store.active.filter { v in !store.projects.contains(where: { $0.mountURL.path == v.path }) }) { volume in
                    HStack { Image(systemName: "externaldrive"); VStack(alignment: .leading) { Text(URL(fileURLWithPath: volume.path).lastPathComponent); Text("Внешнее подключение · " + volume.path).font(.caption).foregroundStyle(.secondary) }; Spacer(); Button("Открыть") { NSWorkspace.shared.open(URL(fileURLWithPath: volume.path)) }; Button("Отключить") { Task { await store.disconnect(volume.path) } }.disabled(store.working) }.padding().background(.background, in: RoundedRectangle(cornerRadius: 12))
                }
            }
            HStack { Text("⌘ K — поиск · Конфигурация хранится на Mac").font(.caption).foregroundStyle(.secondary); Spacer(); if store.working { ProgressView().controlSize(.small) }; Button("Отключить все…") { confirmAll = true }.disabled(store.active.isEmpty || store.working || !store.busy.isEmpty) }.padding(.top, 10)
        }
    }
    func stat(_ title: String, _ value: String, _ detail: String, _ icon: String) -> some View {
        VStack(alignment: .leading, spacing: 12) { Label(title, systemImage: icon).font(.caption).foregroundStyle(.secondary); Text(value).font(.title2.weight(.medium)).foregroundStyle(ink); Text(detail).font(.caption2).foregroundStyle(.secondary) }.frame(maxWidth: .infinity, alignment: .leading).padding(18).background(.regularMaterial, in: RoundedRectangle(cornerRadius: 16)).overlay(RoundedRectangle(cornerRadius: 16).stroke(.primary.opacity(0.05)))
    }
    func row(_ p: Project) -> some View {
        let connected = store.connected(p); let busy = store.busy.contains(p.id)
        return HStack(spacing: 14) {
            Text(String(p.name.prefix(1)).uppercased()).font(.title3.weight(.medium)).foregroundStyle(ink).frame(width: 40, height: 40).background(ink.opacity(0.08), in: RoundedRectangle(cornerRadius: 10))
            VStack(alignment: .leading, spacing: 5) { HStack { Text(p.name).fontWeight(.medium); if p.favorite { Image(systemName: "star.fill").font(.caption2).foregroundStyle(.orange.opacity(0.6)) } }; Text(p.username + "@" + p.host + " · " + p.group).font(.caption).foregroundStyle(.secondary).lineLimit(1) }.frame(maxWidth: .infinity, alignment: .leading)
            if busy { ProgressView().controlSize(.small) } else { Text(connected ? "● Подключён" : "○ Отключён").font(.caption).foregroundStyle(connected ? .green : .secondary) }
            Button(connected ? "Отключить" : "Подключить") { Task { if connected { await store.disconnect(p.mountURL.path, project: p) } else { await store.connect(p) } } }.disabled(busy || store.working).frame(width: 105)
            Menu {
                Button("Открыть в Finder") { NSWorkspace.shared.open(p.mountURL) }.disabled(!connected)
                Button("Проверить SSH-соединение") { Task { do { store.notice = try await MountService.test(p) } catch { store.error = error.localizedDescription } } }
                Button(p.favorite ? "Убрать из избранного" : "В избранное") { store.favorite(p) }
                Button("Изменить…") { editor = p }.disabled(connected || busy)
                if connected { Button("Принудительно отключить…", role: .destructive) { Task { await store.disconnect(p.mountURL.path, project: p, force: true) } } }
                Divider(); Button("Удалить…", role: .destructive) { deleteProject = p }.disabled(connected || busy)
            } label: { Image(systemName: "ellipsis") }.menuStyle(.borderlessButton).frame(width: 24)
        }.padding(14).background(.background, in: RoundedRectangle(cornerRadius: 12)).overlay(RoundedRectangle(cornerRadius: 12).stroke(.primary.opacity(0.06))).disabled(store.working)
    }
    var activity: some View {
        VStack(alignment: .leading, spacing: 0) {
            if store.events.isEmpty { ContentUnavailableView("Пока нет событий", systemImage: "clock") }
            ForEach(store.events) { event in HStack(alignment: .top, spacing: 15) { Text(event.date, style: .time).font(.caption).foregroundStyle(.secondary).frame(width: 68); Image(systemName: event.failed ? "exclamationmark.circle" : "checkmark.circle").foregroundStyle(event.failed ? .orange : .green); VStack(alignment: .leading, spacing: 5) { Text(event.title); Text(event.detail).font(.caption).foregroundStyle(.secondary).textSelection(.enabled) }; Spacer() }.padding(16); Divider() }
        }.background(.background, in: RoundedRectangle(cornerRadius: 14))
    }
    var settings: some View {
        VStack(alignment: .leading, spacing: 18) {
            GroupBox("Подключения") { VStack(alignment: .leading, spacing: 16) {
                Toggle("Открывать папку в Finder после подключения", isOn: $store.openFinder).onChange(of: store.openFinder) { _, v in UserDefaults.standard.set(v, forKey: "openFinder") }
                Toggle("Подключать избранные при запуске", isOn: $store.autoConnect).onChange(of: store.autoConnect) { _, v in UserDefaults.standard.set(v, forKey: "autoConnect") }
                Text("SSHFS автоматически восстанавливает соединение. SSH-ключи с паролем должны быть разблокированы через SSH Agent. Подтверждение нового сервера выполняется в Терминале через ssh.").font(.caption).foregroundStyle(.secondary)
            }.padding(12).frame(maxWidth: .infinity, alignment: .leading) }
            GroupBox("Конфигурация") { VStack(alignment: .leading, spacing: 12) { Text(store.configURL.path).font(.caption).textSelection(.enabled); HStack { Button("Импортировать JSON…") { store.importFile() }; Button("Резервная копия…") { store.exportFile() }; Button("Открыть папку") { NSWorkspace.shared.open(store.directory) } } }.padding(12).frame(maxWidth: .infinity, alignment: .leading) }
            GroupBox("Система") { VStack(alignment: .leading, spacing: 12) { Button("Проверить окружение") { Task { await store.diagnostics() } }; Button("Включить запуск при входе в macOS") { do { try SMAppService.mainApp.register(); store.notice = "Запуск при входе включён. Проверьте разрешение в Системных настройках → Основные → Объекты входа." } catch { store.error = error.localizedDescription } }; Button("Отключить запуск при входе") { Task { do { try await SMAppService.mainApp.unregister(); store.notice = "Запуск при входе отключён." } catch { store.error = error.localizedDescription } } }; Text("При закрытии окна приложение остаётся в строке меню. Выход из приложения не отключает диски.").font(.caption).foregroundStyle(.secondary) }.padding(12).frame(maxWidth: .infinity, alignment: .leading) }
        }.toggleStyle(.switch)
    }
}
struct ProjectEditor: View {
    @EnvironmentObject var store: Store
    @Environment(\.dismiss) private var dismiss
    @State var project: Project
    @State private var error: String?
    @State private var testing = false
    @State private var testResult: String?
    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            Text(project.name.isEmpty ? "Новый проект" : "Изменить проект").font(.title2.bold())
            Text("Подключите удалённую папку как обычный диск на Mac.").foregroundStyle(.secondary)
            Form {
                TextField("Название", text: $project.name)
                TextField("Пользователь", text: $project.username)
                TextField("Сервер", text: $project.host)
                TextField("Порт", value: $project.port, format: .number.grouping(.never))
                TextField("Удалённая папка", text: $project.path)
                TextField("Имя диска в /Volumes", text: $project.mountPoint)
                TextField("SSH-ключ (пусто = Agent)", text: $project.rsa)
                Picker("Пространство", selection: $project.group) { ForEach(["Работа", "Личное", "Архив"], id: \.self) { Text($0) } }
                Toggle("Избранный проект", isOn: $project.favorite)
            }.textFieldStyle(.roundedBorder)
            if let error { Text(error).font(.caption).foregroundStyle(.red).textSelection(.enabled) }
            if let testResult { Text(testResult).font(.caption).foregroundStyle(.green) }
            HStack { Button("Проверить соединение") { testing = true; error = nil; testResult = nil; Task { do { testResult = try await MountService.test(project) } catch { self.error = error.localizedDescription }; testing = false } }.disabled(testing); if testing { ProgressView().controlSize(.small) }; Spacer(); Button("Отмена") { dismiss() }.keyboardShortcut(.cancelAction); Button("Сохранить") { do { try store.save(project); dismiss() } catch { self.error = error.localizedDescription } }.keyboardShortcut(.defaultAction).buttonStyle(.borderedProminent).tint(ink).disabled(testing) }
        }.padding(28).frame(width: 570)
    }
}
