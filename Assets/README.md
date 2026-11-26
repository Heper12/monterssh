# App icon

Generated with the built-in ImageGen tool. `AppIcon-source.png` is the original transparent raster. `AppIcon.icns` is derived by `scripts/build-icon.sh` using macOS `sips` and `iconutil`, covering 16–1024 px including Retina variants. The release bundle declares `CFBundleIconFile=AppIcon`; the app also loads that icon for its Dock representation on launch.

Generation prompt:

> Use case: logo-brand. Asset type: production macOS application icon for Monterssh, a lightweight SSH remote-disk mounting app. Generate one polished minimalist icon, centered square 1024x1024 composition. A large rounded-square macOS tile in desaturated midnight slate blue, with softly luminous frosted glass edges. In its center a single simple ivory / pale icy blue symbol: two interlocking rounded links forming a subtle lowercase m, suggesting a secure connection and mounted volumes. Iconic, highly readable at 32px, generous inner negative space, restrained liquid glass depth and subtle highlights, no busy reflections. Straight-on orthographic view, no perspective tilt. Transparent canvas outside the rounded-square tile; tile fills about 90 percent of width. No text, letters, numbers, captions, watermark, extra objects or surrounding scene. Sophisticated native macOS aesthetic, calm blue-grey palette, crisp clean silhouette.
