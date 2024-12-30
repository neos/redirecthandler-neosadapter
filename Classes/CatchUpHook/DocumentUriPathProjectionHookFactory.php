<?php

namespace Neos\RedirectHandler\NeosAdapter\CatchUpHook;

use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryDependencies;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookInterface;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathFinder;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;

/**
 * @implements CatchUpHookFactoryInterface<DocumentUriPathFinder>
 */
final class DocumentUriPathProjectionHookFactory implements CatchUpHookFactoryInterface
{
    public function __construct(
        protected readonly NodeRedirectService $redirectService,
    ) {
    }

    public function build(CatchUpHookFactoryDependencies $dependencies): CatchUpHookInterface
    {
        return new DocumentUriPathProjectionHook(
            $dependencies->contentRepositoryId,
            $dependencies->projectionState,
            $this->redirectService
        );
    }
}
