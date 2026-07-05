<?php

$Effect_Aff__pure = function($x) { return function() use(&$x) { return $x; }; };
$Effect_Aff__map = function($f, $aff = null) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args) {
            global $Effect_Aff__map;
            return $Effect_Aff__map(...array_merge($__args, $more));
        };
    }
    return function() use(&$f, &$aff) { return $f($aff()); };
};
$Effect_Aff__bind = function($aff, $f = null) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args) {
            global $Effect_Aff__bind;
            return $Effect_Aff__bind(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff, &$f) { return $f($aff())(); };
};
$Effect_Aff__liftEffect = function($eff) { return $eff; };
$Effect_Aff__makeFiber = function($util, $aff) { return function() use(&$aff) { $fiber = new \Fiber($aff); $fiber->start(); return (object)['run' => function() {}, 'join' => function($k) { return function() { return function(){}; }; }]; }; };
$Effect_Aff__fork = function($immediate, $aff = null) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args) {
            global $Effect_Aff__fork;
            return $Effect_Aff__fork(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff) { $fiber = new \Fiber($aff); \Revolt\EventLoop::queue(function() use(&$fiber) { $fiber->start(); }); return (object)['run' => function() {}, 'join' => function($k){ return function(){ return function(){}; }; }]; };
};
$Effect_Aff__delay = function($right, $ms) { return function() use(&$right, &$ms) { $fiber = \Fiber::getCurrent(); \Revolt\EventLoop::delay($ms / 1000, function() use(&$fiber, &$right) { if ($fiber) $fiber->resume(); }); if ($fiber) \Fiber::suspend(); return $right(null); }; };
$Effect_Aff__makeSupervisedFiber = $Effect_Aff__makeFiber;
$Effect_Aff__killAll = function($err, $sup, $cb) { return function() { return function(){}; }; };
$Effect_Aff_makeAff = function($k) { return function() use(&$k) { return $k(function($res){ return function(){}; })(); }; };
$Effect_Aff__throwError = function($err) { return function() use(&$err) { throw $err; }; };
$Effect_Aff__catchError = function($aff, $f = null) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args) {
            global $Effect_Aff__catchError;
            return $Effect_Aff__catchError(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff, &$f) { try { return $aff(); } catch (\Throwable $e) { return $f($e)(); } };
};
$Effect_Aff_generalBracket = function($acq) { return function($cond) { return function($use) use(&$acq) { return function() use(&$acq, &$use) { $res = $acq(); return $use($res)(); }; }; }; };
$Effect_Aff__parAffMap = $Effect_Aff__map;
$Effect_Aff__parAffApply = function($aff1, $aff2 = null) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args) {
            global $Effect_Aff__parAffApply;
            return $Effect_Aff__parAffApply(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff1, &$aff2) { return $aff1()($aff2()); };
};
$Effect_Aff__sequential = function($aff) { return $aff; };
$Effect_Aff__parAffAlt = function($aff1, $aff2 = null) {
    if (func_num_args() < 2) {
        $__args = func_get_args();
        return function(...$more) use ($__args) {
            global $Effect_Aff__parAffAlt;
            return $Effect_Aff__parAffAlt(...array_merge($__args, $more));
        };
    }
    return function() use(&$aff1, &$aff2) { try { return $aff1(); } catch (\Throwable $e) { return $aff2(); } };
};