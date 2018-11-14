<?php
namespace Neos\RedirectHandler\NeosAdapter\Service;

/*
 * This file is part of the Neos.RedirectHandler.NeosAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use TYPO3\Eel\FlowQuery\FlowQuery;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Http\Request;
use TYPO3\Flow\Log\SystemLoggerInterface;
use TYPO3\Flow\Mvc\ActionRequest;
use TYPO3\Flow\Mvc\Exception\NoMatchingRouteException;
use TYPO3\Flow\Mvc\Routing\RouterCachingService;
use TYPO3\Flow\Mvc\Routing\UriBuilder;
use TYPO3\Flow\Persistence\PersistenceManagerInterface;
use TYPO3\Neos\Domain\Model\Domain;
use TYPO3\Neos\Domain\Service\ContentContext;
use TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface;
use TYPO3\Neos\Routing\Exception;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Model\Workspace;
use TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository;
use TYPO3\TYPO3CR\Domain\Service\ContentDimensionCombinator;

/**
 * Service that creates redirects for moved / deleted nodes.
 *
 * Note: This is usually invoked by a signal emitted by Workspace::publishNode()
 *
 * @Flow\Scope("singleton")
 */
class NodeRedirectService implements NodeRedirectServiceInterface
{
    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @Flow\Inject
     * @var RedirectStorageInterface
     */
    protected $redirectStorage;

    /**
     * @Flow\Inject
     * @var RouterCachingService
     */
    protected $routerCachingService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\InjectConfiguration(path="statusCode", package="Neos.RedirectHandler")
     * @var array
     */
    protected $defaultStatusCode;

    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /*
     * @Flow\InjectConfiguration(path="enableRemovedNodeRedirect", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $enableRemovedNodeRedirect;

    /**
     * {@inheritdoc}
     */
    public function createRedirectsForPublishedNode(NodeInterface $node, Workspace $targetWorkspace)
    {
        $nodeType = $node->getNodeType();
        if ($targetWorkspace->getName() !== 'live' || !$nodeType->isOfType('TYPO3.Neos:Document')) {
            return;
        }
        $this->createRedirectsForNodesInDimensions($node, $targetWorkspace);
    }

    /**
     * Cycle dimensions and create redirects if necessary.
     *
     * @param $node
     * @param $targetWorkspace
     */
    protected function createRedirectsForNodesInDimensions(NodeInterface $node, Workspace $targetWorkspace)
    {
        foreach ($this->contentDimensionCombinator->getAllAllowedCombinations() as $allowedCombination) {
            $nodeInDimensions = $this->getNodeInDimensions($node, $allowedCombination);
            if ($nodeInDimensions === null) {
                continue;
            }

            $this->createRedirect($nodeInDimensions, $targetWorkspace);
        }
    }

    /**
     * Creates the actual redirect for the given node and possible children.
     *
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     */
    protected function createRedirect(NodeInterface $node, Workspace $targetWorkspace) {

        $context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'dimensions' => $node->getContext()->getDimensions()
        ]);

        $targetNode = $context->getNodeByIdentifier($node->getIdentifier());
        if ($targetNode === null) {
            // The page has been added or is not available in live context for the given dimension
            return;
        }

        $targetNodeUriPath = $this->buildUriPathForNodeContextPath($targetNode->getContextPath());
        if ($targetNodeUriPath === null) {
            throw new Exception('The target URI path of the node could not be resolved', 1451945358);
        }

        $hosts = $this->getHostnames($node->getContext());

        // The page has been removed
        if ($node->isRemoved()) {
            // By default the redirect handling for removed nodes is activated.
            // If it is deactivated in your settings you will be able to handle the redirects on your own.
            // For example redirect to dedicated landing pages for deleted campaign NodeTypes
            if ($this->enableRemovedNodeRedirect) {
                $this->flushRoutingCacheForNode($targetNode);
                $statusCode = (integer)$this->defaultStatusCode['gone'];
                $this->redirectStorage->addRedirect($targetNodeUriPath, '', $statusCode, $hosts);
            }

            return;
        }

        // compare the "old" node URI to the new one
        $nodeUriPath = $this->buildUriPathForNodeContextPath($node->getContextPath());
        // use the same regexp than the ContentContextBar Ember View
        $nodeUriPath = preg_replace('/@[A-Za-z0-9;&,\-_=]+/', '', $nodeUriPath);
        if ($nodeUriPath === null || $nodeUriPath === $targetNodeUriPath) {
            // The page node path has not been changed
            return;
        }

        $this->flushRoutingCacheForNode($targetNode);
        $statusCode = (integer)$this->defaultStatusCode['redirect'];

        $this->redirectStorage->addRedirect($targetNodeUriPath, $nodeUriPath, $statusCode, $hosts);

        $q = new FlowQuery([$node]);
        foreach ($q->children('[instanceof TYPO3.Neos:Document]') as $childrenNode) {
            $this->createRedirect($childrenNode, $targetWorkspace);
        }
    }

    /**
     * Collects all hostnames from the Domain entries attached to the current site.
     *
     * @param ContentContext $contentContext
     * @return array
     */
    protected function getHostnames(ContentContext $contentContext)
    {
        $site = $contentContext->getCurrentSite();
        $domains = [];
        if ($site !== null) {
            foreach ($site->getActiveDomains() as $domain) {
                /** @var Domain $domain */
                $domains[] = $domain->getHostPattern();
            }
        }
        return $domains;
    }

    /**
     * Removes all routing cache entries for the given $nodeData
     *
     * @param NodeInterface $node
     * @return void
     */
    protected function flushRoutingCacheForNode(NodeInterface $node)
    {
        $nodeData = $node->getNodeData();
        $nodeDataIdentifier = $this->persistenceManager->getIdentifierByObject($nodeData);
        if ($nodeDataIdentifier === null) {
            return;
        }
        $this->routerCachingService->flushCachesByTag($nodeDataIdentifier);
    }

    /**
     * Creates a (relative) URI for the given $nodeContextPath removing the "@workspace-name" from the result
     *
     * @param string $nodeContextPath
     * @return string the resulting (relative) URI or NULL if no route could be resolved
     */
    protected function buildUriPathForNodeContextPath($nodeContextPath)
    {
        try {
            return $this->getUriBuilder()
                ->uriFor('show', ['node' => $nodeContextPath], 'Frontend\\Node', 'TYPO3.Neos');
        } catch (NoMatchingRouteException $exception) {
            return null;
        }
    }

    /**
     * Creates an UriBuilder instance for the current request
     *
     * @return UriBuilder
     */
    protected function getUriBuilder()
    {
        if ($this->uriBuilder === null) {
            $httpRequest = Request::createFromEnvironment();
            $actionRequest = new ActionRequest($httpRequest);
            $this->uriBuilder = new UriBuilder();
            $this->uriBuilder
                ->setRequest($actionRequest);
            $this->uriBuilder
                ->setFormat('html')
                ->setCreateAbsoluteUri(false);
        }
        return $this->uriBuilder;
    }

    /**
     * Get the given node in the given dimensions.
     * If it doesn't exist the method returns null.
     *
     * @param NodeInterface $node
     * @param array $dimensions
     * @return NodeInterface|null
     */
    protected function getNodeInDimensions(NodeInterface $node, array $dimensions)
    {
        $context = $this->contextFactory->create([
            'workspaceName' => $node->getWorkspace()->getName(),
            'dimensions' => $dimensions,
            'invisibleContentShown' => true,
        ]);
        return $context->getNode($node->getPath());
    }
}
