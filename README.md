# TokenForge Crucible

Add-on for TokenForge (WordPress.org build, 0.54.1 or later). It restores the Global SCSS editor, the builder SCSS dock, Automatic.css custom SCSS import, custom recipes and the two-way builder class sync that the WordPress.org build of TokenForge does not ship.

Developed by Prista Digital. Website: https://pristadigital.com

This add-on is not for WordPress.org. See CHANGELOG.md for releases.

## Updates and releases

Sites update from the latest GitHub release of this repository (`includes/updater.php`, the `Update URI` header): it shows in Dashboard > Updates like any other plugin. To release, bump the version in the plugin header, `TOKENFORGE_CRUCIBLE_VERSION` and the readme Stable tag, add a `## X.Y.Z` section to CHANGELOG.md, and push to `main`: `.github/workflows/release.yml` checks the three versions match and publishes `vX.Y.Z` with the plugin zip and the changelog section as notes.
