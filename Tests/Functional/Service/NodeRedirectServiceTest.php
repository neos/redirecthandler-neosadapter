<?php
namespace Neos\RedirectHandler\NeosAdapter\Tests\Functional\Service;

/*
 * This file is part of the Neos.RedirectHandler.NeosAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeExistsException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\PublishingService;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use PHPUnit\Framework\MockObject\MockObject;

class NodeRedirectServiceTest extends FunctionalTestCase
{
    /**
     * @var boolean
     */
    protected static $testablePersistenceEnabled = true;

    /**
     * @var NodeRedirectService
     */
    protected $nodeRedirectService;

    /**
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @var RedirectStorageInterface|MockObject
     */
    protected $mockRedirectStorage;

    /**
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @var Workspace
     */
    protected $liveWorkspace;

    /**
     * @var Workspace
     */
    protected $userWorkspace;

    /**
     * @var ContentContext
     */
    protected $userContext;

    /**
     * @var NodeInterface
     */
    protected $site;

    /**
     * @var PublishingService
     */
    protected $publishingService;

    /**
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @throws IllegalObjectTypeException
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->nodeRedirectService = $this->objectManager->get(NodeRedirectService::class);
        $this->publishingService = $this->objectManager->get(PublishingService::class);
        $this->nodeDataRepository = $this->objectManager->get(NodeDataRepository::class);
        $this->siteRepository = $this->objectManager->get(SiteRepository::class);
        $this->mockRedirectStorage = $this->getMockBuilder(RedirectStorageInterface::class)->getMock();
        $this->inject($this->nodeRedirectService, 'redirectStorage', $this->mockRedirectStorage);
        $this->contentContextFactory = $this->objectManager->get(ContentContextFactory::class);
        $this->nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        $this->workspaceRepository = $this->objectManager->get(WorkspaceRepository::class);
        $this->liveWorkspace = new Workspace('live');
        $this->userWorkspace = new Workspace('user-me', $this->liveWorkspace);
        $this->workspaceRepository->add($this->liveWorkspace);
        $this->workspaceRepository->add($this->userWorkspace);
        $liveContext = $this->contentContextFactory->create([
            'workspaceName' => 'live'
        ]);
        $this->userContext = $this->contentContextFactory->create([
            'workspaceName' => 'user-me'
        ]);

        $sites = $liveContext->getRootNode()->createNode('sites');
        $this->site = $sites->createNode('site', $this->nodeTypeManager->getNodeType('Neos.Neos:Document'), 'site');
        $site = new Site('site');
        $site->setSiteResourcesPackageKey('My.Package');
        $this->siteRepository->add($site);
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        parent::tearDown();
        $this->inject($this->contentContextFactory, 'contextInstances', array());
    }

    /**
     * @test
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     */
    public function createRedirectsForPublishedNodeCreatesRedirectFromPreviousUriWhenMovingDocumentDown()
    {
        $documentNodeType = $this->nodeTypeManager->getNodeType('Neos.Neos:Document');

        $this->mockRedirectStorage->expects($this->exactly(1))
            ->method('addRedirect')
            ->with('/en/document.html', '/en/outer/document.html');

        $outerDocument = $this->site->createNode('outer', $documentNodeType);
        $outerDocument->setProperty('uriPathSegment', 'outer');
        $document = $this->site->createNode('document', $documentNodeType, 'document');
        $document->setProperty('uriPathSegment', 'document');

        $documentToBeMoved = $this->userContext->adoptNode($document);
        $documentToBeMoved->moveInto($outerDocument);

        $this->publishingService->publishNode($documentToBeMoved);
    }

    /**
     * @test
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     */
    public function createRedirectsForPublishedNodeCreatesRedirectFromPreviousUriWhenMovingDocumentUp()
    {
        $documentNodeType = $this->nodeTypeManager->getNodeType('Neos.Neos:Document');

        $this->mockRedirectStorage->expects($this->exactly(1))
            ->method('addRedirect')
            ->with('/en/outer/document.html', '/en/document.html');

        $outerDocument = $this->site->createNode('outer', $documentNodeType);
        $outerDocument->setProperty('uriPathSegment', 'outer');
        $document = $outerDocument->createNode('document', $documentNodeType, 'document');
        $document->setProperty('uriPathSegment', 'document');

        $documentToBeMoved = $this->userContext->adoptNode($document);
        $documentToBeMoved->moveInto($this->site);

        $this->publishingService->publishNode($documentToBeMoved);
    }

    /**
     * @test
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     */
    public function createRedirectsForPublishedNodeLeavesUpwardRedirectWhenMovingDocumentDownAndUp()
    {
        $documentNodeType = $this->nodeTypeManager->getNodeType('Neos.Neos:Document');

        $this->mockRedirectStorage->expects($this->exactly(2))
            ->method('addRedirect')
            ->with($this->logicalOr(
                $this->equalTo('/en/document.html'),
                $this->equalTo('/en/outer/document.html')
            ),
                $this->logicalOr(
                    $this->equalTo('/en/outer/document.html'),
                    $this->equalTo('/en/document.html')
                )
            );

        $outerDocument = $this->site->createNode('outer', $documentNodeType, 'outer');
        $outerDocument->setProperty('uriPathSegment', 'outer');
        $document = $this->site->createNode('document', $documentNodeType, 'document');
        $document->setProperty('uriPathSegment', 'document');

        $documentToBeMoved = $this->userContext->adoptNode($document);
        $documentToBeMoved->moveInto($this->userContext->getNodeByIdentifier('outer'));
        $this->publishingService->publishNode($documentToBeMoved);
        $this->nodeDataRepository->persistEntities();

        $documentToBeMoved = $this->userContext->adoptNode($outerDocument->getNode('document'));
        $documentToBeMoved->moveInto($this->userContext->getNodeByIdentifier('site'));

        $this->publishingService->publishNode($documentToBeMoved);
        $this->nodeDataRepository->persistEntities();
    }
}
