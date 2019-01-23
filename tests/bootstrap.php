<?php declare(strict_types=1);

use Rx\Scheduler;

require \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR . 'autoload.php';

Scheduler::setDefaultFactory(function () {
    return new Scheduler\ImmediateScheduler();
});

Scheduler::setAsyncFactory(function () {
    return new Scheduler\ImmediateScheduler();
});
