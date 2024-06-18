@flowEntities
Feature: Basic redirect handling with document nodes in multiple dimensions

  Background:
    Given using the following content dimensions:
      | Identifier | Values      | Generalizations |
      | language   | de, en, gsw | gsw->de, en     |
      | market     | DE, CH      | CH->DE          |
    And using the following node types:
    """yaml
    'Neos.ContentRepository:Root': []

    'Neos.Neos:Sites':
      superTypes:
        'Neos.ContentRepository:Root': true
    'Neos.Neos:Document':
      properties:
        uriPathSegment:
          type: string

    'Neos.Neos:Test.Redirect.Page':
      superTypes:
        'Neos.Neos:Document': true
      constraints:
        nodeTypes:
          '*': true
          'Neos.Neos:Test.Redirect.Page': true
      properties:
        title:
          type: string

    'Neos.Neos:Test.Redirect.RestrictedPage':
      superTypes:
        'Neos.Neos:Document': true
      constraints:
        nodeTypes:
          '*': true
          'Neos.Neos:Test.Redirect.Page': true
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And I am user identified by "initiating-user-identifier"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |
    And I am in workspace "live" and dimension space point {}
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "site-root"       |
      | nodeTypeName    | "Neos.Neos:Sites" |

    # site-root
    #   behat
    #      company
    #        service
    #        about
    #      imprint
    #      buy
    #      mail
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
            uriPathSuffix: '.html'
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
      | nodeAggregateId                     | "imprint"                          |
      | dimensionSpacePoint                 | {"language": "en", "market": "DE"} |
      | newParentNodeAggregateId            | "company"                          |
      | newSucceedingSiblingNodeAggregateId | null                               |
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
      | nodeAggregateId                     | "service"                          |
      | dimensionSpacePoint                 | {"language": "en", "market": "DE"} |
      | newParentNodeAggregateId            | "behat"                            |
      | newSucceedingSiblingNodeAggregateId | null                               |

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
      | nodeAggregateId           | "company"                          |
      | originDimensionSpacePoint | {"language": "en", "market": "CH"} |
      | propertyValues            | {"uriPathSegment": "evil-company"} |

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
      | nodeAggregateId           | "company"                          |
      | originDimensionSpacePoint | {"language": "en", "market": "CH"} |
      | propertyValues            | {"uriPathSegment": "evil-corp"}    |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                |
      | nodeAggregateId           | "company"                            |
      | originDimensionSpacePoint | {"language": "en", "market": "CH"}   |
      | propertyValues            | {"uriPathSegment": "more-evil-corp"} |

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
      | nodeAggregateId           | "company"                          |
      | originDimensionSpacePoint | {"language": "en", "market": "CH"} |
      | propertyValues            | {"uriPathSegment": "my-company"}   |
    Then I should have a redirect with sourceUri "en_CH/company.html" and targetUri "en_CH/my-company.html"
    And I should have a redirect with sourceUri "en_CH/company/service.html" and targetUri "en_CH/my-company/service.html"
    And I should have a redirect with sourceUri "en_CH/company/about.html" and targetUri "en_CH/my-company/about.html"

    And I should have no redirect with sourceUri "en_CH/company.html" and targetUri "en_CH/company-old.html"

  @fixtures
  Scenario: No redirect should be created for an existing node if any non URI related property changes
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                              |
      | nodeAggregateId           | "buy"                              |
      | originDimensionSpacePoint | {"language": "en", "market": "DE"} |
      | propertyValues            | {"title": "my-buy"}                |
    Then I should have no redirect with sourceUri "en_DE/buy.html"

  @fixtures
  Scenario: No redirect should be created for an restricted node by nodetype
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                            |
      | nodeAggregateId           | "restricted-by-nodetype"                         |
      | originDimensionSpacePoint | {"language": "en", "market": "DE"}               |
      | propertyValues            | {"uriPathSegment": "restricted-by-nodetype-new"} |
    Then I should have no redirect with sourceUri "en/restricted.html"

#  @fixtures
#  Scenario: Redirects should be created for a hidden node
#    When the command DisableNodeAggregate is executed with payload:
#      | Key                          | Value                              |
#      | nodeAggregateId              | "mail"                             |
#      | coveredDimensionSpacePoint   | {"language": "en", "market": "DE"} |
#      | nodeVariantSelectionStrategy | "allVariants"                      |
#    When the command SetNodeProperties is executed with payload:
#      | Key                       | Value                              |
#      | nodeAggregateId           | "mail"                             |
#      | originDimensionSpacePoint | {"language": "en", "market": "DE"} |
#      | propertyValues            | {"uriPathSegment": "not-mail"}     |
#    Then I should have a redirect with sourceUri "en_DE/mail.html" and targetUri "en_DE/not-mail.html"
#    Then I should have a redirect with sourceUri "en_CH/mail.html" and targetUri "en_CH/not-mail.html"

  @fixtures
  Scenario: Change the the `uriPathSegment` and a redirect will be created also for fallback
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                              |
      | nodeAggregateId           | "company"                          |
      | originDimensionSpacePoint | {"language": "de", "market": "DE"} |
      | propertyValues            | {"uriPathSegment": "evil-company"} |

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
      | nodeAggregateId              | "company"                          |
      | coveredDimensionSpacePoint   | {"language": "en", "market": "CH"} |
      | nodeVariantSelectionStrategy | "allSpecializations"               |

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
      | nodeAggregateId              | "company"                          |
      | coveredDimensionSpacePoint   | {"language": "de", "market": "CH"} |
      | nodeVariantSelectionStrategy | "allVariants"                      |

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
      | nodeAggregateId              | "company"                           |
      | nodeVariantSelectionStrategy | "allSpecializations"                |
      | coveredDimensionSpacePoint   | {"language": "de", "market" : "DE"} |

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
