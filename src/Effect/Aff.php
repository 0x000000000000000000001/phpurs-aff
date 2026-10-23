<?php

// This FFI is embedded in generated PHP modules. Keep runtime helpers in this
// file so generated modules and bundles remain self-contained.

// Instructions interpreted by the stack-safe Aff trampoline.
class PhpursAffBind
{
    public $aff;
    public $f;
    public function __construct($aff, $f)
    {
        $this->aff = $aff;
        $this->f = $f;
    }
}

class PhpursAffMap
{
    public $f;
    public $aff;
    public function __construct($f, $aff)
    {
        $this->f = $f;
        $this->aff = $aff;
    }
}

class PhpursAffCatch
{
    public $aff;
    public $f;
    public function __construct($aff, $f)
    {
        $this->aff = $aff;
        $this->f = $f;
    }
}

class PhpursAffBracket
{
    public $acq;
    public $cond;
    public $use;
    public function __construct($acq, $cond, $use)
    {
        $this->acq = $acq;
        $this->cond = $cond;
        $this->use = $use;
    }

    public function run()
    {
        $fiberId = spl_object_id(\Fiber::getCurrent());
        if (!isset(PhpursFiberObj::$masked[$fiberId])) {
            PhpursFiberObj::$masked[$fiberId] = 0;
        }
        $resource = $this->runMasked($fiberId, function () {
            return phpursRunAffTrampoline($this->acq);
        });

        try {
            // Deliver deferred kills only when leaving the outermost mask.
            if (
                PhpursFiberObj::$masked[$fiberId] === 0 &&
                isset(PhpursFiberObj::$pendingKills[$fiberId])
            ) {
                $error = PhpursFiberObj::$pendingKills[$fiberId];
                unset(PhpursFiberObj::$pendingKills[$fiberId]);
                PhpursFiberObj::markKilling(\Fiber::getCurrent());
                throw new PhpursAffKillException($error);
            }
            $result = phpursRunAffTrampoline(($this->use)($resource));
            $this->runMasked($fiberId, function () use ($result, $resource) {
                return phpursRunAffTrampoline(($this->cond->completed)($result)($resource));
            });
            return $result;
        } catch (\Throwable $error) {
            $this->runMasked($fiberId, function () use ($error, $resource) {
                $cleanup =
                    $error instanceof PhpursAffKillException
                        ? ($this->cond->killed)($error->error)($resource)
                        : ($this->cond->failed)($error)($resource);
                return phpursRunAffTrampoline($cleanup);
            });
            throw $error;
        }
    }

    private function runMasked($fiberId, $action)
    {
        PhpursFiberObj::$masked[$fiberId]++;
        try {
            return $action();
        } finally {
            PhpursFiberObj::$masked[$fiberId]--;
        }
    }
}

class PhpursAffKillException extends \Exception
{
    public $error;
    public function __construct($error)
    {
        $this->error = $error;
        parent::__construct('Fiber killed');
    }
}

class PhpursFiberObj
{
    // Runtime registries shared by managed fibers and raw parallel branches.
    public static $masked = [];
    public static $pendingKills = [];
    public static $fiberMap = null;
    public static $parChildren = null;
    public static $awaiters = [];
    public static $rawCanceler = null;
    public static $killingFibers = null;

    public $fiber;
    public $activeCanceler;
    public $isDone = false;
    public $result = null;
    public $joiners = [];
    public $run;
    public $join;
    public $isSuspended;
    public $onComplete;
    public $kill;
    public $supervisor = null;
    public $completeHandlers = [];
    public $suspended = false;

    public function __construct($fiber)
    {
        $this->fiber = $fiber;
        $this->activeCanceler = null;
        if (self::$fiberMap === null) {
            self::$fiberMap = new \WeakMap();
        }
        if ($fiber) {
            self::$fiberMap[$fiber] = $this;
        }

        $this->run = function () use ($fiber) {
            if ($fiber && $fiber->isStarted() === false) {
                \Revolt\EventLoop::queue(function () use ($fiber) {
                    if ($fiber->isStarted() === false) {
                        $fiber->start();
                    }
                });
            }
        };

        $this->join = function ($k) {
            return function () use ($k) {
                if ($this->isDone) {
                    $cb = $k($this->result);
                    $cb();
                } else {
                    $this->joiners[] = $k;
                }
                ($this->run)();
                return function () {};
            };
        };

        $this->onComplete = function ($cb) {
            return function () use ($cb) {
                $this->completeHandlers[] = $cb;
            };
        };

        $this->isSuspended = function () {
            // Mirrors purescript-aff: only a fiber blocked on makeAff counts
            // as suspended; a timer or yielded fiber is merely pending.
            return $this->suspended;
        };

        $this->kill = function ($error, $callback) {
            return function () use ($error, $callback) {
                return $this->requestKill($error, $callback);
            };
        };
    }

    private function requestKill($error, $callback)
    {
        $Right =
            $GLOBALS['Data_Either_Right'] ??
            function ($x) {
                return (object) ['tag' => 'Right', 'value0' => $x];
            };
        if ($this->isDone) {
            $fn = $callback($Right(null));
            $fn();
            return function () {};
        }
        // purescript-aff signals the kill only once the fiber completes.
        $handler = $this->onComplete;
        $effect = $handler(function ($either) use ($callback, $Right) {
            return function () use ($callback, $Right) {
                $fn = $callback($Right(null));
                $fn();
            };
        });
        if (is_callable($effect)) {
            $effect();
        }

        // A fiber killed before its first run must still observe the
        // kill: start it (it suspends or completes immediately) and
        // then deliver the exception below.
        if ($this->fiber && $this->fiber->isStarted() === false) {
            try {
                $this->fiber->start();
            } catch (\Throwable $e) {
                // The fiber wrapper records the failure in finish().
            }
        }

        // Run the pending canceler (registered by makeAff) before the
        // kill exception reaches the suspended fiber.
        $canceler = $this->activeCanceler;
        if (is_object($canceler) && isset($canceler->value0)) {
            $canceler = $canceler->value0;
        }
        $masked = $this->fiber && !empty(self::$masked[spl_object_id($this->fiber)]);
        if ($this->fiber && $this->fiber->isSuspended() && !$masked && is_callable($canceler)) {
            $this->activeCanceler = null;
            $killError = $error;
            $cancelFiber = new \Fiber(function () use ($canceler, $killError) {
                try {
                    phpursRunAffTrampoline($canceler($killError));
                } catch (\Throwable $e) {
                    // A failing canceler does not block the kill.
                }
                if (
                    $this->fiber &&
                    $this->fiber->isSuspended() &&
                    !PhpursFiberObj::isKilling($this->fiber)
                ) {
                    $this->queueKillException($killError);
                }
            });
            $cancelFiber->start();
            return function () {};
        }

        // Throw kill exception if fiber is suspended
        if ($this->fiber && $this->fiber->isSuspended()) {
            if ($masked) {
                self::$pendingKills[spl_object_id($this->fiber)] = $error;
            } else {
                $this->queueKillException($error);
            }
        }
        return function () {};
    }

    private function queueKillException($error)
    {
        self::markKilling($this->fiber);
        \Revolt\EventLoop::queue(function () use ($error) {
            if ($this->fiber && $this->fiber->isSuspended()) {
                $this->fiber->throw(new PhpursAffKillException($error));
            }
        });
    }

    public static function create($aff, $Left, $Right, $supervisor)
    {
        $fiber = new \Fiber(function () use ($aff, &$fiberObj, $Left, $Right) {
            try {
                $result = phpursRunAffTrampoline($aff);
                $fiberObj->finish($Right($result));
            } catch (\Throwable $error) {
                $failure = $error instanceof PhpursAffKillException ? $error->error : $error;
                $fiberObj->finish($Left($failure));
            }
        });
        $fiberObj = new self($fiber);
        $fiberObj->supervisor = $supervisor;
        return $fiberObj;
    }

    public function finish($either)
    {
        $this->isDone = true;
        $this->result = $either;
        foreach ($this->joiners as $k) {
            \Revolt\EventLoop::queue(function () use ($k, $either) {
                $cb = $k($either);
                $cb();
            });
        }
        $this->joiners = [];
        foreach ($this->completeHandlers as $handler) {
            $effect = $handler($either);
            if (is_callable($effect)) {
                $effect();
            }
        }
        $this->completeHandlers = [];
        if ($this->fiber !== null) {
            self::unmarkKilling($this->fiber);
        }
    }

    public static function currentSupervisor()
    {
        $current = \Fiber::getCurrent();
        if ($current === null || self::$fiberMap === null) {
            return null;
        }
        $obj = self::$fiberMap[$current] ?? null;
        return $obj ? $obj->supervisor : null;
    }

    public static function registerParChild($parent, $child)
    {
        if ($parent === null || $child === null) {
            return;
        }
        if (self::$parChildren === null) {
            self::$parChildren = new \WeakMap();
        }
        $list = self::$parChildren[$parent] ?? [];
        $list[] = $child;
        self::$parChildren[$parent] = $list;
    }

    public static function unregisterParChildren($parent)
    {
        if ($parent !== null && self::$parChildren !== null && isset(self::$parChildren[$parent])) {
            unset(self::$parChildren[$parent]);
        }
    }

    // Kills the given fibers and waits for them to terminate. Used by the
    // parallel combinators: purescript-aff never completes a parallel node
    // before its losing or failing branches have run their cancelers.
    // |
    // Revolt runs every callback in a fiber, so a waiting fiber must be
    // resumed from a queued callback; terminating branches notify us instead
    // of us polling (polling would keep the loop blocked inside the callback).
    public static function killAndWaitFibers($fibers, $error)
    {
        $all = [];
        foreach ($fibers as $fiber) {
            if (!$fiber || $fiber->isTerminated()) {
                continue;
            }
            self::killParChildrenOf($fiber, $error);
            $rawCanceler = self::takeRawCanceler($fiber);
            if ($rawCanceler !== null) {
                $cancelFiber = new \Fiber(function () use ($rawCanceler, $error) {
                    try {
                        phpursRunAffTrampoline($rawCanceler($error));
                    } catch (\Throwable $e) {
                    }
                    self::notifyTerminated(\Fiber::getCurrent());
                });
                $cancelFiber->start();
                $all[] = $cancelFiber;
            }
            if ($fiber->isSuspended() && !self::isKilling($fiber)) {
                self::markKilling($fiber);
                try {
                    $fiber->throw(new PhpursAffKillException($error));
                } catch (\Throwable $e) {
                }
            }
            $all[] = $fiber;
        }
        while (true) {
            $pending = [];
            foreach ($all as $fiber) {
                if ($fiber && !$fiber->isTerminated()) {
                    $pending[] = $fiber;
                }
            }
            if (count($pending) === 0) {
                return;
            }
            $current = \Fiber::getCurrent();
            foreach ($pending as $fiber) {
                self::$awaiters[spl_object_id($fiber)] = $current;
            }
            \Fiber::suspend();
        }
    }

    public static function killParChildrenOf($fiber, $error)
    {
        if (self::$parChildren === null || !isset(self::$parChildren[$fiber])) {
            return;
        }
        foreach (self::$parChildren[$fiber] as $child) {
            if ($child->isTerminated()) {
                continue;
            }
            self::killParChildrenOf($child, $error);
            if ($child->isSuspended() && !self::isKilling($child)) {
                self::markKilling($child);
                try {
                    $child->throw(new PhpursAffKillException($error));
                } catch (\Throwable $e) {
                }
            }
        }
    }

    public static function setRawCanceler($fiber, $canceler)
    {
        if (self::$rawCanceler === null) {
            self::$rawCanceler = new \WeakMap();
        }
        self::$rawCanceler[$fiber] = $canceler;
    }

    public static function takeRawCanceler($fiber)
    {
        if (self::$rawCanceler === null || !isset(self::$rawCanceler[$fiber])) {
            return null;
        }
        $canceler = self::$rawCanceler[$fiber];
        unset(self::$rawCanceler[$fiber]);
        return is_callable($canceler) ? $canceler : null;
    }

    public static function markKilling($fiber)
    {
        if (self::$killingFibers === null) {
            self::$killingFibers = new \WeakMap();
        }
        self::$killingFibers[$fiber] = true;
    }

    public static function isKilling($fiber)
    {
        return self::$killingFibers !== null && isset(self::$killingFibers[$fiber]);
    }

    public static function unmarkKilling($fiber)
    {
        if (self::$killingFibers !== null && isset(self::$killingFibers[$fiber])) {
            unset(self::$killingFibers[$fiber]);
        }
    }

    public static function notifyTerminated($fiber)
    {
        if ($fiber === null) {
            return;
        }
        $id = spl_object_id($fiber);
        if (!isset(self::$awaiters[$id])) {
            return;
        }
        $waiter = self::$awaiters[$id];
        unset(self::$awaiters[$id]);
        \Revolt\EventLoop::queue(function () use ($waiter) {
            if ($waiter && $waiter->isSuspended()) {
                $waiter->resume();
            }
        });
    }

    public function kill($err, $k, $Right, $unit)
    {
        $cancelFiber = null;
        if ($this->fiber) {
            if ($this->fiber->isSuspended() || $this->fiber->isStarted() === false) {
                if ($this->activeCanceler !== null) {
                    $affCanceler = ($this->activeCanceler)($err);

                    $cancelFiber = new \Fiber(function () use (
                        $affCanceler,
                        $err,
                        $k,
                        $Right,
                        $unit,
                    ) {
                        try {
                            phpursRunAffTrampoline($affCanceler);
                        } catch (\Throwable $e) {
                        }

                        if ($this->fiber && $this->fiber->isSuspended()) {
                            $this->fiber->throw(new PhpursAffKillException($err));
                        }
                        return $k($Right($unit))();
                    });
                } else {
                    $cancelFiber = new \Fiber(function () use ($err, $k, $Right, $unit) {
                        if ($this->fiber && $this->fiber->isSuspended()) {
                            $this->fiber->throw(new PhpursAffKillException($err));
                        }
                        return $k($Right($unit))();
                    });
                }
            } else {
                return $k($Right($unit))();
            }
        }

        if ($cancelFiber) {
            $cancelFiber->start();
            // Since this runs in a separate fiber, we just let it execute.
        } else {
            $k($Right($unit))();
        }
    }
}

class PhpursAffSupervisor
{
    public $fibers = [];

    public function register($fiberObj)
    {
        $id = spl_object_id($fiberObj);
        $this->fibers[$id] = $fiberObj;
        $handler = $fiberObj->onComplete;
        $effect = $handler(function ($either) use ($id) {
            return function () use ($id) {
                unset($this->fibers[$id]);
            };
        });
        if (is_callable($effect)) {
            $effect();
        }
    }

    public function isEmpty()
    {
        return count($this->fibers) === 0;
    }

    public function capture()
    {
        return array_values($this->fibers);
    }
}

if (!\function_exists('phpursRunAffTrampoline')) {
    function phpursRunAffTrampoline($aff)
    {
        $current = $aff;
        $stack = [];

        while (true) {
            try {
                if ($current instanceof \Closure) {
                    $res = $current();
                } else {
                    $res = $current;
                }

                if ($res instanceof PhpursAffBind) {
                    $stack[] = ['type' => 'bind', 'f' => $res->f];
                    $current = $res->aff;
                    continue;
                } elseif ($res instanceof PhpursAffMap) {
                    $stack[] = ['type' => 'map', 'f' => $res->f];
                    $current = $res->aff;
                    continue;
                } elseif ($res instanceof PhpursAffCatch) {
                    $stack[] = ['type' => 'catch', 'f' => $res->f];
                    $current = $res->aff;
                    continue;
                } elseif ($res instanceof PhpursAffBracket) {
                    $res = $res->run();
                }

                while (true) {
                    if (empty($stack)) {
                        return $res;
                    }

                    $frame = array_pop($stack);

                    if ($frame['type'] === 'bind') {
                        $f = $frame['f'];
                        $current = $f($res);
                        break;
                    } elseif ($frame['type'] === 'map') {
                        $f = $frame['f'];
                        $res = $f($res);
                    } elseif ($frame['type'] === 'catch') {
                        // Success value passed through
                    }
                }
            } catch (\Throwable $e) {
                if ($e instanceof PhpursAffKillException) {
                    throw $e;
                }

                $caught = false;
                while (!empty($stack)) {
                    $frame = array_pop($stack);
                    if ($frame['type'] === 'catch') {
                        $f = $frame['f'];
                        $current = $f($e);
                        $caught = true;
                        break;
                    }
                }
                if (!$caught) {
                    throw $e;
                }
            }
        }
    }
}

$_pure = function ($x) {
    return function () use ($x) {
        return $x;
    };
};
$_map = function ($f, $aff) {
    return function () use ($f, $aff) {
        return new PhpursAffMap($f, $aff);
    };
};
$_bind = function ($aff, $f) {
    return function () use ($aff, $f) {
        return new PhpursAffBind($aff, $f);
    };
};
$_liftEffect = function ($eff) {
    return $eff;
};
$_makeFiber = function ($isLeft, $unsafeFromLeft, $unsafeFromRight, $Left, $Right, $aff) {
    return function () use ($aff, $Left, $Right) {
        $fiberObj = PhpursFiberObj::create(
            $aff,
            $Left,
            $Right,
            PhpursFiberObj::currentSupervisor(),
        );
        $fiberObj->fiber->start();
        return $fiberObj;
    };
};
$_fork = function ($immediate, $aff) {
    return function () use ($aff, $immediate) {
        $fiberObj = PhpursFiberObj::create(
            $aff,
            $GLOBALS['Data_Either_Left'],
            $GLOBALS['Data_Either_Right'],
            PhpursFiberObj::currentSupervisor(),
        );
        if ($fiberObj->supervisor) {
            $fiberObj->supervisor->register($fiberObj);
        }
        if ($immediate) {
            ($fiberObj->run)();
        }
        return $fiberObj;
    };
};
$_delay = function ($right, $ms) {
    return function () use ($right, $ms) {
        $fiber = \Fiber::getCurrent();
        if ($ms <= 0.0) {
            static $ticks = 0;
            static $lastYield = 0;
            $ticks++;

            $shouldYield = false;
            if ($ticks >= 50) {
                $shouldYield = true;
            } elseif ($ticks % 10 === 0) {
                $now = \hrtime(true);
                if ($now - $lastYield > 5000000) {
                    // 5ms in nanoseconds
                    $shouldYield = true;
                }
            }

            if ($shouldYield) {
                $ticks = 0;
                $lastYield = \hrtime(true);
                \Revolt\EventLoop::queue(function () use ($fiber) {
                    if ($fiber && $fiber->isSuspended()) {
                        $fiber->resume();
                    }
                });
                if ($fiber) {
                    \Fiber::suspend();
                }
            }
        } else {
            \Revolt\EventLoop::delay($ms / 1000, function () use ($fiber) {
                if ($fiber && $fiber->isSuspended()) {
                    $fiber->resume();
                }
            });
            if ($fiber) {
                \Fiber::suspend();
            }
        }
        return null;
    };
};
$_makeSupervisedFiber = function ($isLeft, $unsafeFromLeft, $unsafeFromRight, $Left, $Right, $aff) {
    return function () use ($Left, $Right, $aff) {
        $supervisor = new PhpursAffSupervisor();
        $fiberObj = PhpursFiberObj::create($aff, $Left, $Right, $supervisor);
        $fiberObj->fiber->start();
        return (object) [
            'fiber' => $fiberObj,
            'supervisor' => $supervisor,
        ];
    };
};
$_killAll = function ($err, $sup, $cb) {
    return function () use ($err, $sup, $cb) {
        if ($sup && !$sup->isEmpty()) {
            foreach ($sup->capture() as $fiberObj) {
                $killEffect = ($fiberObj->kill)($err, function ($either) {
                    return function () {};
                });
                if (is_callable($killEffect)) {
                    $killEffect();
                }
            }
        }
        if ($cb) {
            $cb();
        }
        return function () {};
    };
};

$_makeAff = function ($isLeft, $unsafeFromLeft, $unsafeFromRight, $Left, $Right, $k) {
    return function () use ($k) {
        $fiber = \Fiber::getCurrent();
        $isDone = false;
        $result = null;
        $exception = null;

        $canceler = $k(function ($res) use ($fiber, &$isDone, &$result, &$exception) {
            return function () use ($fiber, &$isDone, &$result, &$exception, $res) {
                $isDone = true;
                $obj =
                    $fiber && PhpursFiberObj::$fiberMap !== null
                        ? PhpursFiberObj::$fiberMap[$fiber] ?? null
                        : null;
                if ($obj) {
                    $obj->activeCanceler = null;
                }
                if ($fiber && !$obj && PhpursFiberObj::$rawCanceler !== null) {
                    unset(PhpursFiberObj::$rawCanceler[$fiber]);
                }
                if (is_object($res) && $res->tag === 'Left') {
                    $exception = $res->value0;
                } else {
                    $result = $res->value0;
                }

                if ($fiber && $fiber->isSuspended()) {
                    if ($exception !== null) {
                        \Revolt\EventLoop::queue(function () use ($fiber, $exception) {
                            if ($fiber->isSuspended()) {
                                $fiber->throw($exception);
                            }
                        });
                    } else {
                        \Revolt\EventLoop::queue(function () use ($fiber, $result) {
                            if ($fiber->isSuspended()) {
                                $fiber->resume($result);
                            }
                        });
                    }
                }
            };
        })();

        if (!$isDone) {
            if ($fiber) {
                $obj = null;
                if (PhpursFiberObj::$fiberMap !== null) {
                    $obj = PhpursFiberObj::$fiberMap[$fiber] ?? null;
                    if ($obj) {
                        $obj->activeCanceler = $canceler;
                        $obj->suspended = true;
                    }
                }
                if (!$obj) {
                    PhpursFiberObj::setRawCanceler($fiber, $canceler);
                }
                $resumeValue = \Fiber::suspend();
                if ($obj) {
                    $obj->suspended = false;
                }
                return $resumeValue;
            } else {
                throw new \RuntimeException('makeAff used outside of a fiber');
            }
        } else {
            if ($exception !== null) {
                throw $exception;
            }
            return $result;
        }
    };
};

$_throwError = function ($err) {
    return function () use ($err) {
        throw $err;
    };
};
$_catchError = function ($aff, $f) {
    return function () use ($aff, $f) {
        return new PhpursAffCatch($aff, $f);
    };
};
$generalBracket = function ($acq, $cond, $use) {
    return function () use ($acq, $cond, $use) {
        return new PhpursAffBracket($acq, $cond, $use);
    };
};
$_parAffMap = $_map;

$_parAffApply = function ($aff1, $aff2) {
    return function () use ($aff1, $aff2) {
        $parent = \Fiber::getCurrent();
        $isDone = false;
        $completed = 0;
        $res1 = null;
        $res2 = null;
        $error = null;

        $f1 = new \Fiber(function () use (
            $aff1,
            &$isDone,
            &$completed,
            &$res1,
            &$error,
            $parent,
            &$f2,
        ) {
            try {
                $res1 = phpursRunAffTrampoline($aff1);
                if (!$isDone) {
                    $completed++;
                    if ($completed === 2) {
                        $isDone = true;
                        if ($parent && $parent->isSuspended()) {
                            \Revolt\EventLoop::queue(function () use ($parent) {
                                if ($parent->isSuspended()) {
                                    $parent->resume();
                                }
                            });
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (!$isDone) {
                    $isDone = true;
                    $error = $e;
                    if (!($e instanceof PhpursAffKillException)) {
                        PhpursFiberObj::killAndWaitFibers([$f2], $e);
                    }
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function () use ($parent, $e) {
                            if ($parent->isSuspended()) {
                                $parent->throw($e);
                            }
                        });
                    }
                }
            }
            PhpursFiberObj::notifyTerminated(\Fiber::getCurrent());
        });

        $f2 = new \Fiber(function () use (
            $aff2,
            &$isDone,
            &$completed,
            &$res2,
            &$error,
            $parent,
            &$f1,
        ) {
            try {
                $res2 = phpursRunAffTrampoline($aff2);
                if (!$isDone) {
                    $completed++;
                    if ($completed === 2) {
                        $isDone = true;
                        if ($parent && $parent->isSuspended()) {
                            \Revolt\EventLoop::queue(function () use ($parent) {
                                if ($parent->isSuspended()) {
                                    $parent->resume();
                                }
                            });
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (!$isDone) {
                    $isDone = true;
                    $error = $e;
                    if (!($e instanceof PhpursAffKillException)) {
                        PhpursFiberObj::killAndWaitFibers([$f1], $e);
                    }
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function () use ($parent, $e) {
                            if ($parent->isSuspended()) {
                                $parent->throw($e);
                            }
                        });
                    }
                }
            }
            PhpursFiberObj::notifyTerminated(\Fiber::getCurrent());
        });

        \Revolt\EventLoop::queue(function () use ($f1) {
            $f1->start();
        });
        \Revolt\EventLoop::queue(function () use ($f2) {
            $f2->start();
        });

        if ($parent) {
            PhpursFiberObj::registerParChild($parent, $f1);
            PhpursFiberObj::registerParChild($parent, $f2);
        }

        if (!$isDone) {
            try {
                \Fiber::suspend();
            } catch (\Throwable $e) {
                PhpursFiberObj::killAndWaitFibers(
                    [$f1, $f2],
                    $e instanceof PhpursAffKillException ? $e->error : $e,
                );
                if ($parent) {
                    PhpursFiberObj::unregisterParChildren($parent);
                }
                throw $e;
            }
        }
        if ($parent) {
            PhpursFiberObj::unregisterParChildren($parent);
        }

        if ($error !== null) {
            throw $error;
        }
        return $res1($res2);
    };
};

$_sequential = function ($aff) {
    return $aff;
};

$_parAffAlt = function ($aff1, $aff2) {
    return function () use ($aff1, $aff2) {
        $parent = \Fiber::getCurrent();
        $isDone = false;
        $result = null;
        $doneCount = 0;
        $error2 = null;

        $f1 = new \Fiber(function () use (
            $aff1,
            &$isDone,
            &$result,
            &$doneCount,
            &$error2,
            $parent,
            &$f2,
        ) {
            try {
                $res = phpursRunAffTrampoline($aff1);
                if (!$isDone) {
                    $isDone = true;
                    $result = $res;
                    PhpursFiberObj::killAndWaitFibers([$f2], new \Exception('[ParAff] Early exit'));
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function () use ($parent, $result) {
                            if ($parent->isSuspended()) {
                                $parent->resume($result);
                            }
                        });
                    }
                }
            } catch (\Throwable $e) {
                $doneCount++;
                if ($doneCount === 2 && !$isDone) {
                    $isDone = true;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function () use ($parent, $error2) {
                            if ($parent->isSuspended()) {
                                $parent->throw($error2);
                            }
                        });
                    }
                }
            }
            PhpursFiberObj::notifyTerminated(\Fiber::getCurrent());
        });

        $f2 = new \Fiber(function () use (
            $aff2,
            &$isDone,
            &$result,
            &$doneCount,
            &$error2,
            $parent,
            &$f1,
        ) {
            try {
                $res = phpursRunAffTrampoline($aff2);
                if (!$isDone) {
                    $isDone = true;
                    $result = $res;
                    PhpursFiberObj::killAndWaitFibers([$f1], new \Exception('[ParAff] Early exit'));
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function () use ($parent, $result) {
                            if ($parent->isSuspended()) {
                                $parent->resume($result);
                            }
                        });
                    }
                }
            } catch (\Throwable $e) {
                $error2 = $e;
                $doneCount++;
                if ($doneCount === 2 && !$isDone) {
                    $isDone = true;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function () use ($parent, $error2) {
                            if ($parent->isSuspended()) {
                                $parent->throw($error2);
                            }
                        });
                    }
                }
            }
            PhpursFiberObj::notifyTerminated(\Fiber::getCurrent());
        });

        \Revolt\EventLoop::queue(function () use ($f1) {
            $f1->start();
        });
        \Revolt\EventLoop::queue(function () use ($f2) {
            $f2->start();
        });

        if ($parent) {
            PhpursFiberObj::registerParChild($parent, $f1);
            PhpursFiberObj::registerParChild($parent, $f2);
        }

        if (!$isDone) {
            try {
                $value = \Fiber::suspend();
                if ($parent) {
                    PhpursFiberObj::unregisterParChildren($parent);
                }
                return $value;
            } catch (\Throwable $e) {
                PhpursFiberObj::killAndWaitFibers(
                    [$f1, $f2],
                    $e instanceof PhpursAffKillException ? $e->error : $e,
                );
                if ($parent) {
                    PhpursFiberObj::unregisterParChildren($parent);
                }
                throw $e;
            }
        } else {
            if ($parent) {
                PhpursFiberObj::unregisterParChildren($parent);
            }
            if ($doneCount === 2) {
                throw $error2;
            }
            return $result;
        }
    };
};

$exports['_pure'] = $_pure;
$exports['_map'] = $_map;
$exports['_bind'] = $_bind;
$exports['_liftEffect'] = $_liftEffect;
$exports['_makeFiber'] = $_makeFiber;
$exports['_fork'] = $_fork;
$exports['_delay'] = $_delay;
$exports['_makeSupervisedFiber'] = $_makeSupervisedFiber;
$exports['_killAll'] = $_killAll;
$exports['_makeAff'] = $_makeAff;
$exports['_throwError'] = $_throwError;
$exports['_catchError'] = $_catchError;
$exports['generalBracket'] = $generalBracket;
$exports['_parAffMap'] = $_parAffMap;
$exports['_parAffApply'] = $_parAffApply;
$exports['_sequential'] = $_sequential;
$exports['_parAffAlt'] = $_parAffAlt;
return $exports;
