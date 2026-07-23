# Mapper reference for Codex

This folder is a **read-only technical reference** extracted from the TISSER Company Mapper so Codex can understand its visual language, components and expected behaviours while working inside the GesTISSER repository.

## What is included

- `ui/index.reference.html` — complete layout and component inventory.
- `ui/styles.reference.css` — original visual rules, stripped of PHP authentication wrappers.
- `ui/script.reference.js` — original interaction and rendering logic for behavioural analysis only.
- `ui/initial-data.example.js` — sanitised example state.
- `data-model.example.json` — sanitised entities and field names.
- `COMPONENT_MAP.md` — functional map and integration rules.

## What is deliberately excluded

- authentication and account management;
- backend API and persistence files;
- users and password hashes;
- storage files, locks and logs;
- production/company data;
- `.htaccess` and local server launchers.

## Mandatory integration rule

Do **not** deploy or import this reference application into GesTISSER. Do not copy its `localStorage`, state, API, authentication or JSON persistence. Use it only to identify visual components, field expectations and user flows, then reimplement them using GesTISSER's existing PHP 7.0, SQLite, permissions, CSRF, routes, forms and services.

All new GesTISSER CSS classes must use the `gt-` prefix, except when styling an existing Bootstrap component through a scoped parent such as `.gt-body`.
