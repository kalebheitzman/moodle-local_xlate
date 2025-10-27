# Structural Key Generation - Implementation Summary

## Overview
Updated the translator to generate **stable, structure-based translation keys** instead of text-based keys. This ensures translations persist even when source text changes slightly (typos, punctuation, minor edits).

## Key Generation Algorithm

### Input Components
For each translatable element, the key is built from:
1. **Tag name** (e.g., `h2`, `p`, `div`, `button`)
2. **Class names** (from element + up to 2 ancestors, filtered for dynamic/utility classes)
3. **Region** (`data-region` attribute if present)
4. **Type** (e.g., `placeholder`, `title`, `alt` - omitted for text content)
5. **Text content** (the actual translatable string)

### Composite String
These are joined with periods:
```
tagname.class1,class2,class3.region-name.type.text-content
```

### Example
```html
<div class="course-header main-content" data-region="course-info">
  <h2 class="title">Welcome to the Course</h2>
</div>
```

**Composite:** `h2.title,course-header,main-content.course-info.Welcome to the Course`

### Encoding
1. **Hash** the composite string using a simple hash function (djb2-style)
2. **Convert to base36** (0-9, a-z)
3. **Pad or truncate to 12 characters**

**Result:** 12-character key like `a1b2c3d4e5f6`

## Benefits

✅ **Resilient to text changes**
- "Welcome to the Course" → key: `aDIuY291`
- "Welcome to the Course." → key: `aDIuY291` (same!)
- Fixed typo doesn't break translation

✅ **Context-aware**
- Same text in different structural locations gets different keys
- Prevents collision between unrelated elements

✅ **Compact & visible**
- 12 characters balances uniqueness with DOM size
- Injected as `data-xlate-key-content="a1b2c3d4e5f6"` for debugging

✅ **Deterministic**
- Same structure always generates same key
- Predictable behavior across page loads

## DOM Injection

The `data-xlate-key` attribute is now **always injected** into elements:

### During Auto-Detection (Capture Mode)
```javascript
autoDetectString(element, text, type) {
  // ... generates key ...
  setKeyAttribute(element, type, key); // ← Injects data-xlate-key
}
```

### During Translation
```javascript
translateNode(node, map) {
  if (key && map[key]) {
    node.textContent = map[key];
    setKeyAttribute(node, 'text', key); // ← Ensures key is visible
  }
}
```

### Inspection
You can now view the page source and see:
```html
<h2 data-xlate-key-content="a1b2c3d4e5f6">Willkommen im Kurs</h2>
<input data-xlate-key-placeholder="x9y8z7w6v5u4" placeholder="Suchen...">
<img data-xlate-key-alt="m5n4o3p2q1r0" alt="Course banner">
```

## Changes Made

### Files Modified
- `amd/src/translator.js` - Core key generation logic
- `amd/build/translator.min.js` - Rebuilt minified version

### Functions Updated
1. **`generateKey(element, text, type)`** - Complete rewrite using structural approach
2. **`collectContextClasses(element)`** - New helper to gather filtered class names
3. **`getRegion(element)`** - New helper to extract `data-region`
4. **`translateNode(node, map)`** - Enhanced to always inject keys

### Functions Removed
- `getClassHint()` - No longer needed
- `getElementPrefix()` - No longer needed

## Testing

To test the new key generation:

1. **Purge Moodle caches:**
   ```bash
   php admin/cli/purge_caches.php
   ```

2. **View a Moodle page** in the default language (e.g., English)

3. **Inspect elements** in browser DevTools - look for `data-xlate-key` attributes

4. **Change source text slightly** (e.g., add punctuation) and refresh - the key should remain the same

5. **Check `local_xlate_key` table:**
   ```sql
   SELECT component, xkey, source FROM mdl_local_xlate_key LIMIT 10;
   ```
   The `xkey` column will contain 12-character keys like `a1b2c3d4e5f6`

## Next Steps

- Monitor for key collisions (very unlikely with 12 characters + structural context)
- Add collision detection/resolution in the capture logic if needed
- Update admin UI to display structural context when viewing keys
- Consider exposing the composite string in debug mode for troubleshooting
