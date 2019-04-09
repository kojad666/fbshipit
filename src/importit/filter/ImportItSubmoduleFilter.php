<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/yz6eqiy0
 */
namespace Facebook\ImportIt;

use namespace HH\Lib\Str;
use type Facebook\ShipIt\{ShipItChangeset};

final class ImportItSubmoduleFilter {
  <<\TestsBypassVisibility>>
  private static function makeSubmoduleDiff(
    string $path,
    string $old_rev,
    string $new_rev,
  ): string {
    return "--- a/{$path}\n".
      "+++ b/{$path}\n".
      "@@ -1 +1 @@\n".
      "-Subproject commit {$old_rev}\n".
      "+Subproject commit {$new_rev}\n";
  }

  /**
   * Convert a subproject commit to a text file like:
   *   Subproject commit deadbeef
   *
   * This is the inverse to the ShipIt filter
   * ShipItSubmoduleFilter::useSubmoduleCommitFromTextFile.
   */
  public static function moveSubmoduleCommitToTextFile(
    ShipItChangeset $changeset,
    string $submodule_path,
    string $text_file_with_rev,
  ): ShipItChangeset {
    $diffs = Vector {};
    foreach ($changeset->getDiffs() as $diff) {
      $path = $diff['path'];
      $body = $diff['body'];

      if ($path !== $submodule_path) {
        $diffs[] = $diff;
        continue;
      }

      $old_rev = $new_rev = null;
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      foreach(\explode("\n", $body) as $line) {
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        if (!\strncmp('-Subproject commit ', $line, 19)) {
          $old_rev = Str\trim(Str\slice($line, 19));
          /* HH_IGNORE_ERROR[2049] __PHPStdLib */
          /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        } else if (!\strncmp('+Subproject commit ', $line, 19)) {
          $new_rev = Str\trim(Str\slice($line, 19));
        }
      }

      if ($old_rev === null || $new_rev === null) {
        // Do nothing - this will lead to a 'patch does not apply' error for
        // human debugging, which seems like a reasonable error to give :)
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        \printf(
          "  Skipping change to '%s' (-> %s); this will certainly fail.\n",
          $submodule_path,
          $text_file_with_rev,
        );
        $diffs[] = $diff;
        continue;
      }

      $changeset = $changeset
        ->withDebugMessage(
          'Updating submodule at %s (external path %s) to %s (from %s)',
          $text_file_with_rev,
          $submodule_path,
          $new_rev,
          $old_rev,
        );

      $diffs[] = shape(
        'path' => $text_file_with_rev,
        'body' =>
          self::makeSubmoduleDiff($text_file_with_rev, $old_rev, $new_rev),
      );
    }

    return $changeset->withDiffs($diffs->toImmVector());
  }
}
