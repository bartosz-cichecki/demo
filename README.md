# Multi-tenant B2B SaaS Backend Demo

## What this is

A PHP/Symfony backend core for multi-tenant B2B SaaS: passwordless OTP login, tenant workspaces, membership by invitation and consent, separate platform and tenant access, and asynchronous notifications between bounded contexts through a PostgreSQL outbox ([platform guide](docs/platform.md)). It was created as a publishable side effect of a larger commercial project that cannot be open sourced.

## What this proves

- **Atomic writes:** state changes, EventLog and outbox share one transaction — [CommandBus](app/src/SharedKernel/Infrastructure/CommandBus/CommandBus.php), [onboarding rollback test](app/tests/Client/Application/Client/OnboardClientIntegrationTest.php).
- **At-least-once async consumption:** claim-before-side-effect, leases and ownership checks — [ProcessOutboxCommand](app/src/SharedKernel/Ui/ConsoleCommands/ProcessOutboxCommand.php), [worker tests](app/tests/SharedKernel/Ui/ConsoleCommands/ProcessOutboxCommandTest.php).
- **Serialized state transitions:** pessimistic row locks protect invitation mutations — [repository](app/src/Client/Infrastructure/ClientInvitation/ClientInvitationRepository.php), [concurrency integration test](app/tests/Client/Infrastructure/ClientInvitation/ClientInvitationConcurrencyIntegrationTest.php).
- **Enforced context boundaries:** dependency rules reject forbidden imports — [Deptrac configuration](app/deptrac.php), [boundary tests](app/tests/Architecture/BoundedContextDependenciesTest.php).
- **Executable business documentation:** onboarding and invitation consent run through HTTP in [onboarding scenarios](app/tests/Behat/features/platform/client_onboarding.feature) and [invitation scenarios](app/tests/Behat/features/client_invitation/client_invitation.feature).

These are the same correctness problems found in payment processing: atomic state changes plus events, idempotent consumers and concurrent state transitions; the [notification redelivery test](app/tests/Client/Application/ClientInvitation/ClientInvitationNotificationIntegrationTest.php) checks the demo's consumer idempotency.

## Quick start

With Docker Compose and Make available, run from the repository root:

```bash
make up-build
make install
make smoke
make demo
```

`make demo` prepares the test database and runs onboarding and invitation Behat scenarios with readable step-by-step output; fixtures and assertions make the business flow repeatable ([Makefile](Makefile)).

## Architecture flow

One real request, a client admin inviting a person, traced from HTTP through a single `CommandBus` transaction to the asynchronous notification in another bounded context.

<a href="https://raw.githubusercontent.com/bartosz-cichecki/demo/main/docs/architecture-flow/architecture-flow-light.svg">
<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/architecture-flow/architecture-flow-dark.svg">
  <img alt="Architecture flow: HTTP → validated Input → Command → CommandBus transaction (handler; domain records events into DomainEventsBuffer; one ORM flush; for each buffered event EventLog.save and EventBus.dispatch; ClientInvitationSaga writes IntegrationEvent to Outbox; COMMIT) → worker app:process-outbox polls committed rows → User subscriber → Notification port" src="docs/architecture-flow/architecture-flow-light.svg" width="1520">
</picture>
</a>

## Reviewer's tour (10 minutes)

1. [Client onboarding scenario](app/tests/Behat/features/platform/client_onboarding.feature) — follow workspace creation, notification, OTP login and consent to become an admin.
2. [CommandBus](app/src/SharedKernel/Infrastructure/CommandBus/CommandBus.php) and [rollback test](app/tests/Client/Application/Client/OnboardClientIntegrationTest.php) — trace the single flush, EventLog, outbox and shared rollback boundary.
3. [ClientInvitation](app/src/Client/Domain/ClientInvitation/ClientInvitation.php) and [locking test](app/tests/Client/Infrastructure/ClientInvitation/ClientInvitationConcurrencyIntegrationTest.php) — inspect allowed transitions and a lock held until the transaction ends.
4. [Outbox worker](app/src/SharedKernel/Ui/ConsoleCommands/ProcessOutboxCommand.php) and [tests](app/tests/SharedKernel/Ui/ConsoleCommands/ProcessOutboxCommandTest.php) — inspect claims, retry, lease expiry and ownership loss before acknowledgement.
5. [Deptrac configuration](app/deptrac.php) and [boundary tests](app/tests/Architecture/BoundedContextDependenciesTest.php) — see permitted cross-context contracts and rejected dependency probes.

## Business capabilities

The aggregates model concrete business responsibilities:

- **[Client](app/src/Client/Domain/Client/Client.php)** represents a tenant workspace, validates its name and prevents changes to its name or description while inactive.
- **[ClientMember](app/src/Client/Domain/ClientMember/ClientMember.php)** controls a user's roles and access within a tenant, allowing suspension and restoration without deleting the membership.
- **[ClientInvitation](app/src/Client/Domain/ClientInvitation/ClientInvitation.php)** makes membership depend on the invitee's consent and prevents duplicate pending invitations or inviting an existing member.
- **[User](app/src/User/Domain/User/User.php)** provides one identity across tenants and requires an explicit reason when blocking an account.
- **[OtpChallenge](app/src/User/Domain/OtpChallenge/OtpChallenge.php)** enables passwordless login with expiring, single-use codes and a limit on verification attempts.

## Key flows

Each flow is backed by executable Behat scenarios:

- **Client onboarding:** platform admin creates a workspace and invites its first administrator → invitee logs in and accepts → admin membership is granted — [client_onboarding.feature](app/tests/Behat/features/platform/client_onboarding.feature).
- **OTP login:** request a code → read it from the demo mailbox → verify it → authenticated session with no active client — [user_registration.feature](app/tests/Behat/features/user/user_registration.feature).
- **Client selection:** list active memberships → choose or switch the active tenant → access its resources — [active_client_selection.feature](app/tests/Behat/features/user/active_client_selection.feature).
- **Membership by invitation:** tenant admin invites → asynchronous notification → OTP login → acceptance creates membership and selects the client — [client_invitation.feature](app/tests/Behat/features/client_invitation/client_invitation.feature).
- **Membership suspension:** admin suspends another admin → member listing returns 403 → unsuspending restores listing (200), with the membership still present — [client_member_management.feature](app/tests/Behat/features/client_member/client_member_management.feature).
- **Platform isolation:** a tenant user attempts to create a client → access is denied — [client_create_forbidden.feature](app/tests/Behat/features/tenant/client_create_forbidden.feature).

Full HTTP contracts, session behavior and runtime details are documented in the [platform guide](docs/platform.md).

## Engineering approach

The demo uses DDD and related patterns as engineering tools, not as branding:

- **[Bounded contexts](app/deptrac.php)**: User (OTP auth), Client (tenant/membership management); SharedKernel provides shared mechanisms
- **[Domain rules in the domain](app/src/Client/Domain/ClientInvitation/ClientInvitation.php)**: aggregates protect invariants and expose behavior
- **[Outside pattern](docs/architecture.md#6-outside-pattern-hard-rule)**: domain reads external state without side effects through Outside interfaces
- **[CQRS-lite](docs/architecture.md#5-cqrs-lite-team-contract)**: Commands for writes, DBAL Queries for reads, no ORM on the read side
- **[Quality gates](docs/instructions-for-agents.md#5-quality-gates)**: cs-check, phpstan, deptrac-ci, phpunit, behat
- **[UTC clock](docs/architecture.md#62-time-in-the-domain-hard-rule)**: shared ClockInterface with SystemClock/MutableClock and UTC-normalized DateTime storage
- **[Async outbox](docs/platform.md#44-outbox-runtime)**: IntegrationEvent -> Postgres outbox -> worker -> async subscriber, with idempotent consumption

## AI-assisted development and vendor independence

Most of the implementation was built with AI assistance, with humans responsible for scope, design decisions and review. Requirements, constraints and test expectations are written for both engineers and coding agents; the workflow does not depend on a particular model or vendor.

[Backlog item 5.2: membership invitations](docs/backlog.md#52-client-membership-invitations) provides a concrete example: written scope and DONE criteria guide agent implementation, followed by sequential quality gates. Its dated completion note records the results and links to the aggregate, saga, subscriber and tests. The reusable input structure is the existing [prompt template](docs/instructions-for-agents.md#7-prompt-template).

The documents are working instructions for people and agents: [architecture rules](docs/architecture.md) ([Polish translation](docs/architecture.pl.md)) are enforced by [dependency tests](app/tests/Architecture/BoundedContextDependenciesTest.php) and [behavior tests](app/tests/), while the [platform guide](docs/platform.md) describes current contracts.

## Dev setup

Start the environment and install Composer dependencies:

```bash
make up-build
make install
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
make demo           # onboarding + invitations, readable Behat output
make help           # all available commands
make cs-check       # code style dry run
make phpstan        # static analysis
make deptrac-ci     # architecture boundaries
```

`make smoke` checks the [platform health endpoints](docs/platform.md#1-scope-and-responsibilities).

Contributor workflow, quality gates and migration commands: [contributor instructions](docs/instructions-for-agents.md).
