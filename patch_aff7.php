<?php
$content = file_get_contents('test/Test/Main.purs');
$content = str_replace(
    '  r4 <- readRef ref
  pure (isLeft r1 && isLeft r2 && isRight r3 && r4 == "foofoo/kill/zbarbar/throw/bbazcbaz/release/c")',
    '  r4 <- readRef ref
  liftEffect $ Console.log ("r4: " <> r4)
  pure (isLeft r1 && isLeft r2 && isRight r3 && r4 == "foofoo/kill/zbarbar/throw/bbazcbaz/release/c")',
    $content
);
file_put_contents('test/Test/Main.purs', $content);
