@fixtures @contentrepository
Feature: Basic redirect handling with document nodes in one dimension

  Background:
    Given I have the following content dimensions:
      | Identifier | Values      | Generalizations |
      | language   | de, en, gsw | gsw->de, en     |
    And I am user identified by "initiating-user-identifier"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "site-root"       |
      | nodeTypeName    | "Neos.Neos:Sites" |
      | contentStreamId | "cs-identifier"   |
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
      | nodeAggregateId        | parentNodeAggregateId | nodeTypeName                           | initialPropertyValues                        | originDimensionSpacePoint | nodeName |
      | behat                  | site-root             | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "home"}                   | {"language": "en"}        | node1    |
      | company                | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "company"}                | {"language": "en"}        | node2    |
      | service                | company               | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "service"}                | {"language": "en"}        | node3    |
      | about                  | company               | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "about"}                  | {"language": "en"}        | node4    |
      | imprint                | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "imprint"}                | {"language": "en"}        | node5    |
      | buy                    | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "buy", "title": "Buy"}    | {"language": "en"}        | node6    |
      | mail                   | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "mail"}                   | {"language": "en"}        | node7    |
      | restricted-by-nodetype | behat                 | Neos.Neos:Test.Redirect.RestrictedPage | {"uriPathSegment": "restricted-by-nodetype"} | {"language": "en"}        | node8    |

    And A site exists for node name "node1"
    And the sites configuration is:
    """
    Neos:
      Neos:
        sites:
          '*':
            contentRepository: default
            contentDimensions:
              defaultDimensionSpacePoint:
                language: en
              resolver:
                factoryClassName: Neos\Neos\FrontendRouting\DimensionResolution\Resolver\UriPathResolverFactory
                options:
                  segments:
                    -
                      dimensionIdentifier: language
                      dimensionValueMapping:
                        de: ''
                        en: en
                        gsw: ch
    """
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "behat"           |
      | sourceOrigin    | {"language":"en"} |
      | targetOrigin    | {"language":"de"} |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "company"         |
      | sourceOrigin    | {"language":"en"} |
      | targetOrigin    | {"language":"de"} |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "service"         |
      | sourceOrigin    | {"language":"en"} |
      | targetOrigin    | {"language":"de"} |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "imprint"         |
      | sourceOrigin    | {"language":"en"} |
      | targetOrigin    | {"language":"de"} |
    And The documenturipath projection is up to date

  @fixtures
  Scenario: Move a node down into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value              |
      | contentStreamId                     | "cs-identifier"    |
      | nodeAggregateId                     | "imprint"          |
      | dimensionSpacePoint                 | {"language": "en"} |
      | newParentNodeAggregateId            | "company"          |
      | newSucceedingSiblingNodeAggregateId | null               |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "en/imprint" and targetUri "en/company/imprint"

  @fixtures
  Scenario: Move a node down into different node and a redirect will be created also for fallback
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value              |
      | contentStreamId                     | "cs-identifier"    |
      | nodeAggregateId                     | "imprint"          |
      | dimensionSpacePoint                 | {"language": "de"} |
      | newParentNodeAggregateId            | "company"          |
      | newSucceedingSiblingNodeAggregateId | null               |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "imprint" and targetUri "company/imprint"
    Then I should have a redirect with sourceUri "ch/imprint" and targetUri "ch/company/imprint"

  @fixtures
  Scenario: Move a node up into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value              |
      | contentStreamId                     | "cs-identifier"    |
      | nodeAggregateId                     | "service"          |
      | dimensionSpacePoint                 | {"language": "en"} |
      | newParentNodeAggregateId            | "behat"            |
      | newSucceedingSiblingNodeAggregateId | null               |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "en/company/service" and targetUri "en/service"

  @fixtures
  Scenario: Move a node up into different node and a redirect will be created also for fallback
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value              |
      | contentStreamId                     | "cs-identifier"    |
      | nodeAggregateId                     | "service"          |
      | dimensionSpacePoint                 | {"language": "de"} |
      | newParentNodeAggregateId            | "behat"            |
      | newSucceedingSiblingNodeAggregateId | null               |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "company/service" and targetUri "service"
    And I should have a redirect with sourceUri "ch/company/service" and targetUri "ch/service"

  @fixtures
  Scenario: Change the the `uriPathSegment` and a redirect will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                           |
      | contentStreamId           | "cs-identifier"                 |
      | nodeAggregateId           | "company"                       |
      | originDimensionSpacePoint | {"language": "en"}              |
      | propertyValues            | {"uriPathSegment": "evil-corp"} |
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "en/company" and targetUri "en/evil-corp"
    And I should have a redirect with sourceUri "en/company/service" and targetUri "en/evil-corp/service"

  @fixtures
  Scenario: Change the the `uriPathSegment` multiple times and multiple redirects will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                           |
      | contentStreamId           | "cs-identifier"                 |
      | nodeAggregateId           | "company"                       |
      | originDimensionSpacePoint | {"language": "en"}              |
      | propertyValues            | {"uriPathSegment": "evil-corp"} |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                |
      | contentStreamId           | "cs-identifier"                      |
      | nodeAggregateId           | "company"                            |
      | originDimensionSpacePoint | {"language": "en"}                   |
      | propertyValues            | {"uriPathSegment": "more-evil-corp"} |
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "en/company" and targetUri "en/more-evil-corp"
    And I should have a redirect with sourceUri "en/company/service" and targetUri "en/more-evil-corp/service"
    And I should have a redirect with sourceUri "en/evil-corp" and targetUri "en/more-evil-corp"
    And I should have a redirect with sourceUri "en/evil-corp/service" and targetUri "en/more-evil-corp/service"


  @fixtures
  Scenario: Retarget an existing redirect when the source URI matches the source URI of the new redirect
    When I have the following redirects:
      | sourceuripath | targeturipath |
      | company       | company-old   |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                            |
      | contentStreamId           | "cs-identifier"                  |
      | nodeAggregateId           | "company"                        |
      | originDimensionSpacePoint | {"language": "en"}               |
      | propertyValues            | {"uriPathSegment": "my-company"} |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "en/company" and targetUri "en/my-company"
    And I should have no redirect with sourceUri "en/company" and targetUri "en/company-old"
    And I should have a redirect with sourceUri "en/company/service" and targetUri "en/my-company/service"

  @fixtures
  Scenario: No redirect should be created for an existing node if any non URI related property changes
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value               |
      | contentStreamId           | "cs-identifier"     |
      | nodeAggregateId           | "buy"               |
      | originDimensionSpacePoint | {"language": "en"}  |
      | propertyValues            | {"title": "my-buy"} |
    And The documenturipath projection is up to date
    Then I should have no redirect with sourceUri "en/buy"

  @fixtures
  Scenario: No redirect should be created for an restricted node by nodetype
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                            |
      | contentStreamId           | "cs-identifier"                                  |
      | nodeAggregateId           | "restricted-by-nodetype"                         |
      | originDimensionSpacePoint | {"language": "en"}                               |
      | propertyValues            | {"uriPathSegment": "restricted-by-nodetype-new"} |
    And The documenturipath projection is up to date
    Then I should have no redirect with sourceUri "en/restricted"

  @fixtures
  Scenario: Redirects should be created for a hidden node
    When the command DisableNodeAggregate is executed with payload:
      | Key                          | Value              |
      | contentStreamId              | "cs-identifier"    |
      | nodeAggregateId              | "mail"             |
      | coveredDimensionSpacePoint   | {"language": "en"} |
      | nodeVariantSelectionStrategy | "allVariants"      |
    And the graph projection is fully up to date
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                          |
      | contentStreamId           | "cs-identifier"                |
      | nodeAggregateId           | "mail"                         |
      | originDimensionSpacePoint | {"language": "en"}             |
      | propertyValues            | {"uriPathSegment": "not-mail"} |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "en/mail" and targetUri "en/not-mail"

  @fixtures
  Scenario: Change the the `uriPathSegment` and a redirect will be created also for fallback

    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                             |
      | contentStreamId           | "cs-identifier"                   |
      | nodeAggregateId           | "company"                         |
      | originDimensionSpacePoint | {"language": "de"}                |
      | propertyValues            | {"uriPathSegment": "unternehmen"} |
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "company" and targetUri "unternehmen"
    And I should have a redirect with sourceUri "company/service" and targetUri "unternehmen/service"
    And I should have a redirect with sourceUri "ch/company" and targetUri "ch/unternehmen"
    And I should have a redirect with sourceUri "ch/company/service" and targetUri "ch/unternehmen/service"


  @fixtures
  Scenario: A removed node should lead to a GONE response with empty target uri
    Given the event NodeAggregateWasRemoved was published with payload:
      | Key                                  | Value                |
      | contentStreamId                      | "cs-identifier"      |
      | nodeAggregateId                      | "company"            |
      | affectedOccupiedDimensionSpacePoints | [{"language": "en"}] |
      | affectedCoveredDimensionSpacePoints  | [{"language": "en"}] |
    And the graph projection is fully up to date
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "en/company" and statusCode "410"
    And I should have a redirect with sourceUri "en/company" and targetUri ""
    And I should have a redirect with sourceUri "en/company/service" and statusCode "410"
    And I should have a redirect with sourceUri "en/company/service" and targetUri ""

  @fixtures
  Scenario: A removed node should lead to a GONE response with empty target uri also for fallback
    Given the event NodeAggregateWasRemoved was published with payload:
      | Key                                  | Value                                     |
      | contentStreamId                      | "cs-identifier"                           |
      | nodeAggregateId                      | "company"                                 |
      | affectedOccupiedDimensionSpacePoints | [{"language": "de"}]                      |
      | affectedCoveredDimensionSpacePoints  | [{"language": "de"}, {"language": "gsw"}] |
    And the graph projection is fully up to date
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "company" and statusCode "410"
    And I should have a redirect with sourceUri "company" and targetUri ""
    And I should have a redirect with sourceUri "company/service" and statusCode "410"
    And I should have a redirect with sourceUri "company/service" and targetUri ""

    And I should have a redirect with sourceUri "ch/company" and statusCode "410"
    And I should have a redirect with sourceUri "ch/company" and targetUri ""
    And I should have a redirect with sourceUri "ch/company/service" and statusCode "410"
    And I should have a redirect with sourceUri "ch/company/service" and targetUri ""