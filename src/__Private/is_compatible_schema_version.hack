/*
 *  Copyright (c) 2017-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

namespace Facebook\HHAST\__Private;

use const Facebook\HHAST\SCHEMA_VERSION;

/**
 * Schema versions are compatible if one is a strict superset of the other
 * (i.e., syntax was either only added, or only removed, between the two
 * versions). The codegen should be built with the most complete version, and
 * this function should return true when passed any of the other versions (its
 * strict subsets) -- i.e., if $other_version is a strict subset of
 * Facebook\HHAST\SCHEMA_VERSION.
 */
function is_compatible_schema_version(string $other_version): bool {
  invariant(
    SCHEMA_VERSION === '2020-06-02-0001',
    '%s needs updating',
    __FILE__,
  );
  if ($other_version === SCHEMA_VERSION) {
    return true;
  }

  // Return true if $other_superset is a superset of SCHEMA_VERSION

  if ($other_version === '2020-06-23-0001') {
    // removal of array literals
    return true;
  }
  if ($other_version === '2020-06-30-0000') {
    // removal of `yield from`
    return true;
  }

  return false;
}
