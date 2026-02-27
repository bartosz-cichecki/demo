Feature: Platform admin can create a client

    Scenario: Platform admin creates a client
        Given there is a user "admin" with email "admin@example.com"
        And I am logged in as platform admin "admin"
        When I create a client named "Acme Corporation" via platform API
        Then the response status should be 201
        And a client named "Acme Corporation" should exist
