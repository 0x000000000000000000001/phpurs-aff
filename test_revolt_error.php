<?php
require 'vendor/autoload.php';

\Revolt\EventLoop::queue(function() {
    throw new \Exception("My Uncaught Exception");
});

\Revolt\EventLoop::run();
