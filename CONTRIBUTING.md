# Contributing to the Documentation

## Scope

- Edit only the current source under `docs/`, unless the intended change concerns historical material.
- Do not edit `doc/` to change an existing decision; add or update a current document with a clear reference to the historical source.
- Do not add hand-authored SVG or PNG files. Keep the textual Mermaid source in `docs/architecture/diagrams/`, and run `scripts/render-diagrams.sh` when local output is needed.

## Before Opening a Merge Request

1. Use `kebab-case` filenames with the `.md` extension.
2. Follow the front matter fields and review controls in `docs/governance/document-control.md`.
3. Use relative links that work from the file containing them.
4. Do not create a `Requests` module: a general request is a `WorkRecord` of type `request`.
5. Do not add secrets, personal data, or real operational data.
6. Run `./scripts/validate-docs.sh` and fix any violations it reports before requesting a review.

## Review

The change must be reviewed by the document owner and the roles required in the front matter. `docs/governance/document-control.md` defines the review cycle and change log for approved documents.
