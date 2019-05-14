/*
 *  Copyright (c) 2017-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

namespace Facebook\HHAST;

use namespace HH\Lib\{Str, Vec};

function from_json(
  dict<string, mixed> $json,
  ?string $file = null,
): EditableNode {
  $version = $json['version'] ?? null;
  if ($version is string && $version !== SCHEMA_VERSION) {
    throw new SchemaVersionError($file ?? '! no file !', $version);
  }
  return EditableNode::fromJSON(
    /* HH_IGNORE_ERROR[4110] */ $json['parse_tree'],
    $file ?? '! no file !',
    0,
    /* HH_IGNORE_ERROR[4110] */ $json['program_text'],
  );
}

async function json_from_file_async(
  string $file,
): Awaitable<dict<string, mixed>> {
  return await json_from_file_args_async($file, vec[]);
}

async function json_from_file_args_async(
  string $file,
  Traversable<string> $parse_args,
): Awaitable<dict<string, mixed>> {
  $args = Vec\concat(
    vec['--php5-compat-mode', '--full-fidelity-json'],
    vec($parse_args),
    vec[$file],
  );

  try {
    $results = await __Private\ParserQueue::get()->waitForAsync($args);
  } catch (__Private\SubprocessException $e) {
    throw new HHParseError(
      $file,
      'hh_parse failed - exit code: '.$e->getExitCode(),
    );
  }
  $json = $results[0];
  $data = \json_decode(
    $json,
    /* as array = */ true,
    /* depth = */ 512 /* == default */,
    \JSON_FB_HACK_ARRAYS,
  );
  $no_type_refinement_please = $data;
  if (!is_dict($no_type_refinement_please)) {
    // Perhaps we had invalid UTF8 - but JSON msut be UTF8
    //
    // While some can be converted to UTF-8,
    // this isn't guaranteed - JSON literally can't represent all the legal values
    // so the source it returns is useless.
    //
    // Given that, we don't even need to attempt to do the right conversion - we
    // can just do something cheap and throw away the result - so, we can just go
    // over the bytes, and throw them away if they're not 7-bit clean.

    $ascii = '';
    $len = Str\length($json);
    for ($i = 0; $i < $len; ++$i) {
      $byte = $json[$i];
      if ((\ord($byte) & (1 << 7)) === 0) {
        $ascii .= $byte;
      }
    }
    $data = \json_decode($ascii, true, 512, \JSON_FB_HACK_ARRAYS);
    $no_type_refinement_please = $data;
  }
  if (!is_dict($no_type_refinement_please)) {
    throw new HHParseError($file, 'hh_parse did not output valid JSON');
  }

  // Use the raw source rather than the re-encoded, as byte offsets may have
  // changed while re-encoding
  $data['program_text'] = \file_get_contents($file);
  return $data;
}

function json_from_file(string $file): dict<string, mixed> {
  return \HH\Asio\join(json_from_file_async($file));
}

async function from_file_async(string $file): Awaitable<EditableNode> {
  $json = await json_from_file_async($file);
  return from_json($json, $file);
}

async function from_file_args_async(
  string $file,
  Traversable<string> $parse_args,
): Awaitable<EditableNode> {
  $json = await json_from_file_args_async($file, $parse_args);
  return from_json($json, $file);
}

function from_file(string $file): EditableNode {
  return \HH\Asio\join(from_file_async($file));
}

function from_file_args(
  string $file,
  Traversable<string> $parse_args,
): EditableNode {
  return \HH\Asio\join(from_file_args_async($file, $parse_args));
}

async function json_from_text_async(
  string $text,
): Awaitable<dict<string, mixed>> {
  $file = \sys_get_temp_dir().'/hhast-tmp-'.\bin2hex(\random_bytes(16));
  if (
    Str\starts_with($text, '<?') ||
    Str\starts_with(Str\split($text, "\n")[1] ?? '', '<?')
  ) {
    $file .= '.php';
  } else {
    $file .= '.hack';
  }
  $handle = \fopen($file, "w");
  \fwrite($handle, $text);
  \fclose($handle);
  try {
    $json = await json_from_file_async($file);
  } finally {
    \unlink($file);
  }
  return $json;
}

function json_from_text(string $text): dict<string, mixed> {
  return \HH\Asio\join(json_from_text_async($text));
}

async function from_code_async(string $text): Awaitable<EditableNode> {
  $json = await json_from_text_async($text);
  return from_json($json);
}

function from_code(string $text): EditableNode {
  return \HH\Asio\join(from_code_async($text));
}
