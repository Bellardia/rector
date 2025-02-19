<?php

declare (strict_types=1);
namespace Rector\Core\Console\Command;

use Rector\Caching\Detector\ChangedFilesDetector;
use Rector\ChangesReporting\Output\JsonOutputFormatter;
use Rector\Core\Application\ApplicationFileProcessor;
use Rector\Core\Autoloading\AdditionalAutoloader;
use Rector\Core\Configuration\ConfigInitializer;
use Rector\Core\Configuration\Option;
use Rector\Core\Console\ExitCode;
use Rector\Core\Console\Output\OutputFormatterCollector;
use Rector\Core\Contract\Console\OutputStyleInterface;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\StaticReflection\DynamicSourceLocatorDecorator;
use Rector\Core\Util\MemoryLimiter;
use Rector\Core\ValueObject\Configuration;
use Rector\Core\ValueObject\ProcessResult;
use Rector\Core\ValueObjectFactory\ProcessResultFactory;
use RectorPrefix202307\Symfony\Component\Console\Application;
use RectorPrefix202307\Symfony\Component\Console\Input\InputInterface;
use RectorPrefix202307\Symfony\Component\Console\Output\OutputInterface;
final class ProcessCommand extends \Rector\Core\Console\Command\AbstractProcessCommand
{
    /**
     * @readonly
     * @var \Rector\Core\Autoloading\AdditionalAutoloader
     */
    private $additionalAutoloader;
    /**
     * @readonly
     * @var \Rector\Caching\Detector\ChangedFilesDetector
     */
    private $changedFilesDetector;
    /**
     * @readonly
     * @var \Rector\Core\Configuration\ConfigInitializer
     */
    private $configInitializer;
    /**
     * @readonly
     * @var \Rector\Core\Application\ApplicationFileProcessor
     */
    private $applicationFileProcessor;
    /**
     * @readonly
     * @var \Rector\Core\ValueObjectFactory\ProcessResultFactory
     */
    private $processResultFactory;
    /**
     * @readonly
     * @var \Rector\Core\StaticReflection\DynamicSourceLocatorDecorator
     */
    private $dynamicSourceLocatorDecorator;
    /**
     * @readonly
     * @var \Rector\Core\Console\Output\OutputFormatterCollector
     */
    private $outputFormatterCollector;
    /**
     * @readonly
     * @var \Rector\Core\Contract\Console\OutputStyleInterface
     */
    private $rectorOutputStyle;
    /**
     * @readonly
     * @var \Rector\Core\Util\MemoryLimiter
     */
    private $memoryLimiter;
    public function __construct(AdditionalAutoloader $additionalAutoloader, ChangedFilesDetector $changedFilesDetector, ConfigInitializer $configInitializer, ApplicationFileProcessor $applicationFileProcessor, ProcessResultFactory $processResultFactory, DynamicSourceLocatorDecorator $dynamicSourceLocatorDecorator, OutputFormatterCollector $outputFormatterCollector, OutputStyleInterface $rectorOutputStyle, MemoryLimiter $memoryLimiter)
    {
        $this->additionalAutoloader = $additionalAutoloader;
        $this->changedFilesDetector = $changedFilesDetector;
        $this->configInitializer = $configInitializer;
        $this->applicationFileProcessor = $applicationFileProcessor;
        $this->processResultFactory = $processResultFactory;
        $this->dynamicSourceLocatorDecorator = $dynamicSourceLocatorDecorator;
        $this->outputFormatterCollector = $outputFormatterCollector;
        $this->rectorOutputStyle = $rectorOutputStyle;
        $this->memoryLimiter = $memoryLimiter;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName('process');
        $this->setDescription('Upgrades or refactors source code with provided rectors');
        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        // missing config? add it :)
        if (!$this->configInitializer->areSomeRectorsLoaded()) {
            $this->configInitializer->createConfig(\getcwd());
            return self::SUCCESS;
        }
        $configuration = $this->configurationFactory->createFromInput($input);
        $this->memoryLimiter->adjust($configuration);
        // disable console output in case of json output formatter
        if ($configuration->getOutputFormat() === JsonOutputFormatter::NAME) {
            $this->rectorOutputStyle->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }
        $this->additionalAutoloader->autoloadInput($input);
        $this->additionalAutoloader->autoloadPaths();
        $paths = $configuration->getPaths();
        // 1. add files and directories to static locator
        $this->dynamicSourceLocatorDecorator->addPaths($paths);
        if ($this->dynamicSourceLocatorDecorator->isPathsEmpty()) {
            $this->rectorOutputStyle->error('The given paths do not match any files');
            return ExitCode::FAILURE;
        }
        // MAIN PHASE
        // 2. run Rector
        $systemErrorsAndFileDiffs = $this->applicationFileProcessor->run($configuration, $input);
        // REPORTING PHASE
        // 3. reporting phase
        // report diffs and errors
        $outputFormat = $configuration->getOutputFormat();
        $outputFormatter = $this->outputFormatterCollector->getByName($outputFormat);
        $processResult = $this->processResultFactory->create($systemErrorsAndFileDiffs);
        $outputFormatter->report($processResult, $configuration);
        return $this->resolveReturnCode($processResult, $configuration);
    }
    protected function initialize(InputInterface $input, OutputInterface $output) : void
    {
        $application = $this->getApplication();
        if (!$application instanceof Application) {
            throw new ShouldNotHappenException();
        }
        $optionDebug = (bool) $input->getOption(Option::DEBUG);
        if ($optionDebug) {
            $application->setCatchExceptions(\false);
        }
        // clear cache
        $optionClearCache = (bool) $input->getOption(Option::CLEAR_CACHE);
        if ($optionDebug || $optionClearCache) {
            $this->changedFilesDetector->clear();
        }
    }
    /**
     * @return ExitCode::*
     */
    private function resolveReturnCode(ProcessResult $processResult, Configuration $configuration) : int
    {
        // some system errors were found → fail
        if ($processResult->getErrors() !== []) {
            return ExitCode::FAILURE;
        }
        // inverse error code for CI dry-run
        if (!$configuration->isDryRun()) {
            return ExitCode::SUCCESS;
        }
        if ($processResult->getFileDiffs() !== []) {
            return ExitCode::CHANGED_CODE;
        }
        return ExitCode::SUCCESS;
    }
}
