## Local dev
- Place plugin at `moodle/local/xlate`.
- After AMD changes: purge caches so Moodle rebuilds JS.

## Commands
- `composer install`
- `composer run lint`
- `composer run lint:fix`
- `npm run lint:js`

## Review checklist
- [ ] Capability checks on admin pages
- [ ] PARAM_* validation for inputs
- [ ] No direct $_GET/$_POST
- [ ] Cache keys and TTLs sensible
- [ ] DB queries parameterized via $DB
- [ ] Strings in lang/en/local_xlate.php

Target platform: Moodle 5+. Test on at least one 5.x build.
