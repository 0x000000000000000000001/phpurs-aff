<?php
$content = file_get_contents('test/Test/Main.purs');
$content = str_replace(
    'test_general_bracket = assert "bracket/general" do',
    'test_general_bracket = assert "bracket/general" do
  liftEffect $ Console.log "--- Start bracket/general"
  ref <- newRef ""
  let
    action s = do
      liftEffect $ Console.log ("action " <> s)
      delay (Milliseconds 10.0)
      _ <- modifyRef ref (_ <> s)
      pure s
    bracketAction s =
      generalBracket (action s)
        { killed: \error s\' -> do
            liftEffect $ Console.log ("killed " <> s\')
            void $ action (s\' <> "/kill/" <> message error)
        , failed: \error s\' -> do
            liftEffect $ Console.log ("failed " <> s\')
            void $ action (s\' <> "/throw/" <> message error)
        , completed: \r s\' -> do
            liftEffect $ Console.log ("completed " <> s\')
            void $ action (s\' <> "/release/" <> r)
        }',
    $content
);
$content = str_replace(
    '  f1 <- forkAff $ bracketAction "foo" (const (action "a"))',
    '  liftEffect $ Console.log "forking f1"
  f1 <- forkAff $ bracketAction "foo" (const (action "a"))',
    $content
);
$content = str_replace(
    '  r1 <- try $ joinFiber f1',
    '  liftEffect $ Console.log "joining f1"
  r1 <- try $ joinFiber f1
  liftEffect $ Console.log "joined f1"',
    $content
);
$content = str_replace(
    '  r2 <- try $ joinFiber f2',
    '  liftEffect $ Console.log "joining f2"
  r2 <- try $ joinFiber f2
  liftEffect $ Console.log "joined f2"',
    $content
);
$content = str_replace(
    '  r3 <- try $ joinFiber f3',
    '  liftEffect $ Console.log "joining f3"
  r3 <- try $ joinFiber f3
  liftEffect $ Console.log "joined f3"',
    $content
);
$content = str_replace(
    '  killFiber (error "z") f1',
    '  liftEffect $ Console.log "killing f1"
  killFiber (error "z") f1',
    $content
);
file_put_contents('test/Test/Main.purs', $content);
