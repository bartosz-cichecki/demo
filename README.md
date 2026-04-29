# Pragmatic Software Engineering Demo

This repository is a public demo of pragmatic, modern software engineering with AI-assisted development. It was created as a publishable side effect of a larger commercial project that cannot be open sourced.

The goal is not to present Domain-Driven Design as an end in itself. The goal is to show how clear architecture, testable business flows, quality gates, and disciplined boundaries make software easier to build, review, and extend with both humans and AI coding agents.

Large parts of this codebase were produced with AI support and natural-language instructions. The repository demonstrates the practical lesson behind that workflow: LLMs can be a strong productivity lever, but they raise the bar for architectural clarity, tests, and engineering rigor.

The architectural rules are part of the demo, not an afterthought.

## AI-assisted development

Natural language is treated here as a higher-level interface for software delivery: requirements, constraints, architecture rules, test expectations, and implementation steps can be expressed in a form that is readable by both engineers and coding agents.

Most of the implementation was built with AI assistance, but the AI was not treated as a replacement for engineering judgment. The useful pattern is:

- clear boundaries before generation,
- small and reviewable changes,
- behavior covered by tests,
- quality gates that can reject incorrect output,
- architecture rules that are explicit enough for a human or agent to follow.

In this model, AI helps deliver faster. It does not remove the need for design discipline, automated checks, or careful review.

## Vendor-agnostic engineering

This project is intentionally not tied to a specific LLM, coding agent, or vendor workflow. The repository should be understandable and approachable for a human engineer and for an AI agent from any vendor.

That requires written rules, predictable structure, stable commands, and enforced boundaries. **The architecture guide defines those rules:** [docs/architecture.en.md](docs/architecture.en.md).

The same constraints that make the project easier to onboard for people also make it easier for agents to work safely: explicit layers, bounded contexts, clear test strategy, and repeatable quality gates.

## Engineering approach

The demo uses DDD and related patterns as engineering tools, not as branding:

- **Bounded contexts**: SharedKernel, User (OTP auth), Client (tenant/membership management)
- **Domain rules in the domain**: aggregates protect invariants and expose behavior
- **Outside pattern**: domain reads external state without side effects through Outside interfaces
- **CQRS-lite**: Commands for writes, DBAL Queries for reads, no ORM on the read side
- **Quality gates**: cs-check, phpstan, deptrac-ci, phpunit, behat
- **UTC clock**: shared ClockInterface with SystemClock/MutableClock and UTC-normalized DateTime storage
- **Async outbox**: IntegrationEvent -> Postgres outbox -> worker -> async subscriber, with idempotent consumption

## Dev setup

Start the environment:

```bash
make up-build
```

Run a smoke check:

```bash
make smoke
```

Common commands:

```bash
make up             # start without rebuilding
make down           # stop containers
make qa             # style + phpstan + deptrac
make test           # PHPUnit
make behat          # end-to-end acceptance tests
make cs-check       # code style dry run
make phpstan        # static analysis
make deptrac-ci     # architecture boundaries
```

Health endpoints are intentionally simple: `GET /health` checks nginx, and `GET /api/health` checks Symfony through PHP-FPM. Both are covered by `make smoke`.

## Business capabilities behind the demo

The underlying aggregates are small, but they model real business responsibilities rather than only demonstrating patterns.

### `Client`

- **Protects**: name is non-empty and <= 120 chars; an inactive client cannot be renamed or described.
- **Enables**: creating and managing isolated tenant workspaces in a multi-tenant SaaS.

### `ClientMember`

- **Protects**: only `admin` and `user` roles are valid; a suspended member's status cannot skip directly to active without an explicit unsuspend.
- **Enables**: per-tenant role-based access control: provision members, change roles, suspend/unsuspend access.

### `User`

- **Protects**: email format; a blocked user cannot accidentally be left in an inconsistent state because block requires a non-empty reason.
- **Enables**: cross-tenant identity: one user account can belong to multiple client tenants.

### `OtpChallenge`

- **Protects**: code is stored only as a hash; challenges expire after 10 minutes and can only be consumed once; brute-force is limited by a max-attempts check.
- **Enables**: passwordless authentication: users log in by verifying a one-time code sent to their email.

## Key flows

The main flows are backed by Behat scenarios, so they document behavior and protect it from regressions.

### 1. Platform admin creates a client

`app/tests/Behat/features/platform/client_create.feature`

Actors: platform admin

User exists -> admin authenticates as platform admin -> POST create-client "Acme Corporation" -> 201 -> client persisted

Outcome: a new tenant workspace is ready for membership provisioning.

### 2. OTP login: happy path

`app/tests/Behat/features/user/user_registration.feature` - "OTP happy path logs user in and creates session"

Actors: existing user with a membership

POST OTP request -> POST OTP verify with correct code -> `ok: true` -> challenge consumed -> session holds user ID and active client ID

Outcome: user is logged in through a passwordless flow; active tenant context is set.

### 3. Admin provisions a new member

`app/tests/Behat/features/client_member/client_member_management.feature` - "admin provisions a new member by email"

Actors: tenant admin

Admin logged in -> POST provision-member with new email -> membership created with role `user`, status `active` -> client now has 2 members

Outcome: new user gets access to the tenant workspace with the default `user` role.

### 4. Admin suspends and unsuspends a member

`app/tests/Behat/features/client_member/client_member_management.feature` - "admin can suspend and unsuspend"

Actors: tenant admin, tenant member

Admin suspends member -> status `suspended` -> admin unsuspends -> status `active`

Outcome: access toggled without deleting the membership; member record is preserved.

### 5. Tenant user is denied platform-level actions

`app/tests/Behat/features/tenant/client_create_forbidden.feature`

Actors: tenant user (non-platform)

Tenant member logged in -> POST create-client -> 403

Outcome: platform-admin operations are fully gated from tenant users.

### 6. User registration publishes an async notification

`app/tests/Behat/features/user/user_registration.feature` - "Registered user notification is processed asynchronously"

Actors: system

User is registered -> `UserRegistered` domain event -> saga publishes `UserRegisteredIntegrationEvent` -> outbox stores event -> worker processes integration events -> file notification is written once

Outcome: demo shows a full async flow without a broker: domain event, integration event, outbox worker, async subscriber, and idempotent side effect.
