<?hh // strict
/**
 * Copyright (c) 2016, Facebook, Inc.
 * All rights reserved.
 *
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree. An additional
 * grant of patent rights can be found in the PATENTS file in the same
 * directory.
 *
 */

namespace Facebook\HHAST\Linters;

use type Facebook\HHAST\{
  ConstructToken,
  DestructToken,
  EditableList,
  EditableNode,
  EditableToken,
  EndOfLine,
  FunctionDeclaration,
  IFunctionishDeclaration,
  MethodishDeclaration,
  NameToken,
  StaticToken
};
use namespace Facebook\HHAST;
use namespace HH\Lib\{C, Str, Vec};

trait FunctionNamingLinterTrait {
  require extends ASTLinter<IFunctionishDeclaration>;

  abstract public function getSuggestedNameForFunction(
    string $name,
    FunctionDeclaration $fun,
  ): string;

  abstract public function getSuggestedNameForInstanceMethod(
    string $name,
    MethodishDeclaration $meth,
  ): string;

  abstract public function getSuggestedNameForStaticMethod(
    string $name,
    MethodishDeclaration $meth,
  ): string;

  protected function getErrorDescription(
    string $what,
    string $name,
    string $suggestion,
  ): string {
    return sprintf(
      '%s "%s" does not match conventions; consider renaming to "%s"',
      $what,
      $name,
      $suggestion,
    );
  }

  <<__Override>>
  final protected static function getTargetType(
  ): classname<IFunctionishDeclaration> {
    return IFunctionishDeclaration::class;
  }

  private function getCurrentNameNodeForFunctionOrMethod(
    EditableNode $node,
  ): ?EditableToken {
    if ($node instanceof FunctionDeclaration) {
      return $node->getDeclarationHeader()->getName();
    }

    if ($node instanceof MethodishDeclaration) {
      return $node->getFunctionDeclHeader()->getName();
    }

    return null;
  }

  <<__Override>>
  final public function getLintErrorForNode(
    IFunctionishDeclaration $node,
    vec<EditableNode> $_parents,
  ): ?ASTLintError<IFunctionishDeclaration, this> {
    $token = $this->getCurrentNameNodeForFunctionOrMethod($node);
    if ($token === null) {
      return null;
    }
    if ($token instanceof ConstructToken || $token instanceof DestructToken) {
      return null;
    }
    $old = $token->getText();
    if (Str\starts_with($old, '__')) {
      return null;
    }

    $what = null;
    if ($node instanceof FunctionDeclaration) {
      $what = 'Function';
      $new = $this->getSuggestedNameForFunction($old, $node);
    } else if ($node instanceof MethodishDeclaration) {
      if (
        $node->getModifiersUNTYPED()->getDescendantsOfType(
          StaticToken::class,
        ) |> C\is_empty(vec($$))
      ) {
        $what = 'Method';
        $new = $this->getSuggestedNameForInstanceMethod($old, $node);
      } else {
        $what = 'Static method';
        $new = $this->getSuggestedNameForStaticMethod($old, $node);
      }
    } else {
      invariant_violation(
        "Can't handle type %s",
        get_class($node),
      );
    }
    if ($old === $new) {
      return null;
    }
    return new ASTLintError(
      $this,
      $this->getErrorDescription($what, $old, $new),
      $node,
    );
  }

  <<__Override>>
  public function getPrettyTextForNode(
    IFunctionishDeclaration $node,
    ?EditableNode $_context,
  ): string {
    if ($node instanceof FunctionDeclaration) {
      $node = $node->withBody(HHAST\Missing());
    } else if ($node instanceof MethodishDeclaration) {
      $node = $node->withFunctionBody(HHAST\Missing());
    } else {
      invariant_violation(
        'unhandled type: %s',
        get_class($node),
      );
    }
    $leading = $node->getFirstTokenx()->getLeading();
    if ($leading instanceof EditableList) {
      $new = vec[];
      foreach (Vec\reverse($leading->toVec()) as $child) {
        $new[] = $child;
        if ($child instanceof EndOfLine) {
          break;
        }
      }
      $leading = EditableList::fromItems(Vec\reverse($new));
    }
    return $node->replace(
      $node->getFirstTokenx()->withLeading($leading),
      $node->getFirstTokenx(),
      )
      ->getCode();
  }
}