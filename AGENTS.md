# Project Instructions

## Tech Stack & Lightweight Rules
- Use only HTML, CSS, vanilla JavaScript, PHP, MySQL, and XAMPP (no external libraries/frameworks).
- Inspect existing files first before editing. Keep the system ultra-lightweight with zero lag for online capstone demos (no heavy blur, large shadows, long animations, or fake delays).
- Put CSS and JS in external feature/shared files only; do not use inline CSS, inline JS, or inline event handlers.
- Do not break unrelated working features.

## UI, Layout & Color Coding
- Make every page, form, card, table, button, modal, filter, and search bar responsive (mobile, laptop, desktop) with zero overlap, cut text, or broken alignment; wrap long text safely.
- Keep desktop modals compact when content is short, and mobile modals scrollable within the screen.
- Reuse shared backgrounds, layouts, buttons, filters, modals, loaders, and helpers.
- Keep status colors consistent with clear text/icon labels: green (success/completed), blue (active/in progress), yellow/orange (pending/warning), red (error/destructive), gray (neutral/inactive).
- Place status beside/below related details without repeating identical status/alerts across multiple cards; use simple colored text for minor statuses.

## Shared Logic & Database Safety
- Reuse existing backend actions, helpers, services, CSS, and JS. Move reusable feature logic into shared files, update all references, and delete duplicates only after confirming nothing uses them.
- Reuse existing DB tables/columns as one source of truth. Never delete/merge tables just because they are empty—check all PHP/SQL references and foreign keys first, and create a backup + reversible migration with rollback before dropping/merging.
- Put schema changes in versioned files under `DB/migrations` (no runtime `CREATE`/`ALTER TABLE` in page files). Keep server/DB validation safe.

## Workflow & Response Format
- Reply in short, simple Taglish.
- State the exact small change before editing.
- Lint changed PHP files with XAMPP PHP and review `git diff` before finishing.
