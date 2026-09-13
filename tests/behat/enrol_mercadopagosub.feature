@enrol @enrol_mercadopagosub
Feature: Mercado Pago Subscriptions enrolment method
  In order to sell recurring access to a course
  As a manager
  I need to add and configure a Mercado Pago Subscriptions enrolment method

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | manager1 | Manager   | One      | manager1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |
    And the following config values are set as admin:
      | accesstoken   | TEST-ACCESS-TOKEN   | enrol_mercadopagosub |
      | webhooksecret | TEST-WEBHOOK-SECRET | enrol_mercadopagosub |
      | currency      | ARS                 | enrol_mercadopagosub |
    And I log in as "admin"
    And I navigate to "Plugins > Enrolments > Manage enrol plugins" in site administration
    And I click on "Enable" "link" in the "Mercado Pago Subscriptions" "table_row"
    And I log out

  @javascript
  Scenario: A manager adds a subscription enrolment method to a course
    Given I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    When I select "Mercado Pago Subscriptions" from the "Add method" singleselect
    And I set the following fields to these values:
      | Custom instance name    | Monthly access |
      | Allow new subscriptions | No             |
      | Recurring amount        | 15000          |
      | Billing frequency       | 1              |
    And I press "Add method"
    Then I should see "Monthly access" in the "generaltable" "table"

  @javascript
  Scenario: The recurring amount must be greater than zero
    Given I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    When I select "Mercado Pago Subscriptions" from the "Add method" singleselect
    And I set the following fields to these values:
      | Allow new subscriptions | No |
      | Recurring amount        | 0  |
    And I press "Add method"
    Then I should see "The amount must be greater than zero"

  @javascript
  Scenario: The recurring amount must be a number
    Given I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    When I select "Mercado Pago Subscriptions" from the "Add method" singleselect
    And I set the following fields to these values:
      | Allow new subscriptions | No   |
      | Recurring amount        | free |
    And I press "Add method"
    Then I should see "The amount must be a number"

  @javascript
  Scenario: The billing frequency must be at least one period
    Given I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    When I select "Mercado Pago Subscriptions" from the "Add method" singleselect
    And I set the following fields to these values:
      | Allow new subscriptions | No   |
      | Recurring amount        | 1000 |
      | Billing frequency       | 0    |
    And I press "Add method"
    Then I should see "The billing frequency must be at least 1"

  @javascript
  Scenario: A free trial cannot be negative
    Given I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    When I select "Mercado Pago Subscriptions" from the "Add method" singleselect
    And I set the following fields to these values:
      | Allow new subscriptions | No   |
      | Recurring amount        | 1000 |
      | Free trial length       | -1   |
    And I press "Add method"
    Then I should see "The trial length cannot be negative"

  # Enabling the method is what triggers the credentials check, so this scenario
  # deliberately clears them first. It does not need HTTPS, because the
  # credentials check runs before the HTTPS one.
  @javascript
  Scenario: The method cannot be enabled without credentials
    Given the following config values are set as admin:
      | accesstoken | | enrol_mercadopagosub |
    And I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    When I select "Mercado Pago Subscriptions" from the "Add method" singleselect
    And I set the following fields to these values:
      | Allow new subscriptions | Yes  |
      | Recurring amount        | 1000 |
    And I press "Add method"
    Then I should see "This enrolment method has no Mercado Pago credentials configured"

  @javascript
  Scenario: A manager sees how many people are subscribed
    Given the following "enrol_mercadopagosub > instances" exist:
      | course |
      | C1     |
    And the following "enrol_mercadopagosub > subscriptions" exist:
      | course | user     | state  |
      | C1     | student1 | active |
    And I log in as "manager1"
    When I am on the "Course 1" "enrolment methods" page
    Then I should see "Mercado Pago Subscriptions"

  # Everything below here enables an instance, and the plugin refuses to enable
  # one on a site that is not served over HTTPS. $CFG->behat_wwwroot must be an
  # https URL whose certificate validates, because Moodle curls it from the CLI
  # before running.
  #
  # The tag exists so that continuous integration can skip these: moodle-plugin-ci
  # serves Behat over plain http on localhost, where they can only fail — and
  # they would fail by proving the HTTPS guard works, which is not a useful
  # signal on every push. Run them before each release on a site that really is
  # HTTPS, with both conditions in one expression:
  #
  #   moodle-plugin-ci behat --profile chrome \
  #       --tags='@enrol_mercadopagosub&&~@enrol_mercadopagosub_https'
  #
  # Passing a bare negation instead replaces the plugin tag rather than adding
  # to it, and runs the whole of Moodle's own suite. Measured on the sibling
  # plugin, 2026-09-04.
  # This one is the form itself: on an HTTPS site with credentials configured,
  # a manager can actually enable an instance. Its counterpart above proves the
  # refusal; without this, only the refusal was ever tested, and "the guard
  # always says no" would pass both.
  #
  # It sets the amount and nothing else on purpose. Everything the site settings
  # configure — billing frequency above all — has to arrive pre-filled from
  # get_instance_defaults(), and a scenario that fills those fields in by hand
  # would never notice that they came up blank.
  @javascript @enrol_mercadopagosub_https
  Scenario: A manager can enable a method when the site and credentials allow it
    Given I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    When I select "Mercado Pago Subscriptions" from the "Add method" singleselect
    And the field "Billing frequency" matches value "1"
    And I set the following fields to these values:
      | Custom instance name    | Open for business |
      | Allow new subscriptions | Yes               |
      | Recurring amount        | 15000             |
    And I press "Add method"
    Then I should see "Open for business" in the "generaltable" "table"
    And I should not see "This enrolment method has no Mercado Pago credentials configured"
    And I should not see "The billing frequency must be at least 1"

  # The two below are about what a *learner* sees, so the instance comes from
  # the generator rather than from driving the form again. Creating it through
  # the interface made these scenarios depend on the form as well as on the
  # thing they test, and a failure could not be attributed to either.
  @javascript @enrol_mercadopagosub_https
  Scenario: A student sees the subscribe button on the enrolment page
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 2 | C2        | 0        |
    And the following "enrol_mercadopagosub > instances" exist:
      | course | status  | cost  |
      | C2     | enabled | 15000 |
    When I log in as "student1"
    And I am on "Course 2" course homepage
    Then I should see "Subscribe"

  @javascript @enrol_mercadopagosub_https
  Scenario: A student who already has a subscription is not offered another
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 3 | C3        | 0        |
    And the following "enrol_mercadopagosub > instances" exist:
      | course | status  | cost  |
      | C3     | enabled | 15000 |
    And the following "enrol_mercadopagosub > subscriptions" exist:
      | course | user     | state   |
      | C3     | student1 | pending |
    When I log in as "student1"
    And I am on "Course 3" course homepage
    Then I should see "You already have a subscription in progress or active for this course"
    And I should not see "Subscribe"
