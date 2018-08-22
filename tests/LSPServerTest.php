<?hh // strict
/*
 *  Copyright (c) 2017-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */


namespace Facebook\HHAST;

use function Facebook\HHAST\TestLib\{cli_pipe, expect, Ref};
use type Facebook\HHAST\TestLib\TestLSPMessageResponseBehavior;
use function Facebook\HHAST\__Private\LSPImpl\read_message_async;
use namespace Facebook\HHAST\__Private\{LSP, LSPImpl};
use type Facebook\CLILib\Terminal;
use namespace Facebook\TypeAssert;
use namespace HH\Lib\{Dict, Str, Tuple};

final class LSPServerTest extends TestCase {
  use LinterCLITestTrait;

  public function testImmediateExit(): void {
    list($cli, $in, $out, $err) = $this->getCLI('--mode', 'lsp');

    shape(
      'jsonrpc' => '2.0',
      'method' => 'exit',
      'params' => shape(),
    )
      |> $this->messageToRPC($$)
      |> $in->appendToBuffer($$);

    $in->close();

    $exit_code = \HH\Asio\join($cli->mainAsync());
    expect($exit_code)->toBeSame(1);
    expect($err->getBuffer())->toBeSame('');
  }

  public function testExitAfterShutdown(): void {
    list($cli, $in, $out, $err) = $this->getCLI('--mode', 'lsp');

    shape(
      'jsonrpc' => '2.0',
      'id' => __LINE__,
      'method' => 'shutdown',
      'params' => shape(),
    )
      |> $this->messageToRPC($$)
      |> $in->appendToBuffer($$);

    shape(
      'jsonrpc' => '2.0',
      'method' => 'exit',
      'params' => shape(),
    )
      |> $this->messageToRPC($$)
      |> $in->appendToBuffer($$);

    $in->close();

    $exit_code = \HH\Asio\join($cli->mainAsync());
    expect($exit_code)->toBeSame(0);
    expect($err->getBuffer())->toBeSame('');
  }

  private function messageToRPC(LSP\Message $data): string {
    $json = \json_encode($data);
    return Str\format("Content-Length: %d\r\n\r\n%s", Str\length($json), $json);
  }

  public function provideExampleExchanges(): array<array<string>> {
    return \array_map(
      function($file) {
        return [\basename($file, '.json')];
      },
      \glob(__DIR__.'/lsp/*.json'),
    );
  }

  const type TMessage = shape(
    'jsonrpc' => string,
    ?'id' => arraykey,
    ?'method' => string,
    ?'TEST_RESPONSE' => TestLSPMessageResponseBehavior,
    ...
  );
  const type TExchange = vec<this::TMessage>;

  /**
   * @dataProvider provideExampleExchanges
   */
  public function testExampleExchange(string $name): void {
    $mappings = dict[
      'HHAST_ROOT_URI' => 'file://'.\realpath(\dirname(__DIR__)),
      'HHAST_FIXTURES_URI' => 'file://'.\realpath(__DIR__.'/fixtures'),
    ];

    $messages = \file_get_contents(__DIR__.'/lsp/'.$name.'.json')
      |> Str\replace_every($$, $mappings)
      |> \json_decode(
        $$,
        /* assoc = */ true,
        /* depth = */ 512,
        \JSON_FB_HACK_ARRAYS,
      )
      |> TypeAssert\matches_type_structure(
        type_structure(self::class, 'TExchange'),
        $$,
      );

    list($inr, $inw) = cli_pipe();
    list($outr, $outw) = cli_pipe();
    list($errr, $errw) = cli_pipe();
    $cli = new __Private\LinterCLI(
      vec[__FILE__, '--mode', 'lsp'],
      new Terminal($inr, $outw, $errw),
    );

    $responses = Ref(vec[]);

    list($code, $_) = \HH\Asio\join(Tuple\from_async(
      $cli->mainAsync(),
      async {
        foreach ($messages as $message) {
          $behavior = $this->getMessageResponseBehavior($message);
          $message = \json_encode($message, \JSON_UNESCAPED_SLASHES);
          $inw->write(
            'Content-Length: '.Str\length($message)."\r\n\r\n".$message,
          );
          switch ($behavior) {
            case TestLSPMessageResponseBehavior::WAIT:
              $raw = await read_message_async($outr);
              $responses->v[] = \json_decode(
                $raw,
                /* assoc = */ true,
                /* depth = */ 512,
                \JSON_FB_HACK_ARRAYS,
              );
              break;
            case TestLSPMessageResponseBehavior::NONE:
              break;
          }
        }
      },
    ));

    $output =
      \json_encode($responses->v, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES).
        "\n"
      |> Str\replace_every($$, Dict\flip($mappings));

    expect($output)->toMatchExpectFileWithInputFile(
      __DIR__.'/lsp/'.$name.'.expect',
      __DIR__.'/lsp/'.$name.'.json',
    );
  }

  private function getMessageResponseBehavior(
    this::TMessage $msg,
  ): TestLSPMessageResponseBehavior {
    $behavior = $msg['TEST_RESPONSE'] ?? null;
    if ($behavior) {
      return $behavior;
    }

    if (($msg['id'] ?? null) === LSPImpl\InitializedNotification::class) {
      return TestLSPMessageResponseBehavior::NONE;
    }

    if (($msg['method'] ?? null) === 'exit') {
      return TestLSPMessageResponseBehavior::NONE;
    }

    return TestLSPMessageResponseBehavior::WAIT;
  }
}
