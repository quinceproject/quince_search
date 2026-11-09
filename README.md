# Quince Search

**Search with a scope filter and product suggestions for PrestaShop 9.2.**

Quince Search lets customers choose where to look, by product name, by code or SKU,
in a category, by brand or by a product feature, and suggests products while they
type. It works on top of the PrestaShop search, not instead of it: the core keeps its
own index and relevance rules, and the module narrows the pool of results to the
chosen scope.

> Quince Search is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **Search scopes.** A list next to the search field: everywhere, in product name,
  by code or SKU, in category, by brand and by a chosen product feature. The shop
  names each scope in every language, switches scopes on and off and sets their
  order by dragging.
- **Links to a scope.** Every scope has a key, so a link such as
  `?q_scope=reference` opens the search already narrowed.
- **Core search where it is better.** Without a scope, or with "everywhere", the
  PrestaShop core search does the work, with its field weights and its index. The
  module takes over the results page only for a narrowed scope.
- **Suggestions while typing.** Products with name, image, category and price, from
  a dedicated endpoint that returns only what the list shows. The shop sets after how
  many characters suggestions start (1 to 6) and how many products they show
  (3 to 12). A request limit per address stops anyone from pulling the catalogue
  through it.
- **Same visibility as the shop.** Suggestions and scoped results show only active
  products allowed in search, and only those the customer's group may see.
- **"Nothing found" page.** Hints written by the shop, a heading and category tiles
  picked from the catalogue tree, with the number of tiles per row (2 to 8) and an
  option to tint category images to one colour for line-drawing icons.
- **Works with Quince Search Score.** When that module is installed, its score breaks
  ties between equally relevant products. It never outranks a better match.
- **Clean uninstall.** The module removes its settings, and the shop goes back to the
  built-in search instead of being left without one.
- **Multilingual.** English base with a full Polish translation, on PrestaShop's
  standard translation system.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | required |

Quince Engine draws the search field in the header and hosts the module's settings
screen. The module declares it as a dependency, so PrestaShop refuses to install
Quince Search without it.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_search.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_search/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_search` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Search and click **Install**.

**From Quince Engine** *(coming soon)*

The module list in Quince Engine will install the module straight from its release,
without downloading anything by hand.

**With Composer** *(coming soon)*

Installing with `composer require quinceproject/search` will work once the package is
published on Packagist.

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_search/releases).

## Configuration

Open **Quince project → Search** in the back office menu, or click **Configure** next
to the module in the Module Manager.

1. **Search scopes.** Add, name, switch on and off and reorder the scopes customers
   can pick. For a product feature scope, choose which feature.
2. **Nothing found page.** Write the hints, the heading above the categories and pick
   the categories to show.
3. **Suggestions while typing.** Switch suggestions on and set when they start and how
   many products they show.

To use Quince Search as the shop's search, choose it in the search settings of the
Quince Engine header screen.

## Architecture

- **Symfony controller and Twig** for the settings screen, inside the Quince Engine
  back office shell. The module has no CSS or sidebar of its own.
- **`ScopeSearchProvider`** implements PrestaShop's `ProductSearchProviderInterface`
  and answers the `productSearchProvider` hook only for a narrowed scope. The module
  places itself before `ps_facetedsearch` in that hook, because the core takes the
  first answer it gets.
- **`ProductFinder`** finds products for suggestions and for scopes: through the core
  search index for "everywhere", and through the table that fits the scope otherwise.
- **`suggest` front controller** returns suggestions as JSON.
- **Settings** are stored in PrestaShop configuration under the `QUINCE_SEARCH_`
  prefix. The module creates no tables of its own.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_search.git
cd quince_search
composer install
```

The folder must be called `quince_search`, the same as the module. A clone of this
repository already has that name.

To build an installable archive, zip the `quince_search/` folder **with its `vendor/`
directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
