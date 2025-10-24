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

### 3. Client-Side Translation (`amd/src/translator.js`)
```
Bundle loaded → DOM walk → Apply translations → Watch for changes
```
- Translates `data-xlate` attributes (textContent)
- Translates `data-xlate-{attr}` attributes (placeholder, title, alt, aria-label)
- Respects `data-xlate-ignore` to skip subtrees
- Uses `MutationObserver` for dynamic content
- Removes `xlate-loading` class when complete

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

## File Structure Reference

### Core Files
- `bundle.php` - JSON bundle endpoint
- `index.php` - Admin UI (placeholder)
- `settings.php` - Plugin configuration
- `version.php` - Plugin metadata

### Database Definitions
- `db/install.xml` - Table schema
- `db/access.php` - Capability definitions
- `db/hooks.php` - Hook registration
- `db/caches.php` - Cache configuration

### PHP Classes
- `classes/hooks/output.php` - Page injection hooks
- `classes/local/api.php` - Bundle/version API

### Client Assets
- `amd/src/translator.js` - DOM translation engine
- `lang/en/local_xlate.php` - English strings

## Future Development Areas

### Admin CRUD Interface
- Key management (add/edit/delete translation keys)
- Translation management (per-language editing)
- Bulk import/export (CSV/JSON)
- Bundle rebuild action (regenerate version hashes)

### Advanced Features
- Translation capture mode (auto-assign keys to untranslated elements)
- Translation memory (suggest similar translations)
- Approval workflow (draft → review → published)
- Usage analytics (track which keys are actually used)

### Performance Optimizations
- Bundle splitting (per-component bundles)
- Progressive loading (critical keys first)
- Service worker caching
- Bundle compression (gzip/brotli)