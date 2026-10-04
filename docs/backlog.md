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
| 5 | Explicit client selection and membership invitations | DONE | 2026-10-02 | 2026-10-02 | Real multi-tenant flow with consent and cross-BC async |
| 5.1 | Explicit active client selection | DONE | 2026-09-28 | 2026-09-28 | Replace the implicit UUID-ordered client choice at login |
| 5.2 | Client membership invitations | DONE | 2026-09-29 | 2026-09-29 | Membership requires user consent; async notification Client → User |
| 5.3 | Client onboarding invites the first admin | DONE | 2026-10-02 | 2026-10-02 | Close the gap where only fixtures create client admins |
| 6 | Mermaid architecture flow | TODO | 2026-09-28 | — | Show the main architecture flow in 30 seconds |
| 7 | README first screen polish | TODO | 2026-04-30 | — | Explain quickly what the demo is and what it proves |
| 8 | Architecture Decision Records | TODO | 2026-04-30 | — | Show conscious decisions and trade-offs |
| 9 | Repository hygiene | TODO | 2026-04-30 | — | Remove basic red flags from a public repository |
| 10 | Dependabot | TODO | 2026-04-30 | — | Add automated dependency hygiene |

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

Implementation evidence: provision handler (removed in 5.2), [create handler](../app/src/Client/Application/ClientMember/Command/CreateClientMember/CreateClientMemberCommandHandler.php), [factory](../app/src/Client/Domain/ClientMember/Factory/ClientMemberFactory.php), [membership query](../app/src/Client/Infrastructure/ClientMember/ClientMemberQuery.php), and [unique-index migration](../app/src/Client/Infrastructure/Resource/Migrations/Version20260206120000.php).

Verification: [factory tests](../app/tests/Client/Domain/ClientMember/ClientMemberFactoryTest.php) prove rejection before construction and event recording. [Integration tests](../app/tests/Client/Infrastructure/ClientMember/ClientMemberCreationIntegrationTest.php) cover both creation flows with one membership lookup, including active and suspended duplicates. [Behat scenarios](../app/tests/Behat/features/client_member/client_member_management.feature) preserve HTTP 409 and its error payload without changing the existing membership. The database unique index is unchanged. All five quality gates passed sequentially (186 PHPUnit tests, 31 Behat scenarios).

---

## 5. Explicit client selection and membership invitations

Status: `DONE`

### Why

An analysis for the architecture diagram (2026-09-28) showed that the current multi-tenant flow is incomplete:

- Login picks the active client implicitly: `ActiveClientIdResolverService` sorts active memberships by `clientId` and takes the first one, only logging a warning. A user with several clients cannot choose or switch.
- A client admin adds a person through `POST /api/client-members`, which silently creates the user account and an active membership. The person is neither informed nor asked for consent.
- No HTTP flow creates the first admin of a client. `platform_clients_create` creates a client without members; admins exist only through Behat fixtures (`CreateClientMemberCommand`), and every later admin action requires an existing admin. A fresh instance cannot operate a client.
- The only async flow (`UserRegisteredIntegrationEvent`) is consumed in the same BC and writes a notification with little business value.

Invitations with explicit acceptance give the demo a real business flow: consent to membership, explicit tenant choice, client onboarding that ends with an admin, and an async integration event consumed by another BC.

Deliver in three iterations. Each one ends with green quality gates and can be merged separately. 5.2 depends on 5.1; 5.3 depends on 5.2.

### 5.1 Explicit active client selection

Scope:

- `POST /api/auth/otp/verify` logs the user in without setting `active_client_id`, regardless of the number of memberships. Its HTTP contract is unchanged (`{"ok": true}`).
- A user without any active membership can log in. Remove the login denial and the implicit choice (`ActiveClientIdOnLoginSubscriber`, `ActiveClientIdResolverService`) or reduce them to what is still needed.
- `GET /api/me/clients` returns the user's active memberships (client id, client name, roles). The API never selects automatically; a client application may auto-select when the list has one element.
- `POST /api/session/active-client` with `{"clientId": "..."}` sets `active_client_id` only for an active membership, otherwise 403. It also switches the client during a session. Migrate the session id on change, as at login.
- `TenantGuardSubscriber` gets an explicit list of routes that require a logged-in user but no active client. Platform routes (`platform_*`) are unaffected.
- Tenant routes without an active client return `403 {"error": "active_client_required"}`. Reuse the existing `MISSING_CLIENT_ID` guard branch; today every guard denial returns the same `{"error": "Access denied"}` body, so this is the first distinguishable denial and a deliberate public contract.

Done when:

- Behat covers: login without selection, listing clients (suspended memberships excluded), selection, switching, refused selection of a foreign or suspended membership, tenant route before selection (`active_client_required`), and login of a user without memberships.
- Existing scenarios that assert `active_client_id` right after verify are rewritten to verify + select.
- `docs/architecture.md` §11 and §11.1 describe the new session states and routes.

Completion note (2026-09-28): `ActiveClientIdOnLoginSubscriber` and `ActiveClientIdResolverService` were removed. OTP verify migrates the session, sets `user_id` and clears any earlier `active_client_id`; it never selects a client and accepts users without memberships. The existing User ACL `ActiveMembershipsQuery` now also maps the client name (through `ClientQueryInterface`) and roles for `GET /api/me/clients`; `POST /api/session/active-client` reuses `MembershipForClientQueryInterface`, returns 204, stores the lowercase UUID and migrates the session, and answers 403 `Access denied` without changing the session for a foreign, suspended or unknown client (400 for a non-UUID). `TenantGuardSubscriber` checks the user before the active client, lets `ACTIVE_CLIENT_OPTIONAL_ROUTE_NAMES` pass, and returns `403 {"error": "active_client_required"}` from the `MISSING_CLIENT_ID` branch. Fixture sessions (`I am logged in as … in client …`) still arrange `active_client_id` directly; they model an earlier explicit selection, not login. All five quality gates passed sequentially (183 PHPUnit tests, 44 Behat scenarios).

Implementation evidence: [session endpoints](../app/src/User/Ui/Http/Api/ActiveClientController.php), [OTP controller](../app/src/User/Ui/Http/Api/OtpAuthController.php), [tenant guard](../app/src/User/Ui/Http/Security/TenantGuardSubscriber.php), and [membership ACL](../app/src/User/Infrastructure/Tenant/ActiveMembershipsQuery.php). Verification: [selection scenarios](../app/tests/Behat/features/user/active_client_selection.feature) and the rewritten [OTP scenarios](../app/tests/Behat/features/user/user_registration.feature).

### 5.2 Client membership invitations

Scope:

- New `ClientInvitation` aggregate in Client: `clientId`, `email`, role (`user` or `admin`), status `pending` / `accepted` / `rejected` / `revoked`, timestamps. No expiry.
- Invariants in `ClientInvitationFactory`, with facts from Outside (same pattern as membership uniqueness in item 4):
  - at most one pending invitation per `(clientId, email)`, backed by a partial unique index;
  - refused when a user with that email already has a membership in the client, active or suspended. Outside reads the user through Client's own Infrastructure ACL (§4.1); no user means no membership.
  - Refusals map to HTTP 409.
- `ClientInvitation` allows transitions only from `pending` and carries a version column (`#[ORM\Version]`, optimistic locking). Concurrent transitions (accept vs revoke, reject vs revoke, accept vs accept) make the second flush fail; `CommandBus` rolls back the whole transaction, including a membership created by accept, and the controller returns 409.
- Client admin (tenant routes, `ADMIN_REQUIRED_ROUTE_NAMES`): create an invitation with role `user` only, and revoke invitations with role `user`. Role `admin` is granted by invitation only from the platform (5.3); promotion of an accepted member stays with `PUT /api/clients/{clientId}/members/{userId}/roles`.
- Invited user (logged in, no active client required): `GET /api/me/invitations`, `POST /api/invitations/{id}/accept`, `POST /api/invitations/{id}/reject`. Only the user whose email matches the invitation may act on it; Client reads the current user's email through its ACL.
- Accept: `CommandBus` atomically stores `accepted` and creates the `ClientMember` through `ClientMemberFactory`. Only after a successful commit does the controller set the new client as `active_client_id` and migrate the session, as `OtpAuthController::verifyOtp` does today. If a membership already exists at accept time, return 409 and keep the invitation `pending`; the admin can revoke it.
- Async notification: a Client saga translates `ClientInvitationCreated` into `ClientInvitationCreatedIntegrationEvent` (with client name and role). A User `IntegrationEventSubscriber` sends one notification to the invited email through the User notification port ("you were invited to X, log in to respond"). No magic link: the user responds after an OTP login.
- Replace `POST /api/client-members` with the invitation. Remove `ProvisionClientMember*`, `UserProvisioningServiceInterface` / `UserProvisioningService`, and `UpsertUserByEmailCommand` if nothing else uses it. User accounts are then created only by OTP login.
- Remove the user registration notification, which the invitation notification replaces: `SendUserRegisteredNotificationSubscriber`, `sendUserRegisteredNotification`, `UserRegisteredSaga`, `UserRegisteredIntegrationEvent`, the `app.user_registered_notification_log_path` parameter and their tests. Keep the `UserRegistered` domain event; it is still recorded and stored in the event log. Doing both in one iteration keeps an async example in the demo at every step.

Done when:

- Domain tests cover the invariants and transitions.
- Behat covers: invite → notification after the worker runs → OTP login → list → accept → member of the client with that client active; reject; revoke; duplicate pending invitation; invitation of an existing active or suspended member; a user acting on someone else's invitation; a non-admin inviting; a client admin trying to invite with role `admin`.
- The notification is written exactly once after repeated worker runs.
- Integration tests prove the optimistic lock. Between loading the invitation and flushing, a second writer on a separate connection updates status and `version` and commits (a change on the same connection would join the `CommandBus` transaction and be rolled back with it; without a `version` change Doctrine detects no conflict). Assert that the first writer gets `OptimisticLockException`, its membership is rolled back, and the invitation keeps the second writer's state (accept vs revoke → `revoked`, no membership). The same for reject vs revoke. Behat cannot produce a real race.
- Deptrac and `BoundedContextDependenciesTest` allow the new cross-BC subscriber and still reject other foreign imports; probes that reference `UserRegisteredIntegrationEvent` point to the invitation event.
- No reference to the removed classes remains in `app/`, `docs/` or the README.
- `docs/architecture.md` §4.2 no longer uses `UserProvisioningService` as its example; README `Key flows` reflects the new flow.

Completion note (2026-09-29): `ClientInvitation` (Client) has status and `#[ORM\Version]`; `ClientInvitationFactory::createByClientAdmin()` refuses role `admin`, a second pending invitation (backed by the partial unique index `UNIQ_CLIENT_INVITATION_PENDING_CLIENT_EMAIL`) and a person with an active or suspended membership, using facts from `ClientInvitationOutside`. Client reads users through its own ACL port `UserAccountQueryInterface` (adapter over `UserQueryInterface`). `accept()` creates the membership through `ClientMemberFactory` in the same transaction; `revokeByClientAdmin()` refuses role `admin`. A request addressed to someone else's invitation answers 404, refused transitions and concurrent changes 409, and the active client is set only after commit. `ClientInvitationSaga` publishes `ClientInvitationCreatedIntegrationEvent`; `SendClientInvitationNotificationSubscriber` (User) writes one JSON line through `UserNotificationSenderServiceInterface`. Provisioning, `UpsertUserByEmailCommand`, the registration notification flow and the unused `requireActiveClientId()`/`SessionContext::activeClientId()` were removed; Behat fixtures create users with `LogInUserByEmailCommand`. Behat contexts of one suite now share one browser per scenario, so an OTP login in `UserContext` is visible to `ClientInvitationContext`. All five quality gates passed sequentially.

Implementation evidence: [aggregate](../app/src/Client/Domain/ClientInvitation/ClientInvitation.php), [factory](../app/src/Client/Domain/ClientInvitation/Factory/ClientInvitationFactory.php), [Outside](../app/src/Client/Infrastructure/ClientInvitation/ClientInvitationOutside.php), [controller](../app/src/Client/Ui/Http/Api/ClientInvitationController.php), [saga](../app/src/Client/Application/ClientInvitation/Saga/ClientInvitationSaga.php), [subscriber](../app/src/User/Application/IntegrationEventSubscriber/SendClientInvitationNotificationSubscriber.php), and [migration](../app/src/Client/Infrastructure/Resource/Migrations/Version20260929120000.php). Verification: [domain tests](../app/tests/Client/Domain/ClientInvitation/), [concurrency integration tests](../app/tests/Client/Infrastructure/ClientInvitation/ClientInvitationConcurrencyIntegrationTest.php), [notification integration test](../app/tests/Client/Application/ClientInvitation/ClientInvitationNotificationIntegrationTest.php), and [Behat scenarios](../app/tests/Behat/features/client_invitation/client_invitation.feature).

### 5.3 Client onboarding invites the first admin

Status: `DONE`

Scope:

- `POST /api/clients` (`platform_clients_create`) requires `adminEmail`. One command creates the `Client` and a `ClientInvitation` with role `admin` in the same transaction. The rest is the 5.2 flow: async notification, OTP login, accept, membership with role `admin`.
- Recovery route for the platform admin: invite an admin to an existing client (for example `POST /api/clients/{clientId}/admin-invitations`, route name with the `platform_` prefix). It covers a rejected first invitation and a client that lost its admins. Same factory and invariants as 5.2.
- Revoke route for the platform admin: revoke the pending admin invitation of a client by email (for example `POST /api/clients/{clientId}/admin-invitations/revoke` with `{"email": "..."}`, `platform_` prefix). The pending invitation is unique per `(clientId, email)`, so no invitation id or listing endpoint is needed. Without it, a mistyped `adminEmail` would leave a non-expiring admin grant to the owner of the wrong address.
- Invitations with role `admin` can be created and revoked only through these platform routes.
- `CreateClientMemberCommand` stays only as a Behat fixture tool; state this explicitly in code or docs so it does not read as a production path. Fixtures keep using it; rewriting them to the full invitation flow would slow every scenario without adding coverage.

Done when:

- Behat covers: platform creates a client with `adminEmail` → notification → OTP login → accept → admin of the new client; a missing `adminEmail` is rejected; the recovery route after a rejected first invitation; revoking a mistyped admin invitation, after which accepting it is refused, then inviting the correct email; a non-platform user calling any of these routes is refused.
- The updated `platform_clients_create` contract is reflected in Behat and `docs/architecture.md` §11.1.

Completion note (2026-10-02): `platform_clients_create` requires `adminEmail`. `OnboardClientCommand` creates the client and its first pending admin invitation in one `CommandBus` transaction, including EventLog and the notification outbox write. The existing notification → OTP → acceptance flow creates the admin membership and selects the client after commit. Platform-only routes invite another admin to an existing client and revoke a pending admin invitation by normalized email. They share the 5.2 factory invariants; tenant admins cannot create or revoke admin invitations. `CreateClientMemberCommand` remains a fixture/test tool, and `CreateClientCommand` is explicitly marked as a fixture/test tool. The last-active-admin invariant remains outside this iteration.

Implementation evidence: [onboarding handler](../app/src/Client/Application/Client/Command/OnboardClient/OnboardClientCommandHandler.php), [platform invitation controller](../app/src/Client/Ui/Http/Api/PlatformAdminInvitationController.php), [factory](../app/src/Client/Domain/ClientInvitation/Factory/ClientInvitationFactory.php), and [aggregate](../app/src/Client/Domain/ClientInvitation/ClientInvitation.php). Verification: [platform Behat scenarios](../app/tests/Behat/features/platform/client_onboarding.feature) cover notification, OTP, acceptance, rejection recovery, revocation, validation and authorization; [atomicity integration test](../app/tests/Client/Application/Client/OnboardClientIntegrationTest.php) proves rollback after the real outbox write; [concurrency integration tests](../app/tests/Client/Infrastructure/ClientInvitation/ClientInvitationConcurrencyIntegrationTest.php) cover admin accept vs platform revoke in both directions; [domain tests](../app/tests/Client/Domain/ClientInvitation/) preserve invitation rules. Both architecture documents describe all three platform contracts in §11.1 and the transaction in §9. All five quality gates passed sequentially: CS, PHPStan, Deptrac (zero violations/uncovered dependencies), PHPUnit (215 tests, 1173 assertions), and Behat (66 scenarios, 762 steps). The full suite also preserves the client selection, membership and invitation flows from 5.1–5.2.

### Notes

Decisions made on 2026-09-28:

- No automatic client selection in the API; a dedicated `GET /api/me/clients` instead of extending the verify response.
- Invitations replace direct provisioning; the invitation notification replaces the registration notification.
- Client admins invite and revoke with role `user` only; role `admin` is granted and revoked by the platform.
- Invitation transitions use optimistic locking.
- No expiry; admins can revoke; admins are not notified about acceptance or rejection.
- Client onboarding always ends with an admin invitation.

Out of scope: magic-link login, invitation expiry, admin notifications, custom roles beyond `user` / `admin`.

Follow-up candidate, not scheduled: the invariant "a client keeps at least one active admin". Today neither `replaceRoles` nor `suspend` prevents removing the last admin; the 5.3 recovery route fixes the consequence, not the cause. The rule spans several memberships, so it needs an Outside fact and protection against concurrent changes.

---

## 6. Mermaid architecture flow

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

Blocked by item 5. The 2026-09-28 analysis showed that the chain above does not match the code: nested command dispatch, `flush` before event handling, EventLog and EventBus called side by side rather than in sequence, the commit boundary between the outbox write and the worker, and a same-BC consumer. After item 5, base the diagram on the invitation flow and verify each step against the code.

The diagram supports understanding. It does not replace the architecture documentation. Keep it simple and readable. Do not introduce full C4 diagrams or a complete dependency map.

Do this before the README polish, because the diagram becomes direct input for the README architecture section.

---

## 7. README first screen polish

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

## 8. Architecture Decision Records

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

## 9. Repository hygiene

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

## 10. Dependabot

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
