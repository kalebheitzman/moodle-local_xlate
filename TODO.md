# TODO — local_xlate

Backlog derived from the full code review (2026-07-06, Claude Fable 5; findings C1–C7 critical, H1–H12 high) and the translation-quality audit (2026-07-11, T1–T8). Full review with rationale: `Xlate Translation Management Plugin.md` in the TCM Obsidian vault (Moodle folder). Revert point for all 2026-07-11 work: commit `470a0b6f` (pre-fixes).

**Working rules (for AI sessions):** work ONE item at a time, quoting the finding. Before accepting a diff, run the ripple check ("per CLAUDE.md §17, what else must change?"). JS changes require `grunt amd --root=local/xlate --force` + purge caches. Check items off here and update CLAUDE.md when behavior changes.

## Session strategy (agreed 2026-07-11)

**Before the first Sonnet session — deploy & verify the 2026-07-11 batch** (it has not run on a live site yet):
1. `php -l` on: `classes/local/api.php`, `bundle.php`, `classes/mlang_migration.php`, `classes/observer.php`, `classes/task/mlang_course_cleanup_task.php`, `classes/task/mlang_cleanup_task.php`, `classes/translation/backend.php`, `db/upgrade.php`, `db/events.php`
2. `php admin/cli/upgrade.php` (registers observers, version 2026071100) + `php admin/cli/purge_caches.php`
3. Smoke test: restore a course with mlang tags → adhoc `mlang_course_cleanup_task` appears (`cli/list_adhoc.php`) → run it → harvested reviewed rows with source `mlang` in `local_xlate_tr`
4. Smoke test: one autotranslate batch completes end-to-end

**Sonnet order (one item per session):** T4 (surface discarded quality warnings — biggest quality win) → T6 (context field) → C4 (`canCapture` flag — biggest performance win) → C2 (privacy provider) → then remaining P1/P2 in listed order. Do the hash-parity test fixture (Safety nets section) EARLY — it's the guard rail against Sonnet silently breaking the JS/PHP hash sync.

**Reserved for Fable (or a Sonnet session given an explicit design brief first):** H4 (server-side sanitization architecture) and H8/H9 (backend/API rework, structured outputs, Anthropic transport decision). These involve design judgment, not just implementation; don't hand them to Sonnet as bare checklist items.

## P0 — safety

- [x] **C3** Reviewed-translation guard inside `api::save_translation()` — refuses `SOURCE_AUTOTRANSLATE` over `reviewed=1` unless `$force`. *(done, verified 2026-07-11)*
- [x] **C1a** Span-reordering bug in `strip_mlang_tags()` — in-place replacement via `preg_replace_callback`. *(done 2026-07-11, 12 test fixtures pass)*
- [x] **C1b** Attribute-order-tolerant multilang span matching (`SPAN_PATTERN` + `parse_multilang_span()`). *(done 2026-07-11)*
- [x] **C1c** Event-driven course-scoped cleanup: `observer.php` + `db/events.php` + `mlang_course_cleanup_task` (adhoc, deduped); course filter pushed into SQL; portable chunking. *(done 2026-07-11)*
- [x] **C1d** Harvest mlang-embedded translations into `local_xlate_tr` as reviewed rows (`harvest_translations()`, `SOURCE_MLANG`). *(done 2026-07-11)*
- [ ] **C1e** Explicit content-table allow-list for the site-wide scheduled scan (autodiscovery is still the default there). Consider relaxing the `*/5` schedule to hourly now that imports are event-driven — decision pending.
- [ ] **C6** Inline autotranslate blocks PHP worker up to ~10 min: clamp timeout to ~30s in web context, remove sleep-retry and inline repair call (`external::autotranslate_key()`, `backend.php`).
- [ ] **C4** Pass `canCapture` (server-side `has_capability('local/xlate:manage')`) from `output.php` into the JS config; gate `isCapture` on it in `translator.js`. Biggest silent performance drain. **JS change — rebuild AMD.**

## P1 — correctness & compliance

- [ ] **C2** Privacy API provider: `classes/privacy/provider.php` (metadata incl. OpenAI endpoint as external location, export, delete) + retention/purge tasks for `local_xlate_activity` and `local_xlate_mlang_migration.old_value`. Well-documented boilerplate — good subagent task.
- [x] **C5** Thread context/pagetype/courseid through `bundle.php` → `get_keys_bundle_with_associations()`; scoped `sourceMap`; system-context policy = global keys + requester's enrolled courses (managers unrestricted). *(done 2026-07-11)*
- [x] **C7** Monotonic bundle versioning — chained hash `sha1(lang:prev:now)` in `update_bundle_version()`; `generate_version_hash()` deprecated. *(done 2026-07-11)*
- [ ] **H1** `associate_keys_returns()` shape mismatch: implementation returns `xkey => status` map, declaration says list of `{key, status}`. Return `[['key' => k, 'status' => v], ...]`.
- [ ] **H2** `isTranslatableText()` requires ≥30% `[a-zA-Z]` — non-Latin source languages capture nothing. Use Unicode letter check. **JS change — rebuild AMD.**
- [ ] **H3** Excluded paths break on subdirectory installs: strip `parse_url($CFG->wwwroot, PHP_URL_PATH)` prefix in `output.php::should_skip_page()` AND `translator.js` `shouldIgnoreElement()` adminPaths. **JS change — rebuild AMD.**

## P2 — hardening

- [ ] **H4** Server-side HTML sanitization on write (mirror JS `ALLOWED_INLINE_TAGS` whitelist); remove `html_entity_decode` on store in `api::normalize_inline_markup()`. **Design-sensitive — needs a careful brief; touches capture, manage UI, and mlang harvesting.**
- [ ] **H6** Store language codes (not display labels/select indexes) in course custom fields + one-time migration (`customfield_helper.php`).
- [ ] **H10** Course-scoped saves: extend `save_translation`/`delete_translation`/`save_key` WS capability checks to course scope with key-in-course validation, or hide manage UI from `managecourse`-only users.
- [ ] **H5** Scope `set_key_critical_by_xkey()` to keys associated with the authorizing course.
- [ ] **H7** `translate_course_task` cursor advances past failed batches — only advance for saved/legitimately-skipped items; requeue failures.
- [ ] **H11** Sweep stale `xlate:*` localStorage entries (non-current versions) at init. **JS change — rebuild AMD.**
- [ ] Customfield config caching (request-static + MUC) for `is_course_enabled`/`resolve_languages` (perf item #2 in review §6).
- [ ] **H8/H9** Modern `tools`/structured-outputs API using `spec/translate_batch_response_schema.json`; measured `elapsed_ms`; explicit provider admin setting (kill URL-sniffing); decide whether to implement Anthropic Messages API transport. **Design-sensitive — architecture brief first.**
- [ ] `bundle.php` catch-all returns HTTP 200 empty bundle — return 500 + log (review §5).

## Translation quality (from 2026-07-11 Fable audit — review §10)

- [x] **T1** Temperature default 0.2 in `build_payload()` (was provider default ~1.0). *(done 2026-07-11)*
- [x] **T2** Fail cleanly on `finish_reason === 'length'` instead of brace-extracting truncated JSON. *(done 2026-07-11)*
- [x] **T3** Unicode-aware glossary boundary matching (`\p{L}\p{N}` lookarounds — PCRE `\b` never matched Cyrillic/Arabic terms). *(done 2026-07-11)*
- [ ] **T4** Persist + surface quality signals: `postprocess_item()` warnings (`placeholder_missing`, `glossary_not_applied`) and model confidence are currently computed then DISCARDED by `translate_course_task`. Store them (activity log or small column) and add a "needs attention" filter + confidence sort to manage.php review queue. **Highest-value open quality item.**
- [ ] **T5** Extract placeholders (`{$a}`, `{$a->x}`, `%s`, `%d`) from source when building batch items so placeholder validation actually runs on the main path (it's dead code today — tasks never populate `placeholders`).
- [ ] **T6** Populate the `context` field on batch items (course fullname + component/activity type). Short ambiguous UI strings are where LLM translation fails; context is cheap tokens for a big win.
- [ ] **T7** Output sanity checks before persisting: `translated === source` on non-trivial strings, empty-after-trim, wrong-script detection for the target language. Flag (warning), don't block.
- [ ] **T8** Store model confidence per translation; use to sort the review queue.

## P3 — UGC/forum pipeline

- [ ] Design per review §7: separate `local_xlate_ugc_tr` storage keyed by `(component, itemtype, itemid, contenthash, lang)` with author userid; event-bound lifecycle; in-context serving only; on-demand translation; PII scrub pre-pass. **Do not route forum content through the generic pipeline.**

## Safety nets (review §9.4)

- [ ] Hash-parity test fixture (JSON input → expected xkey) consumed by PHPUnit + a JS test; PHPUnit for `api.php` (version monotonicity, reviewed guard) and `mlang_migration` (round-trip fixtures with inline spans).
- [ ] GitHub Actions CI with moodle-plugin-ci (phpcs, phpunit, grunt, validator).
- [ ] One Behat smoke scenario: capture as admin → translated as student.
