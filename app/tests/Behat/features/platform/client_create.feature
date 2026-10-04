Feature: Platform admin can create a client

    Scenario: Platform admin creates a client
        Given there is a user "admin" with email "admin@example.com"
        And I am logged in as platform admin "admin"
        When I onboard client "Acme Corporation" with admin email "first-admin@example.com"
        Then the response status should be 201
        And a client named "Acme Corporation" should exist
