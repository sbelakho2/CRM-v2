## Root cause

A recent minify/format pass broke the sidebar in `templates/base.html.twig` (and one line in `alert_modal.html.twig`). Two distinct defects, both introduced by the a11y pass that converted `<div onclick>` → `<button>`:

**Defect 1 — mismatched closing tags (7 sites).** All 7 sidebar group headers open `<button ...>` but close with `</div>` (a leftover from when they were `<div>`). The HTML5 parser's error-recovery kicks the nested `<ul class="rams-sidebar__nav-list--nested">` out of its parent `<li>`, which (a) breaks the visual layout and (b) orphans the nested lists from the `is-open` class toggle — so collapsed groups can't show their links and items become non-responsive.

Lines (open → wrong close): 203→207, 277→281, 368→372, 437→441, 497→501, 524→528, 568→572.

**Defect 2 — JS collapsed into a comment (line 703).** The entire sidebar-toggle handler got merged onto a single line that begins with `//`:
```js
// Sidebar group toggles: keyboard-accessible (native buttons), // keep aria-expanded in sync... document.querySelectorAll('[data-sidebar-group-toggle]').forEach(...) ... const body = document.body;
```
Everything after the first `//` is a comment — so `addEventListener('click', ...)` never runs (clicks do nothing) AND `const body = document.body` is never declared (the mobile-toggle code at line 709 `body.classList.remove(...)` would throw `ReferenceError` when invoked).

**Defect 3 — same JS-comment-collapse in `templates/components/alert_modal.html.twig:170`:**
```js
// Remember the element that opened the modal for focus restore modal._previousFocus = document.activeElement; // Show modal
```
`modal._previousFocus` is never assigned → alert-modal focus-restore is broken (the modal still opens, but focus isn't returned to the trigger on close).

A focused Explore sweep confirmed this is the **complete** extent of the damage: no other tag mismatches, no other collapsed-JS lines, mobile toggle and confirm_modal otherwise intact.

## The fix (9 surgical edits, 2 files)

**`templates/base.html.twig`:**
1–7. Change `</div>` → `</button>` on the 7 group-header close tags (lines 207, 281, 372, 441, 501, 528, 572). Each is uniquely identifiable by the preceding `<span>{{ 'nav.group.X'|trans... }}</span>` line, so each edit is unambiguous.
8. Replace the single commented line 703 with the properly formatted, executable version:
```js
// Sidebar group toggles: keyboard-accessible (native buttons).
// Keep aria-expanded in sync with the .is-open class.
document.querySelectorAll('[data-sidebar-group-toggle]').forEach(function(toggle) {
    const sync = function() {
        toggle.setAttribute('aria-expanded', toggle.parentElement.classList.contains('is-open') ? 'true' : 'false');
    };
    sync();
    toggle.addEventListener('click', function() {
        toggle.parentElement.classList.toggle('is-open');
        sync();
    });
});
const body = document.body;
```
This restores the click handler AND re-declares `const body` (which the mobile-toggle code at line 709 depends on). No duplicate-declaration risk — line 704 declares `toggleButton`, not `body`.

**`templates/components/alert_modal.html.twig`:**
9. Split line 170 into the comment + the executable statement + the next comment:
```js
// Remember the element that opened the modal for focus restore
modal._previousFocus = document.activeElement;
// Show modal
```

## Verification after the fix
- `php bin/console lint:twig templates/base.html.twig templates/components/alert_modal.html.twig` — confirms Twig syntax.
- `grep -co '<button' templates/base.html.twig` and `grep -co '</button>'` should now both be 8.
- Manual: load any page, confirm the sidebar groups collapse/expand on click and nested links are clickable; open an alert modal (e.g. a confirm dialog), tab away and back, confirm focus returns to the trigger.

## Non-goals / out of scope
- No CSS changes (the inline `.rams-sidebar__group-header` rule already includes `background: none; border: none; width: 100%; text-align: start; font-family: inherit;` to reset button defaults — it's correct, the bug is purely the tag mismatch + dead JS).
- No changes to the mobile toggle, confirm_modal, or any other template — the sweep confirmed they're intact.
- Not touching the larger residual items from the prior audit (DB password in docs, SSRF pinning, etc.) — those are separate; this is just the sidebar regression.