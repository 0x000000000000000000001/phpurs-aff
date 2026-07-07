<?php

$_pure = function($x) use (&$_pure) { return function() use(&$x) { return $x; }; };
$_map = function($f, $aff = null) use (&$_map) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args, &$_map) {
            return $_map(...array_merge($__args, $more));
        };
    }
    return function() use(&$f, &$aff) { return $f($aff()); };
};
$_bind = function($aff, $f = null) use (&$_bind) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args, &$_bind) {
            return $_bind(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff, &$f) { return $f($aff())(); };
};
$_liftEffect = function($eff) use (&$_liftEffect) { return $eff; };
$_makeFiber = function($util, $aff) use (&$_makeFiber) { 
    return function() use(&$aff) { 
        $fiber = new \Fiber($aff); 
        $fiber->start(); 
        return (object)['run' => function() {}, 'join' => function($k) { return function() { return function(){}; }; }]; 
    }; 
};
$_fork = function($immediate, $aff = null) use (&$_fork) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args, &$_fork) {
            return $_fork(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff) { 
        $fiber = new \Fiber($aff); 
        \Revolt\EventLoop::queue(function() use(&$fiber) { $fiber->start(); }); 
        return (object)['run' => function() {}, 'join' => function($k){ return function(){ return function(){}; }; }]; 
    };
};
$_delay = function($right, $ms) use (&$_delay) { 
    return function() use(&$right, &$ms) { 
        $fiber = \Fiber::getCurrent(); 
        \Revolt\EventLoop::delay($ms / 1000, function() use(&$fiber, &$right) { 
            if ($fiber) $fiber->resume(); 
        }); 
        if ($fiber) \Fiber::suspend(); 
        return $right(null); 
    }; 
};
$_makeSupervisedFiber = $_makeFiber;
$_killAll = function($err, $sup, $cb) use (&$_killAll) { return function() { return function(){}; }; };

$_makeAff = function($ffiUtil, $k) use (&$_makeAff) { 
    return function() use(&$ffiUtil, &$k) { 
        $fiber = \Fiber::getCurrent(); 
        $isDone = false;
        $result = null;
        $exception = null;

        $canceler = $k(function($res) use(&$ffiUtil, &$fiber, &$isDone, &$result, &$exception) { 
            return function() use(&$ffiUtil, &$fiber, &$isDone, &$result, &$exception, &$res) { 
                $isDone = true;
                if ($ffiUtil->isLeft($res)) {
                    $exception = $ffiUtil->fromLeft($res);
                } else {
                    $result = $ffiUtil->fromRight($res);
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

$_throwError = function($err) use (&$_throwError) { return function() use(&$err) { throw $err; }; };
$_catchError = function($aff, $f = null) use (&$_catchError) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args, &$_catchError) {
            return $_catchError(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff, &$f) { try { return $aff(); } catch (\Throwable $e) { return $f($e)(); } };
};
$generalBracket = function($acq) use (&$generalBracket) { return function($cond) { return function($use) use(&$acq) { return function() use(&$acq, &$use) { $res = $acq(); return $use($res)(); }; }; }; };
$_parAffMap = $_map;

$_parAffApply = function($aff1, $aff2 = null) use (&$_parAffApply) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args, &$_parAffApply) {
            return $_parAffApply(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff1, &$aff2) { 
        $parent = \Fiber::getCurrent();
        $isDone = false; 
        $completed = 0;
        $res1 = null;
        $res2 = null;
        $error = null;

        $f1 = new \Fiber(function() use(&$aff1, &$isDone, &$completed, &$res1, &$error, $parent) {
            try {
                $res1 = $aff1();
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
            } catch (\Throwable $e) {
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

        $f2 = new \Fiber(function() use(&$aff2, &$isDone, &$completed, &$res2, &$error, $parent) {
            try {
                $res2 = $aff2();
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
            } catch (\Throwable $e) {
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

$_parAffAlt = function($aff1, $aff2 = null) use (&$_parAffAlt) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args, &$_parAffAlt) {
            return $_parAffAlt(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff1, &$aff2) { 
        $parent = \Fiber::getCurrent();
        $isDone = false;
        $result = null;
        $doneCount = 0;
        $error2 = null;

        $f1 = new \Fiber(function() use(&$aff1, &$isDone, &$result, &$doneCount, &$error2, $parent) {
            try {
                $res = $aff1();
                if (!$isDone) {
                    $isDone = true;
                    $result = $res;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function() use($parent, $result) {
                            if ($parent->isSuspended()) $parent->resume($result);
                        });
                    }
                }
            } catch (\Throwable $e) {
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

        $f2 = new \Fiber(function() use(&$aff2, &$isDone, &$result, &$doneCount, &$error2, $parent) {
            try {
                $res = $aff2();
                if (!$isDone) {
                    $isDone = true;
                    $result = $res;
                    if ($parent && $parent->isSuspended()) {
                        \Revolt\EventLoop::queue(function() use($parent, $result) {
                            if ($parent->isSuspended()) $parent->resume($result);
                        });
                    }
                }
            } catch (\Throwable $e) {
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
