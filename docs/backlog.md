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
| 1 | GitHub Actions CI | DONE | 2026-07-14 | 2026-07-14 | Prove quality gates on push/PR |
| 2 | OTP cooldown decision in Domain | DONE | 2026-09-26 | 2026-09-26 | Apply architecture §6–6.1 to OTP issuance |
| 3 | OTP verification attempt limit in Domain | DONE | 2026-09-26 | 2026-09-26 | Keep the rule in the aggregate and commit failed attempts |
| 4 | Client membership uniqueness in Domain | DONE | 2026-09-28 | 2026-09-28 | Centralize validation shared by both creation handlers |
| 5 | Mermaid architecture flow | TODO | 2026-04-30 | — | Show the main architecture flow in 30 seconds |
| 6 | README first screen polish | TODO | 2026-04-30 | — | Explain quickly what the demo is and what it proves |
| 7 | Architecture Decision Records | TODO | 2026-04-30 | — | Show conscious decisions and trade-offs |
| 8 | Repository hygiene | TODO | 2026-04-30 | — | Remove basic red flags from a public repository |
| 9 | Dependabot | TODO | 2026-04-30 | — | Add automated dependency hygiene |

---

## 1. GitHub Actions CI

Status: `DONE`

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

Completion note (2026-07-14): the workflow passed the full set of quality gates on a real pull request, and `quality-gates` is a required check for `main`. The README badge remains part of the later `README first screen polish` task.

---

## 2. OTP cooldown decision in Domain

Status: `DONE`

### Why

Previously, `OtpRateLimitQuery::check()` owned the 60-second cooldown and compared the last send times with the current time. `RequestOtpCommandHandler` acted on its `isAllowed` result. Architecture §6–6.1 requires Infrastructure to provide facts and Domain to own the business decision.

### Scope

- Extend the existing `OtpChallengeOutsideInterface` with reads for the latest send times by email and IP. Accept the unhashed email (using the existing `Email` value object) and raw IP address; hash the IP inside Infrastructure. Domain must not assemble a lookup key through `hashIp()`.
- Reuse the existing DBAL read logic behind Outside, replacing the query contract that returns a decision with reads that return facts. Remove obsolete rate-limit query/DTO code once unused.
- Put `COOLDOWN_SECONDS = 60` and the issuance decision in the existing `OtpChallengeFactory`, using Outside for facts and current time. Check before generating the code or constructing the challenge.
- Return no issue when blocked, for example through a nullable factory result. The handler persists an issued challenge or returns without writing; it does not calculate the cooldown or access Outside.
- Preserve the current email/IP blocking semantics and the silent HTTP response for blocked requests.

### Done when

- Domain tests cover the first request, a request before 60 seconds, admission exactly at 60 seconds, independent email and IP restrictions, and a missing IP. Use deterministic time through the test Outside.
- Test implementations of Outside and factory callers match the updated contracts.
- Integration/Behat coverage verifies the read path and that a blocked request creates no challenge while preserving the HTTP contract.
- Required quality gates pass in the order specified by `instructions-for-agents.md`.

### Notes

Use the existing factory and Outside; a separate policy is optional, not a prerequisite. This task does not add configurable limits, new infrastructure, or atomic rate limiting across concurrent requests. Follow architecture §5.2, §6–6.2 and §11.1.

Completion note (2026-09-26): `OtpChallengeFactory` owns the cooldown and returns `null` before code generation when blocked. Outside hashes IP and maps facts read through `OtpChallengeQueryInterface`; SQL lives in `OtpChallengeQuery`. The obsolete rate-limit query, interface and DTO were removed. The delivered scope also includes `OtpCodeSenderServiceInterface` with local file delivery and Behat coverage of request → mailbox → verify → login. File delivery precedes commit and is not rolled back on a later commit failure. All five quality gates passed sequentially: `make cs-check`, `make phpstan`, `make deptrac-ci`, `make test` (174 tests), and `make behat` (28 scenarios).

Implementation evidence: [factory](../app/src/User/Domain/OtpChallenge/Factory/OtpChallengeFactory.php), [Outside](../app/src/User/Infrastructure/OtpChallenge/OtpChallengeOutside.php), [query](../app/src/User/Infrastructure/OtpChallenge/OtpChallengeQuery.php), [request handler](../app/src/User/Application/OtpChallenge/Command/RequestOtp/RequestOtpCommandHandler.php), and [file sender](../app/src/User/Infrastructure/OtpChallenge/FileOtpCodeSenderService.php). Verification: [domain tests](../app/tests/User/Domain/OtpChallenge/OtpChallengeFactoryTest.php), [handler tests](../app/tests/User/Application/OtpChallenge/RequestOtpCommandHandlerTest.php), and [Behat scenarios](../app/tests/Behat/features/user/user_registration.feature).

---

## 3. OTP verification attempt limit in Domain

Status: `DONE`

### Why

`OtpChallenge` owns `MAX_ATTEMPTS = 5`; `VerifyOtpCommandHandler` calls `verify(code)` without supplying a limit. Returning verification failure as a value is intentional: a wrong code increments the attempt counter, and that change must commit even though authentication is refused.

### Scope

- Move `MAX_ATTEMPTS = 5` into `OtpChallenge` and change `verify(code, maxAttempts)` to `verify(code)`.
- Update callers and domain tests that currently supply artificial limits of one or three attempts to exercise the actual five-attempt rule.
- Preserve `VerifyOtpResult`, `dispatchWithResult()` and the controller's mapping to the existing HTTP response. The result reaches the controller after commit; the handler runs inside the transaction.
- Keep ordinary verification refusal as a result, not an exception escaping the handler: the current CommandBus rolls back on exceptions, which would discard the incremented counter.

### Done when

- Domain tests prove that five incorrect codes exhaust the limit, that a correct code is rejected afterwards, and that verification can succeed before exhaustion.
- Integration/Behat coverage proves that failed verification commits the incremented counter and preserves the current HTTP contract. Retain coverage of expiry and already-consumed challenges.
- Required quality gates pass in the order specified by `instructions-for-agents.md`.

### Notes

No new policy, configuration, or transaction mechanism is needed. Apply architecture §6–6.1 while retaining the command-result contract in §5.1 and central transaction ownership in §9.

Implementation evidence: [verification handler](../app/src/User/Application/OtpChallenge/Command/VerifyOtp/VerifyOtpCommandHandler.php), [aggregate](../app/src/User/Domain/OtpChallenge/OtpChallenge.php), [CommandBus](../app/src/SharedKernel/Infrastructure/CommandBus/CommandBus.php), [HTTP controller](../app/src/User/Ui/Http/Api/OtpAuthController.php), and [domain tests](../app/tests/User/Domain/OtpChallenge/OtpChallengeTest.php).

Verification: [Behat scenarios](../app/tests/Behat/features/user/user_registration.feature) assert persisted counters after each failed request, refusal after exhaustion, success before exhaustion, expiry, already-consumed challenges, and the unchanged HTTP response. All five required quality gates passed.

---

## 4. Client membership uniqueness in Domain

Status: `DONE`

### Why

Previously, both `ProvisionClientMemberCommandHandler` and `CreateClientMemberCommandHandler` checked for an existing membership and threw `ClientMemberAlreadyExistsException`. The shared factory now owns this validation, as required by architecture §6.

### Scope

- Add `membershipExists(clientId, userId)` to the existing `ClientMemberOutsideInterface`.
- Implement it in `ClientMemberOutside` by delegating to the existing `ClientMemberQueryInterface::findByClientAndUser()` and returning whether a DTO was found. Reuse that DBAL query; do not add SQL or an extra lookup in the handler.
- Move the uniqueness check and existing exception into `ClientMemberFactory`, before constructing the member and recording its creation event.
- Remove the duplicate checks from both creation handlers. Keep user provisioning in Application through its existing port.
- Preserve the unique database index on `(client_id, user_id)` as protection against concurrent duplicate inserts.

### Done when

- Factory tests cover creation when absent and refusal when present, with no creation event on refusal. Update the test Outside accordingly.
- Integration/Behat coverage verifies duplicate refusal through both creation flows and preserves their current HTTP behavior.
- Both handlers use the shared domain validation, with one membership lookup through Outside per creation attempt.
- Required quality gates pass in the order specified by `instructions-for-agents.md`.

### Notes

Reuse the existing factory, Outside, query and exception. No separate policy or cross-BC dependency is needed for the membership read. Apply architecture §5.2, §6–6.1 and §11.1; retain the existing User provisioning boundary from §4.2.

Implementation evidence: [provision handler](../app/src/Client/Application/ClientMember/Command/ProvisionClientMember/ProvisionClientMemberCommandHandler.php), [create handler](../app/src/Client/Application/ClientMember/Command/CreateClientMember/CreateClientMemberCommandHandler.php), [factory](../app/src/Client/Domain/ClientMember/Factory/ClientMemberFactory.php), [membership query](../app/src/Client/Infrastructure/ClientMember/ClientMemberQuery.php), and [unique-index migration](../app/src/Client/Infrastructure/Resource/Migrations/Version20260206120000.php).

Verification: [factory tests](../app/tests/Client/Domain/ClientMember/ClientMemberFactoryTest.php) prove rejection before construction and event recording. [Integration tests](../app/tests/Client/Infrastructure/ClientMember/ClientMemberCreationIntegrationTest.php) cover both creation flows with one membership lookup, including active and suspended duplicates. [Behat scenarios](../app/tests/Behat/features/client_member/client_member_management.feature) preserve HTTP 409 and its error payload without changing the existing membership. The database unique index is unchanged. All five quality gates passed sequentially (186 PHPUnit tests, 31 Behat scenarios).

---

## 5. Mermaid architecture flow

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

## 6. README first screen polish

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

## 7. Architecture Decision Records

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

## 8. Repository hygiene

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

## 9. Dependabot

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
