# Demo platform

This document describes the current platform behavior, HTTP/API contracts and runtime implementation. Architectural rules and guarantees live in the canonical Polish [architecture.md](architecture.md) and its full [English translation](architecture.en.md). Development commands and quality gates are linked from the [README](../README.md#dev-setup). Historical plans in [backlog.md](backlog.md) are not a runtime specification.

## 1. Scope and responsibilities

Demo is a JSON HTTP API; the repository contains no runtime browser application. API routing loads controllers from `app/src/**/Ui/Http/Api/` with the `/api` prefix. Health checks are `GET /health` (nginx) and `GET /api/health` (Symfony through PHP-FPM), covered by `make smoke`.

Client owns tenant workspaces, memberships and invitations; User owns cross-tenant identity and OTP authentication. SharedKernel provides shared mechanisms and is not a business bounded context. No current use case writes synchronously into a foreign BC. Invitations do not create accounts: a missing account is created during successful OTP login by `LogInUserByEmailCommand`; invitation notifications are asynchronous.

The aggregates support these capabilities:

- `Client`: a non-empty name of at most 120 characters; an inactive client cannot be renamed or described. It represents a tenant workspace.
- `ClientMember`: valid roles are `admin` and `user`; suspending sets status `suspended`, and explicit unsuspending restores `active`, without deleting the membership. Tenant admins can replace roles and suspend/unsuspend members.
- `ClientInvitation`: membership requires the invitee's consent. Creation and transition rules are specified in the [invitation contracts](#34-client-invitations) and [platform admin contracts](#32-onboarding-and-administrator-invitations).
- `User`: validates email; blocking requires a non-empty reason. One identity can belong to multiple tenants.
- `OtpChallenge`: the database stores only a code hash; a challenge expires after 10 minutes, can be consumed once and permits at most five failed verification attempts.

The MVP does not store user timezone preferences. Timestamps remain UTC in the backend and read models; local presentation belongs to the UI/browser, under the [time rules](architecture.en.md#62-time-in-the-domain-hard-rule).

## 2. Sessions and access control

Session states and active client selection (assuming cross-origin checks pass):

- Anonymous (no `user_id`): only routes on the `TenantGuardSubscriber` allowlist are available (`api_auth_otp_request`, `api_auth_otp_verify`, `/api/health`). Other guarded routes return 401, `platform_*` routes return 403.
- Logged in without an active client: a successful `POST /api/auth/otp/verify` migrates the session id, sets `user_id` and `is_platform_admin`, and removes any `active_client_id` left from an earlier login in the same session. Login never selects a client and does not require a membership. Routes in `ACTIVE_CLIENT_OPTIONAL_ROUTE_NAMES` (`api_me_clients_list`, `api_session_active_client_select`, `api_me_invitations_list`, `api_invitations_accept`, `api_invitations_reject`) and, for a platform admin, `platform_*` routes are available. A tenant route returns `403 {"error": "active_client_required"}`.
- Logged in with an active client: `POST /api/session/active-client` sets `active_client_id` only for an active membership of the user and migrates the session id. A later call switches the client; a refused selection leaves the session state unchanged. A successful `POST /api/invitations/{invitationId}/accept` also makes the invitation's client active and migrates the session, but only after commit; a refused accept does not change the session. Tenant routes then go through the guard checks below.

`PlatformAdminOnLoginSubscriber` derives `session.is_platform_admin` from the `app.platform_admin_emails` allowlist after OTP verification. The subscriber reacts to a 200 response on `api_auth_otp_verify` when the session contains a user id; it does not inspect the `ok` payload. `PlatformAdminGuardSubscriber` requires a non-empty string `user_id` and `is_platform_admin === true`; otherwise it returns 403. Platform access does not depend on tenant membership.

`TenantGuardSubscriber` covers main requests under `/api/`. For mutating methods it first checks same-origin `Origin`, falling back to `Referer` when `Origin` is absent; requests without either header pass this check. Platform routes and allowlisted requests skip tenant checks only after this check.

- After accounting for platform and allowlist exceptions, `TenantGuardSubscriber` requires the `user_id` of an existing, non-blocked user. Routes in `ACTIVE_CLIENT_OPTIONAL_ROUTE_NAMES` do not require an active client. For the others, the guard requires `active_client_id`, a route `{clientId}` matching the active client, and an active membership, and only permits routes in `ADMIN_REQUIRED_ROUTE_NAMES`, requiring the client administrator role. Other routes covered by the guard are denied access. Adding a route for a regular member requires extending the configuration and handling of access requirements in the guard.
- Guard denials return `{"error": "Access denied"}` with 401 or 403. The only distinguishable case is a tenant route without an active client: `403 {"error": "active_client_required"}`.

`ADMIN_REQUIRED_ROUTE_NAMES` currently contains invitation create/revoke and member list/replace-roles/suspend/unsuspend. `ACTIVE_CLIENT_OPTIONAL_ROUTE_NAMES` is listed with the session states above. See [TenantGuardSubscriber](../app/src/User/Ui/Http/Security/TenantGuardSubscriber.php) and [PlatformAdminGuardSubscriber](../app/src/User/Ui/Http/Security/PlatformAdminGuardSubscriber.php) for the guard implementation.

## 3. HTTP/API contracts

The following contracts cover authentication, session selection, onboarding and invitations. Access checks from [§2](#2-sessions-and-access-control) apply before controller behavior. Contract change rules remain in [architecture §11.1](architecture.en.md#111-http-and-routing-contracts).

### 3.1 OTP login

- `POST /api/auth/otp/request` (`api_auth_otp_request`) takes `{"email":"user@example.com"}` and returns `200 {"ok":true}` for an allowed request as well as one refused by cooldown.
- `POST /api/auth/otp/verify` (`api_auth_otp_verify`) takes `{"email":"user@example.com","code":"123456"}` and returns `200 {"ok":true}` for successful verification or `200 {"ok":false}` for failed verification. Input schema validation can return 400; the code input must be a six-character string.
- A successful login consumes the challenge and creates the session described in [§2](#2-sessions-and-access-control); it does not require or select a membership.

For local delivery, each allowed request appends a JSON line with `email` and `code` to `app/var/notifications/otp.jsonl` (inside the container: `/var/www/app/var/notifications/otp.jsonl`). Read the latest message for the recipient and verify with the actual delivered code. Preserve cookies between requests to keep the login session, then use the [active-client endpoints](#33-active-client-selection) to select a tenant.

The factory enforces a 60-second cooldown independently for email and IP. Blocked requests still return `200 {"ok":true}` and write neither a challenge nor a message. Tests use a separate mailbox, `app/var/notifications/otp.test.jsonl`. File delivery happens before the database commit; a later commit failure does not undo the message in this local demo.

### 3.2 Onboarding and administrator invitations

Onboarding and administrator invitations (`Client/Ui/Http/Api/ClientController`, `PlatformAdminInvitationController`). All three routes use the `platform_` prefix, require a platform administrator and do not require an active client; anonymous callers and users without platform privileges receive `403 {"error": "Access denied"}`. Invalid input → `400 {"errors": [{"field": "...", "message": "..."}]}`. Email addresses are normalized by `Email`.

- `POST /api/clients` (`platform_clients_create`) with `{"name": "...", "adminEmail": "...", "description": "..."}` → `201 {"id": "<client uuid>"}`. `name` and a valid `adminEmail` are required, `description` is optional. A missing `adminEmail`, empty or invalid address → 400 without creating a client. `OnboardClientCommand` atomically creates the client and a pending invitation with role `admin` ([atomicity](#41-client-onboarding)), without a user account or membership. The worker sends the notification; the invitee logs in through OTP and uses the [invitation list and accept/reject endpoints](#34-client-invitations). Acceptance grants role `admin` and sets the active client after commit.
- `POST /api/clients/{clientId}/admin-invitations` (`platform_client_admin_invitations_create`) with `{"email": "..."}` → `201 {"id": "<invitation uuid>"}`. Creates an invitation with role `admin` to an existing client, including after rejection of the first invitation or loss of administrators. A non-existent client → `404 {"error": "Not found"}`; a pending invitation for this client and email, regardless of role → `409 {"error": "A pending invitation for this email already exists"}`; an existing active or suspended membership → `409 {"error": "User is already a member of this client"}`. The shared invitation factory rules remain the same as in the tenant flow; the input does not specify a role.
- `POST /api/clients/{clientId}/admin-invitations/revoke` (`platform_client_admin_invitations_revoke`) with `{"email": "..."}` → 204 with no body. Revokes only a pending invitation with role `admin` for the specified client and email. No pending invitation (also after an earlier accept/reject/revoke) → `404 {"error": "Not found"}`; a pending invitation with role `user` → `403 {"error": "Platform admin can revoke only invitations with role admin"}`. A revoked invitation cannot be accepted later. No invitation id or separate platform listing is needed. A `{clientId}` that is not a lowercase UUID → 404 from routing for both admin-invitations routes.
- Invitations with role `admin` can be created and revoked only through the platform flow. Tenant routes still allow creating and revoking only invitations with role `user`. Recovery does not enforce keeping at least one active administrator.

### 3.3 Active client selection

Logged-in session endpoints (`User/Ui/Http/Api/ActiveClientController`):

- `GET /api/me/clients` (`api_me_clients_list`) → 200 with the user's active memberships: `[{"clientId": "...", "clientName": "...", "roles": ["admin"]}]`. Suspended memberships are not returned; a user without memberships gets `[]`. The API never selects a client automatically, even for a one-element list.
- `POST /api/session/active-client` (`api_session_active_client_select`) with `{"clientId": "<uuid>"}` → 204 with no body; the session stores the lowercase UUID, matching the ids from `GET /api/me/clients`. A client without an active membership of the user (foreign, suspended, non-existent) → `403 {"error": "Access denied"}`; a `clientId` that is not a UUID → 400 with validation errors.

### 3.4 Client invitations

Client invitations ([ClientInvitationController](../app/src/Client/Ui/Http/Api/ClientInvitationController.php)).

- `POST /api/clients/{clientId}/invitations` (`api_client_invitations_create`, `ADMIN_REQUIRED_ROUTE_NAMES`) with `{"email": "...", "role": "user"}` → 201 `{"id": "<uuid>"}`. A role other than `user` → `403 {"error": "Client admin can invite only with role user"}`; a pending invitation for this client and email → `409 {"error": "A pending invitation for this email already exists"}`; a person with a membership in the client, active or suspended → `409 {"error": "User is already a member of this client"}`; invalid input → 400. The invitation does not create a user account.
- `POST /api/clients/{clientId}/invitations/{invitationId}/revoke` (`api_client_invitations_revoke`, `ADMIN_REQUIRED_ROUTE_NAMES`) → 204. A non-existent invitation or one of another client → `404 {"error": "Not found"}`; an invitation with role `admin` → `403 {"error": "Client admin can revoke only invitations with role user"}`; a status other than `pending` → `409 {"error": "Invitation is not pending"}`.
- `GET /api/me/invitations` (`api_me_invitations_list`) → 200 `[{"id": "...", "clientId": "...", "clientName": "...", "role": "user", "createdAt": "..."}]`: only pending invitations addressed to the logged-in user's email.
- `POST /api/invitations/{invitationId}/accept` (`api_invitations_accept`) → 204. In one transaction it stores `accepted` and creates the membership with the invitation's role through `ClientMemberFactory`; after commit it sets `active_client_id` ([session effects](#2-sessions-and-access-control)). A non-existent invitation or one addressed to another email → `404 {"error": "Not found"}`; a status other than `pending` → `409 {"error": "Invitation is not pending"}`; a membership that exists at accept time → `409 {"error": "User is already a member of this client"}`, the invitation stays `pending` and the admin can revoke it.
- `POST /api/invitations/{invitationId}/reject` (`api_invitations_reject`) → 204, without a membership; the same not-found/wrong-email 404 and non-pending 409 refusals as accept; rejection does not check for an existing membership.
- Concurrent accept, reject and tenant revoke are serialized by a row lock; a status other than `pending` after acquiring the lock → `409 {"error": "Invitation is not pending"}` ([concurrency](#42-invitation-acceptance-and-concurrency)). An `{invitationId}` that is not a lowercase UUID → 404 from routing.

## 4. Flows and implementation

The following details apply the [transaction](architecture.en.md#9-transactions-and-flush-one-point), [outbox](architecture.en.md#92-outbox-and-async-consumption-guarantees) and [locking](architecture.en.md#94-aggregate-locking-and-state-freshness) rules to the current platform.

### 4.1 Client onboarding

Client onboarding through [OnboardClientCommandHandler](../app/src/Client/Application/Client/Command/OnboardClient/OnboardClientCommandHandler.php) stores the `Client` and the first invitation with role `admin` in one `CommandBus` transaction. `ClientInvitationSaga` writes the notification integration event to the outbox in the same transaction; a failure before commit rolls back the client, invitation, EventLog and outbox.

The platform admin supplies the client's name and first administrator's email. After commit, the worker sends the invitation notification. The invitee logs in through OTP, lists the invitation and accepts it to obtain the admin membership and active client. Recovery uses the same invitation flow for an existing client, under the [HTTP contracts](#32-onboarding-and-administrator-invitations).

`CreateClientCommand` and `CreateClientMemberCommand` are fixture/test tools without production HTTP routes. Production membership creation happens through invitation acceptance.

### 4.2 Invitation acceptance and concurrency

`ClientInvitation::accept(userId)` checks invitation rules, changes its state and records `ClientInvitationAccepted`. Then [AcceptClientInvitationCommandHandler](../app/src/Client/Application/ClientInvitation/Command/AcceptClientInvitation/AcceptClientInvitationCommandHandler.php) reads the immutable client and role data through `ClientInvitationQueryInterface` while still holding the invitation lock, creates the membership through `ClientMemberFactory` and persists it through the repository in the same `CommandBus` transaction. A failure to create or persist the membership also rolls back invitation acceptance.

- `accept`, `reject` and `revoke` run in one short request transaction opened by `CommandBus`. [ClientInvitationRepository](../app/src/Client/Infrastructure/ClientInvitation/ClientInvitationRepository.php) loads `ClientInvitation` through ORM with `LockMode::PESSIMISTIC_WRITE` (`SELECT ... FOR UPDATE`) as the aggregate's first load into the UnitOfWork. The aggregate has no technical version.
- The row lock lasts until commit/rollback. A concurrent request waits and sees the committed state after acquiring the lock. The existing `assertPending()` rule refuses a disallowed transition with `ClientInvitationNotPendingException`, mapped to `409 {"error": "Invitation is not pending"}`.
- Platform revoke uses one locking ORM query to load an invitation by client, normalized email and `pending` status. If the row no longer matches after waiting, the missing pending invitation results in 404, just as after an earlier accept/reject/revoke.
- The partial unique index `(client_id, email) WHERE status = 'pending'` still protects concurrent invitation creation: there is no row to lock before INSERT. The unique membership constraint `(client_id, user_id)` remains an additional safeguard.

The first-load/identity-map requirement is defined in [architecture §9.4](architecture.en.md#94-aggregate-locking-and-state-freshness). Adding an earlier ORM load also requires checking the platform revoke contract: it selects by pending status, while the other transitions validate status in the aggregate. Session changes happen only after the acceptance command commits.

### 4.3 Invitation notifications

`Client/Application/ClientInvitation/Saga/ClientInvitationSaga` translates `ClientInvitationCreated` into `ClientInvitationCreatedIntegrationEvent` (invitation id, client and its name, email, role and occurrence time). The outbox write happens in the transaction that creates the invitation. The consumer is `User/Application/IntegrationEventSubscriber/SendClientInvitationNotificationSubscriber`, which sends one notification through User's own `UserNotificationSenderServiceInterface` port. The local [FileUserNotificationSenderService](../app/src/User/Infrastructure/Notification/FileUserNotificationSenderService.php) appends a JSON line to `var/notifications/user_notifications.jsonl` (`user_notifications.test.jsonl` in tests).

Paths above are relative to the application directory (`app/` in the repository). The notification asks the recipient to log in and accept or reject. Repeated worker runs skip completed consumption; the local file adapter also deduplicates identical lines under an exclusive file lock, covering interruption between delivery and marking `processed`. This is the concrete idempotency mechanism for this demo, not a general exactly-once delivery guarantee.

### 4.4 Outbox runtime

- `DbalOutboxPublisher` assigns the technical `event_id`, stores `event_name` as the event class FQCN, JSON payload, and `created_at` from `ClockInterface` as a UTC storage string.
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
- `shared.async_consumption` stores claims and idempotency per `(event_id, subscriber, handler_method)`.
- The worker uses a claim-before-side-effect model:
  - before calling the handler, it tries to atomically insert or take over an `async_consumption` record with status `processing`
  - if the record has status `processed`, the handler is skipped
  - if the claim belongs to another active worker, the outbox receives an error and returns to retry
  - after handler success, the worker marks consumption as `processed` only if `claimed_by` ownership is preserved
  - after a handler exception, the worker removes its own consumption claim and releases the outbox for retry
- The outbox is marked as `processed` only after all matching handlers succeed and only if ownership (`claimed_by`) is preserved.
- Known MVP limitations:
  - no message broker
  - no Redis
  - no `LISTEN/NOTIFY`
  - no dead letter queue
  - the worker uses polling instead of a wake-up signal

The worker stores at most 500 characters in `last_error`. Lease expiry applies to both outbox and consumption claims. An event without matching handlers is marked processed. Subscriber registration and method conventions are defined in [architecture §8.1](architecture.en.md#81-integration-events-contract).

Implementation: [publisher](../app/src/SharedKernel/Infrastructure/IntegrationEvent/DbalOutboxPublisher.php), [worker](../app/src/SharedKernel/Ui/ConsoleCommands/ProcessOutboxCommand.php). The limits above bound automatic retries; external side effects still require the [architectural idempotency guarantee](architecture.en.md#92-outbox-and-async-consumption-guarantees).

## 5. Verification coverage and limitations

These are descriptions of the checked-in tests, not results of a fresh suite run:

- [OnboardClientIntegrationTest](../app/tests/Client/Application/Client/OnboardClientIntegrationTest.php) forces failure after the real ORM flush and outbox write, then verifies rollback of client, invitation, EventLog and outbox.
- [ClientInvitationConcurrencyIntegrationTest](../app/tests/Client/Infrastructure/ClientInvitation/ClientInvitationConcurrencyIntegrationTest.php) checks all three mutating repository reads: a second independent connection receives SQLSTATE `55P03` (`lock_not_available`) from `FOR UPDATE NOWAIT`; rollback releases the lock. It proves locking, not every possible pair of concurrent HTTP requests.
- [Invitation domain tests](../app/tests/Client/Domain/ClientInvitation/) cover refused transitions from terminal states. [Invitation Behat scenarios](../app/tests/Behat/features/client_invitation/client_invitation.feature) cover notification → OTP → acceptance, rejection, revocation, duplicates, existing memberships and authorization; [platform scenarios](../app/tests/Behat/features/platform/client_onboarding.feature) cover onboarding and recovery, including revoke returning 404 without a pending invitation.
- Acceptance maps `UniqueConstraintViolationException` to the existing-membership 409 as a defensive fallback. That HTTP mapping branch has no dedicated test; the existing-membership domain refusal is covered by Behat.
- [ClientInvitationNotificationIntegrationTest](../app/tests/Client/Application/ClientInvitation/ClientInvitationNotificationIntegrationTest.php) simulates redelivery after a crash and checks that the local notification is not duplicated. [ProcessOutboxCommandTest](../app/tests/SharedKernel/Ui/ConsoleCommands/ProcessOutboxCommandTest.php) covers claims, retries, completed consumption, absent handlers and ownership loss.
- [OTP scenarios](../app/tests/Behat/features/user/user_registration.feature) and [active-client scenarios](../app/tests/Behat/features/user/active_client_selection.feature) cover login and session selection. [Membership scenarios](../app/tests/Behat/features/client_member/client_member_management.feature) cover suspend/unsuspend while preserving the member record. [Tenant authorization](../app/tests/Behat/features/tenant/client_create_forbidden.feature) covers denied platform client creation.
