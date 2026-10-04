Feature: Platform onboards clients by inviting an administrator

    Background:
        Given there is a user "platform" with email "admin@example.com"
        And I am logged in as platform admin "platform"

    Scenario: First administrator receives one notification, logs in with OTP and accepts
        When I onboard client "Acme" with admin email "first@example.com"
        Then the response status should be 201
        And a pending invitation of "first@example.com" to "Acme" with role "admin" should exist
        And no user with email "first@example.com" should exist
        And client "Acme" should have 0 members
        When the integration events are processed
        And the integration events are processed
        Then 1 invitation notification to "Acme" for "first@example.com" should be stored
        And the invitation integration event for "first@example.com" should be processed exactly once
        When I request OTP for email "first@example.com"
        Then I can read the delivered OTP for "first@example.com" from the demo mailbox
        When I verify OTP for email "first@example.com" with the delivered code
        Then OTP verify response should be ok true
        When I list my invitations
        Then my invitations response should contain exactly:
            | email             | client | role  |
            | first@example.com | Acme   | admin |
        When I accept the invitation of "first@example.com" to "Acme"
        Then the response status should be 204
        And "first@example.com" should be a member of "Acme" with roles "admin" and status "active"
        And session should contain active client id for "Acme"
        And the session id should have changed on accepting the invitation
        Given I am logged in as platform admin "platform"
        When I revoke admin "first@example.com" from client "Acme" via platform API
        Then the response status should be 404
        And the response error should be "Not found"

    Scenario: Missing admin email is rejected
        When I onboard client "Incomplete" without admin email
        Then the response status should be 400
        And no client named "Incomplete" should exist

    Scenario Outline: Invalid admin email is rejected without creating a client
        When I onboard client "Invalid" with admin email "<email>"
        Then the response status should be 400
        And no client named "Invalid" should exist

        Examples:
            | email       |
            |             |
            | not-an-email |

    Scenario: A rejected first invitation can be replaced by another administrator
        When I onboard client "Acme" with admin email "first@example.com"
        And I request OTP for email "first@example.com"
        And I verify OTP for email "first@example.com" with code "123456"
        Then OTP verify response should be ok true
        When I reject the invitation of "first@example.com" to "Acme"
        Then the response status should be 204
        And "first@example.com" should not be a member of "Acme"
        Given I am logged in as platform admin "platform"
        When I revoke admin "first@example.com" from client "Acme" via platform API
        Then the response status should be 404
        And the response error should be "Not found"
        When I invite admin "next@example.com" to client "Acme" via platform API
        Then the response status should be 201
        When I request OTP for email "next@example.com" from IP "127.0.0.2"
        And I verify OTP for email "next@example.com" with code "123456"
        Then OTP verify response should be ok true
        When I accept the invitation of "next@example.com" to "Acme"
        Then the response status should be 204
        And "next@example.com" should be a member of "Acme" with roles "admin" and status "active"
        And client "Acme" should have 1 member

    Scenario: Revoke a mistyped email before inviting the correct administrator
        When I onboard client "Acme" with admin email "wrong@example.com"
        And I revoke admin "WRONG@example.com" from client "Acme" via platform API
        Then the response status should be 204
        And the invitation of "wrong@example.com" to "Acme" should have status "revoked"
        When I request OTP for email "wrong@example.com"
        And I verify OTP for email "wrong@example.com" with code "123456"
        Then OTP verify response should be ok true
        When I accept the invitation of "wrong@example.com" to "Acme"
        Then the response status should be 409
        And "wrong@example.com" should not be a member of "Acme"
        And session should not contain active client id
        Given I am logged in as platform admin "platform"
        When I revoke admin "wrong@example.com" from client "Acme" via platform API
        Then the response status should be 404
        And the response error should be "Not found"
        When I invite admin "correct@example.com" to client "Acme" via platform API
        Then the response status should be 201
        When I request OTP for email "correct@example.com" from IP "127.0.0.2"
        And I verify OTP for email "correct@example.com" with code "123456"
        Then OTP verify response should be ok true
        When I accept the invitation of "correct@example.com" to "Acme"
        Then the response status should be 204
        And "correct@example.com" should be a member of "Acme" with roles "admin" and status "active"

    Scenario: A tenant administrator cannot use platform onboarding or manage admin invitations
        Given there is a client "Acme"
        And there is a user "tenant" with email "tenant@example.com"
        And there is a membership of "tenant" in "Acme" with roles "admin"
        When I invite admin "invited@example.com" to client "Acme" via platform API
        Then the response status should be 201
        Given I am logged in as "tenant" in client "Acme"
        When I onboard client "Forbidden" with admin email "new@example.com"
        Then the response status should be 403
        And no client named "Forbidden" should exist
        When I invite admin "new@example.com" to client "Acme" via platform API
        Then the response status should be 403
        When I revoke admin "invited@example.com" from client "Acme" via platform API
        Then the response status should be 403
        When I revoke the invitation of "invited@example.com" to "Acme"
        Then the response status should be 403
        And a pending invitation of "invited@example.com" to "Acme" with role "admin" should exist
        When I invite "new@example.com" to client "Acme" with role "admin"
        Then the response status should be 403
        And there should be 0 invitations of "new@example.com" to "Acme"

    Scenario: An unauthenticated caller cannot use any platform onboarding route
        Given there is a client "Acme"
        And I am anonymous
        When I onboard client "Forbidden" with admin email "new@example.com"
        Then the response status should be 403
        When I invite admin "new@example.com" to client "Acme" via platform API
        Then the response status should be 403
        When I revoke admin "new@example.com" from client "Acme" via platform API
        Then the response status should be 403
        And there should be 0 invitations of "new@example.com" to "Acme"

    Scenario: Recovery shares invitation uniqueness and membership rules
        Given there is a client "Acme"
        When I invite admin "new@example.com" to client "Acme" via platform API
        Then the response status should be 201
        When I invite admin "NEW@example.com" to client "Acme" via platform API
        Then the response status should be 409
        And there should be 1 invitation of "new@example.com" to "Acme"
        When I request OTP for email "new@example.com"
        And I verify OTP for email "new@example.com" with code "123456"
        Then OTP verify response should be ok true
        When I accept the invitation of "new@example.com" to "Acme"
        Then the response status should be 204
        Given I am logged in as platform admin "platform"
        When I invite admin "new@example.com" to client "Acme" via platform API
        Then the response status should be 409

    Scenario: A suspended membership also prevents an admin invitation
        Given there is a client "Acme"
        And there is a user "member" with email "member@example.com"
        And there is a membership of "member" in "Acme" with roles "user"
        And membership of "member" in "Acme" is suspended
        When I invite admin "member@example.com" to client "Acme" via platform API
        Then the response status should be 409
        And there should be 0 invitations of "member@example.com" to "Acme"

    Scenario: Platform revoke cannot revoke a user invitation
        Given there is a client "Acme"
        And there is a pending invitation of "member@example.com" to "Acme" with role "user"
        When I revoke admin "member@example.com" from client "Acme" via platform API
        Then the response status should be 403
        And the invitation of "member@example.com" to "Acme" should have status "pending"

    Scenario: Recovery for a nonexistent client is not found
        When I invite an admin to a nonexistent client via platform API
        Then the response status should be 404
