# xal — XHTML-like Attribute Language

xal is a lean templating and composition layer for PHP 8.4. It combines TAL-style attribute syntax with the evaluation model of XSLT and runs directly on native PHP arrays. There is no compile step, no cache, no build pipeline and no expression language. Every template is a complete, valid HTML document that opens directly in a browser without the engine, so the production file *is* the preview file.

The project started as a reinterpretation of two proven ideas: the attribute templates of PHPTAL (without its loop variables and compile cache) and a region system drawn from twenty years of web-shop practice (without its unconstrained nesting).

> The detailed reference documentation lives in [`www/README.md`](www/README.md) (German). This file is an English overview.

## Quick start

Requirements: Docker with the Compose plugin.

```bash
git clone https://github.com/patrick-fischer-hirschstein/xal.git
cd xal/docker
cp env.example .env        # then fill in your database credentials
docker compose up -d --build
```

Open **http://localhost:8080**. The bundled demo page uses stubbed data, so it renders even before a database is configured.

Check that the PHP container has the native HTML5 DOM API that xal requires:

```bash
docker compose exec php php -r 'var_dump(class_exists("Dom\\HTMLDocument"));'
# bool(true)
```

The `www/` directory is mounted into the containers, so code changes show up on the next page reload.

### Configuration

The database connection is read from the environment, which `docker/.env` provides:

| Variable | Example |
|---|---|
| `XAL_DB_DSN` | `mysql:host=db.example.org;port=3306;dbname=myapp;charset=utf8mb4` |
| `XAL_DB_USER` | `app_reader` |
| `XAL_DB_PASS` | — |

`.env` is git-ignored and must never be committed. The connection is opened lazily, on the first `db()` call.

## Core principles

The system trades degrees of freedom for guarantees. Every limit is deliberate.

- **All data before rendering.** Data preparation delivers the complete render form as a PHP array before any template is touched. Templates trigger no lazy loading, no callbacks and no database access.
- **Context instead of variables.** There are no named loop variables and no expression language. A moving cursor points at the current position in the data tree. Paths are relative to the cursor or to the root.
- **Three levels, done.** A page consists of a *Page* (skeleton), *Regions* (the composition of a content area) and *Components* (building blocks). A component cannot contain a component.
- **No `xal:*` in the output.** All control attributes are stripped during processing, so the production HTML is clean.

## The language

Control attributes use the `xal:` prefix, declared as `xmlns:xal="urn:xal"` on the root element.

**Paths** are binary. A bare name is relative to the context (`title`, `author/name`), and a leading `/` is relative to the root (`/site_name`). Paths have no `.` or `..`, so there is no access to parent elements. A missing path resolves to `null`.

**Directives.** There are exactly four:

| Directive | Effect |
|---|---|
| `xal:text="path"` | Replaces the element's content with the value. `null` removes the element. `path?` keeps it, empty. |
| `xal:foreach="path"` | Repeats the element once per list entry, with the cursor on that entry. An empty list removes the element. |
| `xal:if="path"` | Keeps the element if the value is truthy and removes it otherwise. Ignored when combined with `xal:foreach`. |
| `xal:remove` | Always removes the element. Use it for preview-only content. |

**Attribute binding.** Any other `xal:` attribute binds the HTML attribute of the same name (`xal:href="url"` sets `href`). A falsy value drops the attribute, never the element, which also covers boolean attributes such as `disabled`. `xal:class` is the one exception: it *appends* to the existing `class`.

```html
<tr xal:foreach="products" xal:class="status">
  <td><a xal:href="url" xal:text="name" href="#">Sample product</a></td>
  <td xal:text="price">9.99</td>
  <td><input type="checkbox" xal:checked="in_stock" disabled></td>
</tr>
```

The dummy values (`Sample product`, `9.99`) make the file previewable in a browser. When the template is rendered, real data replaces them. `xal:checked` shows a boolean binding: the checkbox is ticked only when `in_stock` is truthy.

## Composition

| Level | Role | Slot attribute inside | Example |
|---|---|---|---|
| **Page** | Invariant skeleton: logo, menus, grid | `xal:region="…"` | Site frame with header, navigation and footer |
| **Region** | Composition of a page type | `xal:component="…"` | Product detail page, article page |
| **Component** | Reusable, data-driven building block | none (leaf) | Data table, teaser list, image gallery |

In each template, the `<head>` acts as the asset manifest: the assembler collects only `<link>` stylesheets, deduplicates them and rewrites them to root-absolute URLs. `<style>` blocks are preview-only and are stripped from the output. The `<body>` is the payload that gets injected into the slot. Slot elements belong to the skeleton, so injection replaces only their children.

### Request flow

1. **URL resolution** yields the *spa* (site parameter array): page type plus IDs.
2. **Data preparation**: region classes collect *all* data in their constructors.
3. **Composition** declares which page to use, which region goes in each region slot, and which component data goes in each component slot.
4. **The assembler** runs three fixed passes (page → regions → components), harvests CSS, injects the bodies, strips the `xal:*` attributes and outputs the final HTML.

Each render step runs in its own disposable `Walker` instance, so two components in the same request cannot affect each other.

## Error handling

- **Production (default):** visitors always see the page. Broken building blocks are logged to `logs/fehler-YYYY-MM-DD.txt` and degraded. A failed region shows an apology placeholder, and a failed component is silently omitted. Only a missing page template causes a total failure, in which case `index.php` serves an emergency page.
- **Dev mode (`devMode: true`):** fail fast, so real errors throw. Unwired slots are treated as work in progress and highlighted visually.

## Repository layout

```
.
├── docker/                    Local development environment
│   ├── Dockerfile             php:8.4-fpm + pdo_mysql, opcache, dev php.ini
│   ├── docker-compose.yml     xal-php (FPM) + xal-nginx (port 8080)
│   ├── nginx-default.conf
│   └── env.example            Template for .env
└── www/                       Document root
    ├── index.php              Entry point
    ├── patterns.html          Pattern catalogue (never assembled)
    ├── lib/
    │   ├── autoload.php       PSR-4 for two prefixes, no Composer
    │   ├── xal/               Namespace Xal\ — the engine (project-agnostic)
    │   └── tsv/               Namespace tsv\ — the project (DB access, regions)
    └── template/tsv/          Markup and styles only, no PHP
        ├── base.css
        ├── page/  region/  component/
```

The key rule is **`lib/` = code, `template/` = markup and styles**. `lib/xal/` knows nothing about databases or application data. Everything project-specific lives in `tsv\Region` / `tsv\ContentRegion`.

## Deliberate limits

The design deliberately has no parent access in paths, no fifth directive, no nested components, no expression language, no callbacks into the walker and no singletons. Each limit trades a degree of freedom for a guarantee. Extending one requires a new architecture decision record, not a silent rewrite.

## License

[MIT](LICENSE) © 2026 Patrick Fischer
