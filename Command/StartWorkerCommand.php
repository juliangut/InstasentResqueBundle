<?php

namespace Instasent\ResqueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
            ->addArgument('queues', InputArgument::REQUIRED, 'Queue names (separate using comma)');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ioStyle = new SymfonyStyle($input, $output);

        $container = $this->getContainer();

        $count = (int) $input->getOption('count');
        if ($count < 1) {
            $ioStyle->error('Workers count must be higher than 0');
            $ioStyle->newLine();

            return 1;
        }

        $interval = $input->getOption('interval');
        if ($interval < 1) {
            $ioStyle->error('Workers interval must be higher than 0');
            $ioStyle->newLine();

            return 1;
        }

        $workerClass = $input->getOption('worker');
        if (!class_exists($workerClass) || !is_subclass_of($workerClass, '\Instasent\ResqueBundle\WorkerBase')) {
            $ioStyle->error(\sprintf('Worker class %s is not of the right kind', $workerClass));
            $ioStyle->newLine();

            return 1;
        }

        $quiet = $input->getOption('quiet');
        $verbose = $input->getOption('verbose');
        $logger = $input->getOption('logging')
            ? $container->get($input->getOption('logging'))
            : $logger = new \Resque_Log(empty($quiet) || !empty($verbose));

        $redisHost = $container->getParameter('instasent_resque.resque.redis.host');
        $redisPort = $container->getParameter('instasent_resque.resque.redis.port');
        if (!empty($redisHost) && !empty($redisPort)) {
            $redisDatabase = $container->getParameter('instasent_resque.resque.redis.database');

            \Resque::setBackend(
                $redisHost.':'.$redisPort,
                empty($redisDatabase) ? 0 : (int) $redisDatabase
            );
        }

        $prefix = $container->getParameter('instasent_resque.prefix');
        if (!empty($prefix)) {
            $ioStyle->comment(\sprintf('Prefix set to %s', $prefix));

            \Resque_Redis::prefix($prefix);
        }

        $queues = \explode(',', $input->getArgument('queues'));

        $blocking = trim($input->getOption('blocking')) !== '';

        // If set, re-attach failed jobs based on retry_strategy
        \Resque_Event::listen('onFailure', function(\Exception $exception, \Resque_Job $job) use ($ioStyle) {
            $args = $job->getArguments();

            if (empty($args['bcc_resque.retry_strategy'])) {
                return;
            }

            if (!isset($args['bcc_resque.retry_attempt'])) {
                $args['bcc_resque.retry_attempt'] = 0;
            }

            $backOff = $args['bcc_resque.retry_strategy'];
            if (!isset($backOff[$args['bcc_resque.retry_attempt']])) {
                return;
            }

            $delay = $backOff[$args['bcc_resque.retry_attempt']];
            $args['bcc_resque.retry_attempt']++;

            if ($delay === 0) {
                \Resque::enqueue($job->queue, $job->payload['class'], $args);

                $ioStyle->note(\sprintf(
                    'Job failed. Auto re-queued, attempt number: %d',
                    $args['bcc_resque.retry_attempt'] - 1
                ));
            } else {
                $at = time() + $delay;

                \ResqueScheduler::enqueueAt($at, $job->queue, $job->payload['class'], $args);

                $ioStyle->note(\sprintf(
                    'Job failed. Auto re-queued. Scheduled for: %s, attempt number: %d',
                    date('Y-m-d H:i:s', $at),
                    $args['bcc_resque.retry_attempt'] - 1
                ));
            }
        });

//        $kernelRootDir = $container->getParameter('kernel.root_dir');
//        $includeFile = '';
//        // Add compatibility with Symfony 2/3
//        if (file_exists($kernelRootDir.'/bootstrap.php.cache')) {
//            $includeFile = $kernelRootDir.'/bootstrap.php.cache';
//        } elseif (file_exists($kernelRootDir.'/../var/bootstrap.php.cache')) {
//            $includeFile = $kernelRootDir.'/../var/bootstrap.php.cache';
//        }

        if ($count > 1) {
            for ($i = 0; $i < $count; ++$i) {
                $pid = \Resque::fork();

                if ($pid === false || $pid === -1) {
                    $ioStyle->note(\sprintf('Could not fork worker %d', $i));
                    $ioStyle->newLine();
                }

                if ($pid !== 0) {
                    // Parent
                    continue;
                }

                // Child
                // If retrieved from container ensure service is NOT shared
                /* @var \Instasent\ResqueBundle\WorkerBase $worker */
                $worker = $container->has($workerClass)
                    ? $container->get($workerClass)
                    : new $workerClass(array());
                $worker->setQueues($queues);
                $worker->setLogger($logger);

                $ioStyle->comment(\sprintf('Starting worker %d', $i));
                $ioStyle->newLine();

                $worker->work($interval, $blocking);

                break;
            }

            return 0;
        }

        /* @var \Instasent\ResqueBundle\WorkerBase $worker */
        $worker = $container->has($workerClass)
            ? $container->get($workerClass)
            : new $workerClass(array());
        $worker->setQueues($queues);
        $worker->setLogger($logger);

        $ioStyle->comment('Starting worker');
        $ioStyle->newLine();

        $worker->work($interval, $blocking);

        return 0;
    }
}
