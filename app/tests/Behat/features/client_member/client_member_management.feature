Feature: Client member management

    Background:
        Given there is a client "acme"

    # === Provisioning (admin) ===

    Scenario: admin provisions a new member by email
        Given there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And I am logged in as "adm" in client "acme"
        When I provision a member with email "new@example.com"
        Then the membership should be created successfully
        And client "acme" should have 2 members
        And the provisioned member "new@example.com" in client "acme" should have roles "user"
        And the provisioned member "new@example.com" in client "acme" should have status "active"

    Scenario: user cannot provision
        Given there is a user "plain" with email "plain@example.com"
        And there is a membership of "plain" in "acme" with roles "user"
        And I am logged in as "plain" in client "acme"
        When I provision a member with email "new@example.com"
        Then response status should be 403

    Scenario: Duplicate provisioning is rejected
        Given there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And there is a user "existing" with email "existing@example.com"
        And there is a membership of "existing" in "acme" with roles "user"
        And I am logged in as "adm" in client "acme"
        When I provision a member with email "existing@example.com"
        Then I should get a conflict error

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

    Scenario: admin can suspend and unsuspend
        Given there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And there is a user "john" with email "john@example.com"
        And there is a membership of "john" in "acme" with roles "user"
        And I am logged in as "adm" in client "acme"
        When I suspend "john" in "acme"
        Then the operation should succeed
        And the member "john" in client "acme" should have status "suspended"
        When I unsuspend "john" in "acme"
        Then the operation should succeed
        And the member "john" in client "acme" should have status "active"

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

    Scenario: Tenant guard denies provisioning when active client is missing
        Given there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"
        And I am logged in as "adm" without active client
        When I provision a member with email "new@example.com"
        Then response status should be 403

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
