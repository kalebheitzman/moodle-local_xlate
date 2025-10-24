# Developer Reference - local_xlate

## Overview

This Moodle 5+ plugin provides client-side translations similar to LocalizeJS. It injects translation bundles into pages and translates DOM elements marked with `data-xlate` attributes in real-time.

## Database Schema

### `local_xlate_key` - Translation Keys
```sql
id          INT(10)     Primary key
component   CHAR(100)   Component namespace (e.g., 'core', 'mod_forum')
xkey        CHAR(191)   Translation key (e.g., 'Dashboard.Title')
source      TEXT        Original/source text (optional context)
mtime       INT(10)     Last modified timestamp
```
- **Unique constraint**: `(component, xkey)` - prevents duplicate keys
- **Purpose**: Central registry of all translatable strings
- **Example**: `('core', 'Dashboard.Welcome', 'Welcome to your dashboard')`

### `local_xlate_tr` - Translations
```sql
id      INT(10)     Primary key
keyid   INT(10)     Foreign key to local_xlate_key.id
lang    CHAR(30)    Language code (e.g., 'en', 'ar', 'es')
text    TEXT        Translated text
status  INT(2)      Translation status (1=active, 0=draft)
mtime   INT(10)     Last modified timestamp
```
- **Unique constraint**: `(keyid, lang)` - one translation per language per key
- **Index**: `(lang, status)` - optimizes bundle generation queries
- **Purpose**: Stores actual translations for each key/language combination
- **Status field**: Allows draft translations that won't appear in bundles

### `local_xlate_bundle` - Version Control
```sql
id       INT(10)     Primary key
lang     CHAR(30)    Language code
version  CHAR(40)    SHA1 hash for cache busting
mtime    INT(10)     Last updated timestamp
```
- **Unique constraint**: `(lang)` - one version per language
- **Purpose**: Cache invalidation and bundle versioning
- **Version generation**: `sha1($lang . max($translation_mtime))`

## Plugin Flow

### 1. Page Load Initialization
```
User requests page → Moodle renders → Hooks trigger → Scripts injected
```

**Head Hook** (`classes/hooks/output.php::before_head`):
```html
<style>html.xlate-loading body{visibility:hidden}</style>
```
- Prevents Flash of Untranslated Text (FOUT)
- Hides page content until translations are applied

**Body Hook** (`classes/hooks/output.php::before_body`):
- Injects inline bootloader script
- Checks localStorage for cached bundle
- Fetches fresh bundle from server
- Loads AMD translator module

### 2. Bundle Generation (`bundle.php`)
```
Request → Security check → Get cached bundle → Return JSON
```
- **URL**: `/local/xlate/bundle.php?lang=en&v=abc123`
- **Cache**: Moodle application cache (1-hour TTL)
- **Headers**: `Cache-Control: public, max-age=31536000, immutable`
- **Query**: Joins `local_xlate_key` + `local_xlate_tr` where `status=1`
- **Output**: `{"Dashboard.Title": "Dashboard", "Menu.Home": "Home"}`

### Building AMD Modules

After making changes to `amd/src/translator.js`, rebuild with:

```bash
cd /path/to/moodle/local/xlate
grunt amd
```

**Note**: If you encounter permission issues with `.eslintignore`, use:

```bash
grunt amd --force
```

**File Ownership**: Ensure the plugin directory maintains proper ownership for git compatibility:
- Plugin files should be owned by the git user (e.g., `ubuntu:ubuntu`)
- Avoid running grunt as `www-data` to prevent ownership conflicts

## Anti-FOUT (Flash of Untranslated Text) Strategy

### Problem
Without prevention, users would see:
1. Page loads with original text
2. Translations load asynchronously  
3. Text "flickers" as it gets replaced

### Solution - Multi-Layer Approach

**Layer 1: CSS Hide**
```css
html.xlate-loading body { visibility: hidden; }
```
- Applied immediately in `<head>`
- Hides entire page until translations ready

**Layer 2: Async Loading**
```javascript
// Check localStorage first (instant)
var cached = localStorage.getItem('xlate:en:v123');
if (cached) {
    run(JSON.parse(cached));  // Show page immediately
}

// Fetch fresh bundle (background update)
fetch('/local/xlate/bundle.php?lang=en&v=123')
    .then(r => r.json())
    .then(bundle => {
        localStorage.setItem('xlate:en:v123', JSON.stringify(bundle));
        if (!cached) run(bundle);  // Only run if no cache hit
    });
```

**Layer 3: Graceful Fallback**
```javascript
// Always remove loading class, even on errors
try {
    // ... translation logic
} finally {
    document.documentElement.classList.remove('xlate-loading');
}
```

### Performance Benefits
- **First visit**: Brief loading delay, then fully translated
- **Return visits**: Instant display from localStorage
- **Network failure**: Page shows untranslated (better than broken)

## Caching Architecture

### Three-Tier Caching System

**Tier 1: Moodle Application Cache**
- **Location**: `classes/local/api.php::get_bundle()`
- **TTL**: 1 hour
- **Purpose**: Avoid DB queries on every bundle request
- **Key**: Language code (`'en'`, `'ar'`)
- **Invalidation**: Automatic TTL expiry

**Tier 2: Browser localStorage**
- **Location**: Client-side bootloader script
- **TTL**: Until version changes
- **Purpose**: Instant page loads on return visits
- **Key**: `'xlate:' + lang + ':' + version`
- **Invalidation**: Version hash mismatch

**Tier 3: HTTP Cache**
- **Location**: `bundle.php` response headers
- **TTL**: 1 year (immutable)
- **Purpose**: CDN/proxy caching
- **Invalidation**: Version parameter in URL

### Cache Invalidation Flow
```
Translation updated → Version hash changes → New bundle URL → Cache miss → Fresh fetch
```

## Security Model

### Capabilities
- `local/xlate:manage` - **RISK_CONFIG** - Full CRUD access (managers only)
- `local/xlate:viewui` - Read-only admin UI access

### Access Control Patterns
```php
// Admin pages
require_login();
require_capability('local/xlate:viewui', context_system::instance());

// Write operations  
require_capability('local/xlate:manage', context_system::instance());

// Data sanitization
$lang = required_param('lang', PARAM_ALPHANUMEXT);
$text = required_param('text', PARAM_TEXT);
```

### Bundle Security
- No authentication required (public content)
- Language parameter validated (`PARAM_ALPHANUMEXT`)
- Only `status=1` translations exposed
- JSON output properly escaped

## Development Workflows

### Adding New Translation Keys

#### Method 1: Automatic Detection (Recommended)
The plugin includes intelligent automatic string detection that captures translatable content:

1. **Enable auto-detection**: Go to Site Administration → Plugins → Local plugins → Xlate settings
2. **Configure languages**: Select which languages to enable for translation
3. **Browse your site**: Auto-detection runs in the background capturing text
4. **Manage translations**: Visit the "Manage Translations" page to edit detected keys
5. **Smart filtering**: System ignores admin elements and captures only user-facing content

**Auto-Detection Features**:
- **Smart key generation**: Based on element type (Button.Save, Heading.Title, Input.Placeholder)
- **Multi-attribute support**: Handles text content, placeholder, title, and alt attributes
- **Component detection**: Suggests appropriate component based on context
- **Content filtering**: Automatically excludes admin text, navigation, and non-translatable content
- **HTML handling**: Extracts clean text from elements with simple formatting
- **Real-time application**: Elements immediately get `data-xlate` attributes

**Supported Elements**:
- Text content in any HTML element (with smart filtering)
- Form input placeholders (`data-xlate-placeholder`)
- Element titles (`data-xlate-title`) 
- Image alt text (`data-xlate-alt`)
- Respects `data-xlate-ignore` to skip subtrees

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