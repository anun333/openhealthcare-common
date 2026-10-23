# Changelog

## 1.0.0 (unreleased)

- **`OpenEmr`:** one compatibility layer for OpenEMR 7.0 to 8.5, replacing the copies in Clinic Forms, Layout Export and Units Check. It covers settings, site directory, web root, project directory, OpenEMR's version, the event dispatcher (and dispatching to it), the session, CSRF tokens, the access-denied page, the administrator check and the audit log. Every method was the same in all three copies except `deny()`, which now takes the module's name for its log line.
- **`TranslatedStrings::scan()`:** finds translated strings with an apostrophe or quote mark, which OpenEMR's `xl()` shows as a backtick. It returns how many were checked, so a module's test can assert a floor and not pass on nothing.
