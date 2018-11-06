<?php

namespace Instasent\ResqueBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

class StartWorkerSingleCommand extends StartWorkerCommand
{
    /**
     * Command name.
     */
    const NAME = 'instasent:resque:worker-single-start';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Start a instasent resque worker single')
            ->addOption('logging', 'l', InputOption::VALUE_OPTIONAL, 'Logging service')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'How often to check for new jobs across the queues', \Resque::DEFAULT_INTERVAL)
            ->addOption('worker', 'w', InputOption::VALUE_OPTIONAL, 'Worker class', '\Instasent\ResqueBundle\WorkerSingle')
            ->addOption('blocking', 'b', InputOption::VALUE_OPTIONAL, 'Worker blocking')
            ->addOption('foreground', 'f', InputOption::VALUE_NONE, 'Should the worker run in foreground')
            ->addOption('memory-limit', 'm', InputOption::VALUE_REQUIRED, 'Force cli memory_limit (expressed in Mbytes)', 0)
            ->addArgument('queues', InputArgument::REQUIRED, 'Queue names (separate using comma)');
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
        if ($workerClass !== '\Instasent\ResqueBundle\WorkerSingle'
            && (!class_exists($workerClass)
                || !is_subclass_of($workerClass, '\Instasent\ResqueBundle\WorkerSingle')
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
        return 'resque-single';
    }
}
