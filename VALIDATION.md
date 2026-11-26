# Native app validation — 2026-11-26

Environment: Apple Silicon, macOS 26, Swift 6.2.1, Command Line Tools, SSHFS 2.10 and macFUSE. Release build targets macOS 14+. Compatibility with macOS 14/15 has not been exercised on separate machines.

## Completed

- Release `.app` built and launched, ad hoc signature verified with `codesign --verify --deep --strict`.
- All four native sections opened before and after the resource changes: Projects, Connections, Activity, Settings.
- Imported all 37 local legacy projects through the native file picker. Original JSON remained separate from Application Support storage.
- Search and active-project filter displayed the existing live SSHFS disk correctly.
- Added a disposable UI project, tested empty-field validation, saved it, reopened editor, checked refused SSH connection handling, deleted the disposable entry with confirmation. Final config contains 37 projects.
- Diagnostics identified SSH, SSHFS and macFUSE. Activity survives app restarts.
- Bulk disconnect confirmation opened and cancellation preserved the existing disk.
- Live integration check used the existing server configuration with a unique temporary nested mount directory: SSH access and remote directory check, actual SSHFS mount, mount-table verification, ordinary diskutil unmount and temporary-directory cleanup. Repeated successfully after optimization. Remote files were not modified; original mount preserved.
- CoreChecks passed after optimization: legacy import (integer/string port), native atomic round-trip, key path resolution, path traversal/absolute path rejection, mount paths with spaces, non-SSHFS isolation, argument preservation, command success/failure, output cap and timeout.

## Resource measurement

Release application bundle: approximately 1.3 MiB, no bundled browser or PHP runtime.

Idle process samples (`ps -p PID -o %cpu=,rss=,time=`, six samples at two-second intervals):

- Before stream-buffer optimization: 0.0% CPU; resident 34–52 MiB; cumulative CPU increased by 0.01 s over the sample interval.
- After optimization, minimized window: 0.0% CPU; resident 25–29 MiB; cumulative CPU increased by 0.01 s over the sample interval.
- Resident memory fluctuates with macOS memory pressure and UI state; these numbers do not establish an idle-memory improvement. They demonstrate low idle CPU. Battery life itself was not measured.

Identified bottleneck: command runner retained complete diagnostic output and transient Foundation buffers. Streaming output in 16 KiB chunks with autorelease pools and a 128 KB retained limit reduced peak memory in an isolated 100 MB output stress test:

| Version | Maximum RSS, three runs (bytes) |
| --- | --- |
| Before (`830aa82`) | 109674496, 109641728, 109658112 |
| After | 7815168, 7782400, 7782400 |

Run `python3 scripts/benchmark-runner.py` to reproduce. Earlier experiment without autorelease pools did not improve peak memory; it was replaced with the measured implementation. Idle UI has no timer-driven polling; workspace events and foreground activation refresh state. Journal is capped at 200 events; lists create rows lazily. SSHFS/SSH helper processes and network keepalives consume additional resources per mounted disk and are not included in the UI-process samples.

## Limits and remaining system-specific checks

- Administrator dialog flow for a new root-owned `/Volumes` directory is implemented, but the live test used an already writable parent and did not enter an administrator password.
- Login-item registration, reboot/login startup and real sleep/network-loss recovery require interactive system testing; they were not enabled on this computer during validation.
- Force-unmount execution and bulk unmount of every existing live disk were not exercised against the user's working disk. The ordinary unmount service was tested live; force and bulk confirmations were checked in UI/code.
- JSON import/export core is checked. All 37 servers were not individually contacted.
- Build is locally signed, not notarized for distribution. Copy to Applications before enabling launch at login.

## Commands

```sh
./scripts/build-app.sh
swift run CoreChecks
# Optional live check: only against a matching already-active project;
# mount root must be an existing writable directory inside /Volumes.
swift run CoreChecks --live-check /path/to/data.json /Volumes/writable-parent
python3 scripts/benchmark-runner.py
```
