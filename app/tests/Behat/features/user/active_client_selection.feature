Feature: Explicit active client selection

    Scenario: OTP login does not select an active client for a user with several memberships
        Given there is a client "alpha"
        And there is a client "beta"
        And there is a user "multi" with email "multi@example.com"
        And there is a membership of "multi" in "alpha" with roles "admin"
        And there is a membership of "multi" in "beta" with roles "user"
        When I request OTP for email "multi@example.com"
        And I verify OTP for email "multi@example.com" with code "123456"
        Then OTP verify response should be ok true
        And session should contain user id for "multi@example.com"
        And session should not contain active client id

    Scenario: A user without any membership can log in and gets an empty client list
        When I request OTP for email "no-membership@example.com"
        And I verify OTP for email "no-membership@example.com" with code "123456"
        Then OTP verify response should be ok true
        And the user with email "no-membership@example.com" should be logged in
        And session should contain user id for "no-membership@example.com"
        And session should not contain active client id
        When I request my active clients
        Then my active clients response should be empty

    Scenario: The client list contains only the user's active memberships
        Given there is a client "alpha"
        And there is a client "beta"
        And there is a client "gamma"
        And there is a client "delta"
        And there is a user "multi" with email "multi@example.com"
        And there is a user "other" with email "other@example.com"
        And there is a membership of "multi" in "alpha" with roles "admin"
        And there is a membership of "multi" in "beta" with roles "user"
        And there is a membership of "multi" in "gamma" with roles "admin"
        And membership of "multi" in "gamma" is suspended
        And there is a membership of "other" in "delta" with roles "admin"
        When I request OTP for email "multi@example.com"
        And I verify OTP for email "multi@example.com" with code "123456"
        And I request my active clients
        Then my active clients response should contain exactly:
            | client | roles |
            | alpha  | admin |
            | beta   | user  |

    Scenario: A tenant route before client selection requires an active client
        Given there is a client "alpha"
        And there is a user "multi" with email "multi@example.com"
        And there is a membership of "multi" in "alpha" with roles "admin"
        When I request OTP for email "multi@example.com"
        And I verify OTP for email "multi@example.com" with code "123456"
        And I list members of client "alpha"
        Then the response status should be 403
        And the response error should be "active_client_required"

    Scenario: Selecting an active client migrates the session and opens tenant routes
        Given there is a client "alpha"
        And there is a user "multi" with email "multi@example.com"
        And there is a membership of "multi" in "alpha" with roles "admin"
        When I request OTP for email "multi@example.com"
        And I verify OTP for email "multi@example.com" with code "123456"
        And I select active client "alpha"
        Then the response status should be 204
        And session should contain user id for "multi@example.com"
        And session should contain active client id for "alpha"
        And the session id should have changed on client selection
        And the session id from before the client selection should no longer be logged in
        When I list members of client "alpha"
        Then the response status should be 200

    Scenario: The active client can be switched during the session
        Given there is a client "alpha"
        And there is a client "beta"
        And there is a user "multi" with email "multi@example.com"
        And there is a membership of "multi" in "alpha" with roles "admin"
        And there is a membership of "multi" in "beta" with roles "admin"
        When I request OTP for email "multi@example.com"
        And I verify OTP for email "multi@example.com" with code "123456"
        And I select active client "alpha"
        Then the response status should be 204
        When I list members of client "alpha"
        Then the response status should be 200
        When I select active client "beta"
        Then the response status should be 204
        And session should contain active client id for "beta"
        And the session id should have changed on client selection
        When I list members of client "beta"
        Then the response status should be 200
        When I list members of client "alpha"
        Then the response status should be 403
        And the response error should be "Access denied"

    Scenario Outline: Selecting a client without an active membership is refused and keeps the current selection
        Given there is a client "alpha"
        And there is a client "gamma"
        And there is a client "foreign"
        And there is a user "multi" with email "multi@example.com"
        And there is a user "other" with email "other@example.com"
        And there is a membership of "multi" in "alpha" with roles "admin"
        And there is a membership of "multi" in "gamma" with roles "admin"
        And membership of "multi" in "gamma" is suspended
        And there is a membership of "other" in "foreign" with roles "admin"
        When I request OTP for email "multi@example.com"
        And I verify OTP for email "multi@example.com" with code "123456"
        And I select active client "<refused_client>"
        Then the response status should be 403
        And the response error should be "Access denied"
        And session should not contain active client id
        When I select active client "alpha"
        Then the response status should be 204
        When I select active client "<refused_client>"
        Then the response status should be 403
        And the response error should be "Access denied"
        And session should contain active client id for "alpha"
        When I list members of client "<refused_client>"
        Then the response status should be 403

        Examples:
            | refused_client |
            | foreign        |
            | gamma          |

    Scenario: Selecting a client with an uppercase id stores the canonical id used by tenant routes
        Given there is a client "alpha"
        And there is a user "multi" with email "multi@example.com"
        And there is a membership of "multi" in "alpha" with roles "admin"
        When I request OTP for email "multi@example.com"
        And I verify OTP for email "multi@example.com" with code "123456"
        And I select active client "alpha" using an uppercase id
        Then the response status should be 204
        And session should contain active client id for "alpha"
        When I list members of client "alpha"
        Then the response status should be 200

    Scenario: Selecting a client with a malformed id is rejected as invalid input
        Given there is a user "multi" with email "multi@example.com"
        When I request OTP for email "multi@example.com"
        And I verify OTP for email "multi@example.com" with code "123456"
        And I select active client with id "not-a-uuid"
        Then the response status should be 400
        And session should not contain active client id

    Scenario: Logging in again in the same session clears the previously selected client
        Given there is a client "alpha"
        And there is a user "multi" with email "multi@example.com"
        And there is a membership of "multi" in "alpha" with roles "admin"
        When I request OTP for email "multi@example.com"
        And I verify OTP for email "multi@example.com" with code "123456"
        And I select active client "alpha"
        Then the response status should be 204
        Given a fresh OTP challenge exists for email "multi@example.com"
        When I verify OTP for email "multi@example.com" with code "123456"
        Then OTP verify response should be ok true
        And session should not contain active client id
        When I list members of client "alpha"
        Then the response status should be 403
        And the response error should be "active_client_required"

    Scenario: Client list and selection require a logged-in user
        Given there is a client "alpha"
        When I request my active clients
        Then the response status should be 401
        When I select active client "alpha"
        Then the response status should be 401

    Scenario: A platform admin without memberships logs in and uses platform routes without selecting a client
        When I request OTP for email "admin@example.com"
        And I verify OTP for email "admin@example.com" with code "123456"
        Then OTP verify response should be ok true
        And session should not contain active client id
        When I create a client named "Platform Corp"
        Then the response status should be 201
