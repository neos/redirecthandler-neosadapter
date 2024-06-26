@flowEntities
Feature: Basic redirect handling with document nodes without dimensions

  Background:
    Given using no content dimensions
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
      | Key                  | Value                |
      | workspaceName        | "live"               |
      | workspaceTitle       | "Live"               |
      | workspaceDescription | "The live workspace" |
      | newContentStreamId   | "cs-identifier"      |
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
    """yaml
    Neos:
      Neos:
        sites:
          '*':
            uriPathSuffix: '.html'
            contentRepository: default
            contentDimensions:
              resolver:
                factoryClassName: Neos\Neos\FrontendRouting\DimensionResolution\Resolver\NoopResolverFactory
    """

  Scenario: Move a node down into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value           |
      | nodeAggregateId                     | "imprint"       |
      | dimensionSpacePoint                 | {}              |
      | newParentNodeAggregateId            | "company"       |
      | newSucceedingSiblingNodeAggregateId | null            |
        Then I should have a redirect with sourceUri "imprint.html" and targetUri "company/imprint.html"

  Scenario: Move a node up into different node and a redirect will be created
    When the command MoveNodeAggregate is executed with payload:
      | Key                                 | Value           |
      | nodeAggregateId                     | "service"       |
      | dimensionSpacePoint                 | {}              |
      | newParentNodeAggregateId            | "behat"         |
      | newSucceedingSiblingNodeAggregateId | null            |
        Then I should have a redirect with sourceUri "company/service.html" and targetUri "service.html"

  Scenario: Change the the `uriPathSegment` and a redirect will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                           |
      | nodeAggregateId           | "company"                       |
      | originDimensionSpacePoint | {}                              |
      | propertyValues            | {"uriPathSegment": "evil-corp"} |
    Then I should have a redirect with sourceUri "company.html" and targetUri "evil-corp.html"
    And I should have a redirect with sourceUri "company/service.html" and targetUri "evil-corp/service.html"

  Scenario: Change the the `uriPathSegment` multiple times and multiple redirects will be created
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                           |
      | nodeAggregateId           | "company"                       |
      | originDimensionSpacePoint | {}                              |
      | propertyValues            | {"uriPathSegment": "evil-corp"} |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                |
      | nodeAggregateId           | "company"                            |
      | originDimensionSpacePoint | {}                                   |
      | propertyValues            | {"uriPathSegment": "more-evil-corp"} |

    Then I should have a redirect with sourceUri "company.html" and targetUri "more-evil-corp.html"
    And I should have a redirect with sourceUri "company/service.html" and targetUri "more-evil-corp/service.html"
    And I should have a redirect with sourceUri "evil-corp.html" and targetUri "more-evil-corp.html"
    And I should have a redirect with sourceUri "evil-corp/service.html" and targetUri "more-evil-corp/service.html"


  Scenario: Retarget an existing redirect when the source URI matches the source URI of the new redirect
    When I have the following redirects:
      | sourceuripath | targeturipath |
      | company       | company-old   |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                            |
      | nodeAggregateId           | "company"                        |
      | originDimensionSpacePoint | {}                               |
      | propertyValues            | {"uriPathSegment": "my-company"} |
        Then I should have a redirect with sourceUri "company.html" and targetUri "my-company.html"
    And I should have no redirect with sourceUri "company.html" and targetUri "company-old.html"
    And I should have a redirect with sourceUri "company/service.html" and targetUri "my-company/service.html"

  Scenario: No redirect should be created for an existing node if any non URI related property changes
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value               |
      | nodeAggregateId           | "buy"               |
      | originDimensionSpacePoint | {}                  |
      | propertyValues            | {"title": "my-buy"} |
        Then I should have no redirect with sourceUri "buy.html"

  Scenario: No redirect should be created for an restricted node by nodetype
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                            |
      | nodeAggregateId           | "restricted-by-nodetype"                         |
      | originDimensionSpacePoint | {}                                               |
      | propertyValues            | {"uriPathSegment": "restricted-by-nodetype-new"} |
        Then I should have no redirect with sourceUri "restricted.html"

  Scenario: Redirects should be created for a hidden node
    When the command DisableNodeAggregate is executed with payload:
      | Key                          | Value           |
      | nodeAggregateId              | "mail"          |
      | coveredDimensionSpacePoint   | {}              |
      | nodeVariantSelectionStrategy | "allVariants"   |
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                          |
      | nodeAggregateId           | "mail"                         |
      | originDimensionSpacePoint | {}                             |
      | propertyValues            | {"uriPathSegment": "not-mail"} |
        Then I should have a redirect with sourceUri "mail.html" and targetUri "not-mail.html"

  Scenario: A removed node should lead to a GONE response with empty target uri
    Given the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value           |
      | nodeAggregateId              | "company"       |
      | nodeVariantSelectionStrategy | "allVariants"   |

    Then I should have a redirect with sourceUri "company.html" and statusCode "410"
    And I should have a redirect with sourceUri "company.html" and targetUri ""
    And I should have a redirect with sourceUri "company/service.html" and statusCode "410"
    And I should have a redirect with sourceUri "company/service.html" and targetUri ""
