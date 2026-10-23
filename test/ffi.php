<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
$exports = [];
$aff = require dirname(__DIR__) . '/src/Effect/Aff.php';
$Left = fn($value) => (object) ['tag' => 'Left', 'value0' => $value];
$Right = fn($value) => (object) ['tag' => 'Right', 'value0' => $value];
$GLOBALS['Data_Either_Left'] = $Left;
$GLOBALS['Data_Either_Right'] = $Right;
$checks = 0;
function same($expected, $actual)
{
    global $checks;
    if ($actual !== $expected) {
        throw new Exception(
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }
    $checks++;
}
function expectFailure($expected, $action)
{
    try {
        $action();
    } catch (Throwable $error) {
        same($expected, $error instanceof PhpursAffKillException ? $error->error : $error);
        return;
    }
    throw new Exception('Expected Aff failure');
}
function runAff($effect)
{
    $result = null;
    $failure = null;
    $fiber = new Fiber(function () use ($effect, &$result, &$failure) {
        try {
            $result = phpursRunAffTrampoline($effect);
        } catch (Throwable $error) {
            $failure = $error;
        }
    });
    $fiber->start();
    Revolt\EventLoop::run();
    if (!$fiber->isTerminated()) {
        throw new Exception('Aff stalled');
    }
    if ($failure !== null) {
        throw $failure;
    }
    return $result;
}
same(42, runAff($aff['_bind']($aff['_pure'](20), fn($x) => $aff['_pure']($x * 2 + 2))));
same(42, runAff($aff['_map'](fn($x) => $x + 1, $aff['_pure'](41))));
$error = new Exception('expected');
same(
    'expected',
    runAff(
        $aff['_catchError']($aff['_throwError']($error), fn($e) => $aff['_pure']($e->getMessage())),
    ),
);
foreach (['completed', 'failed', 'killed'] as $outcome) {
    $events = [];
    $conditions = (object) [];
    foreach (['completed', 'failed', 'killed'] as $condition) {
        $conditions->$condition = function ($value) use (&$events, $condition) {
            return function ($resource) use (&$events, $condition) {
                return function () use (&$events, $condition, $resource) {
                    $events[] = [$condition, $resource];
                };
            };
        };
    }
    $use = match ($outcome) {
        'completed' => fn($resource) => $aff['_pure']($resource + 1),
        'failed' => fn($resource) => $aff['_throwError']($error),
        'killed' => fn($resource) => $aff['_throwError'](new PhpursAffKillException($error)),
    };
    $bracket = $aff['generalBracket']($aff['_pure'](41), $conditions, $use);
    if ($outcome === 'completed') {
        same(42, runAff($bracket));
    } else {
        expectFailure($error, fn() => runAff($bracket));
    }
    same([[$outcome, 41]], $events);
}
$make = function ($effect) use ($aff, $Left, $Right) {
    return $aff['_makeFiber'](null, null, null, $Left, $Right, $effect)();
};
$made = $make($aff['_pure'](42));
same(true, $made->isDone);
same(42, $made->result->value0);
$forked = $aff['_fork'](true, $aff['_pure'](43))();
Revolt\EventLoop::run();
same(43, $forked->result->value0);
$supervised = $aff['_makeSupervisedFiber'](null, null, null, $Left, $Right, $aff['_pure'](44))();
same(44, $supervised->fiber->result->value0);
$callbackAff = $aff['_makeAff'](null, null, null, $Left, $Right, function ($callback) use ($Right) {
    return function () use ($callback, $Right) {
        Revolt\EventLoop::queue(fn() => $callback($Right(45))());
        return fn($error) => fn() => null;
    };
});
same(45, runAff($callbackAff));
$canceled = false;
$pending = $aff['_makeAff'](null, null, null, $Left, $Right, function ($callback) use (&$canceled) {
    return function () use (&$canceled) {
        return function ($error) use (&$canceled) {
            return function () use (&$canceled) {
                $canceled = true;
            };
        };
    };
});
$pendingFiber = $make($pending);
$killComplete = false;
($pendingFiber->kill)($error, function ($value) use (&$killComplete) {
    return function () use (&$killComplete) {
        $killComplete = true;
    };
})();
Revolt\EventLoop::run();
same(true, $canceled);
same(true, $killComplete);
same($error, $pendingFiber->result->value0);
same(42, runAff($aff['_parAffApply']($aff['_pure'](fn($x) => $x + 1), $aff['_pure'](41))));
same(
    41,
    runAff(
        $aff['_parAffAlt'](
            $aff['_pure'](41),
            $aff['_bind']($aff['_delay']($Right, 1), fn() => $aff['_pure'](42)),
        ),
    ),
);
expectFailure(
    $error,
    fn() => runAff($aff['_parAffApply']($aff['_throwError']($error), $aff['_pure'](41))),
);
echo "$checks Aff checks passed\n";
