# Demo Backlog

Purpose:
Organize the work that strengthens this public demo as a pragmatic example of product delivery, quality, and architecture.

This backlog is not a list of every tool that could be added to the repository. It contains only the work that increases the credibility of the demo without inflating scope or maintenance cost.

## Statuses

- `TODO` — not started
- `IN PROGRESS` — in progress
- `DONE` — completed
- `DEFERRED` — intentionally postponed

## Dates

- `Updated` — the last meaningful update of the backlog item.
- `Done` — completion date, filled only when the status changes to `DONE`.

## Delivery order

| Priority | Topic | Status | Updated | Done | Why |
|---:|---|---|---|---|---|
| 1 | GitHub Actions CI | TODO | 2026-04-30 | — | Prove quality gates on push/PR |
| 2 | Mermaid architecture flow | TODO | 2026-04-30 | — | Show the main architecture flow in 30 seconds |
| 3 | README first screen polish | TODO | 2026-04-30 | — | Explain quickly what the demo is and what it proves |
| 4 | Architecture Decision Records | TODO | 2026-04-30 | — | Show conscious decisions and trade-offs |
| 5 | Repository hygiene | TODO | 2026-04-30 | — | Remove basic red flags from a public repository |
| 6 | Dependabot | TODO | 2026-04-30 | — | Add automated dependency hygiene |

---

## 1. GitHub Actions CI

Status: `TODO`

### Why

Quality gates should not be only a statement in the README. A public demo should prove that the basic quality checks pass automatically on push and pull request.

### Scope

- GitHub Actions workflow for:
  - `cs-check`
  - `phpstan`
  - `deptrac-ci`
  - `phpunit`
  - `behat`
- PostgreSQL as a service container if integration tests or Behat require it.
- Composer cache.
- README badge if the workflow is stable.

### Notes

This is the highest-value single improvement for repository credibility. After this change, the statement “quality gates are part of the demo” becomes verifiable without running the project locally.

---

## 2. Mermaid architecture flow

Status: `TODO`

### Why

The diagram should help readers understand the main architecture flow without reading the full `docs/architecture` document first.

### Scope

Add one Mermaid diagram to the README showing the main flow:

```text
HTTP
-> Input
-> CommandBus
-> Domain
-> DomainEvent
-> EventLog
-> EventBus
-> Saga
-> IntegrationEvent
-> Outbox
-> Worker
-> Async Subscriber
```

### Notes

The diagram supports understanding. It does not replace the architecture documentation. Keep it simple and readable. Do not introduce full C4 diagrams or a complete dependency map.

Do this before the README polish, because the diagram becomes direct input for the README architecture section.

---

## 3. README first screen polish

Status: `TODO`

### Why

The first screen of the README should immediately explain:

- what the demo is,
- what it proves,
- how to run it,
- why Behat scenarios and architecture documentation are worth reading.

### Scope

- Short `What this is`.
- Short `What this proves`.
- Quick start with the minimum useful command set.
- Include or highlight the Mermaid diagram with the main architecture flow.
- Make Behat more visible as executable business documentation.
- Keep AI-assisted development information, but place it below the basic product and setup explanation.

### Notes

The current README already has a `Key flows` section based on real Behat scenarios. This task is polish and hierarchy improvement, not a full rewrite.

---

## 4. Architecture Decision Records

Status: `TODO`

### Why

ADRs should show that architectural decisions are conscious, pragmatic, and driven by the goal of the demo, not by tool fashion.

### Scope

Create `docs/adr/` and add short ADRs using a simple format:

- Context
- Decision
- Consequences
- Status

Suggested ADRs:

1. Behat as executable business documentation.
2. Why no OpenAPI / Swagger / curl cookbook / Bruno for now.
3. Coverage strategy.
4. How AI was used in this project and why.
5. SSR-first and low-JS over SPA.
6. PostgreSQL outbox over message broker for demo/MVP.
7. CQRS-lite over full CQRS.
8. Outside pattern for domain access to external state.
9. DBAL read side over ORM queries.
10. Synchronous domain events with async integration events.

### Important decision: coverage strategy

The coverage ADR should clearly describe the test coverage strategy per layer:

- Domain: high coverage of aggregate behavior and invariants.
- Application: cover orchestration where it provides real value.
- Infrastructure: integration tests for non-trivial adapters, persistence, queries, and outbox.
- UI / E2E: Behat for key business processes and security boundaries.
- No blind percentage target for the whole repository.

The goal is not a nice global percentage. The goal is to protect behavior, domain decisions, and architecture boundaries.

The ADR should also name what is intentionally not covered by separate tests when that would only duplicate Behat scenarios or test the framework itself.

### Important decision: AI-assisted development

The AI-assisted development ADR should describe how AI was used in this project and why:

- natural-language instructions as a higher-level interface for requirements work,
- no agent autonomy without human decision,
- short tasks with clear cause and effect,
- architecture, tests, and quality gates as guardrails for generated code,
- requirements and domain decisions as a real project asset,
- transparency: AI speeds up delivery, but does not replace engineering responsibility.

This can be one of the strongest ADRs in the repository, because it formalizes an uncommon but practical way of building this demo.

### Important decision: no OpenAPI / curl / Bruno at this stage

The demo is not API-first and not integration-first. The main product contract is expressed through business processes covered by Behat and through the SSR-first UI flow.

OpenAPI, Swagger, curl cookbook, or Bruno/Postman collections could add a feeling of completeness, but at this stage they would increase maintenance cost and the risk of drift between:

- the real business process,
- Behat scenarios,
- input classes,
- request documentation,
- manual request collections.

If the application starts exposing a public API for external clients or integrators, OpenAPI returns as a separate product topic. At the current stage, the lack of these artifacts is a conscious decision, not a hygiene gap.

### Notes

Keep ADRs short. Their purpose is to defend trade-offs and show reasoning, not to produce formal documentation for its own sake.

---

## 5. Repository hygiene

Status: `TODO`

### Why

A public repository should not have basic organizational red flags. This is not the core product, but it is a cheap signal that the project is maintained professionally.

### Scope

- `LICENSE`
- `SECURITY.md`
- Pull request template
- Issue templates
- Optional short `CONTRIBUTING.md`

### Notes

Keep this minimal. These files should help repository readers. They should not pretend that this is a large open-source organization.

---

## 6. Dependabot

Status: `TODO`

### Why

Add automated dependency hygiene for the public repository.

### Scope

- `.github/dependabot.yml` for:
  - Composer
  - GitHub Actions
- README badge or short README mention only if it makes sense and does not add noise.

### Notes

Do this after GitHub Actions CI. Automated dependency PRs make sense only when the repository can verify them automatically.
