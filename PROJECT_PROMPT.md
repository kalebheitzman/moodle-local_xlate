# local_xlate — Moodle 5+ client-side translation plugin

## Goals
- Single local plugin: DB (keys/translations/bundle versions), cache, admin UI, versioned JSON bundles, AMD translator, hooks for anti-FOUT CSS + loader.
- Target Moodle 5+. PHP 8.2–8.3. JS via AMD (RequireJS).

## Non-goals
- No server-side rewrite of final HTML.
- No theme overrides or core patches.

## Architecture
- Tables: local_xlate_key, local_xlate_tr, local_xlate_bundle.
- Caches: bundle, keymap (application).
- Output hooks: inject `<style>html.xlate-loading body{visibility:hidden}</style>`; inline bootloader; load `local_xlate/translator`.
- Bundles: `/local/xlate/bundle.php?lang=&v=hash`; localStorage key `xlate:<lang>:<version>`.
- Translator: apply `data-xlate` and `data-xlate-attr` (placeholder/title/alt/aria-label); observe mutations; remove `xlate-loading` ASAP.

## Deliverables (PR order)
1) CRUD admin UI (keys/translations) with paging/search.
2) Bundle versioning + 'Rebuild bundles' action (sha1(lang + max(mtime))).
3) Admin-only 'capture mode' to assign keys in-page.
4) Tests (PHPUnit/Behat) + docs.

## Security
- Capabilities: local/xlate:manage (write), local/xlate:viewui (read).
- Admin pages require login + capability; escape outputs; no raw $_GET/$_POST.
