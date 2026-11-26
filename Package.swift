// swift-tools-version: 6.0
import PackageDescription
let package = Package(name: "Monterssh", platforms: [.macOS(.v14)], products: [.executable(name: "Monterssh", targets: ["Monterssh"])], targets: [.target(name: "MontersshCore"), .executableTarget(name: "Monterssh", dependencies: ["MontersshCore"]), .testTarget(name: "MontersshCoreTests", dependencies: ["MontersshCore"])])
