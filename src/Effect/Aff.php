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
$_makeFiber = function($util, $aff) use (&$_makeFiber) { return function() use(&$aff) { $fiber = new \Fiber($aff); $fiber->start(); return (object)['run' => function() {}, 'join' => function($k) { return function() { return function(){}; }; }]; }; };
$_fork = function($immediate, $aff = null) use (&$_fork) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args, &$_fork) {

            return $_fork(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff) { $fiber = new \Fiber($aff); \Revolt\EventLoop::queue(function() use(&$fiber) { $fiber->start(); }); return (object)['run' => function() {}, 'join' => function($k){ return function(){ return function(){}; }; }]; };
};
$_delay = function($right, $ms) use (&$_delay) { return function() use(&$right, &$ms) { $fiber = \Fiber::getCurrent(); \Revolt\EventLoop::delay($ms / 1000, function() use(&$fiber, &$right) { if ($fiber) $fiber->resume(); }); if ($fiber) \Fiber::suspend(); return $right(null); }; };
$_makeSupervisedFiber = $_makeFiber;
$_killAll = function($err, $sup, $cb) use (&$_killAll) { return function() { return function(){}; }; };
$makeAff = function($k) use (&$makeAff) { return function() use(&$k) { return $k(function($res){ return function(){}; })(); }; };
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
    return function() use(&$aff1, &$aff2) { return $aff1()($aff2()); };
};
$_sequential = function($aff) use (&$_sequential) { return $aff; };
$_parAffAlt = function($aff1, $aff2 = null) use (&$_parAffAlt) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args, &$_parAffAlt) {

            return $_parAffAlt(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff1, &$aff2) { try { return $aff1(); } catch (\Throwable $e) { return $aff2(); } };
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
$exports['makeAff'] = $makeAff;
$exports['_throwError'] = $_throwError;
$exports['_catchError'] = $_catchError;
$exports['generalBracket'] = $generalBracket;
$exports['_parAffMap'] = $_parAffMap;
$exports['_parAffApply'] = $_parAffApply;
$exports['_sequential'] = $_sequential;
$exports['_parAffAlt'] = $_parAffAlt;
return $exports;
