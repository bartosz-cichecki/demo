Feature: User registration

    Scenario: OTP happy path logs user in and creates session
        Given there is a client "acme"
        And there is a user "otp_user" with email "otp-user@example.com"
        And there is a membership of "otp_user" in "acme" with roles "user"
        When I request OTP for email "otp-user@example.com"
        And I verify OTP for email "otp-user@example.com" with code "123456"
        Then OTP verify response should be ok true
        And the latest OTP challenge for "otp-user@example.com" should be consumed
        And the user with email "otp-user@example.com" should be logged in
        And session should contain user id for "otp-user@example.com"
        And session should contain active client id for "acme"

    Scenario: OTP verify with invalid code does not log user in
        When I request OTP for email "otp-user-invalid@example.com"
        And I verify OTP for email "otp-user-invalid@example.com" with code "000000"
        Then OTP verify response should be ok false
        And session should not contain user id

    Scenario: Registered user notification is processed asynchronously
        When I register user "new_user" with email "new-user@example.com"
        Then an integration event for registered user "new-user@example.com" should be stored in the outbox
        When the integration events are processed
        Then a user registration notification for "new-user@example.com" should be stored
        When the integration events are processed
        Then exactly one user registration notification for "new-user@example.com" should be stored
