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

    public function getArguments()
    {
        return $this->args;
    }

    public function hasArgument($arg)
    {
        return isset($this->args[$arg]);
    }

    public function getArgument($arg)
    {
        return isset($this->args[$arg]) ? $this->args[$arg] : null;
    }

    public function setArgument($arg, $value) {
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
