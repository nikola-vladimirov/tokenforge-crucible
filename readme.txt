=== TokenForge Crucible ===
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add-on for TokenForge: restores Global SCSS, your recipes, builder sync and the SCSS dock.

== Description ==

TokenForge Crucible adds the features the WordPress.org build of TokenForge does not ship: the Global SCSS editor, the SCSS dock in the Bricks builder, Automatic.css custom SCSS import, your own recipes, two-way builder class sync, and Global SCSS in Export, Import and Reset.

Requires TokenForge 0.54.2 or later.

Developed by Prista Digital, https://pristadigital.com

== Changelog ==

The full history is in CHANGELOG.md.

= 0.3.2 =
* The builder Global SCSS dock shows the outline bar correctly in the Bricks builder, and the compile error and save status show on their own row, also when the dock is minimised.
* The plugin zip leaves out .gitignore and .gitattributes, so the release passes Plugin Check's hidden files check.

= 0.3.1 =
* Fixes the variables and classes list in the builder SCSS dock, which could stay empty.

= 0.3.0 =
* Global SCSS and your recipes are part of Export / Import and of the Reset to defaults panel, in the 0.53.7 file format.
* Breakpoints (bp_*) are exported and imported with Global SCSS, as 0.53.7 did.
* Requires TokenForge 0.54.2.

= 0.2.0 =
* First public release: Global SCSS, the SCSS dock, recipes, builder sync and Automatic.css import, on TokenForge 0.54.1.
