<?php

namespace Instasent\ResqueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

class StartWorkerCommand extends ContainerAwareCommand
{
    /**
     * Command name.
     */
    const NAME = 'instasent:resque:worker-start';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Start a instasent resque worker')
            ->addOption('logging', 'l', InputOption::VALUE_OPTIONAL, 'Logging service')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'How many workers to fork', 1)
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'How often to check for new jobs across the queues', \Resque::DEFAULT_INTERVAL)
            ->addOption('worker', 'w', InputOption::VALUE_OPTIONAL, 'Worker class', '\Instasent\ResqueBundle\WorkerBase')
            ->addOption('blocking', 'b', InputOption::VALUE_OPTIONAL, 'Worker blocking')
            ->addOption('foreground', 'f', InputOption::VALUE_NONE, 'Should the worker run in foreground')
            ->addOption('memory-limit', 'm', InputOption::VALUE_REQUIRED, 'Force cli memory_limit (expressed in Mbytes)', 0)
            ->addArgument('queues', InputArgument::REQUIRED, 'Queue names (separate using comma)');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ioStyle = new SymfonyStyle($input, $output);

        $container = $this->getContainer();

        try {
            $environment = $this->getEnvironment($container, $input);

            // In windows: When you pass an environment to CMD it replaces the old environment
            // That means we create a lot of problems with respect to user accounts and missing vars
            // this is a workaround where we add the vars to the existing environment.
            if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                foreach ($environment as $var => $value) {
                    putenv($var.'='.$value);
                }

                $environment = null;
            }
        } catch (\Exception $exception) {
            $ioStyle->error($exception->getMessage());
            $ioStyle->newLine();

            return 1;
        }

        $process = new Process($this->getCommand($container, $input), null, $environment, null, null);

        if (!$input->getOption('quiet')) {
            $ioStyle->note(\sprintf('Starting worker %s', $process->getCommandLine()));
            $ioStyle->newLine();
        }

        if (!$input->getOption('foreground')) {
            $process->run();

            if (!$input->getOption('quiet')) {
                $ioStyle->text(\sprintf(
                    '<info>Worker started</info> %s:%s:%s',
                    function_exists('gethostname') ? gethostname() : php_uname('n'),
                    \trim($process->getOutput()),
                    $input->getArgument('queues')
                ));
            }

            return 0;
        }

        $process->run(function ($type, $buffer) use ($ioStyle) {
            $ioStyle->text($buffer);
        });

        $ioStyle->newLine();

        return 0;
    }

    /**
     * Get environment data.
     *
     * @param ContainerInterface $container
     * @param InputInterface     $input
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function getEnvironment(ContainerInterface $container, InputInterface $input)
    {
        $environment = $this->getBaseEnvironment($container, $input);

        $count = (int) $input->getOption('count');
        if ($count < 1) {
            throw new \Exception('Workers count must be higher than 0');
        }
        $environment['COUNT'] = $count;

        $interval = $input->getOption('interval');
        if ($interval < 1) {
            throw new \Exception('Workers interval must be higher than 0');
        }
        $environment['INTERVAL'] = $interval;

        $prefix = $container->getParameter('instasent_resque.prefix');
        if (!empty($prefix)) {
            $environment['PREFIX'] = $prefix;
        }

        $redisHost = $container->getParameter('instasent_resque.resque.redis.host');
        $redisPort = $container->getParameter('instasent_resque.resque.redis.port');
        if (!empty($redisHost) && !empty($redisPort)) {
            $environment['REDIS_BACKEND'] = $redisHost.':'.$redisPort;

            $redisDatabase = $container->getParameter('instasent_resque.resque.redis.database');
            if (!empty($redisDatabase)) {
                $environment['REDIS_BACKEND_DB'] = $redisDatabase;
            }
        }

        $logger = $input->getOption('logging');
        if ($logger) {
            if (!$container->has($logger)) {
                throw new \Exception(\sprintf('Logger %s cannot be found', $logger));
            }

            $environment['LOG_CHANNEL'] = $logger;
        }

        $workerClass = $input->getOption('worker');
        if ($workerClass !== '\Instasent\ResqueBundle\WorkerBase'
            && (!class_exists($workerClass)
                || !is_subclass_of($workerClass, '\Instasent\ResqueBundle\WorkerBase')
            )
        ) {
            throw new \Exception(\sprintf('Worker class %s is not of the right kind', $workerClass));
        }
        $environment['WORKER_CLASS'] = $workerClass;

        $blocking = trim($input->getOption('blocking')) !== '';
        if ($blocking) {
            $environment['BLOCKING'] = 1;
        }

        $environment['QUEUE'] = $input->getArgument('queues');

        return $environment;
    }

    /**
     * Get basic environment data.
     *
     * @param ContainerInterface $container
     * @param InputInterface     $input
     *
     * @return mixed
     */
    final protected function getBaseEnvironment(ContainerInterface $container, InputInterface $input)
    {
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            // here to work around issues with pcntl and cli_set_process_title in PHP > 5.5
            $environment = $_SERVER;

            unset(
                $environment['_'],
                $environment['PHP_SELF'],
                $environment['SCRIPT_NAME'],
                $environment['SCRIPT_FILENAME'],
                $environment['PATH_TRANSLATED'],
                $environment['argv']
            );
        } else {
            $environment = array();
        }

        if (!$input->getOption('quiet')) {
            $environment['VERBOSE'] = 1;
        }

        if ($input->getOption('verbose')) {
            $environment['VVERBOSE'] = 1;
        }

        $environment['SYMFONY_ENV'] = $container->getParameter('kernel.environment');

        $kernelDir = $container->getParameter('kernel.root_dir');
        $cacheFiles = array(
            $kernelDir.'/bootstrap.php.cache',
            $kernelDir.'/../var/bootstrap.php.cache',
        );
        foreach ($cacheFiles as $cacheFile) {
            if (file_exists($cacheFile)) {
                $environment['APP_INCLUDE'] = $cacheFile;
            }
        }

        return $environment;
    }

    /**
     * Get executing command.
     *
     * @param ContainerInterface $container
     * @param InputInterface     $input
     *
     * @return string
     */
    final protected function getCommand(ContainerInterface $container, InputInterface $input)
    {
        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            $php = PHP_BINARY;
        } elseif (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $php = 'php';
        } else {
            $php = PHP_BINDIR.'/php';
        }

        $options = array();

        $memoryLimit = (int) $input->getOption('memory-limit');
        if ($memoryLimit !== 0) {
            $options[] = sprintf('-d memory_limit=%dM', $memoryLimit);
        }

        $binaryName = $this->getBinaryName();

        $command = \sprintf(
            '%s %s %s',
            $php,
            \implode(' ', $options),
            __DIR__.'/../bin/'.$binaryName
        );

        if (!$input->getOption('foreground')) {
            $command = \sprintf(
                'nohup %s > %s/%s.log 2>&1 & echo $!',
                $command,
                $container->get('kernel.logs_dir'),
                $binaryName
            );
        }

        return $command;
    }

    /**
     * Get command binary name.
     *
     * @return string
     */
    protected function getBinaryName()
    {
        return 'resque';
    }
}
