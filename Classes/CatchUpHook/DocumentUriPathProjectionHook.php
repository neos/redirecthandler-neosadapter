<?php

namespace Neos\RedirectHandler\NeosAdapter\CatchUpHook;

use Neos\ContentRepository\Core\Projection\CatchUpHookInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\EventStore\Model\EventEnvelope;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeMove\Event\NodeAggregateWasMoved;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathFinder;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\CoverageNodeMoveMapping;
use Neos\Neos\FrontendRouting\Projection\DocumentNodeInfo;
use Neos\Neos\FrontendRouting\Exception\NodeNotFoundException;
use Neos\Neos\FrontendRouting\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\Factory\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;

final class DocumentUriPathProjectionHook implements CatchUpHookInterface
{
    /**
     * @var array<string, array<DocumentNodeInfo>>
     */
    private array $documentNodeInfosBeforeRemoval;

    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly NodeRedirectService $nodeRedirectService,
    ) {
    }

    public function onBeforeCatchUp(): void
    {
        // Nothing to do here
    }

    public function onBeforeEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void
    {
        match ($eventInstance::class) {
            NodeAggregateWasRemoved::class => $this->onBeforeNodeAggregateWasRemoved($eventInstance),
            NodePropertiesWereSet::class => $this->onBeforeNodePropertiesWereSet($eventInstance),
            NodeAggregateWasMoved::class => $this->onBeforeNodeAggregateWasMoved($eventInstance),
            default => null
        };

    }

    public function onAfterEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void
    {
        match ($eventInstance::class) {
            NodeAggregateWasRemoved::class => $this->onAfterNodeAggregateWasRemoved($eventInstance),
            NodePropertiesWereSet::class => $this->onAfterNodePropertiesWereSet($eventInstance),
            NodeAggregateWasMoved::class => $this->onAfterNodeAggregateWasMoved($eventInstance),
            default => null
        };
    }

    public function onBeforeBatchCompleted(): void
    {
        // Nothing to do here
    }

    public function onAfterCatchUp(): void
    {
        // Nothing to do here
    }

    private function onBeforeNodeAggregateWasRemoved(NodeAggregateWasRemoved $event): void
    {
        if (!$this->isLiveContentStream($event->contentStreamId)) {
            return;
        }

        $this->documentNodeInfosBeforeRemoval = [];

        foreach ($event->affectedCoveredDimensionSpacePoints as $dimensionSpacePoint) {
            $node = $this->tryGetNode(fn() => $this->getState()->getByIdAndDimensionSpacePointHash(
                $event->nodeAggregateId,
                $dimensionSpacePoint->hash
            ));
            if ($node === null) {
                // Probably not a document node
                continue;
            }

            $this->nodeRedirectService->appendAffectedNode(
                $node,
                $this->getNodeAddress($event->contentStreamId, $dimensionSpacePoint, $node->getNodeAggregateId()),
                $this->getContentRepositoryId()
            );
            $this->documentNodeInfosBeforeRemoval[$dimensionSpacePoint->hash][] = $node;

            $children = $this->getState()->getAllChildrenOfNode($node);
            array_map(
                function ($childNode) use ($event, $dimensionSpacePoint) {
                    $this->nodeRedirectService->appendAffectedNode(
                        $childNode,
                        $this->getNodeAddress($event->contentStreamId, $dimensionSpacePoint, $childNode->getNodeAggregateId()),
                        $this->getContentRepositoryId()
                    );
                    $this->documentNodeInfosBeforeRemoval[$dimensionSpacePoint->hash][] = $childNode;
                },
                $children);
        }
    }

    private function onAfterNodeAggregateWasRemoved(NodeAggregateWasRemoved $event): void
    {
        if (!$this->isLiveContentStream($event->contentStreamId)) {
            return;
        }

        foreach ($event->affectedCoveredDimensionSpacePoints as $dimensionSpacePoint) {
            $documentNodeInfosBeforeRemoval = $this->documentNodeInfosBeforeRemoval[$dimensionSpacePoint->hash];
            unset($this->documentNodeInfosBeforeRemoval[$dimensionSpacePoint->hash]);

            array_map(
                fn($node) => $this->nodeRedirectService->createRedirectForRemovedAffectedNode(
                    $node,
                    $this->getContentRepositoryId()
                ),
                $documentNodeInfosBeforeRemoval
            );
        }
    }

    private function onBeforeNodePropertiesWereSet(NodePropertiesWereSet $event): void
    {
        $this->handleNodePropertiesWereSet(
            $event,
            fn(
                DocumentNodeInfo $node, NodeAddress $nodeAddress,
            ) => $this->nodeRedirectService->appendAffectedNode($node, $nodeAddress, $this->getContentRepositoryId())
        );
    }

    private function onAfterNodePropertiesWereSet(NodePropertiesWereSet $event): void
    {
        $this->handleNodePropertiesWereSet(
            $event,
            fn(
                DocumentNodeInfo $node, NodeAddress $nodeAddress,
            ) => $this->nodeRedirectService->createRedirectForAffectedNode($node, $nodeAddress, $this->getContentRepositoryId())
        );
    }

    private function handleNodePropertiesWereSet(NodePropertiesWereSet $event, \Closure $closure): void
    {
        if (!$this->isLiveContentStream($event->contentStreamId)) {
            return;
        }

        $newPropertyValues = $event->propertyValues->getPlainValues();
        if (!isset($newPropertyValues['uriPathSegment'])) {
            return;
        }

        foreach ($event->affectedDimensionSpacePoints as $affectedDimensionSpacePoint) {
            $node = $this->tryGetNode(fn() => $this->getState()->getByIdAndDimensionSpacePointHash(
                $event->nodeAggregateId,
                $affectedDimensionSpacePoint->hash
            ));
            file_put_contents('/var/www/html/Data/Logs/foo.log', $node->getUriPath() . "\n", flags: FILE_APPEND);
            if ($node === null) {
                // probably not a document node
                continue;
            }

            $closure($node, $this->getNodeAddress($event->contentStreamId, $affectedDimensionSpacePoint, $node->getNodeAggregateId()));

            $children = $this->getState()->getAllChildrenOfNode($node);
            array_map(fn($childNode) => $closure($childNode, $this->getNodeAddress($event->contentStreamId, $affectedDimensionSpacePoint, $childNode->getNodeAggregateId())), $children);
        }
    }

    private function onBeforeNodeAggregateWasMoved(NodeAggregateWasMoved $event): void
    {
        $this->handleNodeWasMoved(
            $event,
            fn(
                DocumentNodeInfo $node, NodeAddress $nodeAddress,
            ) => $this->nodeRedirectService->appendAffectedNode($node, $nodeAddress, $this->getContentRepositoryId())
        );
    }

    private function onAfterNodeAggregateWasMoved(NodeAggregateWasMoved $event): void
    {
        $this->handleNodeWasMoved(
            $event,
            fn(
                DocumentNodeInfo $node, NodeAddress $nodeAddress,
            ) => $this->nodeRedirectService->createRedirectForAffectedNode($node, $nodeAddress, $this->getContentRepositoryId())
        );
    }

    private function handleNodeWasMoved(NodeAggregateWasMoved $event, \Closure $closure): void
    {
        if (!$this->isLiveContentStream($event->contentStreamId)) {
            return;
        }

        foreach ($event->nodeMoveMappings as $moveMapping) {
            /* @var \Neos\ContentRepository\Core\Feature\NodeMove\Dto\OriginNodeMoveMapping $moveMapping */
            foreach ($moveMapping->newLocations as $newLocation) {
                /* @var $newLocation CoverageNodeMoveMapping */
                $node = $this->tryGetNode(fn() => $this->getState()->getByIdAndDimensionSpacePointHash(
                    $event->nodeAggregateId,
                    $newLocation->coveredDimensionSpacePoint->hash
                ));

                if (!$node) {
                    // node probably no document node, skip
                    continue;
                }

                $closure($node, $this->getNodeAddress($event->contentStreamId, $newLocation->coveredDimensionSpacePoint, $node->getNodeAggregateId()));

                $children = $this->getState()->getAllChildrenOfNode($node);
                array_map(fn($childNode) => $closure($childNode, $this->getNodeAddress($event->contentStreamId, $newLocation->coveredDimensionSpacePoint, $childNode->getNodeAggregateId())), $children);
            }
        }
    }

    private function getState(): DocumentUriPathFinder
    {
        return $this->contentRepository->projectionState(DocumentUriPathFinder::class);
    }

    private function isLiveContentStream(ContentStreamId $contentStreamId): bool
    {
        return $contentStreamId->equals($this->getState()->getLiveContentStreamId());
    }

    private function tryGetNode(\Closure $closure): ?DocumentNodeInfo
    {
        try {
            return $closure();
        } catch (NodeNotFoundException $_) {
            /** @noinspection BadExceptionsProcessingInspection */
            return null;
        }
    }

    protected function getNodeAddress(
        ContentStreamId $contentStreamId,
        DimensionSpacePoint $dimensionSpacePoint,
        NodeAggregateId $nodeAggregateId,
    ): NodeAddress {
        return new NodeAddress($contentStreamId, $dimensionSpacePoint, $nodeAggregateId, WorkspaceName::forLive());
    }

    private function getContentRepositoryId(): ContentRepositoryId
    {
        return $this->contentRepositoryRegistry->getContentRepositoryIdByContentRepository($this->contentRepository);
    }
}