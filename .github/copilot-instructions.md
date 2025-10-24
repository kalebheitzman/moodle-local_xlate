# local_xlate - Moodle Client-Side Translation Plugin

## Architecture Overview

This is a **Moodle 5+ local plugin** that provides client-side translations similar to LocalizeJS. The system uses a **three-layer approach**:

1. **Server-side bundle generation** (`bundle.php`) - Cached JSON translation bundles
2. **Client-side injection** (hooks) - Anti-FOUT CSS + inline bootloader script  
3. **DOM translation** (AMD module) - Real-time translation of `data-xlate` attributes

### Core Data Flow
```
DB Tables (keys/translations) → Cache → JSON Bundle → localStorage → DOM Translation
```

## Key Components

### Database Schema (`db/install.xml`)
- `local_xlate_key` - Translation keys with component/xkey unique constraint
- `local_xlate_tr` - Translations with keyid/lang unique constraint + status field
- `local_xlate_bundle` - Per-language version hashes for cache busting

### Output Hooks (`classes/hooks/output.php`)
- **Head hook**: Injects `html.xlate-loading body{visibility:hidden}` to prevent FOUT
- **Body hook**: Injects bootloader script that checks localStorage then fetches fresh bundle
- Uses Moodle's new hook system (not deprecated `$CFG->additionalhtmlhead`)

### Bundle API (`classes/local/api.php`)
- `get_bundle($lang)` - Returns key→translation map from cache or DB
- `get_version($lang)` - Returns SHA1 hash for cache busting
- Uses Moodle application cache with 1-hour TTL

### Client Translator (`amd/src/translator.js`)
- Translates `data-xlate` (textContent) and `data-xlate-{attr}` (attributes)
- Respects `data-xlate-ignore` to skip subtrees
- Uses `MutationObserver` for dynamic content
- Removes `xlate-loading` class when ready

## Development Patterns

### Moodle 5+ Conventions
- Uses **hook system** instead of deprecated callbacks
- Targets PHP 8.2+ and modern Moodle APIs
- Follows Moodle coding standards with namespace `local_xlate\`

### Security Model
- `local/xlate:manage` - Write access (RISK_CONFIG, managers only)
- `local/xlate:viewui` - Read access (system context)
- All admin pages require login + capability check

### Caching Strategy
- **Application cache** for bundles (1-hour TTL)
- **Browser localStorage** with versioned keys (`xlate:lang:version`)
- **HTTP cache headers** on bundle.php (1-year immutable)

## Usage Examples

### HTML Markup
```html
<h2 data-xlate="Dashboard.Title"></h2>
<input data-xlate-placeholder="Search.Input">
<div data-xlate-ignore>Skip this subtree</div>
```

### Adding Translation Keys
```php
// In admin UI (to be implemented)
INSERT INTO {local_xlate_key} (component, xkey, source) VALUES ('core', 'Dashboard.Title', 'Dashboard');
INSERT INTO {local_xlate_tr} (keyid, lang, text, status) VALUES (?, 'ar', 'لوحة القيادة', 1);
```

## Development Workflow

### Testing Changes
1. **Clear caches**: Site administration → Development → Purge all caches
2. **Check output**: View page source for hook injection
3. **Debug bundle**: Visit `/local/xlate/bundle.php?lang=en` directly
4. **Console debugging**: Check `window.__XLATE__` object

### Adding Features
- **Admin CRUD**: Extend `index.php` with proper Moodle forms
- **Bundle rebuilding**: Add action to regenerate version hashes
- **Capture mode**: JavaScript to assign keys to untranslated elements

### File Organization
- `/classes/` - Namespaced PHP classes following Moodle autoloading
- `/amd/src/` - RequireJS modules (build with `grunt amd`)
- `/db/` - Database schema, capabilities, hooks, caches
- `/lang/en/` - English language strings

## Critical Implementation Notes

- **Never bypass capabilities** - All admin actions need `local/xlate:manage`
- **Escape all outputs** - Use `json_encode()` for JavaScript data injection
- **Respect language context** - Use `current_language()` from Moodle session
- **Handle missing bundles gracefully** - Translator runs with empty map if fetch fails