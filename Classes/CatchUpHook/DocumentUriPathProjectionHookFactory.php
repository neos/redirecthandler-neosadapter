<?php

namespace Neos\RedirectHandler\NeosAdapter\CatchUpHook;

use Neos\ContentRepository\Core\Projection\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Projection\CatchUpHookInterface;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;

final class DocumentUriPathProjectionHookFactory implements CatchUpHookFactoryInterface
{
    public function __construct(
        protected readonly NodeRedirectService $redirectService,
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
    ) {
    }

    public function build(ContentRepository $contentRepository): CatchUpHookInterface
    {
        return new DocumentUriPathProjectionHook(
            $contentRepository,
            $this->contentRepositoryRegistry,
            $this->redirectService
        );
    }
}
