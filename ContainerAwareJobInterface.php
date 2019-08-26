<?php

namespace Instasent\ResqueBundle;

interface ContainerAwareJobInterface extends JobInterface
{
    public function setKernelOptions(array $kernelOptions);
}
