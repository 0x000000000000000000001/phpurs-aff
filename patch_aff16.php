<?php
$content = file_get_contents('src/Effect/Aff.php');
$content = str_replace(
    '$_makeSupervisedFiber = $_makeFiber;',
    '$_makeSupervisedFiber = function($isLeft, $unsafeFromLeft, $unsafeFromRight, $Left, $Right, $aff) use (&$_makeFiber) {
    return function() use($isLeft, $unsafeFromLeft, $unsafeFromRight, $Left, $Right, $aff, &$_makeFiber) {
        $supervisor = $_makeFiber($isLeft, $unsafeFromLeft, $unsafeFromRight, $Left, $Right, $aff)();
        return (object)[
            "fiber" => $supervisor,
            "supervisor" => $supervisor
        ];
    };
};',
    $content
);
file_put_contents('src/Effect/Aff.php', $content);
