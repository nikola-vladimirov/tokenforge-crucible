# TokenForge Crucible changelog

All the changes to TokenForge Crucible, newest first.

## 0.4.0

Crucible in the WordPress block editor (needs TokenForge 0.57.0 or later; nothing changes with an older TokenForge or in the builder).

- The Global SCSS dock opens in the block editor (post editor and site editor) for administrators, when Global SCSS is switched on: an SCSS button in the editor header beside the TokenForge button, or Alt+Shift+S. The dock sits at the bottom of the editing area and leaves the sidebars free.
- Live preview: what you type shows in the editor canvas right away; nothing is saved until you press Save. Switching Live off shows the saved CSS again.
- The classes of your Global SCSS are offered by the TokenForge class field in the block sidebar, each once and with a short preview. Classes you are typing in the dock are offered right away, before you save.
- The target button in the dock jumps to the SCSS of a class on the selected block, or starts a block for it.
- The Global SCSS section is in the TokenForge window when you open that window in the block editor.
- The dock follows the dark editor look when TokenForge's dark editor interface is on.
- The builder class sync does not run in the block editor.

## 0.3.7

Fixes from a full code and security review.

- Builder sync no longer damages your Global SCSS on Save when it contains `//` inside url() or a quoted string (for example `url(//cdn.example.com/a.png)` or `content: "a//b"`). Only real comments are treated as comments.
- A builder class whose name can't be an SCSS class (for example `w-1/2`, `md:flex` or one in another alphabet) no longer copies the whole Global SCSS into itself when you save in the builder.
- Saving with many synced classes is much faster: the SCSS is read once per save instead of again for every class. The live preview checks only the classes it sent.
- In the builder, a class you change in the Style panel while the SCSS dock is still waiting for a reply keeps your change.
- Builder changes are pulled into the Global SCSS only for users who can edit it (administrators). CSS panel code with Sass logic (loops, mixins, variables) is no longer copied into the Global SCSS.
- A value you set back on purpose in the builder after a sync is no longer undone every time.
- A state block that holds only a comment, like `&:hover { /* todo */ }`, is kept.
- Quoted `}` or `;` in a class's CSS (for example `content: "}"`) no longer breaks the rules after it.
- Multisite: saving Global SCSS needs the edit_css right as well, as TokenForge does.
- Global SCSS over 100 KB is refused on Save with a message, instead of being saved and then lost on the next import or restore.
- Automatic.css import: code you type after the imported block now stays after it, so your overrides still win.
- Dashboard: the SCSS editor uses the WordPress code editor (line numbers, highlighting) again. A save conflict now shows the newer SCSS and keeps your text as a draft you can restore. Recipes can't be saved before they have loaded, and a failed save says so. Unsaved drafts are kept per site.
- Updates are only offered from this plugin's own GitHub releases.
- Two fewer database queries on every page view.

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
