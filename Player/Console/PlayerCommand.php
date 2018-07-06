<?php

/*
 * This file is part of the Blackfire Player package.
 *
 * (c) Fabien Potencier <fabien@blackfire.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Blackfire\Player\Console;

use Blackfire\Player\ExpressionLanguage\ExpressionLanguage;
use Blackfire\Player\ExpressionLanguage\Provider as LanguageProvider;
use Blackfire\Player\Extension\BlackfireExtension;
use Blackfire\Player\Extension\CliFeedbackExtension;
use Blackfire\Player\Extension\TracerExtension;
use Blackfire\Player\Guzzle\Runner;
use Blackfire\Player\Parser;
use Blackfire\Player\Player;
use Blackfire\Player\Result;
use Blackfire\Player\Results;
use Blackfire\Player\ScenarioSet;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class PlayerCommand extends Command
{
    const EXIT_CODE_EXPECTATION_ERROR = 64;
    const EXIT_CODE_SCENARIO_ERROR = 65;
    const EXIT_CODE_SCENARIO_ERROR_NON_FATAL = 66;

    protected function configure()
    {
        $this
            ->setName('run')
            ->setDefinition([
                new InputArgument('file', InputArgument::REQUIRED, 'The file defining the scenarios'),
                new InputOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'The number of clients to create', 1),
                new InputOption('endpoint', '', InputOption::VALUE_REQUIRED, 'Override the scenario endpoint', null),
                new InputOption('full-report', '', InputOption::VALUE_NONE, 'Outputs execution report as JSON', null),
                new InputOption('variable', '', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Override a variable value', null),
                new InputOption('validate', '', InputOption::VALUE_NONE, 'Validate syntax without running', null),
                new InputOption('tracer', '', InputOption::VALUE_NONE, 'Store debug information on disk', null),
                new InputOption('blackfire-env', '', InputOption::VALUE_REQUIRED, 'The blackfire environment to use'),
            ])
            ->setDescription('Runs scenario files')
            ->setHelp('Read https://blackfire.io/docs/player to learn about all supported options.')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        (new CommandInitializer())($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $resultOutput = $output;
        if ($output instanceof ConsoleOutput) {
            $output = $output->getErrorOutput();
        }

        if ($input->getOption('validate')) {
            $output->writeln('<warning>The "--validate" option is deprecated. Use the "validate" command instead.</warning>');
            $output->writeln('');
        }

        $clients = [$this->createClient()];
        $concurrency = $input->getOption('concurrency');
        for ($i = 1; $i < $concurrency; ++$i) {
            $clients[] = $this->createClient();
        }

        $runner = new Runner($clients);

        $language = new ExpressionLanguage(null, [new LanguageProvider()]);
        $player = new Player($runner, $language);
        $player->addExtension(new BlackfireExtension($language, $input->getOption('blackfire-env'), $output), 510);
        $player->addExtension(new CliFeedbackExtension($output, (new Terminal())->getWidth()));
        if ($input->getOption('tracer')) {
            $player->addExtension(new TracerExtension($output));
        }

        $variables = [];
        foreach ($input->getOption('variable') as $variable) {
            list($key, $value) = explode('=', $variable, 2);
            $variables[$key] = $this->escapeValue($value);
        }

        $parser = new Parser($variables);

        if ('php://stdin' === $input->getArgument('file')) {
            $copy = fopen('php://memory', 'r+');
            stream_copy_to_stream(fopen('php://stdin', 'r'), $copy);
            $input->setArgument('file', $copy);
        }

        /** @var ScenarioSet $scenarios */
        $scenarios = $parser->load($input->getArgument('file'));

        // FIXME: should be set on the ScenarioSet directly
        // but for this, we need an enterStep() for the ScenarioSet, which we don't have yet
        foreach ($scenarios as $scenario) {
            if (null !== $input->getOption('endpoint')) {
                $scenario->endpoint($this->escapeValue($input->getOption('endpoint')));
            }

            if (null !== $input->getOption('blackfire-env') && null === $scenario->getBlackfire()) {
                $scenario->blackfire($this->escapeValue($input->getOption('blackfire-env')));
            }

            foreach ($parser->getGlobalVariables() as $key => $value) {
                // Override only if the endpoint is not already defined in the step
                if ('endpoint' === $key && null === $scenario->getEndpoint() && null === $input->getOption('endpoint')) {
                    $scenario->endpoint($value);
                }

                $scenario->set($key, $value);
            }
        }

        if ($input->getOption('validate')) {
            $resultOutput->writeln('<info>The scenarios are valid.</>');

            return;
        }

        $results = $player->run($scenarios);

        if ($input->getOption('full-report')) {
            $resultOutput->writeln($this->createReport($results));
        }

        if ($results->isFatalError()) {
            return self::EXIT_CODE_SCENARIO_ERROR;
        }

        if ($results->isExpectationError()) {
            return self::EXIT_CODE_EXPECTATION_ERROR;
        }

        if ($results->isErrored()) {
            return self::EXIT_CODE_SCENARIO_ERROR_NON_FATAL;
        }
    }

    private function createClient()
    {
        return new GuzzleClient(['cookies' => true, 'allow_redirects' => false, 'http_errors' => false]);
    }

    private function createReport(Results $results)
    {
        $report = [];

        /** @var Result $result */
        foreach ($results as $key => $result) {
            $error = $result->getError();

            $report[$key] = [
                'values' => $result->getValues()->all(),
                'error' => $error ? ['message' => $error->getMessage(), 'code' => $error->getCode()] : null,
            ];
        }

        return JsonOutput::encode($report);
    }

    private function escapeValue($value)
    {
        return sprintf("'%s'", $value);
    }
}
