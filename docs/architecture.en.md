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
  - CLI: `app/src/{BC}/Ui/ConsoleCommands/...` (over time)

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

Deptrac is the source of truth for dependency direction.

### 4.1 Reading data from another context (ACL)
- A context never writes raw SQL/DBAL to tables it does not own.
- If context A needs data from context B, the dependency is pushed to the very bottom (Infrastructure) and goes through context B's public `QueryInterface`.
- Context A defines its own DTO and maps data from context B's DTO. It does not re-export foreign DTOs above the Infrastructure layer (Anti-Corruption Layer).
- Benefit of the modular monolith: the dependency is compile-time, without serialization or network calls, but context boundaries are explicit and enforceable by tools (Deptrac).

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
  - queries cross-BC state (for example `{OtherContext}QueryInterface`) and never modifies foreign aggregates
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
- An integration event is used for asynchronous technical communication between modules/processes through the outbox.
- Integration events are serialized to JSON by Symfony Serializer. Preferred fields are primitives and simple serializable structures without custom normalizers.
- `IntegrationEventPublisherInterface::publish()` does not dispatch the event in memory. The current `DbalOutboxPublisher` implementation writes a record to `shared.async_outbox`.
- `DbalOutboxPublisher` assigns the technical `event_id`, stores `event_name` as the event class FQCN, JSON payload, and `created_at` from `ClockInterface` as a UTC storage string.
- If a sync saga translates a `DomainEvent` into an `IntegrationEvent`, it does this in Application and uses `IntegrationEventPublisherInterface`.
- If the goal of a sync saga reaction is async publish, the saga does not run `CommandBus`; it publishes the `IntegrationEvent` through the publisher.
- DI conventions:
  - sync sagas: `src/*/Application/**/Saga/*Saga.php` with the `app.saga` tag, called by the sync `EventBus`
  - async subscribers: `src/*/Application/IntegrationEventSubscriber/*Subscriber.php` with the `app.integration_event_subscriber` tag, called by the outbox worker

## 9. Transactions and flush (one point)
- Flush/commit is in one place (central orchestration).
- Repositories call `persist()`, not `flush()`.
- Exceptions only when strongly justified and described in code (preferred in SharedKernel, not in a BC).

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

## 10. DI and configuration
- We prefer convention over configuration (autoload, file patterns, minimum manual entries).
- Manual aliases/definitions only when:
  - there is more than one implementation
  - or you need an explicit choice/priority
- Do not use `public: true` only for tests.

## 11. Platform routes (`platform_` convention)
- A route name with the `platform_` prefix is reserved for platform-only endpoints.
- Platform routes:
  - do not require `active_client_id` in the session.
  - `TenantGuardSubscriber`: skips tenant checks for route names `platform_*` (after cross-origin checks).
  - `PlatformAdminGuardSubscriber`: requires `session.is_platform_admin === true`; otherwise 403.
- The `session.is_platform_admin` flag is set after successful login (`PlatformAdminOnLoginSubscriber`) based on the `app.platform_admin_emails` allowlist.
- The architecture test (`PlatformRouteNamingTest`) ensures that no route contains the substring "platform" without the `platform_` prefix.

## 12. Test strategy (minimum)
- Domain unit: test aggregate behavior with FakeOutside and deterministic time.
- Integration: infrastructure (DB, DBAL query, event log, mapping) has meaningful automated test coverage. We do not require a separate mapping test for every aggregate if the mapping is already actually covered by Behat or another integration test that goes through persist/flush/load. Add a dedicated mapping test only when the mapping has no natural coverage or is non-trivial enough that a separate test gives real value.
- E2E (Behat): at least one "happy path" scenario through UI -> Application -> Domain -> Infrastructure.

### 12.1 Behat conventions (KISS)
- Scenarios use aliases (readable names), not raw UUIDs.
- Given: sets application state only through Commands (CommandBus/handlers), never through endpoints.
- When: executes only endpoints (HTTP).
- Then: verifies state through Query (DBAL/read model). Endpoints in Then are allowed only for HTTP code / error mapping assertions.
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

## 14. Working flow (how we work)
1. Discuss the business case and BC boundaries.
2. Write down decisions and consequences in a short note.
3. Turn the note into a backlog of implementation steps.
4. At the end, the prompt for the CLI agent must implement exactly the agreed decisions and pass quality gates.
