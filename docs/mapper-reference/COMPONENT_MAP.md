# Component and functionality map

## 1. Global shell

Reference selectors: `.app-shell`, `.sidebar`, `.brand`, `.nav`, `.nav-item`, `.main`, `.topbar`, `.page-heading`, `.top-actions`, `.search-box`.

GesTISSER adaptation: existing `partials/header.php`, `partials/footer.php`, `assets/mapper-theme.css` and `assets/mapper-layout.js`. Preserve all authentication, point-clock, break modal and route logic.

## 2. Dashboard

Components:
- hero panel with title, supporting text and quick actions;
- KPI/stat cards;
- process summary;
- annual agenda summary;
- system cost/status summary;
- duty sheet coverage;
- capacity by shift;
- priority improvements.

Implementation must query SQLite and reuse current GesTISSER entities.

## 3. Organisation chart

Filters: department, shift and active/inactive status.

Actions: add shift, add department, add person/function, edit person, inspect reporting line, export PDF.

Data mapping:
- `people` -> existing `users`;
- `departments` -> existing `hr_departments`;
- `shifts` -> existing `hr_schedules`;
- `managerId` -> proposed `users.manager_user_id`;
- `capacityPercent` -> proposed `users.capacity_percent`.

The browser view should order first by shift, then by function/title, while the printable organisation chart should preserve reporting relationships.

## 4. Duty sheets

Components: search, department/coverage/status filters, KPI cards, function cards and modal form.

Fields expected: title, department, responsible, primary and secondary backup, status, review date, purpose, responsibilities, daily tasks, periodic tasks, authority, KPIs, systems, absence instructions, documents and notes.

## 5. Process flows

Components: flow selector, add/edit flow, add node, connect nodes, draggable board, SVG edges, node inspector and node-type legend.

Node types: operation, decision, control and external.

Fields expected on each node: label, owner, department, x/y position, duration, system, KPI and notes.

Do not replicate Mapper's in-memory state. Persist flows, nodes and edges in normalised SQLite tables.

## 6. Annual calendar

Components: previous/current/next year controls, event-type filter, 12-month grid, event list and event modal.

Reuse `hr_calendar_events`; enrich it instead of creating a duplicate calendar table.

## 7. Systems and applications

Components: search/category filters, monthly and annual cost KPIs, renewals due, owner coverage and table list.

Fields: name, category, supplier, owner, users, billing period, cost, renewal, status, URL and notes.

## 8. Machines

Components: search, status/department filters, KPI cards and machine cards.

Machine fields observed in the form and cards:
- code, name, brand, model, serial number and year;
- department, location and owner;
- status and criticality;
- nominal capacity, cycle time and operators required;
- supplier, purchase date, next maintenance, manual URL and notes.

Status values: `operational`, `limited`, `maintenance`, `stopped`, `inactive`.

Criticality values: `high`, `medium`, `low`.

## 9. Competency matrix

Filters: text, shift, department and machine.

Fields: person, machine, level, assessment date, expiry date and notes.

Levels:
- 0 — no knowledge;
- 1 — in training;
- 2 — with supervision;
- 3 — autonomous;
- 4 — trainer/specialist.

Uniqueness rule: one current competency record per person and machine.

## 10. Improvements

Components: filters, KPI cards and table.

Fields: title, area, description, priority, impact, effort, status, owner, supplier, linked flow, due date, expected benefit, evidence and timestamps.

## 11. Search and modals

The global search covers people, functions, flows, systems, machines and competencies. GesTISSER may implement this server-side or with endpoint-backed search, but must respect permissions.

Mapper uses native `<dialog>` and generated forms. GesTISSER currently uses Bootstrap and PHP forms. Preserve Bootstrap behaviour and convert only the visual design; do not copy the modal engine blindly.

## 12. Forbidden direct copies

Never integrate the reference constants or mechanisms named `STORAGE_KEY`, `window.TISSER_ACCESS`, `window.TISSER_INITIAL_DATA`, `saveState`, JSON import/reset, local state persistence, or calls to Mapper's `api.php`.
