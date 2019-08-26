<?php

namespace Instasent\ResqueBundle;

interface JobInterface
{
    public function getName();

    public function getQueue();

    public function getArgs();

    public function hasArg($arg);

    public function getArg($arg);

    public function setArg($arg, $value);

    public function perform();

    public function setUp();

    public function tearDown();
}
