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

    Scenario: OTP challenge is locked after five separate invalid verification requests
        Given there is a client "otp-lock-client"
        And there is a user "otp_lock_user" with email "otp-lock@example.com"
        And there is a membership of "otp_lock_user" in "otp-lock-client" with roles "user"
        When I request OTP for email "otp-lock@example.com"
        And I verify OTP for email "otp-lock@example.com" with code "000000"
        And I verify OTP for email "otp-lock@example.com" with code "000000"
        And I verify OTP for email "otp-lock@example.com" with code "000000"
        And I verify OTP for email "otp-lock@example.com" with code "000000"
        And I verify OTP for email "otp-lock@example.com" with code "000000"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-lock@example.com" should have 5 attempts
        When I verify OTP for email "otp-lock@example.com" with code "123456"
        Then OTP verify response should be ok false
        And session should not contain user id

    Scenario: Fresh OTP challenge allows verification after the previous challenge was exhausted
        Given there is a client "otp-recovery-client"
        And there is a user "otp_recovery_user" with email "otp-recovery@example.com"
        And there is a membership of "otp_recovery_user" in "otp-recovery-client" with roles "user"
        And an exhausted OTP challenge exists for email "otp-recovery@example.com"
        And a fresh OTP challenge exists for email "otp-recovery@example.com"
        When I verify OTP for email "otp-recovery@example.com" with code "123456"
        Then OTP verify response should be ok true
        And the latest OTP challenge for "otp-recovery@example.com" should be consumed
        And session should contain user id for "otp-recovery@example.com"
        And session should contain active client id for "otp-recovery-client"

    Scenario: Registered user notification is processed asynchronously
        When I register user "new_user" with email "new-user@example.com"
        Then an integration event for registered user "new-user@example.com" should be stored in the outbox
        When the integration events are processed
        Then a user registration notification for "new-user@example.com" should be stored
        When the integration events are processed
        Then exactly one user registration notification for "new-user@example.com" should be stored
