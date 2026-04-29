# AGENT.md

Purpose
- This file defines working instructions for coding agents.
- The architecture and system rules live only in `docs/architecture.md` (canonical source of truth).
- English readers may use `docs/architecture.en.md`, but `docs/architecture.md` remains canonical.

## 1) Source of truth
Before changing anything, read:
- `docs/architecture.md`

If there is a conflict, `docs/architecture.md` wins.

## 2) How to work in this repository (workflow)
1. Understand the task
- Clarify: which route, which roles/guards, which domain invariants.
- Determine the minimal diff (KISS).

2. Locate the places to change
- Production code: `app/src/`
- Configuration: `app/config/`
- Tests: `app/tests/`
- Documentation: `docs/`

3. Implement
- Follow the layers and BC boundaries defined in `docs/architecture.md`.
- Change only what is required for DONE.
- Add a test that proves the requirement (prefer Behat for HTTP behavior, PHPUnit for unit tests).

4. Clean up
- No unnecessary refactors "on the side".
- Keep naming consistent: route names, commands, classes, files.

5. Run local quality gates (Makefile)
- `make test`
- `make behat`
- `make phpstan`
- `make deptrac-ci`

## 3) Hard guardrails (non-negotiable)
- Do not change the architecture or public API unless the prompt says so.
- Do not move business logic into Application "because it is faster".
- Do not log secrets (tokens/cookies only as hashes or redacted values).
- Do not add new services (Redis, etc.) unless the prompt requires it.
- Minimal diff, no cosmetic changes unless necessary.

## 4) Guiding rules (without duplicating the architecture)
Details are in `docs/architecture.md`, but remember:
- Domain holds rules and invariants, Application orchestrates use cases, Ui handles HTTP.
- Reads and writes may use different paths (CQRS-lite), if this is already the project standard.
- Integrations and IO go through ports (Outside) according to `docs/architecture.md`.
- A subscriber/listener must be thin and predictable.

## 5) Commands (most common)
- `make test`
- `make behat`
- `make phpstan`
- `make deptrac-ci`
- `make cs-check` (if it exists in the repository)

If you must use docker compose, do it through the Makefile if a target exists.

## 6) Agent response format after implementation (report)
Include in the report:
- Summary (1-5 points)
- Files Created / Files Modified (list)
- How you checked it (which make targets were run and their result)
- Risks/notes (briefly)
- If docs changed, identify which files and why

## 7) Prompt template (for agents)
Copy and fill in:

Goal
- (1-2 sentences)

Steps (specific)
1. ...
2. ...

Commands
- `make test`
- `make behat`
- `make phpstan`
- `make deptrac-ci`

DONE criteria
- (tests + system behavior)

Constraints
- No refactor beyond what is necessary
- No domain changes unless the prompt says so
- No architecture/public API changes

## 8) Commit message
- Conventional Commits: `type(scope): description`
- Short, in English
  Examples:
- `feat(retro): add rate limiting for public endpoints`
- `fix(security): harden guard route map`
- `docs(runbooks): document rate limiter configuration`
