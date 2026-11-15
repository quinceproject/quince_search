# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.4] - 2026-11-15

### Security

- Every back-office screen of the module checks the employee's permissions. Before,
  any logged-in employee, whatever their profile, could open and save the search
  settings. The screens now belong to the module's own menu tab (`AdminQuinceSearch`):
  grant it to a profile under **Advanced Parameters → Team → Permissions**. SuperAdmin
  needs nothing. A profile that had access through the hidden `AdminQuince` tab needs
  access to this tab now.

## [0.1.3] - 2026-11-15

### Fixed

- Installing the module for all shops at once failed without a message when the
  module was already first among the search providers in one of the shops, for
  example in a shop where no other module provides search. The module now takes the
  first place in every shop on its own.

## [0.1.2] - 2026-11-10

### Changed

- Polish translation completed: the texts added since the previous translation are now translated.

## [0.1.1] - 2026-11-09

### Fixed

- The release package denies web access to `vendor/`. The 0.1.0 package
  shipped without `vendor/.htaccess`, so on Apache the Composer files in that folder
  could be opened from a browser. The module code is unchanged.

## [0.1.0] - 2026-11-09

### Added

- Search scopes next to the search field: everywhere, product name, code or SKU,
  category, brand and a chosen product feature. Each scope has its own name per
  language, can be switched off and reordered, and can be linked to with `?q_scope=`.
- Results page for a narrowed scope. Without a scope, or with "everywhere", the
  search stays with the PrestaShop core and its index.
- Suggestions while typing, served by a dedicated JSON endpoint, with a configurable
  start threshold and number of products, and a request limit per address.
- "Nothing found" page with hints, a heading and category tiles picked by the shop.
- Tie-breaking by Quince Search Score when that module is installed.
- Polish translation.
