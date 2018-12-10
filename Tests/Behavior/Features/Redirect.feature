Feature: Redirects are created automatically when the URI of an existing node is changed
  Background:
    Given I am authenticated with role "Neos.Neos:Editor"
    And  I have the following content dimensions:
      | Identifier | Default | Presets                |
      | language   | en      | en=en; de=de,en; fr=fr |
    And I have the following nodes:
      | Identifier                           | Path                   | Node Type                  | Properties                                | Workspace | Hidden | Language      |
      | ecf40ad1-3119-0a43-d02e-55f8b5aa3c70 | /sites                 | unstructured               |                                           | live      |        |               |
      | fd5ba6e1-4313-b145-1004-dad2f1173a35 | /sites/behat           | Neos.Neos:Document         | {"uriPathSegment": "home"}                | live      |        | en            |
      | 68ca0dcd-2afb-ef0e-1106-a5301e65b8a0 | /sites/behat/company   | Neos.Neos:Document         | {"uriPathSegment": "company"}             | live      |        | en            |
      | 52540602-b417-11e3-9358-14109fd7a2dd | /sites/behat/service   | Neos.Neos:Document         | {"uriPathSegment": "service"}             | live      |        | en            |
      | dc48851c-f653-ebd5-4d35-3feac69a3e09 | /sites/behat/about     | Neos.Neos:Document         | {"uriPathSegment": "about"}               | live      |        | en            |
      | 511e9e4b-2193-4100-9a91-6fde2586ae95 | /sites/behat/imprint   | Neos.Neos:Document         | {"uriPathSegment": "impressum"}           | live      |        | de            |
      | 511e9e4b-2193-4100-9a91-6fde2586ae95 | /sites/behat/imprint   | Neos.Neos:Document         | {"uriPathSegment": "imprint"}             | live      |        | en            |
      | 511e9e4b-2193-4100-9a91-6fde2586ae95 | /sites/behat/imprint   | Neos.Neos:Document         | {"uriPathSegment": "empreinte"}           | live      |        | fr            |
      | 4bba27c8-5029-4ae6-8371-0f2b3e1700a9 | /sites/behat/buy       | Neos.Neos:Document         | {"uriPathSegment": "buy", "title": "Buy"} | live      |        | en            |
      | 4bba27c8-5029-4ae6-8371-0f2b3e1700a9 | /sites/behat/buy       | Neos.Neos:Document         | {"uriPathSegment": "acheter"}             | live      |        | fr            |
      | 4bba27c8-5029-4ae6-8371-0f2b3e1700a9 | /sites/behat/buy       | Neos.Neos:Document         | {"uriPathSegment": "kaufen"}              | live      | true   | de            |
      | 81dc6c8c-f478-434c-9ac9-bd5d1781cd95 | /sites/behat/mail      | Neos.Neos:Document         | {"uriPathSegment": "mail"}                | live      |        | en            |
      | 81dc6c8c-f478-434c-9ac9-bd5d1781cd95 | /sites/behat/mail      | Neos.Neos:Document         | {"uriPathSegment": "mail"}                | live      | true   | de            |

  @fixtures
  Scenario: Move a node into different node and a redirect will be created
    When I get a node by path "/sites/behat/service" with the following context:
      | Workspace         |
      | user-testaccount  |
    And I move the node into the node with path "/sites/behat/company"
    And I publish the node
    Then I should have a redirect with sourceUri "en/service.html" and targetUri "en/company/service.html"

  @fixtures
  Scenario: Change the the `uriPathSegment` and a redirect will be created
    When I get a node by path "/sites/behat/company" with the following context:
      | Workspace        |
      | user-testaccount |
    And I set the node property "uriPathSegment" to "evil-corp"
    And I publish the node
    Then I should have a redirect with sourceUri "en/company.html" and targetUri "en/evil-corp.html"

  #fixed in 1.0.2
  @fixtures
  Scenario: Retarget an existing redirect when the target URI matches the source URI of the new redirect
    When I get a node by path "/sites/behat/about" with the following context:
      | Workspace        |
      | user-testaccount |
    And I have the following redirects:
      | sourceuripath                           | targeturipath      |
      | en/about.html                           | en/about-you.html  |
    And I set the node property "uriPathSegment" to "about-me"
    And I publish the node
    And I should have a redirect with sourceUri "en/about.html" and targetUri "en/about-me.html"

  @fixtures
  Scenario: Redirects should aways be created in the same dimension the node is in
    When I get a node by path "/sites/behat/imprint" with the following context:
      | Workspace        | Language |
      | user-testaccount | fr       |
    And I set the node property "uriPathSegment" to "empreinte-nouveau"
    And I publish the node
    Then I should have a redirect with sourceUri "fr/empreinte.html" and targetUri "fr/empreinte-nouveau.html"

  #fixed in 1.0.3
  @fixtures
  Scenario: Redirects should aways be created in the same dimension the node is in and not the fallback dimension
    When I get a node by path "/sites/behat/imprint" with the following context:
      | Workspace        | Language |
      | user-testaccount | de,en    |
    And I set the node property "uriPathSegment" to "impressum-neu"
    And I publish the node
    Then I should have a redirect with sourceUri "de/impressum.html" and targetUri "de/impressum-neu.html"
    And I should have no redirect with sourceUri "en/impressum.html" and targetUri "de/impressum-neu.html"

  #fixed in 1.0.3
  @fixtures
  Scenario: I have an existing redirect and it should never be overwritten for a node variant from a different dimension
    When I have the following redirects:
      | sourceuripath                           | targeturipath      |
      | important-page-from-the-old-site        | en/mail.html       |
    When I get a node by path "/sites/behat/mail" with the following context:
      | Workspace        | Language |
      | user-testaccount | de,en    |
    And I unhide the node
    And I publish the node
    Then I should have a redirect with sourceUri "important-page-from-the-old-site" and targetUri "en/mail.html"
    And I should have no redirect with sourceUri "en/mail.html" and targetUri "de/mail.html"

  @fixtures
  Scenario: No redirect should be created for an existing node if any non URI related property changes
    When I get a node by path "/sites/behat/buy" with the following context:
      | Workspace        |
      | user-testaccount |
    And I set the node property "title" to "Buy later"
    And I publish the node
    Then I should have no redirect with sourceUri "en/buy.html"

  @fixtures
  Scenario: Redirects should be created for a hidden node
    When I get a node by path "/sites/behat/buy" with the following context:
      | Workspace        | Language |
      | user-testaccount | de,en    |
    And I set the node property "uriPathSegment" to "nicht-kaufen"
    And I publish the node
    Then I should have a redirect with sourceUri "de/kaufen.html" and targetUri "de/nicht-kaufen.html"

  @fixtures
  Scenario: Create redirects for nodes published in different dimensions
    When I get a node by path "/sites/behat/buy" with the following context:
      | Workspace        |
      | user-testaccount |
    And I move the node into the node with path "/sites/behat/company"
    And I publish the node
    When I get a node by path "/sites/behat/company/buy" with the following context:
      | Workspace        | Language |
      | user-testaccount | de,en    |
    And I publish the node
    Then I should have a redirect with sourceUri "en/buy.html" and targetUri "en/company/buy.html"
    And I should have a redirect with sourceUri "de/kaufen.html" and targetUri "de/company/kaufen.html"

  #fixed in 1.0.4
  @fixtures
  Scenario: Create redirects for nodes that use the current dimension as fallback
    When I get a node by path "/sites/behat/company" with the following context:
      | Workspace        | Language |
      | user-testaccount | en       |
    And I move the node into the node with path "/sites/behat/service"
    And I publish the node
    Then I should have a redirect with sourceUri "en/company.html" and targetUri "en/service/company.html"
    And I should have a redirect with sourceUri "de/company.html" and targetUri "de/service/company.html"
