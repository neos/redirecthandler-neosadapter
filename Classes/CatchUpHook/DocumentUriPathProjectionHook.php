<?php

namespace Neos\RedirectHandler\NeosAdapter\CatchUpHook;

use Neos\ContentRepository\Core\Projection\CatchUpHookInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\EventStore\Model\EventEnvelope;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeMove\Event\NodeAggregateWasMoved;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathFinder;
use Neos\Neos\FrontendRouting\Projection\DocumentNodeInfo;
use Neos\Neos\FrontendRouting\Exception\NodeNotFoundException;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;

final class DocumentUriPathProjectionHook implements CatchUpHookInterface
{
    /**
     * Runtime cache to keep DocumentNodeInfos until they get removed.
     * @var array<string, array<DocumentNodeInfo>>
     */
    private array $documentNodeInfosBeforeRemoval;

    public function __construct(
        private readonly ContentRepository $contentRepository,
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
            $node = $this->findNodeByIdAndDimensionSpacePointHash($event->nodeAggregateId, $dimensionSpacePoint->hash);
            if ($node === null) {
                // Probably not a document node
                continue;
            }

            $this->nodeRedirectService->appendAffectedNode(
                $node,
                NodeAddress::create($this->contentRepository->id, $event->workspaceName, $dimensionSpacePoint, $node->getNodeAggregateId())
            );
            $this->documentNodeInfosBeforeRemoval[$dimensionSpacePoint->hash][] = $node;

            $descendantsOfNode = $this->getState()->getDescendantsOfNode($node);
            array_map(
                function ($descendantOfNode) use ($event, $dimensionSpacePoint) {
                    $this->nodeRedirectService->appendAffectedNode(
                        $descendantOfNode,
                        NodeAddress::create($this->contentRepository->id, $event->workspaceName, $dimensionSpacePoint, $descendantOfNode->getNodeAggregateId())
                    );
                    $this->documentNodeInfosBeforeRemoval[$dimensionSpacePoint->hash][] = $descendantOfNode;
                },
                iterator_to_array($descendantsOfNode)
            );
        }
    }

    private function onAfterNodeAggregateWasRemoved(NodeAggregateWasRemoved $event): void
    {
        if (!$this->isLiveContentStream($event->contentStreamId)) {
            return;
        }

        foreach ($event->affectedCoveredDimensionSpacePoints as $dimensionSpacePoint) {
            if (!array_key_exists($dimensionSpacePoint->hash, $this->documentNodeInfosBeforeRemoval)) {
                continue;
            }
            $documentNodeInfosBeforeRemoval = $this->documentNodeInfosBeforeRemoval[$dimensionSpacePoint->hash];
            unset($this->documentNodeInfosBeforeRemoval[$dimensionSpacePoint->hash]);

            array_map(
                fn (DocumentNodeInfo $node) => $this->nodeRedirectService->createRedirectForRemovedAffectedNode(
                    $node,
                    $this->contentRepository->id
                ),
                $documentNodeInfosBeforeRemoval
            );
        }
    }

    private function onBeforeNodePropertiesWereSet(NodePropertiesWereSet $event): void
    {
        $this->handleNodePropertiesWereSet(
            $event,
            $this->nodeRedirectService->appendAffectedNode(...)
        );
    }

    private function onAfterNodePropertiesWereSet(NodePropertiesWereSet $event): void
    {
        $this->handleNodePropertiesWereSet(
            $event,
            $this->nodeRedirectService->createRedirectForAffectedNode(...)
        );
    }

    /**
     * @param \Closure(DocumentNodeInfo $nodeInfo, NodeAddress $nodeAddress):void $closure
     */
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
            $node = $this->findNodeByIdAndDimensionSpacePointHash($event->nodeAggregateId, $affectedDimensionSpacePoint->hash);
            if ($node === null) {
                // probably not a document node
                continue;
            }

            $closure($node,  NodeAddress::create($this->contentRepository->id, $event->workspaceName, $affectedDimensionSpacePoint, $node->getNodeAggregateId()));

            $descendantsOfNode = $this->getState()->getDescendantsOfNode($node);
            array_map(fn (DocumentNodeInfo $descendantOfNode) => $closure(
                $descendantOfNode,
                NodeAddress::create($this->contentRepository->id, $event->workspaceName, $affectedDimensionSpacePoint, $descendantOfNode->getNodeAggregateId())
            ), iterator_to_array($descendantsOfNode));
        }
    }

    private function onBeforeNodeAggregateWasMoved(NodeAggregateWasMoved $event): void
    {
        $this->handleNodeWasMoved(
            $event,
            $this->nodeRedirectService->appendAffectedNode(...)
        );
    }

    private function onAfterNodeAggregateWasMoved(NodeAggregateWasMoved $event): void
    {
        $this->handleNodeWasMoved(
            $event,
            $this->nodeRedirectService->createRedirectForAffectedNode(...)
        );
    }

    /**
     * @param \Closure(DocumentNodeInfo $nodeInfo, NodeAddress $nodeAddress):void $closure
     */
    private function handleNodeWasMoved(NodeAggregateWasMoved $event, \Closure $closure): void
    {
        if (!$this->isLiveContentStream($event->contentStreamId)) {
            return;
        }

        foreach ($event->succeedingSiblingsForCoverage as $interdimensionalSibling) {
            $node = $this->findNodeByIdAndDimensionSpacePointHash($event->nodeAggregateId, $interdimensionalSibling->dimensionSpacePoint->hash);
            if ($node === null) {
                // node probably no document node, skip
                continue;
            }

            $closure($node, NodeAddress::create($this->contentRepository->id, $event->workspaceName, $interdimensionalSibling->dimensionSpacePoint, $node->getNodeAggregateId()));

            $descendantsOfNode = $this->getState()->getDescendantsOfNode($node);
            array_map(fn (DocumentNodeInfo $descendantOfNode) => $closure(
                $descendantOfNode,
                NodeAddress::create($this->contentRepository->id, $event->workspaceName, $interdimensionalSibling->dimensionSpacePoint, $descendantOfNode->getNodeAggregateId())
            ), iterator_to_array($descendantsOfNode));
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

    private function findNodeByIdAndDimensionSpacePointHash(NodeAggregateId $nodeAggregateId, string $dimensionSpacePointHash): ?DocumentNodeInfo
    {
        try {
            return $this->getState()->getByIdAndDimensionSpacePointHash($nodeAggregateId, $dimensionSpacePointHash);
        } catch (NodeNotFoundException $_) {
            return null;
        }
    }
}
