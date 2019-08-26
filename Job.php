<?php

namespace Instasent\ResqueBundle;

abstract class Job implements JobInterface
{
    /**
     * @var \Resque_Job
     */
    public $job;

    /**
     * @var string The queue name
     */
    public $queue = 'default';

    /**
     * @var array The job args
     */
    public $args = array();

    public function getName()
    {
        return \get_class($this);
    }

    public function getQueue()
    {
        return $this->queue;
    }

    public function getArgs()
    {
        return $this->args;
    }

    public function hasArg($arg)
    {
        return isset($this->args[$arg]);
    }

    public function getArg($arg)
    {
        return isset($this->args[$arg]) ? $this->args[$arg] : null;
    }

    public function setArg($arg, $value) {
        $this->args[$arg] = $value;
    }

    public function perform()
    {
        $this->run($this->args);
    }

    abstract public function run($args);

    public function setUp()
    {
        // noop
    }

    public function tearDown()
    {
        // noop
    }
}
