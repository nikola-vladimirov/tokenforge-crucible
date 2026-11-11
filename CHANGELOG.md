# TokenForge Crucible changelog

All the changes to TokenForge Crucible, newest first.

## 0.3.6

- Automatic.css import: if you edited the imported custom SCSS block in Global SCSS, a new import leaves your version as it is instead of adding a fresh copy below it (the copy used to win and undo your edit). An unedited block is still refreshed, and code typed after it still moves above it.

## 0.3.5

- The Crucible icon shows next to updates in Dashboard > Updates and on the Plugins screen.

## 0.3.4

- Updates from GitHub: new releases show in Dashboard > Updates and on the Plugins screen like any other plugin update, auto-updates included. Install 0.3.4 by hand once; later versions arrive by themselves.
- Global SCSS now shows and works in the TokenForge window on the site's pages and in the builder's TokenForge window, not only on the wp-admin dashboard. The section runs inside the window's isolated area (its own styles, switches and editor), and only for users who can open the window.

## 0.3.3

- The Global SCSS entry in the TokenForge dashboard has the Crucible icon.

## 0.3.2

- The builder Global SCSS dock shows the outline bar (Jump to, # Section, ## Sub-section) correctly in the Bricks builder.
- The compile error and save status now show in the bottom dock on their own row.
- The status stays visible when the dock is minimised.
- The plugin zip now leaves out .gitignore and .gitattributes, so the release passes Plugin Check's hidden files check.

## 0.3.1

- Fixes the variables and classes list in the builder SCSS dock, which could stay empty.

## 0.3.0

- Global SCSS and your recipes are part of Export / Import and of the Reset to defaults panel, in the 0.53.7 file format.
- Breakpoints are exported and imported with Global SCSS, as 0.53.7 did.
- Requires TokenForge 0.54.2 or later (new hooks for Export / Import and the Starter reset).
- readme.txt added; the text domain loader removed (WordPress loads it).

## 0.2.0

Restores the features the WordPress.org build of TokenForge removed, on TokenForge 0.54.1 and later:

- Global SCSS is a section of the TokenForge dashboard, with on/off switch, CSS output, draft recovery and a sync panel.
- The SCSS dock in the Bricks builder, opened with Alt+Shift+S.
- Your recipes, added to TokenForge's recipe list. Custom recipes can replace built-in ones by name.
- Two-way sync between Global SCSS and builder classes, with switches for pull and create.
- Automatic.css custom SCSS is added to Global SCSS when you import Automatic.css settings.
- The compile-scss AI ability, when AI abilities are on.
- Old settings and recipes are migrated once on update.

Requires TokenForge 0.54.1 or later.

## 0.1.0

First internal build. Global SCSS editor and custom recipes, restored from the GitHub build of TokenForge.
