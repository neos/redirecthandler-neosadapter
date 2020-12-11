<?php
declare(strict_types=1);

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

use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandRequestHandler;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\Exception as HttpException;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Routing\RouterCachingService;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Domain\Model\Domain;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that creates redirects for moved / deleted nodes.
 *
 * Note: This is usually invoked by signals.
 *
 * @Flow\Scope("singleton")
 */
class NodeRedirectService
{
    use CreateContentContextTrait;

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
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Bootstrap
     * @Flow\Inject
     */
    protected $bootstrap;

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
     * @Flow\InjectConfiguration(path="enableAutomaticRedirects", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $enableAutomaticRedirects;

    /**
     * @Flow\InjectConfiguration(path="http.baseUri", package="Neos.Flow")
     * @var string
     */
    protected $baseUri;

    /**
     * @var array
     */
    protected $pendingRedirects = [];

    /**
     * @var ActionRequest
     */
    protected $actionRequestForUriBuilder;

    /**
     * Collects the node for redirection if it is a 'Neos.Neos:Document' node and its URI has changed
     *
     * @param NodeInterface $node The node that is about to be published
     * @param Workspace $targetWorkspace
     * @return void
     * @throws MissingActionNameException
     */
    public function collectPossibleRedirects(NodeInterface $node, Workspace $targetWorkspace): void
    {
        if (!$this->enableAutomaticRedirects) {
            return;
        }

        $nodeType = $node->getNodeType();
        if ($targetWorkspace->isPublicWorkspace() === false || $nodeType->isOfType('Neos.Neos:Document') === false) {
            return;
        }
        $this->appendNodeAndChildrenDocumentsToPendingRedirects($node, $targetWorkspace);
    }

    /**
     * Returns the current http request or a generated http request
     * based on a configured baseUri to allow redirect generation
     * for CLI requests.
     *
     * @return ActionRequest
     */
    protected function getActionRequestForUriBuilder(): ?ActionRequest
    {
        if ($this->actionRequestForUriBuilder) {
            return $this->actionRequestForUriBuilder;
        }

        /** @var HttpRequestHandlerInterface $requestHandler */
        $requestHandler = $this->bootstrap->getActiveRequestHandler();

        if ($requestHandler instanceof CommandRequestHandler) {
            // Generate a custom request when the current request was triggered from CLI
            $baseUri = $this->baseUri ?? 'http://localhost';

            // Prevent `index.php` appearing in generated redirects
            putenv('FLOW_REWRITEURLS=1');

            $httpRequest = new ServerRequest('POST', $baseUri);
        } else {
            $httpRequest = $requestHandler->getHttpRequest();
        }

        if (method_exists(ActionRequest::class, 'fromHttpRequest')) {
            $routeParameters = $httpRequest->getAttribute('routingParameters') ?? RouteParameters::createEmpty();
            $httpRequest = $httpRequest->withAttribute('routingParameters', $routeParameters->withParameter('requestUriHost', $httpRequest->getUri()->getHost()));
            // From Flow 6+ we have to use a static method to create an ActionRequest. Earlier versions use the constructor.
            $this->actionRequestForUriBuilder = ActionRequest::fromHttpRequest($httpRequest);
        } else {
            /* @deprecated This case can be removed up when this package only supports Flow 6+. */
            if ($httpRequest instanceof ServerRequest) {
                $httpRequest = new \Neos\Flow\Http\Request([], [], [], [
                    'HTTP_HOST' => $httpRequest->getHeaderLine('host'),
                    'HTTPS' => $httpRequest->getHeaderLine('scheme') === 'https',
                    'REQUEST_URI' => $httpRequest->getHeaderLine('path'),
                ]);
            }
            $this->actionRequestForUriBuilder = new ActionRequest($httpRequest);
        }

        return $this->actionRequestForUriBuilder;
    }

    /**
     * Creates the queued redirects provided we can find the node.
     *
     * @return void
     * @throws MissingActionNameException
     */
    public function createPendingRedirects(): void
    {
        if (!$this->enableAutomaticRedirects) {
            return;
        }

        $this->nodeFactory->reset();
        foreach ($this->pendingRedirects as $nodeIdentifierAndWorkspace => $oldUriPerDimensionCombination) {
            [$nodeIdentifier, $workspaceName] = explode('@', $nodeIdentifierAndWorkspace);
            $this->buildRedirects($nodeIdentifier, $workspaceName, $oldUriPerDimensionCombination);
        }
        $this->pendingRedirects = [];

        $this->persistenceManager->persistAll();
    }

    /**
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return void
     * @throws MissingActionNameException
     */
    protected function appendNodeAndChildrenDocumentsToPendingRedirects(NodeInterface $node, Workspace $targetWorkspace): void
    {
        $identifierAndWorkspaceKey = $node->getIdentifier() . '@' . $targetWorkspace->getName();
        if (isset($this->pendingRedirects[$identifierAndWorkspaceKey])) {
            return;
        }

        if (!$this->hasNodeUriChanged($node, $targetWorkspace)) {
            return;
        }

        $this->pendingRedirects[$identifierAndWorkspaceKey] = $this->createUriPathsAcrossDimensionsForNode($node->getIdentifier(), $targetWorkspace);

        foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
            $this->appendNodeAndChildrenDocumentsToPendingRedirects($childNode, $targetWorkspace);
        }
    }

    /**
     * @param string $nodeIdentifier
     * @param Workspace $targetWorkspace
     * @return array
     * @throws MissingActionNameException
     */
    protected function createUriPathsAcrossDimensionsForNode(string $nodeIdentifier, Workspace $targetWorkspace): array
    {
        $result = [];
        foreach ($this->contentDimensionCombinator->getAllAllowedCombinations() as $allowedCombination) {
            $nodeInDimensions = $this->getNodeInWorkspaceAndDimensions($nodeIdentifier, $targetWorkspace->getName(), $allowedCombination);
            if ($nodeInDimensions === null) {
                continue;
            }

            $nodeUriPath = $this->buildUriPathForNode($nodeInDimensions);
            $nodeUriPath = $this->removeContextInformationFromRelativeNodeUri($nodeUriPath);
            $result[] = [
                $nodeUriPath,
                $allowedCombination
            ];
        }

        return $result;
    }

    /**
     * Has the Uri changed at all.
     *
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return bool
     * @throws MissingActionNameException
     */
    protected function hasNodeUriChanged(NodeInterface $node, Workspace $targetWorkspace): bool
    {
        $newUriPath = $this->buildUriPathForNode($node);
        $newUriPath = $this->removeContextInformationFromRelativeNodeUri($newUriPath);

        $nodeInTargetWorkspace = $this->getNodeInWorkspace($node, $targetWorkspace);
        if (!$nodeInTargetWorkspace) {
            return false;
        }
        $oldUriPath = $this->buildUriPathForNode($nodeInTargetWorkspace);
        $oldUriPath = $this->removeContextInformationFromRelativeNodeUri($oldUriPath);

        return ($newUriPath !== $oldUriPath);
    }

    /**
     * Build redirects in all dimensions for a given node.
     *
     * @param string $nodeIdentifier
     * @param string $workspaceName
     * @param $oldUriPerDimensionCombination
     * @return void
     * @throws MissingActionNameException
     */
    protected function buildRedirects(string $nodeIdentifier, string $workspaceName, array $oldUriPerDimensionCombination): void
    {
        foreach ($oldUriPerDimensionCombination as [$oldRelativeUri, $dimensionCombination]) {
            $this->createRedirectFrom($oldRelativeUri, $nodeIdentifier, $workspaceName, $dimensionCombination);
        }
    }

    /**
     * Gets the node in the given dimensions and workspace and redirects the oldUri to the new one.
     *
     * @param string $oldUri
     * @param string $nodeIdentifer
     * @param string $workspaceName
     * @param array $dimensionCombination
     * @return bool
     * @throws MissingActionNameException
     */
    protected function createRedirectFrom(string $oldUri, string $nodeIdentifer, string $workspaceName, array $dimensionCombination): bool
    {
        $node = $this->getNodeInWorkspaceAndDimensions($nodeIdentifer, $workspaceName, $dimensionCombination);
        if ($node === null) {
            return false;
        }

        if ($this->isRestrictedByNodeType($node) || $this->isRestrictedByPath($node) || $this->isRestrictedByOldUri($oldUri, $node)) {
            return false;
        }

        $newUri = $this->buildUriPathForNode($node);

        if ($node->isRemoved()) {
            return $this->removeNodeRedirectIfNeeded($node, $newUri);
        }

        if ($oldUri === $newUri) {
            return false;
        }

        $hosts = $this->getHostnames($node);
        $this->flushRoutingCacheForNode($node);
        $statusCode = (integer)$this->defaultStatusCode['redirect'];

        $this->redirectStorage->addRedirect($oldUri, $newUri, $statusCode, $hosts);

        return true;
    }

    /**
     * Removes a redirect
     *
     * @param NodeInterface $node
     * @param string $newUri
     * @return bool
     */
    protected function removeNodeRedirectIfNeeded(NodeInterface $node, string $newUri): bool
    {
        // By default the redirect handling for removed nodes is activated.
        // If it is deactivated in your settings you will be able to handle the redirects on your own.
        // For example redirect to dedicated landing pages for deleted campaign NodeTypes
        if ($this->enableRemovedNodeRedirect) {
            $hosts = $this->getHostnames($node);
            $this->flushRoutingCacheForNode($node);
            $statusCode = (integer)$this->defaultStatusCode['gone'];
            $this->redirectStorage->addRedirect($newUri, '', $statusCode, $hosts);

            return true;
        }

        return false;
    }

    /**
     * Removes any context information appended to a node Uri.
     *
     * @param string $relativeNodeUri
     * @return string
     */
    protected function removeContextInformationFromRelativeNodeUri(string $relativeNodeUri): string
    {
        // FIXME: Uses the same regexp than the ContentContextBar Ember View, but we can probably find something better.
        return (string)preg_replace('/@[A-Za-z0-9;&,\-_=]+/', '', $relativeNodeUri);
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
                $this->logger->debug(vsprintf('Redirect skipped based on the current node type (%s) for node %s because is of type %s', [
                    $node->getNodeType()->getName(),
                    $node->getContextPath(),
                    $disabledNodeType
                ]));

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
                $this->logger->debug(vsprintf('Redirect skipped based on the current node path (%s) for node %s because prefix matches %s', [
                    $node->getPath(),
                    $node->getContextPath(),
                    $pathPrefix
                ]));

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
                $this->logger->debug(vsprintf('Redirect skipped based on the old URI (%s) for node %s because prefix matches %s', [
                    $oldUri,
                    $node->getContextPath(),
                    $uriPrefix
                ]));

                return true;
            }
        }

        return false;
    }

    /**
     * Collects all hostnames from the Domain entries attached to the current site.
     *
     * @param NodeInterface $node
     * @return array
     */
    protected function getHostnames(NodeInterface $node): array
    {
        $contentContext = $this->createContextMatchingNodeData($node->getNodeData());
        $domains = [];
        $site = $contentContext->getCurrentSite();
        if ($site === null) {
            return $domains;
        }

        foreach ($site->getActiveDomains() as $domain) {
            /** @var Domain $domain */
            $domains[] = $domain->getHostname();
        }

        return $domains;
    }

    /**
     * Removes all routing cache entries for the given $nodeData
     *
     * @param NodeInterface $node
     * @return void
     */
    protected function flushRoutingCacheForNode(NodeInterface $node): void
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
     * @throws MissingActionNameException
     * @throws HttpException
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
        if ($this->uriBuilder !== null) {
            return $this->uriBuilder;
        }

        $this->uriBuilder = new UriBuilder();
        $this->uriBuilder
            ->setFormat('html')
            ->setCreateAbsoluteUri(false)
            ->setRequest($this->getActionRequestForUriBuilder());

        return $this->uriBuilder;
    }

    /**
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return NodeInterface|null
     */
    protected function getNodeInWorkspace(NodeInterface $node, Workspace $targetWorkspace): ?NodeInterface
    {
        return $this->getNodeInWorkspaceAndDimensions($node->getIdentifier(), $targetWorkspace->getName(), $node->getContext()->getDimensions());
    }

    /**
     * @param string $nodeIdentifier
     * @param string $workspaceName
     * @param array $dimensionCombination
     * @return NodeInterface|null
     */
    protected function getNodeInWorkspaceAndDimensions(string $nodeIdentifier, string $workspaceName, array $dimensionCombination): ?NodeInterface
    {
        $context = $this->contextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $dimensionCombination,
            'invisibleContentShown' => true,
        ]);

        return $context->getNodeByIdentifier($nodeIdentifier);
    }
}
