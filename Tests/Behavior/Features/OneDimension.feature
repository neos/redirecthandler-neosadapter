@fixtures @contentrepository
Feature: Basic redirect handling with document nodes in one dimension

  Background:
    Given I have the following content dimensions:
      | Identifier | Values     | Generalizations |
      | language   | de, en, ch | ch->de, en      |
    And I am user identified by "initiating-user-identifier"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |
    And the event RootNodeAggregateWithNodeWasCreated was published with payload:
      | Key                         | Value                                                      |
      | contentStreamId             | "cs-identifier"                                            |
      | nodeAggregateId             | "site-root"                                                |
      | nodeTypeName                | "Neos.Neos:Sites"                                          |
      | coveredDimensionSpacePoints | [{"language": "en"},{"language": "de"},{"language": "ch"}] |
      | nodeAggregateClassification | "root"                                                     |
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
      | behat-de               | site-root             | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "home"}                   | {"language": "de"}        | node1    |
      | company                | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "company"}                | {"language": "en"}        | node2    |
      | company-de             | behat-de              | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "company"}                | {"language": "de"}        | node2-de |
      | service                | company               | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "service"}                | {"language": "en"}        | node3    |
      | service-de             | company-de            | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "service"}                | {"language": "de"}        | node3-de |
      | about                  | company               | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "about"}                  | {"language": "en"}        | node4    |
      | about-de               | company-de            | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "about"}                  | {"language": "de"}        | node4-de |
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
                        ch: ch
    """
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


#  @fixtures
#  Scenario: Redirects should always be created in the same dimension the node is in
#    When I get a node by path "/sites/behat/imprint" with the following context:
#      | Workspace        | Language |
#      | user-testaccount | fr       |
#    And I set the node property "uriPathSegment" to "empreinte-nouveau"
#    And I publish the node
#    Then I should have a redirect with sourceUri "fr/empreinte" and targetUri "fr/empreinte-nouveau"
#
#  #fixed in 1.0.3
#  @fixtures
#  Scenario: Redirects should aways be created in the same dimension the node is in and not the fallback dimension
#    When I get a node by path "/sites/behat/imprint" with the following context:
#      | Workspace        | Language |
#      | user-testaccount | de,en    |
#    And I set the node property "uriPathSegment" to "impressum-neu"
#    And I publish the node
#    Then I should have a redirect with sourceUri "de/impressum" and targetUri "de/impressum-neu"
#    And I should have no redirect with sourceUri "en/impressum" and targetUri "de/impressum-neu"
#
#  #fixed in 1.0.3
#  @fixtures
#  Scenario: I have an existing redirect and it should never be overwritten for a node variant from a different dimension
#    When I have the following redirects:
#      | sourceuripath                           | targeturipath      |
#      | important-page-from-the-old-site        | en/mail       |
#    When I get a node by path "/sites/behat/mail" with the following context:
#      | Workspace        | Language |
#      | user-testaccount | de,en    |
#    And I unhide the node
#    And I publish the node
#    Then I should have a redirect with sourceUri "important-page-from-the-old-site" and targetUri "en/mail"
#    And I should have no redirect with sourceUri "en/mail" and targetUri "de/mail"
#

  @fixtures
  Scenario: Redirects should be created for a hidden node
    When the command DisableNodeAggregate is executed with payload:
      | Key                          | Value              |
      | contentStreamId              | "cs-identifier"    |
      | nodeAggregateId              | "mail"             |
      | originDimensionSpacePoint    | {"language": "en"} |
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

#  @fixtures
#  Scenario: Create redirects for nodes published in different dimensions
#    When I get a node by path "/sites/behat/buy" with the following context:
#      | Workspace        |
#      | user-testaccount |
#    And I move the node into the node with path "/sites/behat/company"
#    And I publish the node
#    When I get a node by path "/sites/behat/company/buy" with the following context:
#      | Workspace        | Language |
#      | user-testaccount | de,en    |
#    And I publish the node
#    Then I should have a redirect with sourceUri "en/buy" and targetUri "en/company/buy"
#    And I should have a redirect with sourceUri "de/kaufen" and targetUri "de/company/kaufen"
#
#  #fixed in 1.0.4
#  @fixtures
#  Scenario: Create redirects for nodes that use the current dimension as fallback
#    When I get a node by path "/sites/behat/company" with the following context:
#      | Workspace        | Language |
#      | user-testaccount | en       |
#    And I move the node into the node with path "/sites/behat/service"
#    And I publish the node
#    Then I should have a redirect with sourceUri "en/company" and targetUri "en/service/company"
#    And I should have a redirect with sourceUri "de/company" and targetUri "de/service/company"
  @fixtures
  Scenario: Change the the `uriPathSegment` and a redirect will be created also for fallback
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                             |
      | contentStreamId           | "cs-identifier"                   |
      | nodeAggregateId           | "company-de"                      |
      | originDimensionSpacePoint | {"language": "ch"}                |
      | propertyValues            | {"uriPathSegment": "unternehmen"} |
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "de/company" and targetUri "de/unternehmen"
    And I should have a redirect with sourceUri "de/company/service" and targetUri "de/unternehmen/service"


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