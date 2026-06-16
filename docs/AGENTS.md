# AGENTS.md

## Project overview
This project is a Tesla parts warehouse system for local development in Laragon and future deployment on paperhelp.discount.

## Rules
- Read `docs/warehouse-spec.md` before making changes.
- Build the project in phases.
- Use Laravel + Blade + MySQL.
- Do not remove movement history; use corrective operations instead.
- Every stock item must belong to a warehouse location.
- Reserve must be separate from physical stock.
- Add `created_by` and `updated_by` where appropriate.
- Prefer clean CRUD, validation, and readable Blade templates.

## Workflow
- Before coding, propose a brief implementation plan.
- After coding, list changed files.
- Keep code simple and production-oriented.