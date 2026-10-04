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
- **Enables**: per-tenant role-based access control: members join by accepting an invitation; admins change roles and suspend/unsuspend access.

### `ClientInvitation`

- **Protects**: at most one pending invitation per client and email; no invitation for a person who already has a membership, active or suspended; a client admin invites and revokes only with role `user`; only the invited email can accept or reject; transitions happen only from `pending`. Concurrent transitions are serialized by a pessimistic row lock held until the `CommandBus` transaction commits or rolls back; the next request sees the committed state and the domain rule decides whether the transition is allowed. Platform revoke returns 404 when no pending invitation remains.
- **Enables**: membership with the invitee's consent; accepting creates the membership and the invitation status change atomically.

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

Outcome: a new tenant workspace is ready for membership invitations.

### 2. OTP login: happy path

`app/tests/Behat/features/user/user_registration.feature` - "OTP happy path logs user in and creates session"

Actors: existing user with a membership

POST OTP request -> read the code from the demo mailbox -> POST OTP verify with that code -> `ok: true` -> challenge consumed -> session holds user ID and no active client -> POST select active client -> session holds active client ID

Outcome: user is logged in through a passwordless flow and explicitly chooses the active tenant context.

For local delivery, each allowed request appends a JSON line with `email` and `code` to `app/var/notifications/otp.jsonl` (inside the container: `/var/www/app/var/notifications/otp.jsonl`). Read the latest message for the recipient and send its code to `POST /api/auth/otp/verify` as `{"email":"user@example.com","code":"123456"}`; use the actual delivered code. The request endpoint is `POST /api/auth/otp/request` with `{"email":"user@example.com"}`. Preserve cookies between requests to keep the login session. After login, `GET /api/me/clients` lists the user's active memberships and `POST /api/session/active-client` with `{"clientId":"..."}` selects or switches the active client; tenant routes return `403 {"error":"active_client_required"}` until one is selected.

The factory enforces a 60-second cooldown independently for email and IP. Blocked requests still return `200 {"ok":true}` and write neither a challenge nor a message. Tests use a separate mailbox, `app/var/notifications/otp.test.jsonl`. File delivery happens before the database commit; a later commit failure does not undo the message in this local demo.

### 3. Admin invites a person who accepts after OTP login

`app/tests/Behat/features/client_invitation/client_invitation.feature` - "Invitee is notified asynchronously, logs in with OTP, accepts and works in the client"

Actors: tenant admin, invited person, outbox worker

Admin `POST /api/clients/{clientId}/invitations` with email and role `user` -> 201, invitation `pending`, no user account is created -> `ClientInvitationCreated` domain event -> Client saga publishes `ClientInvitationCreatedIntegrationEvent` to the outbox in the same transaction -> worker (`app:process-outbox`) runs User's async subscriber -> one notification "you were invited to X, log in to respond" in `app/var/notifications/user_notifications.jsonl` -> invitee logs in with OTP (the account is created here) -> `GET /api/me/invitations` -> `POST /api/invitations/{id}/accept` -> invitation `accepted` and membership with role `user` in one transaction -> after commit the session's active client is the new client

Outcome: membership requires consent, and the demo shows a real cross-BC async flow (Client -> integration event -> User) with a notification delivered exactly once even when the worker runs repeatedly.

The same feature covers rejecting, revoking by the admin, a duplicate pending invitation, inviting an active or suspended member (409), acting on someone else's invitation (404), inviting without the admin role and inviting with role `admin` as a client admin (403). `app/tests/Client/Infrastructure/ClientInvitation/ClientInvitationConcurrencyIntegrationTest.php` proves that each repository read used for a transition holds a row lock: a separate connection receives PostgreSQL SQLSTATE `55P03` from `FOR UPDATE NOWAIT` until the first transaction ends. Domain tests cover refused transitions from every terminal state; Behat covers the HTTP contracts, including platform revoke returning 404 when no pending invitation exists.

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
