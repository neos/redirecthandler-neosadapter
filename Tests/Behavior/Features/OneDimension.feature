@flowEntities
Feature: Basic redirect handling with document nodes in one dimension

  Background:
    Given using the following content dimensions:
      | Identifier | Values      | Generalizations |
      | language   | de, en, gsw | gsw->de, en     |
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
    """yaml
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

  Scenario: Move a node down into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value              |
      | nodeAggregateId                     | "imprint"          |
      | dimensionSpacePoint                 | {"language": "en"} |
      | newParentNodeAggregateId            | "company"          |
      | newSucceedingSiblingNodeAggregateId | null               |
        Then I should have a redirect with sourceUri "en/imprint.html" and targetUri "en/company/imprint.html"
    And I should have a redirect with sourceUri "imprint.html" and targetUri "company/imprint.html"
    And I should have a redirect with sourceUri "ch/imprint.html" and targetUri "ch/company/imprint.html"

  Scenario: Move a node up into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value              |
      | nodeAggregateId                     | "service"          |
      | dimensionSpacePoint                 | {"language": "en"} |
      | newParentNodeAggregateId            | "behat"            |
      | newSucceedingSiblingNodeAggregateId | null               |
        Then I should have a redirect with sourceUri "en/company/service.html" and targetUri "en/service.html"
    And I should have a redirect with sourceUri "company/service.html" and targetUri "service.html"
    And I should have a redirect with sourceUri "ch/company/service.html" and targetUri "ch/service.html"

  Scenario: Change the the `uriPathSegment` and a redirect will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                           |
      | nodeAggregateId           | "company"                       |
      | originDimensionSpacePoint | {"language": "en"}              |
      | propertyValues            | {"uriPathSegment": "evil-corp"} |

    Then I should have a redirect with sourceUri "en/company.html" and targetUri "en/evil-corp.html"
    And I should have a redirect with sourceUri "en/company/about.html" and targetUri "en/evil-corp/about.html"
    And I should have a redirect with sourceUri "en/company/service.html" and targetUri "en/evil-corp/service.html"

    And I should have no redirect with sourceUri "company.html"
    And I should have no redirect with sourceUri "company/about.html"
    And I should have no redirect with sourceUri "company/service.html"

    And I should have no redirect with sourceUri "ch/company.html"
    And I should have no redirect with sourceUri "ch/company/about.html"
    And I should have no redirect with sourceUri "ch/company/service.html"


  Scenario: Change the the `uriPathSegment` multiple times and multiple redirects will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                           |
      | nodeAggregateId           | "company"                       |
      | originDimensionSpacePoint | {"language": "en"}              |
      | propertyValues            | {"uriPathSegment": "evil-corp"} |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                |
      | nodeAggregateId           | "company"                            |
      | originDimensionSpacePoint | {"language": "en"}                   |
      | propertyValues            | {"uriPathSegment": "more-evil-corp"} |

    Then I should have a redirect with sourceUri "en/company.html" and targetUri "en/more-evil-corp.html"
    And I should have a redirect with sourceUri "en/company/service.html" and targetUri "en/more-evil-corp/service.html"
    And I should have a redirect with sourceUri "en/evil-corp.html" and targetUri "en/more-evil-corp.html"
    And I should have a redirect with sourceUri "en/evil-corp/service.html" and targetUri "en/more-evil-corp/service.html"


  Scenario: Retarget an existing redirect when the source URI matches the source URI of the new redirect
    When I have the following redirects:
      | sourceuripath | targeturipath |
      | company       | company-old   |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                            |
      | nodeAggregateId           | "company"                        |
      | originDimensionSpacePoint | {"language": "en"}               |
      | propertyValues            | {"uriPathSegment": "my-company"} |
        Then I should have a redirect with sourceUri "en/company.html" and targetUri "en/my-company.html"
    And I should have no redirect with sourceUri "en/company.html" and targetUri "en/company-old.html"
    And I should have a redirect with sourceUri "en/company/service.html" and targetUri "en/my-company/service.html"

  Scenario: No redirect should be created for an existing node if any non URI related property changes
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value               |
      | nodeAggregateId           | "buy"               |
      | originDimensionSpacePoint | {"language": "en"}  |
      | propertyValues            | {"title": "my-buy"} |
        Then I should have no redirect with sourceUri "en/buy.html"

  Scenario: No redirect should be created for an restricted node by nodetype
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                            |
      | nodeAggregateId           | "restricted-by-nodetype"                         |
      | originDimensionSpacePoint | {"language": "en"}                               |
      | propertyValues            | {"uriPathSegment": "restricted-by-nodetype-new"} |
        Then I should have no redirect with sourceUri "en/restricted.html"

##  Scenario: Redirects should be created for a hidden node
#    When the command DisableNodeAggregate is executed with payload:
#      | Key                          | Value              |
#      | nodeAggregateId              | "mail"             |
#      | coveredDimensionSpacePoint   | {"language": "en"} |
#      | nodeVariantSelectionStrategy | "allVariants"      |
##    When the command SetNodeProperties is executed with payload:
#      | Key                       | Value                          |
#      | nodeAggregateId           | "mail"                         |
#      | originDimensionSpacePoint | {"language": "en"}             |
#      | propertyValues            | {"uriPathSegment": "not-mail"} |
#    #    Then I should have a redirect with sourceUri "en/mail.html" and targetUri "en/not-mail.html"

  Scenario: Change the the `uriPathSegment` and a redirect will be created also for fallback

    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                             |
      | nodeAggregateId           | "company"                         |
      | originDimensionSpacePoint | {"language": "de"}                |
      | propertyValues            | {"uriPathSegment": "unternehmen"} |

    Then I should have a redirect with sourceUri "company.html" and targetUri "unternehmen.html"
    And I should have a redirect with sourceUri "company/service.html" and targetUri "unternehmen/service.html"
    And I should have a redirect with sourceUri "ch/company.html" and targetUri "ch/unternehmen.html"
    And I should have a redirect with sourceUri "ch/company/service.html" and targetUri "ch/unternehmen/service.html"


  Scenario: A removed node should lead to a GONE response with empty target uri (allSpecializations)
    Given the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                |
      | nodeAggregateId              | "company"            |
      | coveredDimensionSpacePoint   | {"language": "en"}   |
      | nodeVariantSelectionStrategy | "allSpecializations" |

    Then I should have a redirect with sourceUri "en/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "en/company.html" and targetUri ""
    And I should have a redirect with sourceUri "en/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "en/company/service.html" and targetUri ""

    And I should have no redirect with sourceUri "company.html"
    And I should have no redirect with sourceUri "company/service.html"
    And I should have no redirect with sourceUri "ch/company.html"
    And I should have no redirect with sourceUri "ch/company/service.html"

  Scenario: A removed node should lead to a GONE response with empty target uri (allVariants)
    Given the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value              |
      | nodeAggregateId              | "company"          |
      | coveredDimensionSpacePoint   | {"language": "de"} |
      | nodeVariantSelectionStrategy | "allVariants"      |

    Then I should have a redirect with sourceUri "en/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "en/company.html" and targetUri ""
    And I should have a redirect with sourceUri "en/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "en/company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "company.html" and statusCode "410"
    And I should have a redirect with sourceUri "company.html" and targetUri ""
    And I should have a redirect with sourceUri "company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "ch/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch/company.html" and targetUri ""
    And I should have a redirect with sourceUri "ch/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch/company/service.html" and targetUri ""

  Scenario: A removed node should lead to a GONE response with empty target uri also for fallback (allSpecializations)
    Given the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                |
      | nodeAggregateId              | "company"            |
      | nodeVariantSelectionStrategy | "allSpecializations" |
      | coveredDimensionSpacePoint   | {"language": "de"}   |

    Then I should have a redirect with sourceUri "company.html" and statusCode "410"
    And I should have a redirect with sourceUri "company.html" and targetUri ""
    And I should have a redirect with sourceUri "company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "company/service.html" and targetUri ""

    And I should have a redirect with sourceUri "ch/company.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch/company.html" and targetUri ""
    And I should have a redirect with sourceUri "ch/company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "ch/company/service.html" and targetUri ""

    And I should have no redirect with sourceUri "en/company.html"
    And I should have no redirect with sourceUri "en/company/service.html"
