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
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Request;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Flow\Mvc\Routing\RouterCachingService;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Neos\Routing\Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;

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
     * @Flow\InjectConfiguration(path="restrictByNodeType", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $restrictByNodeType;

    /**
     * {@inheritdoc}
     */
    public function createRedirectsForPublishedNode(NodeInterface $node, Workspace $targetWorkspace)
    {
        try {
            $this->executeRedirectsForPublishedNode($node, $targetWorkspace);
        } catch (\Exception $exception) {
            $this->systemLogger->log(sprintf('Can not create redirect for the node = %s in workspace = %s. See original exception: "%s"', $node->getContextPath(), $targetWorkspace->getName(), $exception->getMessage()), LOG_WARNING);
        }
    }

    /**
     * Creates a redirect for the node if it is a 'Neos.Neos:Document' node and its URI has changed
     *
     * @param NodeInterface $publishedNode The node that is about to be published
     * @param Workspace $targetWorkspace
     * @return void
     * @throws Exception
     * @throws \Neos\Eel\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function executeRedirectsForPublishedNode(NodeInterface $publishedNode, Workspace $targetWorkspace)
    {
        $nodeType = $publishedNode->getNodeType();
        if ($targetWorkspace->getName() !== 'live' || !$nodeType->isOfType('Neos.Neos:Document')) {
            return;
        }

        $liveNode = $this->getLiveNode($publishedNode);
        if ($liveNode === null) {
            // The page has been added
            return;
        }

        if ($this->isRestrictedByNodeType($liveNode) || $this->isRestrictedByPath($liveNode)) {
            return;
        }

        $liveNodeUriPath = $this->buildUriPathForNodeContextPath($liveNode);
        if ($liveNodeUriPath === null) {
            throw new Exception('The target URI path of the node could not be resolved', 1451945358);
        }

        $hosts = $this->getHostnames($publishedNode->getContext());

        // The page has been removed
        if ($publishedNode->isRemoved()) {
            // By default the redirect handling for removed nodes is activated.
            // If it is deactivated in your settings you will be able to handle the redirects on your own.
            // For example redirect to dedicated landing pages for deleted campaign NodeTypes
            if ($this->enableRemovedNodeRedirect) {
                $this->flushRoutingCacheForNode($liveNode);
                $statusCode = (integer)$this->defaultStatusCode['gone'];
                $this->redirectStorage->addRedirect($liveNodeUriPath, '', $statusCode, $hosts);
            }

            return;
        }

        // We need to reset the internal node cache to get a node with new path in the same request
        $this->nodeFactory->reset();

        // compare the "old" node URI to the new one
        $publishedNodeUriPath = $this->buildUriPathForNodeContextPath($publishedNode);
        // use the same regexp than the ContentContextBar Ember View
        $publishedNodeUriPath = preg_replace('/@[A-Za-z0-9;&,\-_=]+/', '', $publishedNodeUriPath);
        if ($publishedNodeUriPath === null || $publishedNodeUriPath === $liveNodeUriPath) {
            // The page node path has not been changed
            return;
        }

        $this->flushRoutingCacheForNode($liveNode);
        $statusCode = (integer)$this->defaultStatusCode['redirect'];
        $this->redirectStorage->addRedirect($liveNodeUriPath, $publishedNodeUriPath, $statusCode, $hosts);

        foreach ($publishedNode->getChildNodes('Neos.Neos:Document') as $childNode) {
            $this->executeRedirectsForPublishedNode($childNode, $targetWorkspace);
        }
    }

    /**
     * @param NodeInterface $publishedNode
     * @return NodeInterface|null
     */
    protected function getLiveNode(NodeInterface $publishedNode): ?NodeInterface
    {
        $liveContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'dimensions' => $publishedNode->getContext()->getDimensions()
        ]);

        return $liveContext->getNodeByIdentifier($publishedNode->getIdentifier());
    }

    /**
     * Check if the current node type is restricted by Settings
     *
     * @param NodeInterface $publishedNode
     * @return bool
     */
    protected function isRestrictedByNodeType(NodeInterface $publishedNode)
    {
        if (!isset($this->restrictByNodeType)) {
            return false;
        }

        foreach ($this->restrictByNodeType as $disabledNodeType => $status) {
            if ($status !== true) {
                continue;
            }
            if ($publishedNode->getNodeType()->isOfType($disabledNodeType)) {
                $this->systemLogger->log(vsprintf('Redirect skipped based on the current node type (%s) for node %s because is of type %s', [
                    $publishedNode->getNodeType()->getName(),
                    $publishedNode->getContextPath(),
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
     * @param NodeInterface $publishedNode
     * @return bool
     */
    protected function isRestrictedByPath(NodeInterface $publishedNode)
    {
        if (!isset($this->restrictByPathPrefix)) {
            return false;
        }

        foreach ($this->restrictByPathPrefix as $pathPrefix => $status) {
            if ($status !== true) {
                continue;
            }
            $pathPrefix = rtrim($pathPrefix, '/') . '/';
            if (mb_strpos($publishedNode->getPath(), $pathPrefix) === 0) {
                $this->systemLogger->log(vsprintf('Redirect skipped based on the current node path (%s) for node %s because prefix matches %s', [
                    $publishedNode->getPath(),
                    $publishedNode->getContextPath(),
                    $pathPrefix
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
    protected function getHostnames(ContentContext $contentContext)
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
     * @return string the resulting (relative) URI or NULL if no route could be resolved
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function buildUriPathForNodeContextPath(NodeInterface $node)
    {
        try {
            return $this->getUriBuilder()
                ->uriFor('show', ['node' => $node], 'Frontend\\Node', 'Neos.Neos');
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
}
