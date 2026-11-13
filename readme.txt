=== TokenForge Crucible ===
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add-on for TokenForge: restores Global SCSS, your recipes, builder sync and the SCSS dock.

== Description ==

TokenForge Crucible adds the features the WordPress.org build of TokenForge does not ship: the Global SCSS editor, the SCSS dock in the Bricks builder and in the block editor (with TokenForge 0.57.0 or later), Automatic.css custom SCSS import, your own recipes, two-way builder class sync, and Global SCSS in Export, Import and Reset.

Requires TokenForge 0.54.2 or later.

Deleting the add-on keeps your Global SCSS, recipes and sync settings in the database, so reinstalling it (or moving to a TokenForge build that has Global SCSS) brings them back. The versions you saved by name stay too (the options tokenforge_crucible_versions and tokenforge_crucible_version_1, _2 and so on), and so does the last site report (the option tokenforge_crucible_site_report). Your SCSS files stay as well (the options tokenforge_crucible_scss_files, tokenforge_crucible_page_map, tokenforge_crucible_page_css_built and one tokenforge_crucible_page_css_ option for each page file); TokenForge Reset with Global SCSS ticked removes them. To clear the SCSS and recipes first, use TokenForge Reset with Global SCSS and Recipes ticked.

Developed by Prista Digital, https://pristadigital.com

== Changelog ==

The full history is in CHANGELOG.md.

= 0.5.0 =
* Versions: every Global SCSS or recipe save can be undone; save named versions, see what changed and restore only the parts you tick.
* Token lint: Check finds hard-coded values that have a token, with one-click fixes; "Scan the site" also reads the custom CSS of the builder.
* Site report: unused Global SCSS classes, duplicate classes and the size of each class.
* SCSS files: split the Global SCSS into files loaded with @use "name";, and page files that load only on the pages, templates or post types you choose.
* AI abilities: an AI agent can read, check and save Global SCSS classes using tokens, lint a page, run the site report and save a version.
* The SCSS dock also opens in Etch, Oxygen, Breakdance, Elementor and Divi (needs a TokenForge version with the builder hook).

= 0.4.0 =
* Block editor: the Global SCSS dock opens in the post and site editor (SCSS button or Alt+Shift+S) with live preview, the classes of your Global SCSS show in the TokenForge class field, and the Global SCSS section is in the TokenForge window there. Needs TokenForge 0.57.0 or later for the block editor; the builder works as before.

= 0.3.7 =
* Fixes from a full code and security review: builder sync no longer damages Global SCSS with "//" in url() or strings, or with class names like w-1/2; much faster saves with many classes; only administrators pull builder changes into Global SCSS; multisite needs edit_css; 100 KB save limit; the dashboard code editor loads again; safer recipe saves and save conflicts.

= 0.3.6 =
* Automatic.css import: if you edited the imported custom SCSS block, a new import keeps your version instead of adding a fresh copy that undid your edit.

= 0.3.5 =
* The Crucible icon shows next to updates in Dashboard > Updates and on the Plugins screen.

= 0.3.4 =
* Updates from GitHub: new releases show in Dashboard > Updates like any other plugin update. Install 0.3.4 by hand once; later versions arrive by themselves.
* Global SCSS now shows and works in the TokenForge window on the site's pages and in the builder's TokenForge window, not only in wp-admin.

= 0.3.3 =
* The Global SCSS entry in the TokenForge dashboard has the Crucible icon.

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
