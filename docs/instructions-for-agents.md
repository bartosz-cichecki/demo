# AGENT.md

Purpose
- This file defines working instructions for coding agents in this repository.
- Architecture and system rules live in `docs/architecture.md` (the canonical source of truth).
- `docs/architecture.en.md` is a faithful translation for English readers; if the documents conflict, `docs/architecture.md` wins.

## 1) Source of truth
Before changing anything, read:
- `docs/architecture.md`

Select the sections relevant to the task. Do not use plans, backlogs, temporary files, or external comparison material as evidence of current runtime behavior.

## 2) Workflow and pre-flight
1. Understand the task
- Identify the affected bounded context, route/guard, use case, and domain invariants.
- State measurable DONE criteria and the verification needed to prove them.
- Determine the minimal diff (KISS).

2. Run a lightweight pre-flight before the first edit
- Cross-BC: name the data or behavior owner. For reads, identify the consuming Infrastructure ACL and its own DTO. For writes, identify the consuming Application port and its Infrastructure adapter (`architecture.md` sections 4.1 and 4.2).
- DI: check `app/config/services.autoload.yaml` and an existing analogy first. If manual config is needed, state why convention is insufficient and place module-specific details in `app/src/{BC}/Infrastructure/Resource/config.yaml` (`architecture.md` section 10).
- Public HTTP surface: identify any affected route name, path, HTTP method, input, response status/payload, guard map/allowlist, route-specific subscriber, and behavior test (`architecture.md` sections 11 and 11.1).
- Documentation-only work: identify the concrete code, configuration, test, Makefile, or deployment files that prove each changed rule.
- If ownership, security, public API compatibility, or verification cannot be established without unsafe guessing, stop and ask for a decision.

3. Locate the places to change
- Production code: `app/src/`
- Configuration: `app/config/`
- Tests: `app/tests/`
- Documentation: `docs/`

4. Implement
- Follow the layers and BC boundaries in `docs/architecture.md`.
- Search for an existing analogy before adding configuration or an abstraction.
- Change only what is required for DONE.
- Add a test that proves changed behavior: prefer Behat for HTTP behavior and PHPUnit for domain/unit behavior.

5. Clean up
- No unrelated refactors or cosmetic changes.
- Keep route names, commands, classes, files, and configuration naming consistent.
- Every changed line must map to the task goal.

## 3) Hard guardrails
- Do not change architecture or the public HTTP surface unless the prompt explicitly requires it.
- Do not move business logic into Application for convenience.
- Do not import a foreign BC's Application or Domain classes into the consuming Application layer; use the consuming BC's own port and an Infrastructure adapter.
- Do not treat a green Deptrac result as the only proof of cross-BC compliance; current Deptrac rules are layer-based, not BC-specific.
- Do not add manual DI before checking autoload conventions and module-local configuration.
- Do not log secrets. Tokens and cookies may only be logged as hashes or redacted values.
- Do not add services or infrastructure such as Redis unless the prompt requires it.
- Keep the diff minimal. Report unrelated cleanup opportunities instead of implementing them.

## 4) Architecture reminders
Details are in `docs/architecture.md`.

- Domain owns rules and invariants; Application orchestrates use cases; Ui handles HTTP/CLI input and responses.
- Commands write through ORM; Queries read through DBAL and return DTOs (CQRS-lite).
- Domain obtains read-only external state through Outside. Cross-BC orchestration uses ports and Infrastructure adapters.
- Subscribers/listeners stay thin and predictable; writes go through Commands.
- Route names participate in security and must remain aligned with platform/tenant guards.

## 5) Quality gates
Choose gates according to the scope and run them one at a time, from cheapest to most expensive:
1. `make cs-check`
2. `make phpstan`
3. `make deptrac-ci`
4. `make test`
5. `make behat`

Wait for the full result and exit code before starting the next gate. Stop on failure, fix the issue, and rerun the appropriate gates sequentially. `make qa` covers only the first three gates.

For documentation-only changes, run `git diff --check`; do not run the full application suite unless the task or repository requirements call for it.

If Docker Compose is needed, use a Makefile target when one exists.

## 6) Report after implementation
Include:
- Summary (1-5 points).
- Files Created / Files Modified.
- Pre-flight outcome where applicable: affected BC, cross-BC/ACL, DI, and public HTTP surface.
- Compliance check: name the exact relevant `docs/architecture.md` sections and state how the implementation applies each one. Report ambiguity or an intentional exception as a decision, not as compliance.
- How you checked it: commands in execution order and their results.
- Risks/notes, including checks that were not run.
- If docs changed, identify the files, changed sections, supporting repository evidence, and reason.

## 7) Prompt template
Goal
- (1-2 sentences)

Steps
1. ...
2. ...

DONE criteria
- ...

Task-specific risks or constraints
- ...

Do not repeat fixed repository rules or quality-gate lists in each task prompt; they remain in this file and `docs/architecture.md`.

## 8) Commit message
- Conventional Commits: `type(scope): description`
- Short and in English.

Examples:
- `feat(user): harden otp rate limiting`
- `fix(security): enforce platform route guard`
- `docs(architecture): document dependency rules`
