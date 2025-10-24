# local_xlate (Moodle 5+ minimal)

Client-side translation plugin (LocalizeJS-style).

## Install
1. Unzip so folder path is `moodle/local/xlate`.
2. Go to **Site administration → Notifications** to install DB.
3. Enable at **Site administration → Plugins → Local plugins → Xlate**.
4. Purge caches.

## Use
```html
<h2 data-xlate="Dashboard.Title"></h2>
<input data-xlate-placeholder="Search.Input" placeholder="">
<div data-xlate-ignore>Do not translate this subtree</div>
```

## Notes
- Add CRUD UI to manage keys/translations and bump per-lang version to bust caches.
- AMD module watches for new nodes.
- Target: Moodle 5+. Update `$plugin->requires` to match your site baseline if needed.
