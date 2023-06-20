@fixtures @contentrepository
Feature: Basic redirect handling with document nodes in multiple dimensions

  Background:
    Given I have the following content dimensions:
      | Identifier | Values      | Generalizations |
      | language   | de, en, gsw | gsw->de, en     |
      | market     | DE, CH      | CH->DE          |
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
      | nodeAggregateId        | parentNodeAggregateId | nodeTypeName                           | initialPropertyValues                        | originDimensionSpacePoint          | nodeName |
      | behat                  | site-root             | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "home"}                   | {"language": "en", "market": "DE"} | node1    |
      | company                | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "company"}                | {"language": "en", "market": "DE"} | node2    |
      | service                | company               | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "service"}                | {"language": "en", "market": "DE"} | node3    |
      | about                  | company               | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "about"}                  | {"language": "en", "market": "DE"} | node4    |
      | imprint                | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "imprint"}                | {"language": "en", "market": "DE"} | node5    |
      | buy                    | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "buy", "title": "Buy"}    | {"language": "en", "market": "DE"} | node6    |
      | mail                   | behat                 | Neos.Neos:Test.Redirect.Page           | {"uriPathSegment": "mail"}                   | {"language": "en", "market": "DE"} | node7    |
      | restricted-by-nodetype | behat                 | Neos.Neos:Test.Redirect.RestrictedPage | {"uriPathSegment": "restricted-by-nodetype"} | {"language": "en", "market": "DE"} | node8    |

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
                    -
                      dimensionIdentifier: market
                      dimensionValueMapping:
                        DE: DE
                        CH: CH
    """
    And The documenturipath projection is up to date
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value                              |
      | nodeAggregateId | "behat"                            |
      | sourceOrigin    | {"language": "en", "market": "DE"} |
      | targetOrigin    | {"language": "de", "market": "DE"} |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value                             |
      | nodeAggregateId | "company"                         |
      | sourceOrigin    | {"language":"en", "market": "DE"} |
      | targetOrigin    | {"language":"de", "market": "DE"} |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value                             |
      | nodeAggregateId | "service"                         |
      | sourceOrigin    | {"language":"en", "market": "DE"} |
      | targetOrigin    | {"language":"de", "market": "DE"} |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value                             |
      | nodeAggregateId | "imprint"                         |
      | sourceOrigin    | {"language":"en", "market": "DE"} |
      | targetOrigin    | {"language":"de", "market": "DE"} |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value                              |
      | nodeAggregateId | "imprint"                          |
      | sourceOrigin    | {"language":"en", "market": "DE"}  |
      | targetOrigin    | {"language":"gsw", "market": "CH"} |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value                             |
      | nodeAggregateId | "company"                         |
      | sourceOrigin    | {"language":"en", "market": "DE"} |
      | targetOrigin    | {"language":"en", "market": "CH"} |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value                             |
      | nodeAggregateId | "service"                         |
      | sourceOrigin    | {"language":"en", "market": "DE"} |
      | targetOrigin    | {"language":"en", "market": "CH"} |

  @fixtures
  Scenario: Move a node down into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value                              |
      | contentStreamId                     | "cs-identifier"                    |
      | nodeAggregateId                     | "imprint"                          |
      | dimensionSpacePoint                 | {"language": "en", "market": "DE"} |
      | newParentNodeAggregateId            | "company"                          |
      | newSucceedingSiblingNodeAggregateId | null                               |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "DE/imprint.html" and targetUri "DE/company/imprint.html"
    Then I should have a redirect with sourceUri "CH/imprint.html" and targetUri "CH/company/imprint.html"
    Then I should have a redirect with sourceUri "en_DE/imprint.html" and targetUri "en_DE/company/imprint.html"
    Then I should have a redirect with sourceUri "en_CH/imprint.html" and targetUri "en_CH/company/imprint.html"
    Then I should have a redirect with sourceUri "ch_DE/imprint.html" and targetUri "ch_DE/company/imprint.html"
    Then I should have a redirect with sourceUri "ch_CH/imprint.html" and targetUri "ch_CH/company/imprint.html"

  @fixtures
  Scenario: Move a node up into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value                              |
      | contentStreamId                     | "cs-identifier"                    |
      | nodeAggregateId                     | "service"                          |
      | dimensionSpacePoint                 | {"language": "en", "market": "DE"} |
      | newParentNodeAggregateId            | "behat"                            |
      | newSucceedingSiblingNodeAggregateId | null                               |
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "DE/company/service.html" and targetUri "DE/service.html"
    And I should have a redirect with sourceUri "CH/company/service.html" and targetUri "CH/service.html"
    And I should have a redirect with sourceUri "en_DE/company/service.html" and targetUri "en_DE/service.html"
    And I should have a redirect with sourceUri "en_CH/company/service.html" and targetUri "en_CH/service.html"
    And I should have a redirect with sourceUri "ch_DE/company/service.html" and targetUri "ch_DE/service.html"
    And I should have a redirect with sourceUri "ch_CH/company/service.html" and targetUri "ch_CH/service.html"

  @fixtures
  Scenario: Change the the `uriPathSegment` and a redirect will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                              |
      | contentStreamId           | "cs-identifier"                    |
      | nodeAggregateId           | "company"                          |
      | originDimensionSpacePoint | {"language": "en", "market": "CH"} |
      | propertyValues            | {"uriPathSegment": "evil-company"} |
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "en_CH/company.html" and targetUri "en_CH/evil-company.html"
    And I should have a redirect with sourceUri "en_CH/company/service.html" and targetUri "en_CH/evil-company/service.html"
    And I should have a redirect with sourceUri "en_CH/company/about.html" and targetUri "en_CH/evil-company/about.html"

    And I should have no redirect with sourceUri "CH/company.html"
    And I should have no redirect with sourceUri "DE/company.html"
    And I should have no redirect with sourceUri "ch_CH/company.html"
    And I should have no redirect with sourceUri "ch_DE/company.html"

  @fixtures
  Scenario: Change the the `uriPathSegment` multiple times and multiple redirects will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                              |
      | contentStreamId           | "cs-identifier"                    |
      | nodeAggregateId           | "company"                          |
      | originDimensionSpacePoint | {"language": "en", "market": "CH"} |
      | propertyValues            | {"uriPathSegment": "evil-corp"}    |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                |
      | contentStreamId           | "cs-identifier"                      |
      | nodeAggregateId           | "company"                            |
      | originDimensionSpacePoint | {"language": "en", "market": "CH"}   |
      | propertyValues            | {"uriPathSegment": "more-evil-corp"} |
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "en_CH/company.html" and targetUri "en_CH/more-evil-corp.html"
    And I should have a redirect with sourceUri "en_CH/company/service.html" and targetUri "en_CH/more-evil-corp/service.html"
    And I should have a redirect with sourceUri "en_CH/company/about.html" and targetUri "en_CH/more-evil-corp/about.html"
    And I should have a redirect with sourceUri "en_CH/evil-corp.html" and targetUri "en_CH/more-evil-corp.html"
    And I should have a redirect with sourceUri "en_CH/evil-corp/service.html" and targetUri "en_CH/more-evil-corp/service.html"
    And I should have a redirect with sourceUri "en_CH/evil-corp/about.html" and targetUri "en_CH/more-evil-corp/about.html"

    And I should have no redirect with sourceUri "CH/company.html"
    And I should have no redirect with sourceUri "DE/company.html"
    And I should have no redirect with sourceUri "en_DE/company.html"

  @fixtures
  Scenario: Retarget an existing redirect when the source URI matches the source URI of the new redirect
    When I have the following redirects:
      | sourceuripath      | targeturipath          |
      | en_CH/company.html | en_CH/company-old.html |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                              |
      | contentStreamId           | "cs-identifier"                    |
      | nodeAggregateId           | "company"                          |
      | originDimensionSpacePoint | {"language": "en", "market": "CH"} |
      | propertyValues            | {"uriPathSegment": "my-company"}   |
    And The documenturipath projection is up to date
    Then I should have a redirect with sourceUri "en_CH/company.html" and targetUri "en_CH/my-company.html"
    And I should have a redirect with sourceUri "en_CH/company/service.html" and targetUri "en_CH/my-company/service.html"
    And I should have a redirect with sourceUri "en_CH/company/about.html" and targetUri "en_CH/my-company/about.html"

    And I should have no redirect with sourceUri "en_CH/company.html" and targetUri "en_CH/company-old.html"

  @fixtures
  Scenario: No redirect should be created for an existing node if any non URI related property changes
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                              |
      | contentStreamId           | "cs-identifier"                    |
      | nodeAggregateId           | "buy"                              |
      | originDimensionSpacePoint | {"language": "en", "market": "DE"} |
      | propertyValues            | {"title": "my-buy"}                |
    And The documenturipath projection is up to date
    Then I should have no redirect with sourceUri "en_DE/buy.html"

  @fixtures
  Scenario: No redirect should be created for an restricted node by nodetype
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                            |
      | contentStreamId           | "cs-identifier"                                  |
      | nodeAggregateId           | "restricted-by-nodetype"                         |
      | originDimensionSpacePoint | {"language": "en", "market": "DE"}               |
      | propertyValues            | {"uriPathSegment": "restricted-by-nodetype-new"} |
    And The documenturipath projection is up to date
    Then I should have no redirect with sourceUri "en/restricted.html"

#  @fixtures
#  Scenario: Redirects should be created for a hidden node
#    When the command DisableNodeAggregate is executed with payload:
#      | Key                          | Value                              |
#      | contentStreamId              | "cs-identifier"                    |
#      | nodeAggregateId              | "mail"                             |
#      | coveredDimensionSpacePoint   | {"language": "en", "market": "DE"} |
#      | nodeVariantSelectionStrategy | "allVariants"                      |
#    And the graph projection is fully up to date
#    When the command SetNodeProperties is executed with payload:
#      | Key                       | Value                              |
#      | contentStreamId           | "cs-identifier"                    |
#      | nodeAggregateId           | "mail"                             |
#      | originDimensionSpacePoint | {"language": "en", "market": "DE"} |
#      | propertyValues            | {"uriPathSegment": "not-mail"}     |
#    And The documenturipath projection is up to date
#    Then I should have a redirect with sourceUri "en_DE/mail.html" and targetUri "en_DE/not-mail.html"
#    Then I should have a redirect with sourceUri "en_CH/mail.html" and targetUri "en_CH/not-mail.html"

  @fixtures
  Scenario: Change the the `uriPathSegment` and a redirect will be created also for fallback
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                              |
      | contentStreamId           | "cs-identifier"                    |
      | nodeAggregateId           | "company"                          |
      | originDimensionSpacePoint | {"language": "de", "market": "DE"} |
      | propertyValues            | {"uriPathSegment": "evil-company"} |
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "DE/company.html" and targetUri "DE/evil-company.html"
    And I should have a redirect with sourceUri "DE/company/service.html" and targetUri "DE/evil-company/service.html"
    And I should have a redirect with sourceUri "CH/company.html" and targetUri "CH/evil-company.html"
    And I should have a redirect with sourceUri "CH/company/service.html" and targetUri "CH/evil-company/service.html"
    And I should have a redirect with sourceUri "ch_DE/company.html" and targetUri "ch_DE/evil-company.html"
    And I should have a redirect with sourceUri "ch_DE/company/service.html" and targetUri "ch_DE/evil-company/service.html"
    And I should have a redirect with sourceUri "ch_CH/company.html" and targetUri "ch_CH/evil-company.html"
    And I should have a redirect with sourceUri "ch_CH/company/service.html" and targetUri "ch_CH/evil-company/service.html"

    And I should have no redirect with sourceUri "en_DE/company.html"
    And I should have no redirect with sourceUri "en_DE/company/service.html"
    And I should have no redirect with sourceUri "en_CH/company.html"
    And I should have no redirect with sourceUri "en_CH/company/service.html"

  @fixtures
  Scenario: A removed node should lead to a GONE response with empty target uri (allSpecializations)
    Given the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                              |
      | contentStreamId              | "cs-identifier"                    |
      | nodeAggregateId              | "company"                          |
      | coveredDimensionSpacePoint   | {"language": "en", "market": "CH"} |
      | nodeVariantSelectionStrategy | "allSpecializations"               |
    And the graph projection is fully up to date
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "en_CH/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "en_CH/company.html" and targetUri ""
    And I should have a redirect with sourceUri "en_CH/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "en_CH/company/service.html" and targetUri ""
    And I should have a redirect with sourceUri "en_CH/company/about.html" and statusCode "410"
    And I should have a redirect with sourceUri "en_CH/company/about.html" and targetUri ""

    And I should have no redirect with sourceUri "DE/company.html"
    And I should have no redirect with sourceUri "DE/company/service.html"
    And I should have no redirect with sourceUri "DE/company/about.html"
    And I should have no redirect with sourceUri "CH/company.html"
    And I should have no redirect with sourceUri "CH/company/service.html"
    And I should have no redirect with sourceUri "CH/company/about.html"
    And I should have no redirect with sourceUri "ch_DE/company.html"
    And I should have no redirect with sourceUri "ch_DE/company/service.html"
    And I should have no redirect with sourceUri "ch_DE/company/about.html"
    And I should have no redirect with sourceUri "en_DE/company.html"
    And I should have no redirect with sourceUri "en_DE/company/service.html"
    And I should have no redirect with sourceUri "en_DE/company/about.html"

  @fixtures
  Scenario: A removed node should lead to a GONE response with empty target uri (allVariants)
    Given the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                              |
      | contentStreamId              | "cs-identifier"                    |
      | nodeAggregateId              | "company"                          |
      | coveredDimensionSpacePoint   | {"language": "de", "market": "CH"} |
      | nodeVariantSelectionStrategy | "allVariants"                      |
    And the graph projection is fully up to date
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "DE/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "DE/company.html" and targetUri ""
    And I should have a redirect with sourceUri "DE/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "DE/company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "CH/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "CH/company.html" and targetUri ""
    And I should have a redirect with sourceUri "CH/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "CH/company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "ch_CH/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch_CH/company.html" and targetUri ""
    And I should have a redirect with sourceUri "ch_CH/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch_CH/company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "ch_DE/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch_DE/company.html" and targetUri ""
    And I should have a redirect with sourceUri "ch_DE/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch_DE/company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "en_CH/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "en_CH/company.html" and targetUri ""
    And I should have a redirect with sourceUri "en_CH/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "en_CH/company/service.html" and targetUri ""
    And I should have a redirect with sourceUri "en_CH/company/about.html" and statusCode "410"
    And I should have a redirect with sourceUri "en_CH/company/about.html" and targetUri ""

    And I should have a redirect with sourceUri "en_DE/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "en_DE/company.html" and targetUri ""
    And I should have a redirect with sourceUri "en_DE/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "en_DE/company/service.html" and targetUri ""
    And I should have a redirect with sourceUri "en_DE/company/about.html" and statusCode "410"
    And I should have a redirect with sourceUri "en_DE/company/about.html" and targetUri ""


  @fixtures
  Scenario: A removed node should lead to a GONE response with empty target uri also for fallback (allSpecializations)
    Given the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                               |
      | contentStreamId              | "cs-identifier"                     |
      | nodeAggregateId              | "company"                           |
      | nodeVariantSelectionStrategy | "allSpecializations"                |
      | coveredDimensionSpacePoint   | {"language": "de", "market" : "DE"} |
    And the graph projection is fully up to date
    And The documenturipath projection is up to date

    Then I should have a redirect with sourceUri "DE/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "DE/company.html" and targetUri ""
    And I should have a redirect with sourceUri "DE/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "DE/company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "CH/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "CH/company.html" and targetUri ""
    And I should have a redirect with sourceUri "CH/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "CH/company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "ch_DE/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch_DE/company.html" and targetUri ""
    And I should have a redirect with sourceUri "ch_DE/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch_DE/company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "ch_CH/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch_CH/company.html" and targetUri ""
    And I should have a redirect with sourceUri "ch_CH/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch_CH/company/service.html" and targetUri ""

    And I should have no redirect with sourceUri "en_DE/company.html"
    And I should have no redirect with sourceUri "en_DE/company/service.html"
    And I should have no redirect with sourceUri "en_CH/company.html"
    And I should have no redirect with sourceUri "en_CH/company/service.html"