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

use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Request;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\RouterCachingService;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Routing\Exception;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;

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
     * @var NodeFactory
     */
    protected $nodeFactory;

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

    /**
     * @Flow\InjectConfiguration(path="enableRemovedNodeRedirect", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $enableRemovedNodeRedirect;

    /**
     * @Flow\InjectConfiguration(path="restrictByPathPrefix", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $restrictByPathPrefix;

    /**
     * @Flow\InjectConfiguration(path="restrictByOldUriPrefix", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $restrictByOldUriPrefix;

    /**
     * @Flow\InjectConfiguration(path="restrictByNodeType", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $restrictByNodeType;

    /**
     * Creates a redirect for the node if it is a 'Neos.Neos:Document' node and its URI has changed
     *
     * @param NodeInterface $node The node that is about to be published
     * @param Workspace $targetWorkspace
     * @return void
     * @throws Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    public function createRedirectsForPublishedNode(NodeInterface $node, Workspace $targetWorkspace)
    {
        $nodeType = $node->getNodeType();
        if ($targetWorkspace->isPublicWorkspace() === false || $nodeType->isOfType('Neos.Neos:Document') === false) {
            return;
        }
        $this->createRedirectsForNodesInDimensions($node, $targetWorkspace);
    }

    /**
     * Cycle dimensions and create redirects if necessary.
     *
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return void
     * @throws Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
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
     * @return void
     * @throws Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function createRedirect(NodeInterface $node, Workspace $targetWorkspace)
    {
        $targetNode = $this->getTargetNode($node, $targetWorkspace);
        if ($targetNode === null) {
            // The node has been added or is not available in target context for the given dimension
            return;
        }

        if ($this->isRestrictedByNodeType($targetNode) || $this->isRestrictedByPath($targetNode)) {
            return;
        }

        $targetNodeUriPath = $this->buildUriPathForNode($targetNode);
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

        // We need to reset the internal node cache to get a node with new path in the same request
        $this->nodeFactory->reset();

        // compare the "old" node URI to the new one
        $nodeUriPath = $this->buildUriPathForNode($node);
        // use the same regexp than the ContentContextBar Ember View
        $nodeUriPath = preg_replace('/@[A-Za-z0-9;&,\-_=]+/', '', $nodeUriPath);
        if ($nodeUriPath === $targetNodeUriPath || $this->isRestrictedByOldUri($nodeUriPath, $node)) {
            // The page URI path has not been changed or is restricted
            return;
        }

        $this->flushRoutingCacheForNode($targetNode);
        $statusCode = (integer)$this->defaultStatusCode['redirect'];

        $this->redirectStorage->addRedirect($targetNodeUriPath, $nodeUriPath, $statusCode, $hosts);

        foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
            $this->createRedirect($childNode, $targetWorkspace);
        }
    }

    /**
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return NodeInterface|null
     */
    protected function getTargetNode(NodeInterface $node, Workspace $targetWorkspace)
    {
        $context = $this->contextFactory->create([
            'workspaceName' => $targetWorkspace->getName(),
            'invisibleContentShown' => true,
            'dimensions' => $node->getContext()->getDimensions()
        ]);

        return $context->getNodeByIdentifier($node->getIdentifier());
    }

    /**
     * Check if the current node type is restricted by Settings
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isRestrictedByNodeType(NodeInterface $node): bool
    {
        if (!isset($this->restrictByNodeType)) {
            return false;
        }

        foreach ($this->restrictByNodeType as $disabledNodeType => $status) {
            if ($status !== true) {
                continue;
            }
            if ($node->getNodeType()->isOfType($disabledNodeType)) {
                $this->systemLogger->log(vsprintf('Redirect skipped based on the current node type (%s) for node %s because is of type %s', [
                    $node->getNodeType()->getName(),
                    $node->getContextPath(),
                    $disabledNodeType
                ]), LOG_DEBUG, null, 'RedirectHandler');
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current node path is restricted by Settings
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isRestrictedByPath(NodeInterface $node): bool
    {
        if (!isset($this->restrictByPathPrefix)) {
            return false;
        }

        foreach ($this->restrictByPathPrefix as $pathPrefix => $status) {
            if ($status !== true) {
                continue;
            }
            $pathPrefix = rtrim($pathPrefix, '/') . '/';
            if (mb_strpos($node->getPath(), $pathPrefix) === 0) {
                $this->systemLogger->log(vsprintf('Redirect skipped based on the current node path (%s) for node %s because prefix matches %s', [
                    $node->getPath(),
                    $node->getContextPath(),
                    $pathPrefix
                ]), LOG_DEBUG, null, 'RedirectHandler');

                return true;
            }
        }

        return false;
    }

    /**
     * Check if the old URI is restricted by Settings
     *
     * @param string $oldUri
     * @param NodeInterface $node
     * @return bool
     */
    protected function isRestrictedByOldUri(string $oldUri, NodeInterface $node): bool
    {
        if (!isset($this->restrictByOldUriPrefix)) {
            return false;
        }

        foreach ($this->restrictByOldUriPrefix as $uriPrefix => $status) {
            if ($status !== true) {
                continue;
            }
            $uriPrefix = rtrim($uriPrefix, '/') . '/';
            if (mb_strpos($oldUri, $uriPrefix) === 0) {
                $this->systemLogger->log(vsprintf('Redirect skipped based on the old URI (%s) for node %s because prefix matches %s', [
                    $oldUri,
                    $node->getContextPath(),
                    $uriPrefix
                ]), LOG_DEBUG, null, 'RedirectHandler');

                return true;
            }
        }

        return false;
    }

    /**
     * Collects all hostnames from the Domain entries attached to the current site.
     *
     * @param ContentContext $contentContext
     * @return array
     */
    protected function getHostnames(ContentContext $contentContext): array
    {
        $site = $contentContext->getCurrentSite();
        $domains = [];
        if ($site !== null) {
            foreach ($site->getActiveDomains() as $domain) {
                /** @var Domain $domain */
                $domains[] = $domain->getHostname();
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
     * @param NodeInterface $node
     * @return string the resulting (relative) URI
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function buildUriPathForNode(NodeInterface $node): string
    {
        return $this->getUriBuilder()
            ->uriFor('show', ['node' => $node], 'Frontend\\Node', 'Neos.Neos');
    }

    /**
     * Creates an UriBuilder instance for the current request
     *
     * @return UriBuilder
     */
    protected function getUriBuilder(): UriBuilder
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
