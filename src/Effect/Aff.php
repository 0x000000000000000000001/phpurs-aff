<?php
if (!function_exists('phpurs_uncurry2')) {
function phpurs_uncurry2($fn) {
    return function($a, $b = null) use ($fn) {
        if (func_num_args() < 2) {
            $__args = func_get_args();
            return function(...$more) use ($fn, $__args) {
                return phpurs_uncurry2($fn)(...array_merge($__args, $more));
            };
        }
        return $fn($a)($b);
    };
}
function phpurs_uncurry3($fn) {
    return function($a, $b = null, $c = null) use ($fn) {
        if (func_num_args() < 3) {
            $__args = func_get_args();
            return function(...$more) use ($fn, $__args) {
                return phpurs_uncurry3($fn)(...array_merge($__args, $more));
            };
        }
        return $fn($a)($b)($c);
    };
}
function phpurs_uncurry4($fn) {
    return function($a, $b = null, $c = null, $d = null) use ($fn) {
        if (func_num_args() < 4) {
            $__args = func_get_args();
            return function(...$more) use ($fn, $__args) {
                return phpurs_uncurry4($fn)(...array_merge($__args, $more));
            };
        }
        return $fn($a)($b)($c)($d);
    };
}
function phpurs_uncurry5($fn) {
    return function($a, $b = null, $c = null, $d = null, $e = null) use ($fn) {
        if (func_num_args() < 5) {
            $__args = func_get_args();
            return function(...$more) use ($fn, $__args) {
                return phpurs_uncurry5($fn)(...array_merge($__args, $more));
            };
        }
        return $fn($a)($b)($c)($d)($e);
    };
}
}





$Effect_Aff__pure = function($x) { return function() use(&$x) { return $x; }; };
$Effect_Aff__map = phpurs_uncurry2(function($f) { return function($aff) use(&$f) { return function() use(&$f, &$aff) { return $f($aff()); }; }; });
$Effect_Aff__bind = phpurs_uncurry2(function($aff) { return function($f) use(&$aff) { return function() use(&$aff, &$f) { return $f($aff())(); }; }; });
$Effect_Aff__liftEffect = function($eff) { return $eff; };
$Effect_Aff__makeFiber = function($util, $aff) { return function() use(&$aff) { $fiber = new \Fiber($aff); $fiber->start(); return (object)['run' => function() {}, 'join' => function($k) { return function() { return function(){}; }; }]; }; };
$Effect_Aff__fork = phpurs_uncurry2(function($immediate) { return function($aff) use(&$immediate) { return function() use(&$aff) { $fiber = new \Fiber($aff); \Revolt\EventLoop::queue(function() use(&$fiber) { $fiber->start(); }); return (object)['run' => function() {}, 'join' => function($k){ return function(){ return function(){}; }; }]; }; }; });
$Effect_Aff__delay = function($right, $ms) { return function() use(&$right, &$ms) { $fiber = \Fiber::getCurrent(); \Revolt\EventLoop::delay($ms / 1000, function() use(&$fiber, &$right) { if ($fiber) $fiber->resume(); }); if ($fiber) \Fiber::suspend(); return $right(null); }; };
$Effect_Aff__makeSupervisedFiber = $Effect_Aff__makeFiber;
$Effect_Aff__killAll = function($err, $sup, $cb) { return function() { return function(){}; }; };
$Effect_Aff_makeAff = function($k) { return function() use(&$k) { return $k(function($res){ return function(){}; })(); }; };
$Effect_Aff__throwError = function($err) { return function() use(&$err) { throw $err; }; };
$Effect_Aff__catchError = phpurs_uncurry2(function($aff) { return function($f) use(&$aff) { return function() use(&$aff, &$f) { try { return $aff(); } catch (\Throwable $e) { return $f($e)(); } }; }; });
$Effect_Aff_generalBracket = function($acq) { return function($cond) { return function($use) use(&$acq) { return function() use(&$acq, &$use) { $res = $acq(); return $use($res)(); }; }; }; };
$Effect_Aff__parAffMap = $Effect_Aff__map;
$Effect_Aff__parAffApply = phpurs_uncurry2(function($aff1) { return function($aff2) use(&$aff1) { return function() use(&$aff1, &$aff2) { return $aff1()($aff2()); }; }; });
$Effect_Aff__sequential = function($aff) { return $aff; };
$Effect_Aff__parAffAlt = phpurs_uncurry2(function($aff1) { return function($aff2) use(&$aff1) { return function() use(&$aff1, &$aff2) { try { return $aff1(); } catch (\Throwable $e) { return $aff2(); } }; }; });