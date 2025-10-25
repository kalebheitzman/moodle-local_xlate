# Developer Reference – local_xlate# Developer Reference - local_xlate



## 1. Overview## Overview

local_xlate delivers client-side translations for Moodle 5+. The plugin injects

an inline bootloader, serves versioned JSON bundles, and runs an AMD module thatThis Moodle 5+ plugin provides client-side translations similar to LocalizeJS. It injects translation bundles into pages and translates DOM elements marked with `data-xlate` attributes in real-time.

translates (and optionally captures) DOM text. No theme overrides or core

patches are required.## Database Schema



**End-to-end flow**### `local_xlate_key` - Translation Keys

``````sql

local_xlate_key / local_xlate_trid          INT(10)     Primary key

  ↓component   CHAR(100)   Component namespace (e.g., 'core', 'mod_forum')

local_xlate\local\api::get_bundle()xkey        CHAR(191)   Translation key (e.g., 'Dashboard.Title')

  ↓source      TEXT        Original/source text (optional context)

/local/xlate/bundle.php (JSON, immutable)mtime       INT(10)     Last modified timestamp

  ↓```

Browser localStorage (`xlate:<lang>:<version>`)- **Unique constraint**: `(component, xkey)` - prevents duplicate keys

  ↓- **Purpose**: Central registry of all translatable strings

AMD translator (translation + auto-capture)- **Example**: `('core', 'Dashboard.Welcome', 'Welcome to your dashboard')`

  ↓

local_xlate_save_key web service (for captured strings)### `local_xlate_tr` - Translations

``````sql

id      INT(10)     Primary key

## 2. Architecture at a Glancekeyid   INT(10)     Foreign key to local_xlate_key.id

- **Hook layer (`classes/hooks/output.php`)**lang    CHAR(30)    Language code (e.g., 'en', 'ar', 'es')

  - `before_head` drops FOUT-prevention CSS.text    TEXT        Translated text

  - `before_body` injects a bootloader that hydrates the translator.status  INT(2)      Translation status (1=active, 0=draft)

  - Hooks are skipped on admin paths so Moodle’s own language strings remain inmtime   INT(10)     Last modified timestamp

    control.```

- **Bundle endpoint (`bundle.php`)**- **Unique constraint**: `(keyid, lang)` - one translation per language per key

  - Uses `local_xlate\local\api` to produce language bundles.- **Index**: `(lang, status)` - optimizes bundle generation queries

  - Emits immutable JSON so browsers/CDNs can cache aggressively.- **Purpose**: Stores actual translations for each key/language combination

- **Client runtime (`amd/src/translator.js`)**- **Status field**: Allows draft translations that won't appear in bundles

  - Applies translations, observes DOM mutations, and captures new strings.

- **Server APIs (`classes/local/api.php`, `classes/external.php`)**### `local_xlate_bundle` - Version Control

  - Handle CRUD, cache invalidation, and expose web services to the translator```sql

    and admin UI.id       INT(10)     Primary key

lang     CHAR(30)    Language code

## 3. Server-Side Injectionversion  CHAR(40)    SHA1 hash for cache busting

`classes/hooks/output.php` registers two hooks via `db/hooks.php`:mtime    INT(10)     Last updated timestamp

```

| Hook | Purpose | Details |- **Unique constraint**: `(lang)` - one version per language

|------|---------|---------|- **Purpose**: Cache invalidation and bundle versioning

| `before_standard_head_html_generation` | Adds `<style>html.xlate-loading body{visibility:hidden}</style>` | Prevents flash-of-untranslated-text while bundles load. |- **Version generation**: `sha1($lang . max($translation_mtime))`

| `before_standard_top_of_body_html_generation` | Injects the bootloader script | Passes `lang`, `siteLang`, `version`, `autodetect`, and a bundle URL to the AMD translator. |

## Plugin Flow

The bootloader waits for `require` and `M.cfg`, then calls:

```javascript### 1. Page Load Initialization

translator.init({```

  lang:        current_language(),User requests page → Moodle renders → Hooks trigger → Scripts injected

  siteLang:    get_config('core', 'lang'),```

  version:     \local_xlate\local\api::get_version($lang),

  autodetect:  get_config('local_xlate', 'autodetect'),**Head Hook** (`classes/hooks/output.php::before_head`):

  bundleurl:   '/local/xlate/bundle.php?...'```html

});<style>html.xlate-loading body{visibility:hidden}</style>

``````

If RequireJS is not yet ready, it retries after 100 ms.- Prevents Flash of Untranslated Text (FOUT)

- Hides page content until translations are applied

## 4. Client Runtime (`amd/src/translator.js`)

**Bundle hydration****Body Hook** (`classes/hooks/output.php::before_body`):

- Uses `localStorage['xlate:<lang>:<version>']` for immediate translations.- Injects inline bootloader script

- Fetches the latest bundle with `credentials: 'same-origin'`, falling back to- Checks localStorage for cached bundle

  anonymous fetch on error.- Fetches fresh bundle from server

- Supports both `{key: value}` and `{translations, sourceMap}` payloads.- Loads AMD translator module



**Translation**### 2. Bundle Generation (`bundle.php`)

- `translateNode()` applies `data-xlate` attributes and attribute-specific keys```

  (`placeholder`, `title`, `alt`, `aria-label`).Request → Security check → Get cached bundle → Return JSON

- Maintains `window.__XLATE__ = { lang, siteLang, map, sourceMap }` for```

  debugging and downstream scripts.- **URL**: `/local/xlate/bundle.php?lang=en&v=abc123`

- **Cache**: Moodle application cache (1-hour TTL)

**Automatic detection**- **Headers**: `Cache-Control: public, max-age=31536000, immutable`

- Guarded by `shouldIgnoreElement()` to skip scripts, admin UI, navigation, and- **Query**: Joins `local_xlate_key` + `local_xlate_tr` where `status=1`

  any subtree under `data-xlate-ignore`.- **Output**: `{"Dashboard.Title": "Dashboard", "Menu.Home": "Home"}`

- `autoDetectElement()` exits immediately unless the current page language

  equals the site default (prevents capturing translated text).### Building AMD Modules

- Generates stable keys from DOM context and a normalized text hash.

- Captures text content, `placeholder`, `title`, and `alt` attributes.After making changes to `amd/src/translator.js`, rebuild with:

- Submits new strings through `core/ajax` → `local_xlate_save_key`.

```bash

**Dynamic content support**cd /path/to/moodle/local/xlate

- Depth-first `walk()` translates/captures current nodes.grunt amd

- MutationObserver processes newly added nodes and their descendants.```

- Timers at 1 s and 3 s re-run detection for late-loading widgets.

- Interaction listeners (`focus`, `click`, `scroll`) throttle reprocessing for**Note**: If you encounter permission issues with `.eslintignore`, use:

  UI that appears after user input.

```bash

**Auto-detect toggle**grunt amd --force

- `translator.setAutoDetect(enabled)` exposes runtime control; wire it from the```

  bootloader if you need per-page overrides.

**File Ownership**: Ensure the plugin directory maintains proper ownership for git compatibility:

## 5. Automatic String Capture Workflow- Plugin files should be owned by the git user (e.g., `ubuntu:ubuntu`)

- The AMD module is the **only** system that writes to `local_xlate_key` and- Avoid running grunt as `www-data` to prevent ownership conflicts

  `local_xlate_tr`.

- Preconditions:## Anti-FOUT (Flash of Untranslated Text) Strategy

  1. Auto-detection is enabled (setting or manual call to `setAutoDetect(true)`).

  2. `currentLang === siteLang` (site default language).### Problem

  3. The user has `local/xlate:manage` (required by the web service).Without prevention, users would see:

- When triggered, `autoDetectString()`1. Page loads with original text

  1. Creates a unique identifier to avoid duplicate submissions.2. Translations load asynchronously  

  2. Calls `local_xlate_save_key` via `core/ajax`.3. Text "flickers" as it gets replaced

  3. On success, sets the `data-xlate` attribute so future loads reuse the key.

- Server-side, `local_xlate\local\api::save_key_with_translation()` stores the### Solution - Multi-Layer Approach

  key, saves the translation, invalidates caches, and updates the per-language

  bundle version.**Layer 1: CSS Hide**

```css

## 6. Bundle Generation & Cachinghtml.xlate-loading body { visibility: hidden; }

`classes/local/api.php````

- Applied immediately in `<head>`

| Method | Summary |- Hides entire page until translations ready

|--------|---------|

| `get_bundle($lang)` | Returns `{ xkey => text }`, cached (application cache) for 1 hour. |**Layer 2: Async Loading**

| `get_page_bundle(...)` | Context-aware variant; component filtering scaffolding exists but is currently disabled while debugging. |```javascript

| `get_version($lang)` | Reads `local_xlate_bundle.version` (`'dev'` fallback). |// Check localStorage first (instant)

| `generate_version_hash($lang)` | `sha1("<lang>:<max mtime>")`. |var cached = localStorage.getItem('xlate:en:v123');

| `update_bundle_version($lang)` | Stores the new hash and `mtime`. |if (cached) {

| `invalidate_bundle_cache($lang)` | Clears the cache entry used by `get_bundle`. |    run(JSON.parse(cached));  // Show page immediately

| `save_key_with_translation(...)` | Atomic helper used by auto-capture and admin UI. |}



`bundle.php` wraps `get_bundle()` and emits JSON with// Fetch fresh bundle (background update)

`Cache-Control: public, max-age=31536000, immutable`.fetch('/local/xlate/bundle.php?lang=en&v=123')

    .then(r => r.json())

## 7. Database Schema (`db/install.xml`)    .then(bundle => {

- **`local_xlate_key`** (`id`, `component`, `xkey`, `source`, `mtime`)        localStorage.setItem('xlate:en:v123', JSON.stringify(bundle));

  - Unique `(component, xkey)` prevents duplicates.        if (!cached) run(bundle);  // Only run if no cache hit

- **`local_xlate_tr`** (`id`, `keyid`, `lang`, `text`, `status`, `mtime`)    });

  - Unique `(keyid, lang)` ensures one translation per language.```

  - Index `(lang, status)` supports bundle lookups.

- **`local_xlate_bundle`** (`id`, `lang`, `version`, `mtime`)**Layer 3: Graceful Fallback**

  - Unique `(lang)` stores the current version hash used by the bootloader.```javascript

// Always remove loading class, even on errors

## 8. Settings & Admin UItry {

`settings.php`    // ... translation logic

- `enable` – master switch that controls whether hooks run at all.} finally {

- `autodetect` – intended to toggle auto-capture (developer TODO: wire directly    document.documentElement.classList.remove('xlate-loading');

  into `translator.setAutoDetect()`).}

- `enabled_languages` – multiselect of installed languages used for reporting in```

  the admin UI.

- `component_mapping` – newline-delimited mapping hints used when generating### Performance Benefits

  default component names during auto-capture.- **First visit**: Brief loading delay, then fully translated

- Manage Translations shortcut – button linking to `/local/xlate/manage.php`.- **Return visits**: Instant display from localStorage

- **Network failure**: Page shows untranslated (better than broken)

`manage.php`

- Requires `local/xlate:manage`.## Caching Architecture

- Adds keys manually, edits translations, and provides search/filter/pagination.

- Offers quick visibility into translation coverage per enabled language.### Three-Tier Caching System



## 9. External Functions (`classes/external.php`)**Tier 1: Moodle Application Cache**

Configured in `db/services.php`:- **Location**: `classes/local/api.php::get_bundle()`

- **TTL**: 1 hour

| Function | Capability | Description |- **Purpose**: Avoid DB queries on every bundle request

|----------|------------|-------------|- **Key**: Language code (`'en'`, `'ar'`)

| `local_xlate_save_key` | `local/xlate:manage` | Saves or updates a key and translation, bumps bundle version, clears cache. |- **Invalidation**: Automatic TTL expiry

| `local_xlate_get_key` | `local/xlate:viewui` | Returns an existing key (used by tooling/UIs). |

| `local_xlate_rebuild_bundles` | `local/xlate:manage` | Rebuilds bundle versions for languages with active translations. |**Tier 2: Browser localStorage**

- **Location**: Client-side bootloader script

The AMD module calls `local_xlate_save_key`; other integrations can consume the- **TTL**: Until version changes

services through Moodle’s web service layer if required.- **Purpose**: Instant page loads on return visits

- **Key**: `'xlate:' + lang + ':' + version`

## 10. Development Workflow- **Invalidation**: Version hash mismatch

1. **Install/upgrade**: place the plugin under `local/xlate` and visit **Site

   admin → Notifications** or run `php admin/cli/upgrade.php`.**Tier 3: HTTP Cache**

2. **Build AMD assets** after editing `amd/src/*.js`:- **Location**: `bundle.php` response headers

   ```bash- **TTL**: 1 year (immutable)

   grunt amd --root=local/xlate --force- **Purpose**: CDN/proxy caching

   ```- **Invalidation**: Version parameter in URL

3. **Purge caches** for AMD and PHP changes:

   ```bash### Cache Invalidation Flow

   php admin/cli/purge_caches.php```

   ```Translation updated → Version hash changes → New bundle URL → Cache miss → Fresh fetch

4. **Versioning**: bump `version.php` and add upgrade steps in `db/upgrade.php````

   when schema or behaviour changes require it.

5. **Coding standards**: follow Moodle PHP guidelines and keep AMD modules ES5-## Security Model

   compatible (no transpilation pipeline).

### Capabilities

## 11. Debugging & Diagnostics- `local/xlate:manage` - **RISK_CONFIG** - Full CRUD access (managers only)

- View page source to confirm the anti-FOUT CSS and bootloader are present.- `local/xlate:viewui` - Read-only admin UI access

- In the browser console, inspect `window.__XLATE__` for current language data

  and the live translation map.### Access Control Patterns

- Monitor the Network panel for bundle requests and `local_xlate_save_key````php

  calls. Failures usually indicate missing capabilities or language guards.// Admin pages

- Query the database (`local_xlate_key`, `local_xlate_tr`) to ensure onlyrequire_login();

  default-language sessions create new entries.require_capability('local/xlate:viewui', context_system::instance());

- Visit `/local/xlate/bundle.php?lang=<code>` to confirm bundle output and cache

  headers.// Write operations  

- `example_html_output.html` contains a captured DOM snippet that helps duringrequire_capability('local/xlate:manage', context_system::instance());

  regression testing of auto-detection heuristics.

// Data sanitization

## 12. File Map$lang = required_param('lang', PARAM_ALPHANUMEXT);

```$text = required_param('text', PARAM_TEXT);

local/xlate/```

├── amd/

│   ├── src/translator.js### Bundle Security

│   └── build/translator.min.js- No authentication required (public content)

├── bundle.php- Language parameter validated (`PARAM_ALPHANUMEXT`)

├── classes/- Only `status=1` translations exposed

│   ├── hooks/output.php- JSON output properly escaped

│   ├── local/api.php

│   └── external.php## Development Workflows

├── db/

│   ├── access.php### Adding New Translation Keys

│   ├── caches.php

│   ├── hooks.php#### Method 1: Automatic Detection (Recommended)

│   ├── install.xmlThe plugin includes intelligent automatic string detection that captures translatable content:

│   ├── services.php

│   └── upgrade.php1. **Enable auto-detection**: Go to Site Administration → Plugins → Local plugins → Xlate settings

├── example_html_output.html2. **Configure languages**: Select which languages to enable for translation

├── index.php (placeholder admin page)3. **Browse your site**: Auto-detection runs in the background capturing text

├── lang/en/local_xlate.php4. **Manage translations**: Visit the "Manage Translations" page to edit detected keys

├── manage.php5. **Smart filtering**: System ignores admin elements and captures only user-facing content

├── settings.php

├── version.php**Auto-Detection Features**:

├── README.md- **Smart key generation**: Based on element type (Button.Save, Heading.Title, Input.Placeholder)

└── PROJECT_PROMPT.md / CONTRIBUTING.md (meta docs)- **Multi-attribute support**: Handles text content, placeholder, title, and alt attributes

```- **Component detection**: Suggests appropriate component based on context

- **Content filtering**: Automatically excludes admin text, navigation, and non-translatable content

## 13. Future Considerations- **HTML handling**: Extracts clean text from elements with simple formatting

- Wire the `autodetect` setting to `translator.setAutoDetect()` so admins can- **Real-time application**: Elements immediately get `data-xlate` attributes

  disable capture without editing JS.

- Re-enable component filtering in `api::get_page_bundle()` once context-aware**Supported Elements**:

  bundles are required.- Text content in any HTML element (with smart filtering)

- Extend the translation tables with workflow metadata (reviewer, confidence,- Form input placeholders (`data-xlate-placeholder`)

  etc.) before shipping the related admin features.- Element titles (`data-xlate-title`) 

- Image alt text (`data-xlate-alt`)

Happy hacking!- Respects `data-xlate-ignore` to skip subtrees


#### Method 2: Translation Management Interface
Use the web interface for manual key management:

1. **Access management**: Go to Site Administration → Plugins → Local plugins → Xlate → "Manage Translations" link
2. **Add new keys**: Use the form to create component.key pairs manually
3. **Edit translations**: Update translations for each enabled language
4. **Search and filter**: Find specific keys using pagination and filters
5. **Bulk operations**: Export/import translation data (future feature)

#### Method 3: Manual Database Operations
For direct database access or bulk operations:
1. Insert into `local_xlate_key`:
   ```sql
   INSERT INTO {local_xlate_key} (component, xkey, source, mtime) 
   VALUES ('core', 'Dashboard.Welcome', 'Welcome back!', 1698076800);
   ```

2. Add translations:
   ```sql
   INSERT INTO {local_xlate_tr} (keyid, lang, text, status, mtime)
   VALUES (1, 'ar', 'مرحباً بعودتك!', 1, 1698076800);
   ```

3. Update bundle version:
   ```sql
   INSERT INTO {local_xlate_bundle} (lang, version, mtime)
   VALUES ('ar', 'abc123def456', 1698076800)
   ON DUPLICATE KEY UPDATE version='abc123def456', mtime=1698076800;
   ```

4. Clear Moodle caches:
   ```bash
   php admin/cli/purge_caches.php
   ```

### Versioning & Upgrades
- Bump `$plugin->version` in `version.php` whenever database/cache structure or upgrade logic changes.
- Add/extend `db/upgrade.php` with incremental checkpoints and call `upgrade_plugin_savepoint()`.
- After bumping the version, trigger the upgrade via `php admin/cli/upgrade.php --non-interactive` on the target site.

### Testing Translation Changes
1. **View bundle directly**: `/local/xlate/bundle.php?lang=ar`
2. **Check localStorage**: Developer Tools → Application → Local Storage
3. **Debug object**: Console → `window.__XLATE__`
4. **Network tab**: Verify bundle fetch and caching headers

### Common Debugging Issues
- **FOUT still occurring**: Check if hooks are firing (view page source)
- **Translations not loading**: Verify bundle URL and JSON validity
- **Stale translations**: Clear localStorage and Moodle caches
- **Missing translations**: Check `status=1` and proper key format
- **Settings undefined variable**: Create an `admin_settingpage` and add via `$ADMIN->add()` (no direct `$settings` global)
- **Class autoload misses**: `classes/autoload.php` maps key classes; `db/hooks.php`, `classes/hooks/output.php`, and `bundle.php` also include `require_once` fallbacks when Moodle's classmap is stale
- **Nothing injecting**: Plugin may not be enabled - check `mdl_config_plugins` table
- **Settings not appearing**: Purge all caches after installing plugin

### First-Time Plugin Setup

After installing the plugin:

1. **Enable the plugin** (run on VM/server):
   ```bash
   php admin/cli/cfg.php --component=local_xlate --name=enable --set=1
   ```
   
   Or via SQL:
   ```sql
   INSERT INTO mdl_config_plugins (plugin, name, value) 
   VALUES ('local_xlate', 'enable', '1')
   ON DUPLICATE KEY UPDATE value = '1';
   ```

2. **Purge all caches**: Site Administration → Development → Purge all caches

3. **Verify settings appear**: Site Administration → Plugins → Local plugins → Xlate

-4. **Check HTML injection**: View page source, look for:
   - In `<head>`: `<style>html.xlate-loading body{visibility:hidden}</style>`
   - In `<body>`: `<script>` with bootloader code

5. **Test bundle endpoint**: Visit `/local/xlate/bundle.php?lang=en` (should return `{}`)

## File Structure Reference

### Core Files
- `bundle.php` - JSON bundle endpoint
- `manage.php` - Translation management interface with pagination and filtering
- `settings.php` - Plugin configuration
- `version.php` - Plugin metadata

### Database Definitions
- `db/install.xml` - Table schema
- `db/access.php` - Capability definitions
- `db/hooks.php` - Hook registration
- `db/caches.php` - Cache configuration

### PHP Classes
- `classes/hooks/output.php` - Page injection hooks
- `classes/local/api.php` - Bundle/version API and CRUD operations
- `classes/external.php` - External functions for AJAX operations

### Client Assets
- `amd/src/translator.js` - DOM translation engine
- `amd/src/capture.js` - Capture mode functionality for key assignment
- `lang/en/local_xlate.php` - English strings

### External Functions (AJAX API)
- `classes/external.php` - Web service functions
- `db/services.php` - External function definitions

## API Reference

### Core API Methods (`classes/local/api.php`)

#### Bundle Generation
```php
// Get translation bundle for a language
api::get_bundle(string $lang): array

// Get version hash for cache busting
api::get_version(string $lang): string

// Generate new version hash based on translation timestamps
api::generate_version_hash(string $lang): string

// Update bundle version for a language
api::update_bundle_version(string $lang): string

// Invalidate bundle cache
api::invalidate_bundle_cache(string $lang): void

// Rebuild all language bundles
api::rebuild_all_bundles(): array
```

#### CRUD Operations
```php
// Get translation key by component and xkey
api::get_key_by_component_xkey(string $component, string $xkey): object|false

// Create or update a translation key
api::create_or_update_key(string $component, string $xkey, string $source = ''): int

// Save translation for a key
api::save_translation(int $keyid, string $lang, string $text, int $status = 1): int

// Save key with translation in one atomic operation
api::save_key_with_translation(string $component, string $xkey, string $source, string $lang, string $translation): int

// Get paginated list of keys with search
api::get_keys_paginated(int $offset = 0, int $limit = 50, string $search = ''): array

// Count total keys (with optional search filter)
api::count_keys(string $search = ''): int
```

### External Functions (`classes/external.php`)

#### Available AJAX Methods
```javascript
// Save a translation key via AJAX
Ajax.call([{
    methodname: 'local_xlate_save_key',
    args: {
        component: 'core',
        key: 'Dashboard.Title', 
        source: 'Dashboard',
        lang: 'en',
        translation: 'Dashboard'
    }
}]);

// Get existing key data
Ajax.call([{
    methodname: 'local_xlate_get_key',
    args: {
        component: 'core',
        key: 'Dashboard.Title'
    }
}]);

// Rebuild all translation bundles
Ajax.call([{
    methodname: 'local_xlate_rebuild_bundles',
    args: {}
}]);
```

## Capture Mode Implementation

### Architecture Overview
The capture mode provides a visual interface for assigning translation keys to page elements without requiring technical knowledge.

### Component Breakdown

#### 1. Capture AMD Module (`amd/src/capture.js`)
```javascript
// Public API
require(['local_xlate/capture'], function(Capture) {
    Capture.enter();     // Start capture mode
    Capture.exit();      // Stop capture mode  
    Capture.toggle();    // Toggle on/off
    Capture.isActive();  // Check status
});
```

**Core Features**:
- **Visual overlay**: Semi-transparent background with instructions
- **Element highlighting**: Orange outline on hover for translatable elements
- **Smart filtering**: Skips scripts, styles, already-translated elements
- **Event handling**: Click detection with keyboard shortcuts (ESC to exit)
- **Modal integration**: Key assignment dialog with form validation

#### 2. Element Detection Logic
```javascript
// Elements are considered translatable if they:
// 1. Don't already have xlate attributes
// 2. Aren't in data-xlate-ignore subtrees  
// 3. Aren't script/style/meta tags
// 4. Have text content OR relevant attributes (placeholder, title, alt)

function isTranslatableElement(element) {
    // Implementation handles all filtering logic
}
```

#### 3. Key Generation Algorithm
```javascript
// Auto-generates suggested keys based on:
// - Element content (cleaned and formatted)
// - Element type context (Button.*, Heading.*, Input.*)
// - Attribute type (placeholder, title, alt)

function generateSuggestedKey(element) {
    // Returns keys like: "Button.Save", "Heading.Welcome", "Input.SearchPlaceholder"
}
```

#### 4. Modal Assignment Interface
- **Multi-field support**: Handles text + attributes in single dialog
- **Validation**: Requires key and component fields
- **Preview**: Shows content being translated
- **Customization**: Allows editing of suggested keys

### Integration Points

#### Admin Interface

The plugin provides two main admin interfaces:

**Settings Page**: Site Administration → Plugins → Local plugins → Xlate
- Enable/disable the plugin and auto-detection
- Select which languages to enable for translation
- Configure component mapping rules
- Access link to translation management

**Translation Management**: `/local/xlate/manage.php`
```php
// Capability-based UI rendering
if (has_capability('local/xlate:manage', $context)) {
    // Show capture mode controls
    // Include JavaScript for button handlers
    // Provide rebuild bundles functionality
}
```

#### External Function Security
```php
// All AJAX operations require proper capabilities
require_capability('local/xlate:manage', context_system::instance());

// Input validation on all parameters
$params = self::validate_parameters(self::save_key_parameters(), $args);
```

### Usage Workflow
1. **Admin Access**: Navigate to Site Administration → Plugins → Local plugins → Xlate settings
2. **Activate**: Click "Start Capture Mode" (requires manage capability)
3. **Navigation**: Visit any Moodle page - overlay appears
4. **Selection**: Hover highlights elements, click to assign keys
5. **Assignment**: Modal opens with suggested key and component
6. **Customization**: Edit key structure and component as needed
7. **Saving**: Keys stored via AJAX, element gets data attributes
8. **Automatic Updates**: Bundle versions regenerated, caches invalidated

## Future Development Areas

### Admin CRUD Interface (In Progress)
- ✅ Capture mode for easy key assignment
- 🔄 Key management (add/edit/delete translation keys)  
- 🔄 Translation management (per-language editing)
- 🔄 Bulk import/export (CSV/JSON)
- ✅ Bundle rebuild action (regenerate version hashes)

### Advanced Features
- ✅ Translation capture mode (auto-assign keys to untranslated elements)
- 🔄 Translation memory (suggest similar translations)
- 🔄 Approval workflow (draft → review → published)
- 🔄 Usage analytics (track which keys are actually used)

### Performance Optimizations
- 🔄 Bundle splitting (per-component bundles)
- 🔄 Progressive loading (critical keys first)
- 🔄 Service worker caching
- 🔄 Bundle compression (gzip/brotli)