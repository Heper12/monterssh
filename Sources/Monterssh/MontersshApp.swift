import SwiftUI
import AppKit

@main struct MontersshApp: App {
    @StateObject private var store = Store()
    var body: some Scene {
        Window("Monterssh", id: "main") {
            ContentView().environmentObject(store).frame(minWidth: 940, minHeight: 640).task { await store.start() }
        }.defaultSize(width: 1180, height: 780)
        .commands { CommandGroup(after: .newItem) { Button("Импортировать проекты…") { store.importFile() }.keyboardShortcut("i", modifiers: [.command, .shift]) } }
        MenuBarExtra("Monterssh", systemImage: "externaldrive.badge.wifi") { MenuContent().environmentObject(store) }
    }
}
struct MenuContent: View {
    @EnvironmentObject var store: Store
    @Environment(\.openWindow) var openWindow
    var body: some View {
        Button("Открыть Monterssh") { openWindow(id: "main"); NSApp.activate(ignoringOtherApps: true) }
        Divider()
        Text("Активных дисков: \(store.active.count)")
        ForEach(store.projects.filter(\.favorite)) { p in Button((store.connected(p) ? "● " : "○ ") + p.name) { Task { if store.connected(p) { await store.disconnect(p.mountURL.path, project: p) } else { await store.connect(p) } } }.disabled(store.working || store.busy.contains(p.id)) }
        Button("Обновить статусы") { Task { await store.refresh() } }
        Button("Отключить все…") { openWindow(id: "main"); NSApp.activate(ignoringOtherApps: true); store.page = "Подключения" }
        Divider()
        Button("Завершить Monterssh") { NSApp.terminate(nil) }.keyboardShortcut("q")
    }
}
