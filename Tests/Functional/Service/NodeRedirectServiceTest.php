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
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\PublishingService;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;
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
        $site->setState(Site::STATE_ONLINE);
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
    public function createRedirectsForPublishedNodeCreatesRedirectFromPreviousUriWhenMovingDocumentDown(): void
    {
        $documentNodeType = $this->nodeTypeManager->getNodeType('Neos.Neos:Document');

        $count = 0;
        $this->mockRedirectStorage
            ->method('addRedirect')
            ->willReturnCallback(function ($sourceUri, $targetUri, $statusCode, $hosts) use (&$count) {
                if ($sourceUri === '/en/document.html') {
                    self::assertSame('/en/outer/document.html', $targetUri);
                    self::assertSame(301, $statusCode);
                    self::assertSame([], $hosts);
                    $count++;
                }
                return [];
            });

        $outerDocument = $this->site->createNode('outer', $documentNodeType);
        $outerDocument->setProperty('uriPathSegment', 'outer');
        $document = $this->site->createNode('document', $documentNodeType, 'document');
        $document->setProperty('uriPathSegment', 'document');

        $documentToBeMoved = $this->userContext->adoptNode($document);
        $documentToBeMoved->moveInto($outerDocument);

        $this->publishingService->publishNode($documentToBeMoved);
        $this->persistenceManager->persistAll();

        self::assertSame(1, $count, 'The primary redirect should have been created');
    }

    /**
     * @test
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     */
    public function createRedirectsForPublishedNodeCreatesRedirectFromPreviousUriWhenMovingDocumentUp(): void
    {
        $documentNodeType = $this->nodeTypeManager->getNodeType('Neos.Neos:Document');

        $count = 0;
        $this->mockRedirectStorage
            ->method('addRedirect')
            ->willReturnCallback(function ($sourceUri, $targetUri, $statusCode, $hosts) use (&$count) {
                if ($sourceUri === '/en/outer/document.html') {
                    self::assertSame('/en/document.html', $targetUri);
                    self::assertSame(301, $statusCode);
                    self::assertSame([], $hosts);
                    $count++;
                }
                return [];
            });

        $outerDocument = $this->site->createNode('outer', $documentNodeType);
        $outerDocument->setProperty('uriPathSegment', 'outer');
        $document = $outerDocument->createNode('document', $documentNodeType, 'document');
        $document->setProperty('uriPathSegment', 'document');

        $documentToBeMoved = $this->userContext->adoptNode($document);
        $documentToBeMoved->moveInto($this->site);

        $this->publishingService->publishNode($documentToBeMoved);
        $this->persistenceManager->persistAll();

        self::assertSame(1, $count, 'The primary redirect should have been created');
    }

    /**
     * @test
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     */
    public function createRedirectsForPublishedNodeLeavesUpwardRedirectWhenMovingDocumentDownAndUp(): void
    {
        $documentNodeType = $this->nodeTypeManager->getNodeType('Neos.Neos:Document');

        $countA = 0;
        $countB = 0;
        $this->mockRedirectStorage
            ->method('addRedirect')
            ->willReturnCallback(function ($sourceUri, $targetUri, $statusCode, $hosts) use (&$countA, &$countB) {
                if ($sourceUri === '/en/outer/document.html') {
                    self::assertSame('/en/document.html', $targetUri);
                    self::assertSame(301, $statusCode);
                    self::assertSame([], $hosts);
                    $countA++;
                } elseif ($sourceUri === '/en/document.html') {
                    self::assertSame('/en/outer/document.html', $targetUri);
                    self::assertSame(301, $statusCode);
                    self::assertSame([], $hosts);
                    $countB++;
                }
                return [];
            });

        $outerDocument = $this->site->createNode('outer', $documentNodeType, 'outer');
        $outerDocument->setProperty('uriPathSegment', 'outer');
        $document = $this->site->createNode('document', $documentNodeType, 'document');
        $document->setProperty('uriPathSegment', 'document');

        $documentToBeMoved = $this->userContext->adoptNode($document);
        $documentToBeMoved->moveInto($this->userContext->getNodeByIdentifier('outer'));
        $this->publishingService->publishNode($documentToBeMoved);
        $this->persistenceManager->persistAll();

        $documentToBeMoved = $this->userContext->adoptNode($outerDocument->getNode('document'));
        $documentToBeMoved->moveInto($this->userContext->getNodeByIdentifier('site'));

        $this->publishingService->publishNode($documentToBeMoved);
        $this->persistenceManager->persistAll();

        self::assertSame(1, $countA, 'The primary redirect should have been created');
        self::assertSame(1, $countB, 'The secondary redirect should have been created');
    }
}
