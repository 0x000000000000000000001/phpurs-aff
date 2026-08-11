<?php

class PhpursAffBind {
    public $aff;
    public $f;
    public function __construct($aff, $f) {
        $this->aff = $aff;
        $this->f = $f;
    }
}

class PhpursAffMap {
    public $f;
    public $aff;
    public function __construct($f, $aff) {
        $this->f = $f;
        $this->aff = $aff;
    }
}

class PhpursAffCatch {
    public $aff;
    public $f;
    public function __construct($aff, $f) {
        $this->aff = $aff;
        $this->f = $f;
    }
}

class PhpursAffBracket {
    public $acq;
    public $cond;
    public $use;
    public function __construct($acq, $cond, $use) {
        $this->acq = $acq;
        $this->cond = $cond;
        $this->use = $use;
    }
}

class PhpursAffKillException extends \Exception {
    public $error;
    public function __construct($error) {
        $this->error = $error;
        parent::__construct("Fiber killed");
    }
}

class PhpursFiberObj {
    public $fiber;
    public $activeCanceler;
    public static $fiberMap = null;
    public $isDone = false;
    public $result = null;
    public $joiners = [];
    public $run;
    public $join;
    public $isSuspended;
    public $onComplete;
    public $kill;

    public function __construct($fiber) {
        $this->fiber = $fiber;
        $this->activeCanceler = null;
        if (self::$fiberMap === null) {
            self::$fiberMap = new \WeakMap();
        }
        if ($fiber) {
            self::$fiberMap[$fiber] = $this;
        }

        $this->run = function() use ($fiber) {
            if ($fiber && $fiber->isStarted() === false) {
                \Revolt\EventLoop::queue(function() use($fiber) { 
                    if ($fiber->isStarted() === false) {
                        $fiber->start();
                    }
                });
            }
        };
        
        $this->join = function($k) {
            return function() use($k) {
                if ($this->isDone) {
                    $cb = $k($this->result);
                    $cb();
                } else {
                    $this->joiners[] = $k;
                }
                ($this->run)();
                return function() {};
            };
        };
        
        $this->onComplete = function($cb) {
            return function() {};
        };
        
        $this->isSuspended = function() {
            return false;
        };
        
        $this->kill = function($error, $cb) {
            return function() use($error, $cb) {
                if ($this->isDone) {
                    $Right = $GLOBALS['Data_Either_Right'] ?? function($x) { return (object)['tag' => 'Right', 'value0' => $x]; };
                    $fn = $cb($Right(null));
                    $fn();
                    return function() {};
                }
                
                $this->activeCanceler = $error;
                
                // Throw kill exception if fiber is suspended
                if ($this->fiber && $this->fiber->isSuspended()) {
                    \Revolt\EventLoop::queue(function() use($error) {
                        if ($this->fiber && $this->fiber->isSuspended()) {
                            $this->fiber->throw(new PhpursAffKillException($error));
                        }
                    });
                }
                
                $Right = $GLOBALS['Data_Either_Right'] ?? function($x) { return (object)['tag' => 'Right', 'value0' => $x]; };
                $fn = $cb($Right(null));
                $fn();
                return function() {};
            };
        };
    }
    
    public function finish($either) {
        $this->isDone = true;
        $this->result = $either;
        foreach ($this->joiners as $k) {
            \Revolt\EventLoop::queue(function() use($k, $either) { 
                $cb = $k($either);
                $cb(); 
            });
        }
        $this->joiners = [];
    }

    public function kill($err, $k, $Right, $unit) {
        $cancelFiber = null;
        if ($this->fiber) {
            if ($this->fiber->isSuspended() || $this->fiber->isStarted() === false) {
                if ($this->activeCanceler !== null) {
                    $affCanceler = ($this->activeCanceler)($err);
                    
                    $cancelFiber = new \Fiber(function() use($affCanceler, $err, $k, $Right, $unit) {
                        try {
                            phpursRunAffTrampoline($affCanceler);
                        } catch (\Throwable $e) {}
                        
                        if ($this->fiber && $this->fiber->isSuspended()) {
                            $this->fiber->throw(new PhpursAffKillException($err));
                        }
                        return $k($Right($unit))();
                    });
                } else {
                    $cancelFiber = new \Fiber(function() use($err, $k, $Right, $unit) {
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

if (!\function_exists('phpursRunAffTrampoline')) {
function phpursRunAffTrampoline($aff) {
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
                $acq = $res->acq;
                $cond = $res->cond;
                $use = $res->use;
                
                try {
                    $resource = phpursRunAffTrampoline($acq);
                } catch (\Throwable $e) {
                    throw $e;
                }
                
                try {
                    $useResult = phpursRunAffTrampoline($use($resource));
                    
                    // Completed!
                    try {
                        phpursRunAffTrampoline(($cond->completed)($useResult)($resource));
                    } catch (\Throwable $e) {
                        throw $e;
                    }
                    
                    $res = $useResult;
                } catch (\Throwable $err) {
                    if ($err instanceof PhpursAffKillException) {
                        // Killed!
                        try {
                            phpursRunAffTrampoline(($cond->killed)($err->error)($resource));
                        } catch (\Throwable $e) {
                            throw $e;
                        }
                        throw $err;
                    } else {
                        // Failed!
                        try {
                            phpursRunAffTrampoline(($cond->failed)($err)($resource));
                        } catch (\Throwable $e) {
                            throw $e;
                        }
                        throw $err;
                    }
                }
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
            if ($e instanceof \FiberError) { echo "\n\n!!! FIBER ERROR INSIDE FIBER:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n"; }
            if (strpos($e->getMessage(), 'Object of class stdClass') !== false) { 
                echo "\n\n!!! GLOBAL FATAL ERROR CAUGHT IN AFF:\n" . $e->getTraceAsString() . "\n\n"; 
                \file_put_contents('/tmp/aff_caught.log', 'CAUGHT: ' . \get_class($e) . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND); 
            }
            
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

$_pure = function($x) use (&$_pure) { return function() use($x) { return $x; }; };
$_map = function($f, $aff) use (&$_map) {
    return function() use($f, $aff) { return new PhpursAffMap($f, $aff); };
};
$_bind = function($aff, $f) use (&$_bind) {
    return function() use($aff, $f) { return new PhpursAffBind($aff, $f); };
};
$_liftEffect = function($eff) use (&$_liftEffect) { return $eff; };
$_makeFiber = function($isLeft, $unsafeFromLeft, $unsafeFromRight, $Left, $Right, $aff) use (&$_makeFiber) {
    return function() use($aff, $Left, $Right) { 
        $fiber = new \Fiber(function() use ($aff, &$obj, $Left, $Right) { 
            try {
                $res = phpursRunAffTrampoline($aff);
                $obj->finish($Right($res));
            } catch (\Throwable $e) {
                if ($e instanceof PhpursAffKillException) {
                    // echo "\nFATAL ERROR IN FIBER: " . $e->getMessage() . "\n";
                    if ($e instanceof PhpursAffKillException) { $obj->finish($Left($e->error)); } else { $obj->finish($Left($e)); }
                } else {
                    // echo "\nFATAL ERROR IN FIBER: " . $e->getMessage() . "\n";
                    if ($e instanceof PhpursAffKillException) { $obj->finish($Left($e->error)); } else { $obj->finish($Left($e)); }
                }
            }
        }); 
        $obj = new PhpursFiberObj($fiber);
        $fiber->start(); 
        return $obj; 
    }; 
};
$_fork = function($immediate, $aff) use (&$_fork) {
    return function() use($aff, $immediate) { 
        $Left = $GLOBALS['Data_Either_Left'];
        $Right = $GLOBALS['Data_Either_Right'];
        $fiber = new \Fiber(function() use ($aff, &$obj, $Left, $Right) { 
            try {
                $res = phpursRunAffTrampoline($aff);
                $obj->finish($Right($res));
            } catch (\Throwable $e) {
                if ($e instanceof PhpursAffKillException) {
                    // echo "\nFATAL ERROR IN FIBER: " . $e->getMessage() . "\n";
                    if ($e instanceof PhpursAffKillException) { $obj->finish($Left($e->error)); } else { $obj->finish($Left($e)); }
                } else {
                    // echo "\nFATAL ERROR IN FIBER: " . $e->getMessage() . "\n";
                    if ($e instanceof PhpursAffKillException) { $obj->finish($Left($e->error)); } else { $obj->finish($Left($e)); }
                }
            }
        }); 
        $obj = new PhpursFiberObj($fiber);
        if ($immediate) {
            ($obj->run)();
        }
        return $obj; 
    };
};
$_delay = function($right, $ms) use (&$_delay) { 
    return function() use($right, $ms) { 
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
                if ($now - $lastYield > 5000000) { // 5ms in nanoseconds
                    $shouldYield = true;
                }
            }

            if ($shouldYield) {
                $ticks = 0;
                $lastYield = \hrtime(true);
                \Revolt\EventLoop::queue(function() use($fiber) { 
                    if ($fiber && $fiber->isSuspended()) $fiber->resume(); 
                }); 
                if ($fiber) \Fiber::suspend(); 
            }
        } else {
            \Revolt\EventLoop::delay($ms / 1000, function() use($fiber) { 
                if ($fiber && $fiber->isSuspended()) $fiber->resume(); 
            }); 
            if ($fiber) \Fiber::suspend(); 
        }
        return null; 
    }; 
};
$_makeSupervisedFiber = $_makeFiber;
$_killAll = function($err, $sup, $cb) use (&$_killAll) { return function() { return function(){}; }; };

$_makeAff = function($isLeft, $unsafeFromLeft, $unsafeFromRight, $Left, $Right, $k) use (&$_makeAff) {
    return function() use($k) { 
        $fiber = \Fiber::getCurrent(); 
        $isDone = false;
        $result;
        $exception;

        $canceler = $k(function($res) use($fiber, &$isDone, &$result, &$exception) { 
            return function() use($fiber, &$isDone, &$result, &$exception, $res) { 
                $isDone = true;
                if (is_object($res) && $res->tag === "Left") {
                    $exception = $res->value0;
                } else {
                    $result = $res->value0;
                }
                
                if ($fiber && $fiber->isSuspended()) { 
                    if ($exception !== null) {
                        \Revolt\EventLoop::queue(function() use($fiber, $exception) {
                            if ($fiber->isSuspended()) $fiber->throw($exception); 
                        });
                    } else {
                        \Revolt\EventLoop::queue(function() use($fiber, $result) {
                            if ($fiber->isSuspended()) $fiber->resume($result); 
                        });
                    }
                } 
            }; 
        })(); 
        
        if (!$isDone) {
            if ($fiber) {
                return \Fiber::suspend(); 
            } else {
                throw new \RuntimeException("makeAff used outside of a fiber");
            }
        } else {
            if ($exception !== null) throw $exception;
            return $result;
        }
    }; 
};

$_throwError = function($err) use (&$_throwError) { return function() use($err) { throw $err; }; };
$_catchError = function($aff, $f) use (&$_catchError) {
    return function() use($aff, $f) { return new PhpursAffCatch($aff, $f); };
};
$generalBracket = function($acq, $cond, $use) use (&$generalBracket) {
    return function() use($acq, $cond, $use) { return new PhpursAffBracket($acq, $cond, $use); }; 
};
$_parAffMap = $_map;

$_parAffApply = function($aff1, $aff2) use (&$_parAffApply) {
    return function() use($aff1, $aff2) { 
        $parent = \Fiber::getCurrent();
        $isDone = false; 
        $completed = 0;
        $res1;
        $res2;
        $error;

        $f1 = new \Fiber(function() use($aff1, &$isDone, &$completed, &$res1, &$error, $parent) {
            try {
                $res1 = phpursRunAffTrampoline($aff1);
                if (!$isDone) {
                    $completed++;
                    if ($completed === 2) {
                        $isDone = true;
                        if ($parent && $parent->isSuspended()) {
                            \Revolt\EventLoop::queue(function() use($parent) {
                                if ($parent->isSuspended()) $parent->resume();
                            });
                        }
                    }
                }
            } catch (\Throwable $e) { if ($e instanceof \FiberError) { echo "\n\n!!! FIBER ERROR INSIDE FIBER:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n"; }
            if (strpos($e->getMessage(), 'Object of class stdClass') !== false) { echo "\n\n!!! GLOBAL FATAL ERROR CAUGHT IN AFF:\n" . $e->getTraceAsString() . "\n\n"; } if ($e instanceof \FiberError) { echo "\n\n!!! FIBER ERROR INSIDE FIBER:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n"; }
            if (strpos($e->getMessage(), 'Object of class stdClass') !== false) { \file_put_contents('/tmp/aff_caught.log', 'CAUGHT: ' . \get_class($e) . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND); }
                if (!$isDone) {
                    $isDone = true;
                    $error = $e;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function() use($parent, $e) {
                            if ($parent->isSuspended()) $parent->throw($e);
                        });
                    }
                }
            }
        });

        $f2 = new \Fiber(function() use($aff2, &$isDone, &$completed, &$res2, &$error, $parent) {
            try {
                $res2 = phpursRunAffTrampoline($aff2);
                if (!$isDone) {
                    $completed++;
                    if ($completed === 2) {
                        $isDone = true;
                        if ($parent && $parent->isSuspended()) {
                            \Revolt\EventLoop::queue(function() use($parent) {
                                if ($parent->isSuspended()) $parent->resume();
                            });
                        }
                    }
                }
            } catch (\Throwable $e) { if ($e instanceof \FiberError) { echo "\n\n!!! FIBER ERROR INSIDE FIBER:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n"; }
            if (strpos($e->getMessage(), 'Object of class stdClass') !== false) { echo "\n\n!!! GLOBAL FATAL ERROR CAUGHT IN AFF:\n" . $e->getTraceAsString() . "\n\n"; } if ($e instanceof \FiberError) { echo "\n\n!!! FIBER ERROR INSIDE FIBER:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n"; }
            if (strpos($e->getMessage(), 'Object of class stdClass') !== false) { \file_put_contents('/tmp/aff_caught.log', 'CAUGHT: ' . \get_class($e) . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND); }
                if (!$isDone) {
                    $isDone = true;
                    $error = $e;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function() use($parent, $e) {
                            if ($parent->isSuspended()) $parent->throw($e);
                        });
                    }
                }
            }
        });

        \Revolt\EventLoop::queue(function() use($f1) { $f1->start(); });
        \Revolt\EventLoop::queue(function() use($f2) { $f2->start(); });

        if (!$isDone) {
            \Fiber::suspend();
        }
        
        if ($error !== null) throw $error;
        return $res1($res2); 
    };
};

$_sequential = function($aff) use (&$_sequential) { return $aff; };

$_parAffAlt = function($aff1, $aff2) use (&$_parAffAlt) {
    return function() use($aff1, $aff2) { 
        $parent = \Fiber::getCurrent();
        $isDone = false;
        $result;
        $doneCount = 0;
        $error2;

        $f1 = new \Fiber(function() use($aff1, &$isDone, &$result, &$doneCount, &$error2, $parent) {
            try {
                $res = phpursRunAffTrampoline($aff1);
                if (!$isDone) {
                    $isDone = true;
                    $result = $res;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function() use($parent, $result) {
                            if ($parent->isSuspended()) $parent->resume($result);
                        });
                    }
                }
            } catch (\Throwable $e) { if ($e instanceof \FiberError) { echo "\n\n!!! FIBER ERROR INSIDE FIBER:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n"; }
            if (strpos($e->getMessage(), 'Object of class stdClass') !== false) { echo "\n\n!!! GLOBAL FATAL ERROR CAUGHT IN AFF:\n" . $e->getTraceAsString() . "\n\n"; } if ($e instanceof \FiberError) { echo "\n\n!!! FIBER ERROR INSIDE FIBER:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n"; }
            if (strpos($e->getMessage(), 'Object of class stdClass') !== false) { \file_put_contents('/tmp/aff_caught.log', 'CAUGHT: ' . \get_class($e) . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND); }
                $doneCount++;
                if ($doneCount === 2 && !$isDone) {
                    $isDone = true;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function() use($parent, $error2) {
                            if ($parent->isSuspended()) $parent->throw($error2); 
                        });
                    }
                }
            }
        });

        $f2 = new \Fiber(function() use($aff2, &$isDone, &$result, &$doneCount, &$error2, $parent) {
            try {
                $res = phpursRunAffTrampoline($aff2);
                if (!$isDone) {
                    $isDone = true;
                    $result = $res;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function() use($parent, $result) {
                            if ($parent->isSuspended()) $parent->resume($result);
                        });
                    }
                }
            } catch (\Throwable $e) { if ($e instanceof \FiberError) { echo "\n\n!!! FIBER ERROR INSIDE FIBER:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n"; }
            if (strpos($e->getMessage(), 'Object of class stdClass') !== false) { echo "\n\n!!! GLOBAL FATAL ERROR CAUGHT IN AFF:\n" . $e->getTraceAsString() . "\n\n"; } if ($e instanceof \FiberError) { echo "\n\n!!! FIBER ERROR INSIDE FIBER:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n"; }
            if (strpos($e->getMessage(), 'Object of class stdClass') !== false) { \file_put_contents('/tmp/aff_caught.log', 'CAUGHT: ' . \get_class($e) . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND); }
                $error2 = $e;
                $doneCount++;
                if ($doneCount === 2 && !$isDone) {
                    $isDone = true;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function() use($parent, $error2) {
                            if ($parent->isSuspended()) $parent->throw($error2);
                        });
                    }
                }
            }
        });

        \Revolt\EventLoop::queue(function() use($f1) { $f1->start(); });
        \Revolt\EventLoop::queue(function() use($f2) { $f2->start(); });

        if (!$isDone) {
            return \Fiber::suspend();
        } else {
            if ($doneCount === 2) throw $error2;
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

