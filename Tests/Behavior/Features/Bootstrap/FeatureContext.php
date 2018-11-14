<?php

require_once(__DIR__ . '/../../../../../Neos.Behat/Tests/Behat/FlowContext.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository/Tests/Behavior/Features/Bootstrap/NodeOperationsTrait.php');
require_once(__DIR__ . '/RedirectOperationTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository/Tests/Behavior/Features/Bootstrap/NodeAuthorizationTrait.php');
require_once(__DIR__ . '/../../../../../../Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/IsolatedBehatStepsTrait.php');
require_once(__DIR__ . '/../../../../../../Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/SecurityOperationsTrait.php');

use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Service\AuthorizationService;
use Neos\ContentRepository\Tests\Behavior\Features\Bootstrap\NodeAuthorizationTrait;
use Neos\ContentRepository\Tests\Behavior\Features\Bootstrap\NodeOperationsTrait;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Tests\Behavior\Features\Bootstrap\IsolatedBehatStepsTrait;
use Neos\Flow\Tests\Behavior\Features\Bootstrap\SecurityOperationsTrait;
use Neos\RedirectHandler\NeosAdapter\Tests\Behavior\Features\Bootstrap\RedirectOperationTrait;

/**
 * Features context
 */
class FeatureContext extends \Neos\Behat\Tests\Behat\FlowContext
{
    use NodeOperationsTrait;
    use NodeAuthorizationTrait;
    use IsolatedBehatStepsTrait;
    use SecurityOperationsTrait;
    use RedirectOperationTrait;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Initializes the context
     *
     * @param array $parameters Context parameters (configured through behat.yml)
     */
    public function __construct(array $parameters)
    {
        parent::__construct($parameters);
        $this->nodeAuthorizationService = $this->objectManager->get(AuthorizationService::class);
        $this->nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        $this->setupSecurity();
    }
}
