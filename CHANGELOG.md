# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
