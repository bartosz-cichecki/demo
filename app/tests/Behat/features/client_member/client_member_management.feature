Feature: Client member management

    Background:
        Given there is a client "acme"

    # Members join through invitations: client_invitation/client_invitation.feature

    # === Replace roles (admin) ===

    Scenario: admin can replace roles
        Given there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And there is a user "john" with email "john@example.com"
        And there is a membership of "john" in "acme" with roles "user"
        And I am logged in as "adm" in client "acme"
        When I replace roles for "john" in "acme" with "admin"
        Then the operation should succeed
        And the member "john" in client "acme" should have roles "admin"

    Scenario: user cannot replace roles
        Given there is a user "plain" with email "plain@example.com"
        And there is a membership of "plain" in "acme" with roles "user"
        And there is a user "john" with email "john@example.com"
        And there is a membership of "john" in "acme" with roles "user"
        And I am logged in as "plain" in client "acme"
        When I replace roles for "john" in "acme" with "admin"
        Then response status should be 403

    # === Suspend / unsuspend (admin) ===

    Scenario: admin can suspend and unsuspend another admin and restore member listing
        Given there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And there is a user "john" with email "john@example.com"
        And there is a membership of "john" in "acme" with roles "admin"
        And I am logged in as "john" in client "acme"
        When I list members in "acme"
        Then response status should be 200
        Given I am logged in as "adm" in client "acme"
        When I suspend "john" in "acme"
        Then the operation should succeed
        And the member "john" in client "acme" should have status "suspended"
        Given I am logged in as "john" in client "acme"
        When I list members in "acme"
        Then response status should be 403
        And response error should be "Access denied"
        Given I am logged in as "adm" in client "acme"
        When I unsuspend "john" in "acme"
        Then the operation should succeed
        And the member "john" in client "acme" should have status "active"
        And the member "john" in client "acme" should have roles "admin"
        And there should be exactly 2 memberships for client "acme"
        Given I am logged in as "john" in client "acme"
        When I list members in "acme"
        Then response status should be 200

    Scenario: user cannot suspend
        Given there is a user "plain" with email "plain@example.com"
        And there is a membership of "plain" in "acme" with roles "user"
        And there is a user "john" with email "john@example.com"
        And there is a membership of "john" in "acme" with roles "user"
        And I am logged in as "plain" in client "acme"
        When I suspend "john" in "acme"
        Then response status should be 403

    # === TenantGuard ===

    Scenario: Cross-tenant access is denied
        Given there is a client "other"
        And there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And I am logged in as "adm" in client "acme"
        When I list members in "other"
        Then response status should be 403

    Scenario: Tenant guard denies access when active client is missing
        Given there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And I am logged in as "adm" without active client
        When I list members in "acme"
        Then response status should be 403
        And response error should be "active_client_required"

    Scenario: Tenant guard denies access for suspended membership
        Given there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And membership of "adm" in "acme" is suspended
        And I am logged in as "adm" in client "acme"
        When I list members in "acme"
        Then response status should be 403

    Scenario: Tenant guard denies access for user role
        Given there is a user "plain" with email "plain@example.com"
        And there is a membership of "plain" in "acme" with roles "user"
        And I am logged in as "plain" in client "acme"
        When I list members in "acme"
        Then response status should be 403

    Scenario: admin can list members
        Given there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And I am logged in as "adm" in client "acme"
        When I list members in "acme"
        Then response status should be 200
