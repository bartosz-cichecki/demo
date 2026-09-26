Feature: User registration

    Scenario: OTP happy path logs user in and creates session
        Given there is a client "acme"
        And there is a user "otp_user" with email "otp-user@example.com"
        And there is a membership of "otp_user" in "acme" with roles "user"
        When I request OTP for email "otp-user@example.com"
        Then I can read the delivered OTP for "otp-user@example.com" from the demo mailbox
        And the demo mailbox should contain 1 OTP messages
        And there should be 1 OTP challenges for "otp-user@example.com"
        When I verify OTP for email "otp-user@example.com" with the delivered code
        Then OTP verify response should be ok true
        And the latest OTP challenge for "otp-user@example.com" should be consumed
        And the user with email "otp-user@example.com" should be logged in
        And session should contain user id for "otp-user@example.com"
        And session should contain active client id for "acme"

    Scenario Outline: Cooldown silently blocks delivery for the same email or IP
        When I request OTP for email "cooldown@example.com" from IP "192.0.2.1"
        And I request OTP for email "<email>" from IP "<ip>"
        Then the demo mailbox should contain 1 OTP messages
        And there should be 1 OTP challenges for "cooldown@example.com"
        And there should be 1 OTP challenges in total

        Examples:
            | email                | ip        |
            | cooldown@example.com | 192.0.2.1 |
            | cooldown@example.com | 192.0.2.2 |
            | another@example.com  | 192.0.2.1 |

    Scenario Outline: Cooldown ends exactly 60 seconds after issuance
        Given OTP was requested for "boundary@example.com" from IP "192.0.2.1" <seconds> seconds ago
        When I request OTP for email "boundary@example.com" from IP "192.0.2.1"
        Then the demo mailbox should contain <count> OTP messages
        And there should be <count> OTP challenges for "boundary@example.com"

        Examples:
            | seconds | count |
            | 59      | 1     |
            | 60      | 2     |

    Scenario: A different email and IP can receive OTP independently
        When I request OTP for email "first@example.com" from IP "192.0.2.1"
        And I request OTP for email "second@example.com" from IP "192.0.2.2"
        Then the demo mailbox should contain 2 OTP messages
        And there should be 1 OTP challenges for "first@example.com"
        And there should be 1 OTP challenges for "second@example.com"
        And I can read the delivered OTP for "second@example.com" from the demo mailbox

    Scenario Outline: OTP login selects an active membership and skips a suspended admin membership
        Given there is a client "alpha"
        And there is a client "beta"
        And there is a user "mixed_member" with email "mixed-member@example.com"
        And there is a membership of "mixed_member" in "<suspended_client>" with roles "admin"
        And membership of "mixed_member" in "<suspended_client>" is suspended
        And there is a membership of "mixed_member" in "<active_client>" with roles "user"
        When I request OTP for email "mixed-member@example.com"
        And I verify OTP for email "mixed-member@example.com" with code "123456"
        Then OTP verify response should be ok true
        And session should contain user id for "mixed-member@example.com"
        And session should contain active client id for "<active_client>"

        Examples:
            | suspended_client | active_client |
            | alpha            | beta          |
            | beta             | alpha         |

    Scenario: OTP verify with invalid code does not log user in
        When I request OTP for email "otp-user-invalid@example.com"
        And I verify OTP for email "otp-user-invalid@example.com" with code "000000"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-user-invalid@example.com" should have 1 attempts
        And session should not contain user id

    Scenario: OTP challenge is locked after five separate invalid verification requests
        Given there is a client "otp-lock-client"
        And there is a user "otp_lock_user" with email "otp-lock@example.com"
        And there is a membership of "otp_lock_user" in "otp-lock-client" with roles "user"
        When I request OTP for email "otp-lock@example.com"
        And I verify OTP for email "otp-lock@example.com" with code "000000"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-lock@example.com" should have 1 attempts
        When I verify OTP for email "otp-lock@example.com" with code "000000"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-lock@example.com" should have 2 attempts
        When I verify OTP for email "otp-lock@example.com" with code "000000"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-lock@example.com" should have 3 attempts
        When I verify OTP for email "otp-lock@example.com" with code "000000"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-lock@example.com" should have 4 attempts
        When I verify OTP for email "otp-lock@example.com" with code "000000"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-lock@example.com" should have 5 attempts
        When I verify OTP for email "otp-lock@example.com" with code "123456"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-lock@example.com" should have 5 attempts
        And session should not contain user id
        When I verify OTP for email "otp-lock@example.com" with code "000000"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-lock@example.com" should have 5 attempts
        And session should not contain user id

    Scenario: Correct OTP after four failed attempts logs in and cannot be reused
        Given there is a client "otp-last-attempt-client"
        And there is a user "otp_last_attempt_user" with email "otp-last-attempt@example.com"
        And there is a membership of "otp_last_attempt_user" in "otp-last-attempt-client" with roles "user"
        And an OTP challenge with 4 failed attempts exists for email "otp-last-attempt@example.com"
        When I verify OTP for email "otp-last-attempt@example.com" with code "123456"
        Then OTP verify response should be ok true
        And the latest OTP challenge for "otp-last-attempt@example.com" should have 4 attempts
        And the latest OTP challenge for "otp-last-attempt@example.com" should be consumed
        And session should contain user id for "otp-last-attempt@example.com"
        When I verify OTP for email "otp-last-attempt@example.com" with code "123456"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-last-attempt@example.com" should have 4 attempts

    Scenario: Expired OTP is rejected without incrementing attempts
        Given OTP was requested for "otp-expired@example.com" from IP "192.0.2.1" 601 seconds ago
        When I verify OTP for email "otp-expired@example.com" with code "123456"
        Then OTP verify response should be ok false
        And the latest OTP challenge for "otp-expired@example.com" should have 0 attempts
        And the latest OTP challenge for "otp-expired@example.com" should not be consumed
        And session should not contain user id

    Scenario: Fresh OTP challenge allows verification after the previous challenge was exhausted
        Given there is a client "otp-recovery-client"
        And there is a user "otp_recovery_user" with email "otp-recovery@example.com"
        And there is a membership of "otp_recovery_user" in "otp-recovery-client" with roles "user"
        And an OTP challenge with 5 failed attempts exists for email "otp-recovery@example.com"
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
