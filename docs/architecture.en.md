# Architecture

This is the full translation of the canonical Polish [architecture.md](architecture.md), which defines architectural rules, patterns, constraints and guarantees. Current platform behavior, HTTP contracts and runtime details live in [platform.md](platform.md) (English only). Quality gates and workflow: [instructions for agents and contributors](instructions-for-agents.md).

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

Public async contracts are `App\{BC}\Application\IntegrationEvent\**\*IntegrationEvent`, and their cross-BC consumers are `App\{BC}\Application\IntegrationEventSubscriber\*Subscriber`. For events, `**` means zero or more segments. A subscriber must live directly in `Application/IntegrationEventSubscriber`, matching the flat DI registration convention (§8.1); a subscriber in a subnamespace does not receive the cross-BC exception. Both the namespace and the class name suffix must match. A helper in IntegrationEvent or a Subscriber outside IntegrationEventSubscriber gains no public access. Sync contracts, integration events and subscribers are separated from ordinary Application. Outside remains separated from Domain so that even the same BC's Application and subscribers cannot use it (§6).

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
- In Demo, Client owns clients, memberships and invitations, while User owns user identity and authentication. These boundaries also apply to cross-BC orchestration.

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
- The domain model and read models operate in UTC without conversion to the user's local timezone.
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
- If a sync saga translates its own `DomainEvent` into its own `IntegrationEvent`, it does this in Application and uses `IntegrationEventPublisherInterface`.
- If the goal of a sync saga reaction is async publish, the saga does not run `CommandBus`; it publishes the `IntegrationEvent` through the publisher.
- Short example: `ClientInvitationSaga` in Client Application translates its own `ClientInvitationCreated` into its own `ClientInvitationCreatedIntegrationEvent` through the publisher; it does not call User directly. The [notification flow](platform.md#43-invitation-notifications) describes the consumer and delivery.
- DI conventions:
  - sync sagas: `src/*/Application/**/Saga/*Saga.php` with the `app.saga` tag, called by the sync `EventBus`
  - async subscribers: `src/*/Application/IntegrationEventSubscriber/*Subscriber.php` with the `app.integration_event_subscriber` tag, called by the outbox worker
- An async subscriber handler is a public `on*()` method with one typed parameter matching the concrete `IntegrationEvent`.

## 9. Transactions and flush (one point)
- `CommandBus` owns the transaction: it runs the handler, performs one ORM flush, saves collected events to EventLog and dispatches them through the sync EventBus, then commits.
- Repositories call `persist()`, not `flush()`.
- Aggregate changes, EventLog and outbox writes made in this transaction share commit/rollback. A failure before commit must not leave a partially persisted use case.
- Effects outside the database (for example a file or HTTP session) are not covered by DB rollback. Ui may expose the result of a committed change only after `CommandBus` returns.
- Exceptions only when strongly justified and described in code (preferred in SharedKernel, not in a BC).
- Concrete atomicity boundaries for onboarding and acceptance: [platform.md §4](platform.md#4-flows-and-implementation).

### 9.1 EventBus / Subscribers (hard rule)
- Subscribers (including `*Saga.php`) do not modify ORM entities directly.
- If a reaction to an event requires a domain state change, the subscriber runs a dedicated Command. Technical integration event writes to the outbox go through the publisher (§8.1), under the DBAL exception (§5.3).
- This keeps the event mechanism in-memory and ready for a future switch to async/outbox without changing domain logic.

### 9.2 Outbox and async consumption guarantees
- `shared.async_outbox` is a durable integration event store. Publishing inside a `CommandBus` transaction is atomic with the domain change (§9); a consumer processes committed records in a separate process.
- Claiming a record must be atomic. A lease allows abandoned work to be reclaimed, and acknowledging processing requires retained ownership (`claimed_by`).
- Consumption uses claim-before-side-effect. `shared.async_consumption` identifies execution by `(event_id, subscriber, handler_method)`; a handler marked as `processed` is skipped. Another worker's active claim prevents concurrent execution of the same handler.
- The outbox is marked as `processed` only after all matching handlers succeed and while ownership is retained. A handler failure releases its own claim and allows retry within the runtime policy.
- An async subscriber may run `CommandBus`; this is a separate transaction in the worker process.
- There is no exactly-once guarantee for external effects. A process interrupted after the effect but before marking `processed` may cause execution to repeat. The handler must provide business idempotency; the consumption record does not replace this protection.
- Retry/lease parameters, polling and current implementation limitations: [outbox runtime](platform.md#44-outbox-runtime).

### 9.3 Migrations
- Migrations belong to the schema owner and are stored in `app/src/{BC}/Infrastructure/Resource/Migrations/`; migrations for shared mechanisms belong to `SharedKernel`.
- Migration namespaces are registered centrally in the Doctrine Migrations configuration.
- The scope of migration generation and execution through the Makefile is described in the [contributor instructions](instructions-for-agents.md#21-migration-commands).

### 9.4 Aggregate locking and state freshness
- Mutations requiring serialized state transitions must read a fresh aggregate under a lock in a short `CommandBus` transaction. In Demo, `ClientInvitationRepository` uses ORM `LockMode::PESSIMISTIC_WRITE` (`SELECT ... FOR UPDATE`) as the aggregate's first load into the UnitOfWork.
- The lock lasts until commit/rollback. A concurrent operation waits; after acquiring the lock, the domain rule evaluates committed state. The lock itself does not replace transition validation.
- Beware of Doctrine's identity map: DQL with `setLockMode()` locks the row but does not refresh an entity already in the UnitOfWork. This applies to `getForClient()` and `getPendingForClientAndEmail()`, unlike the locking `find()` in `get()`. An earlier ORM read requires reassessing state freshness and the operation's contract; acquiring the lock alone does not guarantee that the object is refreshed.
- Locking an existing row does not protect concurrent creation: there is no row to lock before INSERT. DB uniqueness constraints remain a required safeguard for invariants alongside domain validation.
- Application to invitations, indexes and HTTP consequences: [platform.md §4.2](platform.md#42-invitation-acceptance-and-concurrency).

## 10. DI and configuration
- `app/config/services.yaml` is the root service configuration: it imports convention-based autoloading and each module's Infrastructure configuration.
- `app/config/services.autoload.yaml` uses patterns to register controllers, factories, repositories, queries, Outside implementations, Command handlers, console commands, sagas, integration event subscribers, `*Service` classes, and other Infrastructure services.
- DI details and Doctrine mappings owned by a module belong in `app/src/{BC}/Infrastructure/Resource/config.yaml`. The root config remains the place for imports and truly global parameters.
- A manual alias or definition is justified when convention is insufficient: an explicit implementation choice, a locator/iterator of tagged services, a special argument or environment parameter, a decorator, or other configuration that cannot be expressed by the autoload pattern is required.
- Do not use `public: true` only for tests.

## 11. Platform routes (`platform_` convention)
- A route name with the `platform_` prefix is reserved for platform endpoints. No other route may contain the substring `platform`; `PlatformRouteNamingTest` enforces the convention.
- Platform privileges are separate from client membership and role. Platform routes require a platform administrator but do not require an active client (`active_client_id`).
- `TenantGuardSubscriber` and `PlatformAdminGuardSubscriber` enforce this separation. The platform exemption from tenant checks does not bypass cross-origin checks.
- Access to tenant routes is explicitly configured; no matching rule means refusal (default deny).
- Session states, privilege source and check order: [platform.md §2](platform.md#2-sessions-and-access-control).

### 11.1 HTTP and routing contracts
- The public endpoint contract includes the path, HTTP method, input, response status, and JSON payload. Changing any of these elements changes the public HTTP surface and requires explicit task scope and behavior test updates.
- The route name is an internal routing and security contract, not part of the public HTTP contract. New or changed route names require checking the `platform_` prefix, the route access requirements configuration and allowlist in `TenantGuardSubscriber`, and subscribers that react to a specific route.
- Contract catalogue: [platform.md §3](platform.md#3-httpapi-contracts).

## 12. Test strategy (minimum)
- Domain unit: test aggregate behavior with FakeOutside and deterministic time.
- Integration: infrastructure (DB, DBAL query, event log, mapping) has meaningful automated test coverage. We do not require a separate mapping test for every aggregate if the mapping is already actually covered by Behat or another integration test that goes through persist/flush/load. Add a dedicated mapping test only when the mapping has no natural coverage or is non-trivial enough that a separate test gives real value.
- E2E (Behat): at least one "happy path" scenario through UI -> Application -> Domain -> Infrastructure.

Tests in `app/tests/Architecture/BoundedContextDependenciesTest.php` run Deptrac against a temporary copy of the sources with the unchanged `app/deptrac.php`. They check existing adapters, allowed Infrastructure access to foreign Query/Command/DTO contracts and subscriber access to foreign IntegrationEvent contracts, rejection of other cross-BC dependencies and names outside the convention, and Outside and SharedKernel restrictions. An artificially added BC automatically receives the same contract: it can expose and consume public contracts, while forbidden dependencies are rejected. Uncovered dependencies must fail the Deptrac check (`--report-uncovered --fail-on-uncovered`).

### 12.1 Behat conventions (KISS)
- Scenarios use aliases (readable names), not raw UUIDs.
- Given: sets application state only through Commands (CommandBus/handlers), never through endpoints.
- When: executes only endpoints (HTTP).
- Then: verifies state through Query (DBAL/read model). Endpoints in Then are allowed only for HTTP code / error mapping assertions.
- Contexts of one suite share one browser per scenario (`app.behat.kernel_browser` in `config/services_test.yaml`), so the session from an OTP login in `UserContext` applies to steps of another context.
- For shared "Given" steps, use:
  - `FixtureContext` (shared state arrangement steps)
  - `FixtureRegistry` (alias -> fixture/Id mapping)
