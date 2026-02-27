Feature: Tenant cannot create a client

    Scenario: Tenant user is denied client creation
        Given there is a client "seed"
        And there is a user "member" with email "member@example.com"
        And there is a membership of "member" in "seed" with roles "user"
        And I am logged in as "member" in client "seed"
        When I try to create a client named "New Corp"
        Then the response status should be 403
