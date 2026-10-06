# Architecture

This is a translation. The canonical source of truth is the Polish version: [architecture.md](architecture.md).

## 1. Purpose and priorities
- KISS over "clever".
- Clear layer separation and dependency direction enforced by tools (Deptrac).
- CQRS-lite: reads and writes are separated technically and semantically.
- Stable internal contracts (events/commands/queries) without versioning (MVP).
- One obvious transaction/flush point.
- Convention over configuration (minimum manual DI entries).

## 2. Context structure (Bounded Context)
Application sources are in `app/`.

Standard layout:
- `app/src/{BC}/Domain`
- `app/src/{BC}/Application`
- `app/src/{BC}/Infrastructure`
- `app/src/{BC}/Ui`
  - HTTP API: `app/src/{BC}/Ui/Http/...`
  - CLI: `app/src/{BC}/Ui/ConsoleCommands/...`

Production class autoloading uses PSR-4 `App\\` -> `app/src/`. The current business contexts are `Client` and `User`; shared mechanisms belong to `SharedKernel`.

SharedKernel:
- `app/src/SharedKernel/...` contains shared mechanisms (for example Clock, EventLog, CommandBus, DomainEventsRecorder/Collector, integration events, outbox, and async worker).

## 3. Directory structure (template)
```
app/src/{BoundedContext}/
├── Application/
│   └── {Aggregate}/
│       ├── Command/
│       │   └── {Action}/
│       │       ├── {Action}Command.php
│       │       └── {Action}CommandHandler.php
│       └── Query/
│           ├── {Aggregate}QueryInterface.php
│           └── Dto/
│               └── {Aggregate}Dto.php
├── Domain/
│   └── {Aggregate}/
│       ├── {Aggregate}.php
│       ├── Event/
│       │   └── {Aggregate}{Verb}.php
│       ├── Factory/
│       │   ├── {Aggregate}FactoryInterface.php
│       │   └── {Aggregate}Factory.php
│       ├── Outside/
│       │   └── {Aggregate}OutsideInterface.php
│       └── Repository/
│           ├── {Aggregate}RepositoryInterface.php
│           └── Exception/
│               └── {Aggregate}DoesNotExistException.php
├── Infrastructure/
│   ├── {Aggregate}/
│   │   ├── {Aggregate}Outside.php
│   │   ├── {Aggregate}Query.php
│   │   └── {Aggregate}Repository.php
│   └── Resource/
│       ├── config.yaml
│       └── Migrations/
│           └── Version{timestamp}.php
└── Ui/
    ├── Http/
    │   └── Api/
    │       └── {Aggregate}Controller.php
    ├── Input/
    │   └── {Action}Input.php
    └── ConsoleCommands/
        └── {Action}{Aggregate}Command.php
```

## 4. Layers and dependency rules (hard rules)
- Domain:
  - does not know Infrastructure or Ui
  - allowed dependencies: `SharedKernel\Domain` and the technical minimum required for mapping (according to Deptrac)
- Application:
  - orchestrates use cases
  - knows Domain and contracts (interfaces), does not know Infrastructure details
  - does not know Outside
- Infrastructure:
  - implements interfaces from Domain/Application (repository, query, outside)
  - contains DBAL/ORM, integrations, subscribers, transports
- Ui:
  - input adapters (HTTP/CLI), validation, Input -> Command mapping, response (HTTP)

Deptrac (`app/deptrac.php`) enforces dependency direction and the boundaries of all BCs. SharedKernel is not a BC and retains separate Clock/Core/ValueObject/Application/Infrastructure/Ui rules. Clock may depend on ValueObject, Core on Clock, and ValueObject on Core and the UUID library. SharedKernel/Application may use business contexts' Application, public contracts and Domain, but not Outside (§6). SharedKernel/Infrastructure additionally has access to their Outside and Infrastructure, while SharedKernel/Ui has access to Outside and Ui.

Cross-BC contract (A and B are different business contexts):
- Sync: `Infrastructure A -> QueryInterface, Command or DTO B` is allowed.
- Async: `Application/IntegrationEventSubscriber A -> IntegrationEvent B` is allowed. This is the only cross-BC exception for Application; subscribers retain their own permitted Application dependencies, including the prohibition on Outside access.
- A's `Domain`, ordinary `Application` and `Ui` do not import any class or interface from B, including `IntegrationEvent`. For sync communication, the consumer defines its own port; only the Infrastructure adapter knows the foreign contract.
- `Infrastructure A -> repository (including interfaces), handler, implementation, service or IntegrationEvent B` is forbidden. Subscribers receive no access to foreign sync contracts or other foreign BC classes.
- A foreign service interface is not automatically a public contract. It requires an existing, specifically justified exception: a named consumer and interface, a rationale and a test. There are currently no such exceptions; `ValueHasherServiceInterface` and `UserNotificationSenderServiceInterface` are used only within User.

Public sync contracts use the namespace `App\{BC}\Application\{module}\…\Query\*QueryInterface`, `…\Command\**\*Command` or `…\Query\Dto\*Dto`. There is at least one module/aggregate segment below Application; QueryInterface lives directly in Query, and DTO directly in Query/Dto. `**` below Command means zero or more segments: a Command can live directly in Command or in a use-case subnamespace. The Command directory does not expose handlers, and an `Interface` suffix does not expose repositories or services.

Public async contracts are `App\{BC}\Application\IntegrationEvent\**\*IntegrationEvent`, and their cross-BC consumers are `App\{BC}\Application\IntegrationEventSubscriber\*Subscriber`. For events, `**` means zero or more segments. A subscriber must live directly in `Application/IntegrationEventSubscriber`, matching the flat DI registration convention (§8.1 and §9.2); a subscriber in a subnamespace does not receive the cross-BC exception. Both the namespace and the class name suffix must match. A helper in IntegrationEvent or a Subscriber outside IntegrationEventSubscriber gains no public access. Sync contracts, integration events and subscribers are separated from ordinary Application. Outside remains separated from Domain so that even the same BC's Application and subscribers cannot use it (§6).

Every first-level directory `app/src/{BC}/`, except `SharedKernel`, is automatically covered by the same contract. A new BC following the standard structure (§2–3) requires no additional layers, exceptions between pairs of contexts or registry entries. Files directly in `app/src/`, such as `Kernel.php`, are not BCs.

### 4.1 Reading data from another context (ACL)
- A context never writes raw SQL/DBAL to tables it does not own.
- If context A needs data from context B, it defines its own read port (for Domain: its own Outside). The port's adapter in Infrastructure A uses context B's public `QueryInterface`. Domain never references the foreign Query directly.
- Context A defines its own DTO and maps data from context B's DTO. It does not re-export foreign DTOs above the Infrastructure layer (Anti-Corruption Layer).
- Benefit of the modular monolith: the dependency is compile-time, without serialization or network calls, while context boundaries are explicit in namespaces and adapters.
- Example: `User/Infrastructure/Tenant/ActiveMembershipsQuery` implements its own `ActiveMembershipsQueryInterface`, reads through Client's `ClientMemberQueryInterface` and `ClientQueryInterface` and maps `ClientMemberDto` together with the name from `ClientDto` to its own `ActiveMembershipDto`. Deptrac checks dependency boundaries; SQL table ownership and the semantic correctness of mapping still require review.
- Example in the other direction: `Client/Infrastructure/UserAccount/UserAccountQuery` implements Client's own `UserAccountQueryInterface`, reads through User's `UserQueryInterface` and returns `Email`/`Id` values instead of `UserDto`. It is used by `ClientInvitationOutside` (the acting user's email and whether the person with a given email has a membership) and by the logged-in user's invitation list.

### 4.2 A cross-BC write use case
- If a use case in context A must initiate a write owned by context B, context A's Application layer depends on its own port.
- The port implementation lives in context A's Infrastructure layer. The adapter may invoke context B's public Command through `CommandBus` and read the result through context B's public `QueryInterface`.
- In sync communication, the consuming context's Domain, Application and Ui do not import any classes from another BC. Details of the foreign contract remain in the Infrastructure adapter. The separate async exception applies only to integration event subscribers (§8.1).
- The adapter does not invoke a foreign handler or repository.
- Currently no use case writes synchronously in a foreign BC. Client owns memberships and invitations, User owns users. An invitation does not create an account: accounts are created only by OTP login (`LogInUserByEmailCommand`), and User sends the invitation notification asynchronously (§8.1).

## 5. CQRS-lite (team contract)

### 5.1 Write side (Commands)
- A state change of domain entities is always a Command.
- ORM (Doctrine) is used for aggregate state changes (persist/flush through one point).
- Command:
  - immutable DTO
  - no logic
- Handler:
  - orchestrates
  - uses Factory/Repository
  - does not record domain events (the aggregate does this through Outside)
- Command handlers return `void` by default and are invoked through `CommandBus::dispatch()`.
- When the current operation must return a minimal business result, a Command may implement `CommandWithResultInterface<TResult>` and be invoked through `CommandBus::dispatchWithResult()`.
- The result from `dispatchWithResult()` is returned only after a successful flush, event handling, and commit.
- A Command result must not be an aggregate, entity, or read model.

### 5.2 Read side (Queries)
- Data reads are always DBAL/SQL (no ORM for queries).
- Query returns DTOs (never entities).
- Query has no side effects.

### 5.3 Technical exceptions
- Single DBAL/SQL "write" operations outside ORM are allowed for technical concerns (for example `EventLogInterface::save` in SharedKernel).
- This is an exception, not the norm in business contexts.

## 6. Outside pattern (hard rule)
- Outside is a dependency of the Domain layer: aggregates, factories, and domain services (Domain Services / Policy).
- Application does not know Outside and does not inject it. Handlers never depend directly on OutsideInterface.
- Outside is a **side-effect-free window to the world**. It lets the domain query external state (time, permission state, value hashes, counters, limits, etc.) while preserving business knowledge encapsulation. The domain decides when and how to use this data.
- Domain:
  - takes time from `{Aggregate}OutsideInterface::now()`
  - records events through `{Aggregate}OutsideInterface::record(DomainEvent $event)`
  - queries cross-BC state through its own Outside; only the Infrastructure adapter knows `{OtherContext}QueryInterface`, and it never modifies foreign aggregates
  - queries read-only state inside the BC (for example `count{AggregateItems}()`)
- Infrastructure provides the Outside implementation, which delegates to SharedKernel mechanisms (for example `ClockInterface`, `DomainEventsRecorder`) and to queries from other BCs.
- Consequence: business validations live in the aggregate/factory/policy, not in the handler. The handler is pure orchestration.

### 6.1 Policy as a Domain Service
- Policy is a form of Domain Service. The name does not change its nature.
- Prefer a "pure policy" when the caller already has the required domain data, but treat this as a preference, not dogma.
- If a policy needs external read-only state, it may use Outside or another shared domain read-only mechanism (for example `SharedKernel\Domain\Clock\ClockInterface`). Do not create a wrapper and do not push data through the caller only to make the policy look "pure".
- Handler/Application passes domain input (for example `{aggregateId}`, `{newCount}`), and the decision and rule live in Domain.
- If a policy makes a decision based on the current time, it may use a domain read-only dependency (`Outside` or `ClockInterface`) instead of receiving `now` as a technical argument from the caller.
- Example:
  ```
  handler: policy.assertCanAddItems(aggregateId, newCount)
  policy:  currentCount = outside.countItems(aggregateId)
           assert currentCount + newCount <= limit
  ```

### 6.2 Time in the domain (hard rule)

- All timestamps created in the domain (for example `createdAt`, `updatedAt`, `statusChangedAt`, `occurredAt`) are determined inside the domain based on `{Aggregate}OutsideInterface::now()` or the domain `ClockInterface` in a policy/domain service without its own Outside.
- Production implementations of `{Aggregate}OutsideInterface::now()` delegate to `ClockInterface`; they do not create their own time through local `DateTime::now()`.
- Application/Ui does not pass time into aggregates/factories only to "inject now" (so we do not add parameters like `DateTime $now`, `DateTime $createdAt` as a technical workaround).
- Rationale: time is part of domain rules (creation/change moment), and time control in tests is done through `FakeOutside` with deterministic `now()` or through `MutableClock`.
- `ClockInterface` returns `SharedKernel\Domain\ValueObject\DateTime`. The production implementation is `SystemClock`; the test implementation is `MutableClock`.
- `DateTime::now()` always creates a UTC value. The `DateTime` constructor normalizes input `DateTimeImmutable` to UTC.
- `DateTime::fromStorageString()` interprets the storage string as UTC, and `DateTime::toStorageString()` writes the `Y-m-d H:i:s` format in UTC.
- The Doctrine type `domain_datetime` normalizes reads and writes to a UTC storage string.
- The PHP runtime in the container has `date.timezone=UTC`.
- Backend and database treat timestamps as UTC. This also applies to `TIMESTAMP WITHOUT TIME ZONE` columns, which in the MVP mean "UTC wall time"; the application must explicitly normalize writes and reads to UTC.
- Technical infrastructure timestamps are also UTC: EventLog, outbox publisher, and worker use `ClockInterface` and write UTC storage strings.
- PostgreSQL is not the source of local application time. Current production timestamps must come from the application clock or explicit UTC in infrastructure.
- User timezone is not stored in the DB in the MVP. The backend does not maintain user timezone preferences and does not convert timestamps to the local timezone in the domain model or read models.
- Local time presentation is the responsibility of the UI/browser.
- Date ranges that are part of **explicit business input** are passed as domain values and validated in the domain; they are not replaced with `now()`. If such a range has local-time semantics, the UI sends the range to the backend already converted to UTC.

## 7. Aggregates without public getters
- Aggregates expose **behavior**, not internal state.
- Public getters (for example `status()`, `{secretHash}()`) are not allowed. Aggregate state is an implementation detail.
- Domain events are the external contract: if the outside world needs data from an aggregate, it receives it through an event (for example `{Aggregate}{Verb}` contains `{aggregateId}` and the fields required by consumers).
- State reads for UI/API are done through Query (DBAL) returning DTOs, not through getters on the aggregate.
- Exception: private/internal helper methods (for example `private function status()`) are allowed because they do not break encapsulation.

## 8. Domain events (contract)
- Every aggregate domain event contains:
  - `{aggregate}Id` as type `Id`
  - `occurredAt` as `DateTime` (VO from SharedKernel)
  - event-specific fields
- Field naming is consistent across the whole BC (for example always `{aggregateId}`).
- No event versioning (MVP / KISS).
- `DomainEvent` is a synchronous, in-process contract. It is recorded by the domain, saved to EventLog, and dispatched by the sync `EventBus` inside the `CommandBus` transaction.
- `DomainEvent` is not an async queue and is not a durable delivery contract between processes.

### 8.1 Integration events (contract)
- `IntegrationEvent` is a separate contract from `DomainEvent`.
- An integration event is used for asynchronous technical communication between modules/processes through the outbox. It is a public async contract of the publishing BC; in a foreign BC, only `Application/IntegrationEventSubscriber` following the convention in §4 may import it. Ordinary Application, Domain, Ui and Infrastructure do not import foreign events.
- Integration events are serialized to JSON by Symfony Serializer. Preferred fields are primitives and simple serializable structures without custom normalizers.
- `IntegrationEventPublisherInterface::publish()` does not dispatch the event in memory. The current `DbalOutboxPublisher` implementation writes a record to `shared.async_outbox`.
- `DbalOutboxPublisher` assigns the technical `event_id`, stores `event_name` as the event class FQCN, JSON payload, and `created_at` from `ClockInterface` as a UTC storage string.
- If a sync saga translates its own `DomainEvent` into its own `IntegrationEvent`, it does this in Application and uses `IntegrationEventPublisherInterface`.
- If the goal of a sync saga reaction is async publish, the saga does not run `CommandBus`; it publishes the `IntegrationEvent` through the publisher.
- Example: `Client/Application/ClientInvitation/Saga/ClientInvitationSaga` translates `ClientInvitationCreated` into `ClientInvitationCreatedIntegrationEvent` (invitation id, client and its name, email, role). The outbox write happens in the transaction that creates the invitation. The consumer is `User/Application/IntegrationEventSubscriber/SendClientInvitationNotificationSubscriber`, which sends one notification through User's own `UserNotificationSenderServiceInterface` port. The local `FileUserNotificationSenderService` implementation appends a JSON line to `var/notifications/user_notifications.jsonl` (`user_notifications.test.jsonl` in tests) and skips an identical line, which prevents a duplicate after the worker is interrupted between delivery and marking `processed` (§9.2).
- DI conventions:
  - sync sagas: `src/*/Application/**/Saga/*Saga.php` with the `app.saga` tag, called by the sync `EventBus`
  - async subscribers: `src/*/Application/IntegrationEventSubscriber/*Subscriber.php` with the `app.integration_event_subscriber` tag, called by the outbox worker

## 9. Transactions and flush (one point)
- Flush/commit is in one place (central orchestration).
- Repositories call `persist()`, not `flush()`.
- `ClientInvitation::accept(userId)` checks invitation rules, changes its state and records `ClientInvitationAccepted`. Then `AcceptClientInvitationCommandHandler` reads the immutable client and role data through `ClientInvitationQueryInterface` while still holding the invitation lock, creates the membership through `ClientMemberFactory` and persists it through the repository in the same `CommandBus` transaction. A failure to create or persist the membership also rolls back invitation acceptance.
- Exceptions only when strongly justified and described in code (preferred in SharedKernel, not in a BC).
- Client onboarding through `OnboardClientCommand` stores the `Client` and the first invitation with role `admin` in one `CommandBus` transaction. `ClientInvitationSaga` writes the notification to the outbox in the same transaction; a failure before commit rolls back the client, invitation, EventLog and outbox. `OnboardClientIntegrationTest` forces a failure after the real ORM flush and outbox write and checks that no partial onboarding remains. `CreateClientCommand` and `CreateClientMemberCommand` are fixture/test tools without a production HTTP route; the administrator membership is created by accepting the invitation.

### 9.1 EventBus / Subscribers (hard rule)
- Subscribers (including `*Saga.php`) do not modify ORM entities directly.
- If a reaction to an event requires a database write or a domain state change, the subscriber runs a dedicated Command.
- This keeps the event mechanism in-memory and ready for a future switch to async/outbox without changing domain logic.

### 9.2 Outbox and async consumption
- `shared.async_outbox` is a technical durable queue/state store table for integration events.
- The CLI worker `app:process-outbox` processes the outbox by polling. Options:
  - `--limit` - maximum number of records claimed in one batch, default 50
  - `--once` - process one batch and exit
  - `--sleep` - seconds to sleep between empty runs, default 5
- The worker claims a pending batch with an atomic `UPDATE ... FROM (SELECT ... FOR UPDATE SKIP LOCKED) ... RETURNING`.
- An outbox record is a claim candidate when `processed_at IS NULL`, `attempts < 5`, and there is no active claim or the claim has expired.
- Lease TTL is 5 minutes. After lease expiry, another worker may reclaim the record.
- `attempts` is incremented when the outbox is claimed. On error, the worker stores a shortened `last_error`, clears the outbox claim, and leaves the record for retry as long as `attempts < 5`.
- After 5 attempts are exhausted, the record is not claimed automatically anymore. The MVP has no separate dead letter queue.
- The worker denormalizes the event based on `event_name`, checks that it implements `IntegrationEvent`, and looks for matching handlers in tagged async subscribers.
- An async subscriber is a service tagged with `app.integration_event_subscriber`. The autoload convention covers `src/*/Application/IntegrationEventSubscriber/*Subscriber.php`.
- An async subscriber handler is a public `on*()` method with one typed parameter matching the concrete `IntegrationEvent`.
- `shared.async_consumption` stores claims and idempotency per `(event_id, subscriber, handler_method)`.
- The worker uses a claim-before-side-effect model:
  - before calling the handler, it tries to atomically insert or take over an `async_consumption` record with status `processing`
  - if the record has status `processed`, the handler is skipped
  - if the claim belongs to another active worker, the outbox receives an error and returns to retry
  - after handler success, the worker marks consumption as `processed` only if `claimed_by` ownership is preserved
  - after a handler exception, the worker removes its own consumption claim and releases the outbox for retry
- The outbox is marked as `processed` only after all matching handlers succeed and only if ownership (`claimed_by`) is preserved.
- An async subscriber may run `CommandBus`; this is a separate transaction in the worker process.
- Idempotency in `async_consumption` protects against re-running a handler marked as `processed`. A handler that performs external side effects should still be designed as business-idempotent in case the process stops after the side effect and before marking `processed`.
- Known MVP limitations:
  - no message broker
  - no Redis
  - no `LISTEN/NOTIFY`
  - no dead letter queue
  - the worker uses polling instead of a wake-up signal

### 9.3 Migrations
- Migrations belong to the schema owner and are stored in `app/src/{BC}/Infrastructure/Resource/Migrations/`; migrations for shared mechanisms belong to `SharedKernel`.
- Migration namespaces are registered centrally in the Doctrine Migrations configuration.
- Diff generation is targeted at a context namespace, but running `doctrine:migrations:migrate` covers the shared set of all registered pending migrations. The target names `migrations-migrate-client` and `migrations-migrate-user` do not mean that execution is isolated to a single BC.

### 9.4 Pessimistic row locking for invitations
- `accept`, `reject` and `revoke` run in one short request transaction opened by `CommandBus`. The repository loads `ClientInvitation` through ORM with `LockMode::PESSIMISTIC_WRITE` (`SELECT ... FOR UPDATE`) as the aggregate's first load into the UnitOfWork. The aggregate has no technical version.
- The first-load requirement matters for `getForClient()` and `getPendingForClientAndEmail()`: DQL with `setLockMode()` locks the row but does not refresh an entity already in the identity map (unlike the locking `find()` in `get()`). Adding an earlier ORM read requires reassessing state freshness and the platform revoke contract; acquiring the lock alone does not guarantee that the object is refreshed.
- The row lock lasts until commit/rollback. A concurrent request waits and sees the committed state after acquiring the lock. The existing `assertPending()` rule refuses a disallowed transition with `ClientInvitationNotPendingException`, mapped to `409 {"error": "Invitation is not pending"}`.
- Platform revoke uses one locking ORM query to load an invitation by client, normalized email and `pending` status. If the row no longer matches after waiting, the missing pending invitation results in 404, just as after an earlier accept/reject/revoke.
- The partial unique index `(client_id, email) WHERE status = 'pending'` still protects concurrent invitation creation: there is no row to lock before INSERT. The unique membership constraint `(client_id, user_id)` remains an additional safeguard. Handling `UniqueConstraintViolationException` during accept is a defensive fallback; this HTTP mapping branch has no dedicated test. Currently, only the fixture/test `CreateClientMemberCommand` creates memberships outside accept.
- `ClientInvitationConcurrencyIntegrationTest` proves the lock for all three repository reads used for mutations: a second independent connection executes `FOR UPDATE NOWAIT` and receives SQLSTATE `55P03` (`lock_not_available`); rollback releases the lock. Domain tests and Behat protect transition rules and HTTP contracts.

## 10. DI and configuration
- `app/config/services.yaml` is the root service configuration: it imports convention-based autoloading and each module's Infrastructure configuration.
- `app/config/services.autoload.yaml` uses patterns to register controllers, factories, repositories, queries, Outside implementations, Command handlers, console commands, sagas, integration event subscribers, `*Service` classes, and other Infrastructure services.
- DI details and Doctrine mappings owned by a module belong in `app/src/{BC}/Infrastructure/Resource/config.yaml`. The root config remains the place for imports and truly global parameters.
- A manual alias or definition is justified when convention is insufficient: an explicit implementation choice, a locator/iterator of tagged services, a special argument or environment parameter, a decorator, or other configuration that cannot be expressed by the autoload pattern is required.
- Do not use `public: true` only for tests.

## 11. Platform routes (`platform_` convention)
- A route name with the `platform_` prefix is reserved for platform-only endpoints.
- Platform routes:
  - do not require `active_client_id` in the session.
  - `TenantGuardSubscriber`: skips tenant checks for route names `platform_*` (after cross-origin checks).
  - `PlatformAdminGuardSubscriber`: requires `session.is_platform_admin === true`; otherwise 403.
- The `session.is_platform_admin` flag is set after successful login (`PlatformAdminOnLoginSubscriber`) based on the `app.platform_admin_emails` allowlist.
- The architecture test (`PlatformRouteNamingTest`) ensures that no route contains the substring "platform" without the `platform_` prefix.

Session states and active client selection:
- Anonymous (no `user_id`): only routes on the `TenantGuardSubscriber` allowlist are available (`api_auth_otp_request`, `api_auth_otp_verify`, `/api/health`). Other guarded routes return 401, `platform_*` routes return 403.
- Logged in without an active client: a successful `POST /api/auth/otp/verify` migrates the session id, sets `user_id` and `is_platform_admin`, and removes any `active_client_id` left from an earlier login in the same session. Login never selects a client and does not require a membership. Routes in `ACTIVE_CLIENT_OPTIONAL_ROUTE_NAMES` (`api_me_clients_list`, `api_session_active_client_select`, `api_me_invitations_list`, `api_invitations_accept`, `api_invitations_reject`) and, for a platform admin, `platform_*` routes are available. A tenant route returns `403 {"error": "active_client_required"}`.
- Logged in with an active client: `POST /api/session/active-client` sets `active_client_id` only for an active membership of the user and migrates the session id. A later call switches the client; a refused selection leaves the session state unchanged. A successful `POST /api/invitations/{invitationId}/accept` also makes the invitation's client active and migrates the session, but only after commit; a refused accept does not change the session. Tenant routes go through the remaining guard checks (§11.1).

### 11.1 Current HTTP/API surface
- Application routing loads controllers from `app/src/**/Ui/Http/Api/` and adds the `/api` prefix.
- The current HTTP controllers return JSON. The repository contains no runtime browser application, so such a consumer and its contracts are not inferred from external materials.
- The public endpoint contract includes the path, HTTP method, input, response status, and JSON payload. Changing any of these elements changes the public HTTP surface and requires explicit task scope and behavior test updates.
- The route name is an internal routing and security contract, not part of the public HTTP contract. New or changed route names require checking the `platform_` prefix, the route access requirements configuration and allowlist in `TenantGuardSubscriber`, and subscribers that react to a specific route.
- After accounting for platform and allowlist exceptions, `TenantGuardSubscriber` requires the `user_id` of an existing, non-blocked user. Routes in `ACTIVE_CLIENT_OPTIONAL_ROUTE_NAMES` do not require an active client. For the others, the guard requires `active_client_id`, a route `{clientId}` matching the active client, and an active membership, and only permits routes in `ADMIN_REQUIRED_ROUTE_NAMES`, requiring the client administrator role. Other routes covered by the guard are denied access. Adding a route for a regular member requires extending the configuration and handling of access requirements in the guard.
- Guard denials return `{"error": "Access denied"}` with 401 or 403. The only distinguishable case is a tenant route without an active client: `403 {"error": "active_client_required"}`.
- Onboarding and administrator invitations (`Client/Ui/Http/Api/ClientController`, `PlatformAdminInvitationController`). All three routes use the `platform_` prefix, require a platform administrator and do not require an active client; anonymous callers and users without platform privileges receive `403 {"error": "Access denied"}`. Invalid input → `400 {"errors": [{"field": "...", "message": "..."}]}`. Email addresses are normalized by `Email`.
  - `POST /api/clients` (`platform_clients_create`) with `{"name": "...", "adminEmail": "...", "description": "..."}` → `201 {"id": "<client uuid>"}`. `name` and a valid `adminEmail` are required, `description` is optional. A missing `adminEmail`, empty or invalid address → 400 without creating a client. `OnboardClientCommand` atomically creates the client and a pending invitation with role `admin` (§9), without a user account or membership. The worker sends the notification; the invitee logs in through OTP and uses the list and accept/reject flow from 5.2. Acceptance grants role `admin` and sets the active client after commit.
  - `POST /api/clients/{clientId}/admin-invitations` (`platform_client_admin_invitations_create`) with `{"email": "..."}` → `201 {"id": "<invitation uuid>"}`. Creates an invitation with role `admin` to an existing client, including after rejection of the first invitation or loss of administrators. A non-existent client → `404 {"error": "Not found"}`; a pending invitation for this client and email, regardless of role → `409 {"error": "A pending invitation for this email already exists"}`; an existing active or suspended membership → `409 {"error": "User is already a member of this client"}`. The shared invitation factory rules remain the same as in the tenant flow; the input does not specify a role.
  - `POST /api/clients/{clientId}/admin-invitations/revoke` (`platform_client_admin_invitations_revoke`) with `{"email": "..."}` → 204 with no body. Revokes only a pending invitation with role `admin` for the specified client and email. No pending invitation (also after an earlier accept/reject/revoke) → `404 {"error": "Not found"}`; a pending invitation with role `user` → `403 {"error": "Platform admin can revoke only invitations with role admin"}`. A revoked invitation cannot be accepted later. No invitation id or separate platform listing is needed. A `{clientId}` that is not a lowercase UUID → 404 from routing for both admin-invitations routes.
  - Invitations with role `admin` can be created and revoked only through the platform flow. Tenant routes still allow creating and revoking only invitations with role `user`. Recovery does not enforce keeping at least one active administrator.
- Logged-in session endpoints (`User/Ui/Http/Api/ActiveClientController`):
  - `GET /api/me/clients` (`api_me_clients_list`) → 200 with the user's active memberships: `[{"clientId": "...", "clientName": "...", "roles": ["admin"]}]`. Suspended memberships are not returned; a user without memberships gets `[]`. The API never selects a client automatically, even for a one-element list.
  - `POST /api/session/active-client` (`api_session_active_client_select`) with `{"clientId": "<uuid>"}` → 204 with no body; the session stores the lowercase UUID, matching the ids from `GET /api/me/clients`. A client without an active membership of the user (foreign, suspended, non-existent) → `403 {"error": "Access denied"}`; a `clientId` that is not a UUID → 400 with validation errors.
  - `POST /api/auth/otp/verify` keeps its `{"ok": true}` / `{"ok": false}` contract.
- Client invitations (`Client/Ui/Http/Api/ClientInvitationController`). In production, a membership is created only by accepting an invitation; `CreateClientMemberCommand` has no HTTP route and serves Behat fixtures.
  - `POST /api/clients/{clientId}/invitations` (`api_client_invitations_create`, `ADMIN_REQUIRED_ROUTE_NAMES`) with `{"email": "...", "role": "user"}` → 201 `{"id": "<uuid>"}`. A role other than `user` → `403 {"error": "Client admin can invite only with role user"}`; a pending invitation for this client and email → `409 {"error": "A pending invitation for this email already exists"}`; a person with a membership in the client, active or suspended → `409 {"error": "User is already a member of this client"}`; invalid input → 400. The invitation does not create a user account.
  - `POST /api/clients/{clientId}/invitations/{invitationId}/revoke` (`api_client_invitations_revoke`, `ADMIN_REQUIRED_ROUTE_NAMES`) → 204. A non-existent invitation or one of another client → `404 {"error": "Not found"}`; an invitation with role `admin` → `403 {"error": "Client admin can revoke only invitations with role user"}`; a status other than `pending` → `409 {"error": "Invitation is not pending"}`.
  - `GET /api/me/invitations` (`api_me_invitations_list`) → 200 `[{"id": "...", "clientId": "...", "clientName": "...", "role": "user", "createdAt": "..."}]`: only pending invitations addressed to the logged-in user's email.
  - `POST /api/invitations/{invitationId}/accept` (`api_invitations_accept`) → 204. In one transaction it stores `accepted` and creates the membership with the invitation's role through `ClientMemberFactory`; after commit it sets `active_client_id` (§11). A non-existent invitation or one addressed to another email → `404 {"error": "Not found"}`; a status other than `pending` → `409 {"error": "Invitation is not pending"}`; a membership that exists at accept time → `409 {"error": "User is already a member of this client"}`, the invitation stays `pending` and the admin can revoke it.
  - `POST /api/invitations/{invitationId}/reject` (`api_invitations_reject`) → 204, without a membership; the same 404/409 refusals as accept.
  - Concurrent accept, reject and tenant revoke are serialized by a row lock; a status other than `pending` after acquiring the lock → `409 {"error": "Invitation is not pending"}` (§9.4). An `{invitationId}` that is not a lowercase UUID → 404 from routing.

## 12. Test strategy (minimum)
- Domain unit: test aggregate behavior with FakeOutside and deterministic time.
- Integration: infrastructure (DB, DBAL query, event log, mapping) has meaningful automated test coverage. We do not require a separate mapping test for every aggregate if the mapping is already actually covered by Behat or another integration test that goes through persist/flush/load. Add a dedicated mapping test only when the mapping has no natural coverage or is non-trivial enough that a separate test gives real value.
- E2E (Behat): at least one "happy path" scenario through UI -> Application -> Domain -> Infrastructure.

Tests in `app/tests/Architecture/BoundedContextDependenciesTest.php` run Deptrac against a temporary copy of the sources with the unchanged `app/deptrac.php`. They check existing adapters, allowed Infrastructure access to foreign Query/Command/DTO contracts and subscriber access to foreign IntegrationEvent contracts, rejection of other cross-BC dependencies and names outside the convention, and Outside and SharedKernel restrictions. An artificially added BC automatically receives the same contract: it can expose and consume public contracts, while forbidden dependencies are rejected. Tests, `make deptrac-ci` and `composer deptrac:ci` use `--report-uncovered --fail-on-uncovered`, so uncovered dependencies cause the check to fail.

### 12.1 Behat conventions (KISS)
- Scenarios use aliases (readable names), not raw UUIDs.
- Given: sets application state only through Commands (CommandBus/handlers), never through endpoints.
- When: executes only endpoints (HTTP).
- Then: verifies state through Query (DBAL/read model). Endpoints in Then are allowed only for HTTP code / error mapping assertions.
- Contexts of one suite share one browser per scenario (`app.behat.kernel_browser` in `config/services_test.yaml`), so the session from an OTP login in `UserContext` applies to steps of another context.
- For shared "Given" steps, use:
  - `FixtureContext` (shared state arrangement steps)
  - `FixtureRegistry` (alias -> fixture/Id mapping)

## 13. Quality gates (before merge)
Use commands from the `Makefile` in the root directory.
- `make cs-check`
- `make phpstan`
- `make deptrac-ci`
- `make test`
- `make behat` (if UI / E2E flow is affected)

Run the gates selected for the scope one at a time, in the order above, waiting for the complete result and exit code before starting the next one. After a failure, stop the sequence, fix the problem, and rerun the appropriate gates. `make qa` runs only `cs-check`, `phpstan`, and `deptrac-ci` sequentially; it does not replace `make test` or `make behat`.

For documentation-only changes, unless the task requires more, the minimum check is `git diff --check`. The repository defines no separate documentation validation target.

## 14. Working flow (how we work)
1. Discuss the business case and BC boundaries.
2. Write down decisions and consequences in a short note.
3. Turn the note into a backlog of implementation steps.
4. At the end, the prompt for the CLI agent must implement exactly the agreed decisions and pass quality gates.
