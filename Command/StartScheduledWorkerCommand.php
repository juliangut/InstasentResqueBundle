<?php

namespace Instasent\ResqueBundle\Command;

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

class StartScheduledWorkerCommand extends StartWorkerCommand
{
    /**
     * Command name.
     */
    const NAME = 'instasent:resque:scheduledworker-start';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Start a instasent scheduled resque worker')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'How often to check for new jobs across the queues', \Resque::DEFAULT_INTERVAL)
            ->addOption('worker', 'w', InputOption::VALUE_OPTIONAL, 'Worker class', '\Instasent\ResqueBundle\WorkerScheduler')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force creation of a new worker if the PID file exists', false)
            ->addOption('foreground', 'f', InputOption::VALUE_NONE, 'Should the worker run in foreground', false)
            ->addOption('memory-limit', 'm', InputOption::VALUE_REQUIRED, 'Force cli memory_limit (expressed in Mbytes)', 0);
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
            $pidFile = $container->get('kernel')->getCacheDir().'/instasent_resque_scheduledworker.pid';
            if (file_exists($pidFile) && !$input->getOption('force')) {
                $ioStyle->error('PID file exists - use --force to override');
                $ioStyle->newLine();

                return 1;
            }

            if (file_exists($pidFile)) {
                unlink($pidFile);
            }

            $process->run();

            $pid = \trim($process->getOutput());
            file_put_contents($pidFile, $pid);

            if (!$input->getOption('quiet')) {
                $ioStyle->text(\sprintf(
                    '<info>Worker started</info> %s:%s:%s',
                    function_exists('gethostname') ? gethostname() : php_uname('n'),
                    $pid,
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
     * {@inheritdoc}
     */
    protected function getEnvironment(ContainerInterface $container, InputInterface $input)
    {
        $environment = $this->getBaseEnvironment($container, $input);

        $interval = $input->getOption('interval');
        if ($interval < 1) {
            throw new \Exception('Workers interval must be higher than 0');
        }
        $environment['INTERVAL'] = $interval;

        $vendorDir = $container->getParameter('instasent_resque.resque.vendor_dir');
        $environment['RESQUE_PHP'] = $vendorDir.'/chrisboulton/php-resque/lib/Resque.php';

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
        if ($workerClass !== '\Instasent\ResqueBundle\WorkerScheduler'
            && (!class_exists($workerClass)
                || !is_subclass_of($workerClass, '\Instasent\ResqueBundle\WorkerScheduler')
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
     * {@inheritdoc}
     */
    protected function getBinaryName()
    {
        return 'resque-scheduler';
    }
}
