# DDD Demo

A demonstration of Domain-Driven Design patterns: Outside pattern, CQRS-lite, Behat conventions, and quality gates.

## What this demo shows

- **Bounded contexts**: SharedKernel, User (OTP auth), Client (tenant/membership management)
- **Outside pattern**: domain reads external state without side-effects via Outside interfaces
- **CQRS-lite**: Commands for writes, DBAL Queries for reads (no ORM on the read side)
- **Quality gates**: cs-check, phpstan, deptrac-ci, phpunit, behat
- **UTC clock**: shared ClockInterface with SystemClock/MutableClock and UTC-normalized DateTime storage
- **Async outbox**: IntegrationEvent → Postgres outbox → worker → async subscriber, with idempotent consumption

## Dev (Docker + Symfony)

### Start (build if needed)
```bash
make up-build
```

### Start (no rebuild)
```bash
make up
```

### Stop
```bash
make down
```

### Smoke tests
```bash
make smoke
```

### Quality gates
```bash
make cs-check       # code style (dry-run)
make phpstan        # static analysis (level max)
make deptrac-ci     # architecture boundaries
make test           # PHPUnit
make behat          # end-to-end acceptance tests
```

Run all at once (style + phpstan + deptrac):
```bash
make qa
```

### Health endpoints
- `GET /health` — nginx only
- `GET /api/health` — Symfony via PHP-FPM

Both are checked by `make smoke` (and `make health` on its own).

---

## Aggregates (what you can build with them)

### `Client`
- **Protects**: name is non-empty and ≤ 120 chars; an inactive client cannot be renamed or described.
- **Enables**: creating and managing isolated tenant workspaces in a multi-tenant SaaS.

### `ClientMember`
- **Protects**: only `admin` and `user` roles are valid; a suspended member's status cannot skip directly to active without an explicit unsuspend.
- **Enables**: per-tenant role-based access control — provision members, change roles, suspend/unsuspend access.

### `User`
- **Protects**: email format; a blocked user cannot accidentally be left in an inconsistent state (block requires a non-empty reason).
- **Enables**: cross-tenant identity — one user account can belong to multiple client tenants.

### `OtpChallenge`
- **Protects**: code is stored only as a hash; challenges expire after 10 minutes and can only be consumed once; brute-force is limited by a max-attempts check.
- **Enables**: passwordless authentication — users log in by verifying a one-time code sent to their email.

---

## Key flows (backed by Behat)

### 1. Platform admin creates a client
`app/tests/Behat/features/platform/client_create.feature`

Actors: platform admin

User exists → admin authenticates as platform admin → POST create-client "Acme Corporation" → 201 → client persisted

Outcome: a new tenant workspace is ready for membership provisioning.

---

### 2. OTP login — happy path
`app/tests/Behat/features/user/user_registration.feature` — *"OTP happy path logs user in and creates session"*

Actors: existing user with a membership

POST OTP request → POST OTP verify (correct code) → `ok: true` → challenge consumed → session holds user ID + active client ID

Outcome: user is logged in via passwordless flow; active tenant context is set.

---

### 3. Admin provisions a new member
`app/tests/Behat/features/client_member/client_member_management.feature` — *"admin provisions a new member by email"*

Actors: tenant admin

Admin logged in → POST provision-member with new email → membership created with role `user`, status `active` → client now has 2 members

Outcome: new user gets access to the tenant workspace with the default `user` role.

---

### 4. Admin suspends and unsuspends a member
`app/tests/Behat/features/client_member/client_member_management.feature` — *"admin can suspend and unsuspend"*

Actors: tenant admin, tenant member

Admin suspends member → status `suspended` → admin unsuspends → status `active`

Outcome: access toggled without deleting the membership; member record is preserved.

---

### 5. Tenant user is denied platform-level actions
`app/tests/Behat/features/tenant/client_create_forbidden.feature`

Actors: tenant user (non-platform)

Tenant member logged in → POST create-client → 403

Outcome: platform-admin operations are fully gated from tenant users.

---

### 6. User registration publishes an async notification

`app/tests/Behat/features/user/user_registration.feature` — *"Registered user notification is processed asynchronously"*

Actors: system

User is registered → `UserRegistered` domain event → saga publishes `UserRegisteredIntegrationEvent` → outbox stores event → worker processes integration events → file notification is written once

Outcome: demo shows a full async flow without a broker: domain event, integration event, outbox worker, async subscriber and idempotent side effect.
