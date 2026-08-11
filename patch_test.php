<?php
$content = file_get_contents('test/Test/Main.purs');
$content = str_replace(
    'action s = do',
    "action s = do\n      liftEffect \$ Console.log (\"Action: \" <> s)",
    $content
);
$content = str_replace(
    'readRef ref',
    "liftEffect \$ Console.log \"Fork finished\"\n    readRef ref",
    $content
);
file_put_contents('test/Test/Main.purs', $content);
