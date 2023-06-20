<?php

use Behat\MinkExtension\Context\MinkContext;
use Neos\Behat\Tests\Behat\FlowContextTrait;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Repository\ContentHypergraph;
use Neos\ContentRepository\Core\Factory\ContentRepositoryId;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Tests\Behavior\Features\Bootstrap\Helpers\ContentRepositoryInternalsFactory;
use Neos\ContentRepository\Core\Tests\Behavior\Features\Bootstrap\Helpers\FakeClockFactory;
use Neos\ContentRepository\Core\Tests\Behavior\Features\Bootstrap\Helpers\FakeUserIdProviderFactory;
use Neos\ContentRepository\Core\Tests\Behavior\Features\Bootstrap\NodeOperationsTrait;
use Neos\ContentRepository\Core\Tests\Behavior\Features\Helper\ContentGraphs;
use Neos\ContentRepository\Security\Service\AuthorizationService;
use Neos\ContentRepository\Core\Tests\Behavior\Features\Bootstrap\EventSourcedTrait;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Tests\Behavior\Features\Bootstrap\IsolatedBehatStepsTrait;
use Neos\Flow\Tests\Behavior\Features\Bootstrap\SecurityOperationsTrait;
use Neos\Flow\Utility\Environment;
use Neos\Neos\Tests\Functional\Command\BehatTestHelper;
use Neos\RedirectHandler\NeosAdapter\Tests\Behavior\Features\Bootstrap\RedirectOperationTrait;
use Neos\ContentRepositoryRegistry\Factory\ProjectionCatchUpTrigger\CatchUpTriggerWithSynchronousOption;

require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/CurrentSubgraphTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/CurrentUserTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/CurrentDateTimeTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/GenericCommandExecutionAndEventPublication.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/ProjectedNodeAggregateTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/ProjectedNodeTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/MigrationsTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/NodeOperationsTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Security/Tests/Behavior/Features/Bootstrap/NodeAuthorizationTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentGraph.DoctrineDbalAdapter/Tests/Behavior/Features/Bootstrap/ProjectionIntegrityViolationDetectionTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/StructureAdjustmentsTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.Neos/Tests/Behavior/Features/Bootstrap/RoutingTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.Neos/Tests/Behavior/Features/Bootstrap/BrowserTrait.php');
require_once(__DIR__ . '/RedirectOperationTrait.php');

require_once(__DIR__ . '/../../../../../../Application/Neos.Behat/Tests/Behat/FlowContextTrait.php');
require_once(__DIR__ . '/../../../../../../Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/IsolatedBehatStepsTrait.php');
require_once(__DIR__ . '/../../../../../../Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/SecurityOperationsTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/NodeOperationsTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/NodeTraversalTrait.php');
require_once(__DIR__ . '/../../../../../../Neos/Neos.ContentRepository.Core/Tests/Behavior/Features/Bootstrap/EventSourcedTrait.php');


/**
 * Features context
 */
class FeatureContext extends MinkContext
{
    use FlowContextTrait;
    use SecurityOperationsTrait;
    use IsolatedBehatStepsTrait;
    use NodeOperationsTrait;

    use EventSourcedTrait;
    use RoutingTrait;
    use BrowserTrait;

    use RedirectOperationTrait;

    /**
     * @var string
     */
    protected $behatTestHelperObjectName = BehatTestHelper::class;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var Environment
     */
    protected $environment;

    public function __construct()
    {
        if (self::$bootstrap === null) {
            self::$bootstrap = $this->initializeFlow();
        }
        $this->objectManager = self::$bootstrap->getObjectManager();
        $this->environment = $this->objectManager->get(Environment::class);

        $this->nodeAuthorizationService = $this->objectManager->get(AuthorizationService::class);
        $this->setupSecurity();

        CatchUpTriggerWithSynchronousOption::enableSynchonityForSpeedingUpTesting();

        $this->setupEventSourcedTrait(true);
    }

    protected function getContentRepositoryRegistry(): ContentRepositoryRegistry
    {
        /** @var ContentRepositoryRegistry $contentRepositoryRegistry */
        $contentRepositoryRegistry = $this->objectManager->get(ContentRepositoryRegistry::class);

        return $contentRepositoryRegistry;
    }

    protected function getContentRepositoryService(ContentRepositoryId $contentRepositoryId, ContentRepositoryServiceFactoryInterface $factory): ContentRepositoryServiceInterface
    {
        return $this->getContentRepositoryRegistry()->getService($contentRepositoryId, $factory);
    }

    /**
     * @param array<string> $adapterKeys "DoctrineDBAL" if
     * @return void
     */
    protected function initCleanContentRepository(array $adapterKeys): void
    {
        $this->logToRaceConditionTracker(['msg' => 'initCleanContentRepository']);

        $configurationManager = $this->getObjectManager()->get(ConfigurationManager::class);
        $registrySettings = $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Neos.ContentRepositoryRegistry'
        );

        if (!in_array('Postgres', $adapterKeys)) {
            // in case we do not have tests annotated with @adapters=Postgres, we
            // REMOVE the Postgres projection from the Registry settings. This way, we won't trigger
            // Postgres projection catchup for tests which are not yet postgres-aware.
            //
            // This is to make the testcases more stable and deterministic. We can remove this workaround
            // once the Postgres adapter is fully ready.
            unset($registrySettings['presets'][$this->contentRepositoryId->value]['projections']['Neos.ContentGraph.PostgreSQLAdapter:Hypergraph']);
        }
        $registrySettings['presets'][$this->contentRepositoryId->value]['userIdProvider']['factoryObjectName'] = FakeUserIdProviderFactory::class;
        $registrySettings['presets'][$this->contentRepositoryId->value]['clock']['factoryObjectName'] = FakeClockFactory::class;

        $this->contentRepositoryRegistry = new ContentRepositoryRegistry(
            $registrySettings,
            $this->getObjectManager()
        );


        $this->contentRepository = $this->contentRepositoryRegistry->get($this->contentRepositoryId);
        // Big performance optimization: only run the setup once - DRAMATICALLY reduces test time
        if ($this->alwaysRunContentRepositorySetup || !self::$wasContentRepositorySetupCalled) {
            $this->contentRepository->setUp();
            self::$wasContentRepositorySetupCalled = true;
        }
        $this->contentRepositoryInternals = $this->contentRepositoryRegistry->getService($this->contentRepositoryId, new ContentRepositoryInternalsFactory());

        $availableContentGraphs = [];
        $availableContentGraphs['DoctrineDBAL'] = $this->contentRepository->getContentGraph();
        // NOTE: to disable a content graph (do not run the tests for it), you can use "null" as value.
        if (in_array('Postgres', $adapterKeys)) {
            $availableContentGraphs['Postgres'] = $this->contentRepository->projectionState(ContentHypergraph::class);
        }

        if (count($availableContentGraphs) === 0) {
            throw new \RuntimeException('No content graph active during testing. Please set one in settings in activeContentGraphs');
        }
        $this->availableContentGraphs = new ContentGraphs($availableContentGraphs);
    }
}
