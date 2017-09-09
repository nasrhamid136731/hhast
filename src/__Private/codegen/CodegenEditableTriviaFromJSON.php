<?hh // strict
/**
 * Copyright (c) 2017, Facebook, Inc.
 * All rights reserved.
 *
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the "hack" directory of this source tree. An additional
 * grant of patent rights can be found in the PATENTS file in the same
 * directory.
 *
 */

namespace Facebook\HHAST\__Private;

use type Facebook\HackCodegen\HackBuilderValues;

final class CodegenEditableTriviaFromJSON extends CodegenBase {
  public function generate(): void {
    $cg = $this->getCodegenFactory();

    $cg
      ->codegenFile($this->getOutputDirectory().
        '/editable_trivia_from_json.php')
      ->setNamespace('Facebook\\HHAST\\__Private')
      ->useNamespace('Facebook\\HHAST')
      ->addFunction(
        $cg
          ->codegenFunction('editable_trivia_from_json')
          ->setReturnType('HHAST\\EditableTrivia')
          ->addParameter('array<string, mixed> $json')
          ->addParameter('int $position')
          ->addParameter('string $source')
          ->setBody(
            $cg
              ->codegenHackBuilder()
              ->addAssignment(
                '$trivia_text',
                'substr($source, $position, $json[\'width\'])',
                HackBuilderValues::literal(),
              )
              ->startSwitch('(string) $json[\'kind\']')
              ->addCaseBlocks(
                new Vector($this->getSchema()['trivia']),
                ($trivia, $body) ==> {
                  $body
                    ->addCase(var_export($trivia['trivia_type_name'], true))
                    ->addReturnf(
                      'new HHAST\\%s($trivia_text)',
                      $trivia['trivia_kind_name'],
                    )
                    ->unindent();
                },
              )
              ->addDefault()
              ->addLine(
                'throw new \\Exception(\'unexpected JSON kind: \'.(string) $json[\'kind\']);',
              )
              ->endDefault()
              ->endSwitch_()
              ->getCode(),
          ),
      )
      ->save();
  }
}
