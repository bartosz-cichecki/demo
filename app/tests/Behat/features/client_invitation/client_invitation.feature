Feature: Client membership invitations
    Membership requires the invitee's consent. A client admin invites an email address,
    the invitee is notified asynchronously, logs in with OTP and accepts or rejects.

    Background:
        Given there is a client "acme"
        And there is a user "adm" with email "adm@example.com"
        And there is a membership of "adm" in "acme" with roles "admin"

    # === Happy path ===

    Scenario: Invitee is notified asynchronously, logs in with OTP, accepts and works in the client
        Given I am logged in as "adm" in client "acme"
        When I invite "new-person@example.com" to client "acme" with role "user"
        Then the response status should be 201
        And a pending invitation of "new-person@example.com" to "acme" with role "user" should exist
        And no user with email "new-person@example.com" should exist
        And 0 invitation notifications to "acme" for "new-person@example.com" should be stored
        When the integration events are processed
        Then 1 invitation notification to "acme" for "new-person@example.com" should be stored
        And no user with email "new-person@example.com" should exist
        When I request OTP for email "new-person@example.com"
        Then I can read the delivered OTP for "new-person@example.com" from the demo mailbox
        When I verify OTP for email "new-person@example.com" with the delivered code
        Then OTP verify response should be ok true
        And session should not contain active client id
        When I list my invitations
        Then my invitations response should contain exactly:
            | email                  | client | role |
            | new-person@example.com | acme   | user |
        When I accept the invitation of "new-person@example.com" to "acme"
        Then the response status should be 204
        And the invitation of "new-person@example.com" to "acme" should have status "accepted"
        And "new-person@example.com" should be a member of "acme" with roles "user" and status "active"
        And client "acme" should have 2 members
        And session should contain active client id for "acme"
        And the session id should have changed on accepting the invitation
        When I request my active clients
        Then my active clients response should contain exactly:
            | client | roles |
            | acme   | user  |
        When I list my invitations
        Then my invitations response should be empty
        When I accept the invitation of "new-person@example.com" to "acme"
        Then the response status should be 409
        And the response error should be "Invitation is not pending"
        And client "acme" should have 2 members

    Scenario: The invitation notification is written exactly once despite repeated worker runs
        Given there is a pending invitation of "bob@example.com" to "acme" with role "user"
        When the integration events are processed
        And the integration events are processed
        And the integration events are processed
        Then 1 invitation notification to "acme" for "bob@example.com" should be stored
        And the invitation integration event for "bob@example.com" should be processed exactly once

    # === Invitee decisions ===

    Scenario: Invitee rejects the invitation and does not become a member
        Given there is a pending invitation of "bob@example.com" to "acme" with role "user"
        When I request OTP for email "bob@example.com"
        And I verify OTP for email "bob@example.com" with code "123456"
        And I reject the invitation of "bob@example.com" to "acme"
        Then the response status should be 204
        And the invitation of "bob@example.com" to "acme" should have status "rejected"
        And "bob@example.com" should not be a member of "acme"
        And session should not contain active client id
        When I list my invitations
        Then my invitations response should be empty
        When I reject the invitation of "bob@example.com" to "acme"
        Then the response status should be 409
        And the response error should be "Invitation is not pending"
        When I accept the invitation of "bob@example.com" to "acme"
        Then the response status should be 409
        And the response error should be "Invitation is not pending"
        And the invitation of "bob@example.com" to "acme" should have status "rejected"
        And "bob@example.com" should not be a member of "acme"
        And session should not contain active client id

    Scenario: A user sees only own pending invitations and cannot act on someone else's
        Given there is a client "beta"
        And there is a pending invitation of "bob@example.com" to "acme" with role "user"
        And there is a pending invitation of "eve@example.com" to "beta" with role "user"
        When I request OTP for email "eve@example.com"
        And I verify OTP for email "eve@example.com" with code "123456"
        And I list my invitations
        Then my invitations response should contain exactly:
            | email           | client | role |
            | eve@example.com | beta   | user |
        When I accept the invitation of "bob@example.com" to "acme"
        Then the response status should be 404
        And the response error should be "Not found"
        When I reject the invitation of "bob@example.com" to "acme"
        Then the response status should be 404
        And the response error should be "Not found"
        And the invitation of "bob@example.com" to "acme" should have status "pending"
        And "eve@example.com" should not be a member of "acme"
        And session should not contain active client id

    Scenario: A membership created before acceptance makes accept fail and keeps the invitation pending
        Given there is a pending invitation of "bob@example.com" to "acme" with role "user"
        And there is a user "bob" with email "bob@example.com"
        And there is a membership of "bob" in "acme" with roles "admin"
        When I request OTP for email "bob@example.com"
        And I verify OTP for email "bob@example.com" with code "123456"
        And I accept the invitation of "bob@example.com" to "acme"
        Then the response status should be 409
        And the response error should be "User is already a member of this client"
        And the invitation of "bob@example.com" to "acme" should have status "pending"
        And "bob@example.com" should be a member of "acme" with roles "admin" and status "active"
        And client "acme" should have 2 members
        And session should not contain active client id
        Given I am logged in as "adm" in client "acme"
        When I revoke the invitation of "bob@example.com" to "acme"
        Then the response status should be 204
        And the invitation of "bob@example.com" to "acme" should have status "revoked"

    Scenario: Invitee routes require a logged-in user
        Given there is a pending invitation of "bob@example.com" to "acme" with role "user"
        When I list my invitations
        Then the response status should be 401
        When I accept the invitation of "bob@example.com" to "acme"
        Then the response status should be 401
        When I reject the invitation of "bob@example.com" to "acme"
        Then the response status should be 401
        And the invitation of "bob@example.com" to "acme" should have status "pending"

    # === Client admin: revoke ===

    Scenario: Client admin revokes a pending invitation, which can no longer be accepted, and may invite again
        Given there is a pending invitation of "bob@example.com" to "acme" with role "user"
        And I am logged in as "adm" in client "acme"
        When I revoke the invitation of "bob@example.com" to "acme"
        Then the response status should be 204
        And the invitation of "bob@example.com" to "acme" should have status "revoked"
        When I revoke the invitation of "bob@example.com" to "acme"
        Then the response status should be 409
        And the response error should be "Invitation is not pending"
        When I request OTP for email "bob@example.com"
        And I verify OTP for email "bob@example.com" with code "123456"
        And I list my invitations
        Then my invitations response should be empty
        When I accept the invitation of "bob@example.com" to "acme"
        Then the response status should be 409
        And the response error should be "Invitation is not pending"
        And "bob@example.com" should not be a member of "acme"
        And session should not contain active client id
        Given I am logged in as "adm" in client "acme"
        When I invite "bob@example.com" to client "acme" with role "user"
        Then the response status should be 201
        And there should be 2 invitations of "bob@example.com" to "acme"
        And a pending invitation of "bob@example.com" to "acme" with role "user" should exist

    Scenario: A member without the admin role cannot revoke
        Given there is a pending invitation of "bob@example.com" to "acme" with role "user"
        And there is a user "plain" with email "plain@example.com"
        And there is a membership of "plain" in "acme" with roles "user"
        And I am logged in as "plain" in client "acme"
        When I revoke the invitation of "bob@example.com" to "acme"
        Then the response status should be 403
        And the response error should be "Access denied"
        And the invitation of "bob@example.com" to "acme" should have status "pending"

    Scenario: A client admin cannot revoke an invitation of another client
        Given there is a client "beta"
        And there is a pending invitation of "bob@example.com" to "beta" with role "user"
        And I am logged in as "adm" in client "acme"
        When I revoke the invitation of "bob@example.com" to "beta"
        Then the response status should be 403
        When I revoke the invitation of "bob@example.com" to "beta" through client "acme"
        Then the response status should be 404
        And the response error should be "Not found"
        And the invitation of "bob@example.com" to "beta" should have status "pending"

    # === Client admin: invariants and permissions ===

    Scenario: Only one pending invitation per client and email
        Given there is a pending invitation of "bob@example.com" to "acme" with role "user"
        And I am logged in as "adm" in client "acme"
        When I invite "BOB@example.com" to client "acme" with role "user"
        Then the response status should be 409
        And the response error should be "A pending invitation for this email already exists"
        And there should be 1 invitation of "bob@example.com" to "acme"

    Scenario: An active member cannot be invited
        Given there is a user "bob" with email "bob@example.com"
        And there is a membership of "bob" in "acme" with roles "user"
        And I am logged in as "adm" in client "acme"
        When I invite "bob@example.com" to client "acme" with role "user"
        Then the response status should be 409
        And the response error should be "User is already a member of this client"
        And there should be 0 invitations of "bob@example.com" to "acme"
        And "bob@example.com" should be a member of "acme" with roles "user" and status "active"

    Scenario: A suspended member cannot be invited
        Given there is a user "bob" with email "bob@example.com"
        And there is a membership of "bob" in "acme" with roles "user"
        And membership of "bob" in "acme" is suspended
        And I am logged in as "adm" in client "acme"
        When I invite "bob@example.com" to client "acme" with role "user"
        Then the response status should be 409
        And the response error should be "User is already a member of this client"
        And there should be 0 invitations of "bob@example.com" to "acme"
        And "bob@example.com" should be a member of "acme" with roles "user" and status "suspended"

    Scenario: A member without the admin role cannot invite
        Given there is a user "plain" with email "plain@example.com"
        And there is a membership of "plain" in "acme" with roles "user"
        And I am logged in as "plain" in client "acme"
        When I invite "bob@example.com" to client "acme" with role "user"
        Then the response status should be 403
        And the response error should be "Access denied"
        And there should be 0 invitations of "bob@example.com" to "acme"

    Scenario: An admin of another client cannot invite to this client
        Given there is a client "beta"
        And there is a user "other" with email "other@example.com"
        And there is a membership of "other" in "beta" with roles "admin"
        And I am logged in as "other" in client "beta"
        When I invite "bob@example.com" to client "acme" with role "user"
        Then the response status should be 403
        And there should be 0 invitations of "bob@example.com" to "acme"

    Scenario: Inviting requires an active client
        Given I am logged in as "adm" without active client
        When I invite "bob@example.com" to client "acme" with role "user"
        Then the response status should be 403
        And the response error should be "active_client_required"
        And there should be 0 invitations of "bob@example.com" to "acme"

    Scenario: A client admin cannot invite with role admin
        Given I am logged in as "adm" in client "acme"
        When I invite "bob@example.com" to client "acme" with role "admin"
        Then the response status should be 403
        And the response error should be "Client admin can invite only with role user"
        And there should be 0 invitations of "bob@example.com" to "acme"
