@fixtures @contentrepository
Feature: Basic redirect handling with document nodes without dimensions

  Background:
    Given I have no content dimensions
    And I am user identified by "initiating-user-identifier"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key                         | Value             |
      | contentStreamId             | "cs-identifier"   |
      | nodeAggregateId             | "site-root"       |
      | nodeTypeName                | "Neos.Neos:Sites" |
    And the graph projection is fully up to date

    # site-root
    #   behat
    #      company
    #        service
    #        about
    #      imprint
    #      buy
    #      mail
    And I am in content stream "cs-identifier" and dimension space point {}
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId | nodeTypeName                           | initialPropertyValues                        | nodeName |
      | behat                  | site-root             | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "home"}                   | node1    |
      | company                | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "company"}                | node2    |
      | service                | company               | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "service"}                | node3    |
      | about                  | company               | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "about"}                  | node4    |
      | imprint                | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "imprint"}                | node5    |
      | buy                    | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "buy", "title": "Buy"}    | node6    |
      | mail                   | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "mail"}                   | node7    |
      | restricted-by-nodetype | behat                 | Neos.Neos:Test.Redirect.RestrictedPage | {"uriPathSegment": "restricted-by-nodetype"} | node8    |
    And A site exists for node name "node1"
    And the sites configuration is:
    """
    Neos:
      Neos:
        sites:
          '*':
            contentRepository: default
            contentDimensions:
              resolver:
                factoryClassName: Neos\Neos\FrontendRouting\DimensionResolution\Resolver\NoopResolverFactory
    """
    And The documenturipath projection is up to date

  @fixtures
  Scenario: Move a node down into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value           |
      | contentStreamId                     | "cs-identifier" |
      | nodeAggregateId                     | "imprint"       |
      | dimensionSpacePoint                 | {}              |
      | newParentNodeAggregateId            | "company"       |
      | newSucceedingSiblingNodeAggregateId | null            |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "imprint" and targetUri "company/imprint"

  Scenario: Move a node up into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value           |
      | contentStreamId                     | "cs-identifier" |
      | nodeAggregateId                     | "service"       |
      | dimensionSpacePoint                 | {}              |
      | newParentNodeAggregateId            | "behat"         |
      | newSucceedingSiblingNodeAggregateId | null            |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "company/service" and targetUri "service"

  @fixtures
  Scenario: Change the the `uriPathSegment` and a redirect will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                           |
      | contentStreamId           | "cs-identifier"                 |
      | nodeAggregateId           | "company"                       |
      | originDimensionSpacePoint | {}                              |
      | propertyValues            | {"uriPathSegment": "evil-corp"} |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "company" and targetUri "evil-corp"
    And I should have a redirect with sourceUri "company/service" and targetUri "evil-corp/service"

  @fixtures
  Scenario: Change the the `uriPathSegment` multiple times and multiple redirects will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                           |
      | contentStreamId           | "cs-identifier"                 |
      | nodeAggregateId           | "company"                       |
      | originDimensionSpacePoint | {}                              |
      | propertyValues            | {"uriPathSegment": "evil-corp"} |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                |
      | contentStreamId           | "cs-identifier"                      |
      | nodeAggregateId           | "company"                            |
      | originDimensionSpacePoint | {}                                   |
      | propertyValues            | {"uriPathSegment": "more-evil-corp"} |
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "company" and targetUri "more-evil-corp"
    And I should have a redirect with sourceUri "company/service" and targetUri "more-evil-corp/service"
    And I should have a redirect with sourceUri "evil-corp" and targetUri "more-evil-corp"
    And I should have a redirect with sourceUri "evil-corp/service" and targetUri "more-evil-corp/service"


  @fixtures
  Scenario: Retarget an existing redirect when the source URI matches the source URI of the new redirect
    When I have the following redirects:
      | sourceuripath | targeturipath |
      | company       | company-old   |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                            |
      | contentStreamId           | "cs-identifier"                  |
      | nodeAggregateId           | "company"                        |
      | originDimensionSpacePoint | {}                               |
      | propertyValues            | {"uriPathSegment": "my-company"} |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "company" and targetUri "my-company"
    And I should have no redirect with sourceUri "company" and targetUri "company-old"
    And I should have a redirect with sourceUri "company/service" and targetUri "my-company/service"

  @fixtures
  Scenario: No redirect should be created for an existing node if any non URI related property changes
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value               |
      | contentStreamId           | "cs-identifier"     |
      | nodeAggregateId           | "buy"               |
      | originDimensionSpacePoint | {}                  |
      | propertyValues            | {"title": "my-buy"} |
    And The documenturipath projection is up to date
    Then I should have no redirect with sourceUri "buy"

  @fixtures
  Scenario: No redirect should be created for an restricted node by nodetype
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                            |
      | contentStreamId           | "cs-identifier"                                  |
      | nodeAggregateId           | "restricted-by-nodetype"                         |
      | originDimensionSpacePoint | {}                                               |
      | propertyValues            | {"uriPathSegment": "restricted-by-nodetype-new"} |
    And The documenturipath projection is up to date
    Then I should have no redirect with sourceUri "restricted"

  @fixtures
  Scenario: Redirects should be created for a hidden node
    When the command DisableNodeAggregate is executed with payload:
      | Key                          | Value           |
      | contentStreamId              | "cs-identifier" |
      | nodeAggregateId              | "mail"          |
      | coveredDimensionSpacePoint    | {}              |
      | nodeVariantSelectionStrategy | "allVariants"   |
    And the graph projection is fully up to date
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                          |
      | contentStreamId           | "cs-identifier"                |
      | nodeAggregateId           | "mail"                         |
      | originDimensionSpacePoint | {}                             |
      | propertyValues            | {"uriPathSegment": "not-mail"} |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "mail" and targetUri "not-mail"

  @fixtures
  Scenario: A removed node should lead to a GONE response with empty target uri
    Given the event NodeAggregateWasRemoved was published with payload:
      | Key                                  | Value           |
      | contentStreamId                      | "cs-identifier" |
      | nodeAggregateId                      | "company"       |
      | affectedOccupiedDimensionSpacePoints | []              |
      | affectedCoveredDimensionSpacePoints  | [[]]            |
    And the graph projection is fully up to date
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "company" and statusCode "410"
    And I should have a redirect with sourceUri "company" and targetUri ""
    And I should have a redirect with sourceUri "company/service" and statusCode "410"
    And I should have a redirect with sourceUri "company/service" and targetUri ""