<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/nhndf5h3
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\Str;

/** Utility class for commit messages with sections preceded by "Header: ".
 *
 * For example, Phabricator creates messages like:
 * Summary:
 *   Foo bar
 * Test Plan:
 *   Baz
 */
final class ShipItMessageSections {
  /** Get a Map { $header => $content} of sections.
   *
   * @param $valid_sections what sections are real sections; if specified, and
   *   something that looks like a section header is seen that isn't in this
   *   list, it will be considered content. If unspecified, every line like
   *   /^[a-zA-Z ]+:/ will be considered a header. All headers should be
   *   lowercase.
   */
  public static function getSections(
    ShipItChangeset $changeset,
    ?ImmSet<string> $valid_sections = null,
  ): Map<string, string> {
    $sections = Map {'' => ''};
    $section = '';
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    foreach(\explode("\n", $changeset->getMessage()) as $line) {
      $line = Str\trim_right($line);
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      if (\preg_match('/^[a-zA-Z ]+:/', $line)) {
        $h = Str\lowercase(Str\slice($line, 0, Str\search($line, ':')));
        if ($valid_sections === null || $valid_sections->contains($h)) {
          $section = $h;
          $value = Str\trim(Str\slice($line, Str\length($section) + 1));

          // Treat "Summary: FBOnly: bar" as "FBOnly: bar" - handy if using
          // Phabricator
          if (
            /* HH_IGNORE_ERROR[2049] __PHPStdLib */
            /* HH_IGNORE_ERROR[4107] __PHPStdLib */
            \preg_match('/^[a-zA-Z ]+:/', $value)
            && $valid_sections !== null
          ) {
            $h = Str\lowercase(Str\slice($value, 0, Str\search($value, ':')));
            if ($valid_sections->contains($h)) {
              $section = $h;
              $value = Str\trim(Str\slice($value, Str\length($section) + 1));
            }
          }
          $sections[$section] = $value;
          continue;
        }
      }
      $sections[$section] .= "\n{$line}";
    }
    if ($sections[""] === '') {
      $sections->removeKey('');
    }

    return $sections->map($x ==> Str\trim($x));
  }

  /** Convert a section map back to a commit message */
  public static function buildMessage(
    ImmMap<string, string> $sections,
  ): string {
    $out = '';
    foreach ($sections as $section => $text) {
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      if (\ctype_space($text) || Str\length($text) === 0) {
        continue;
      }
      $section_head = Str\capitalize_words($section).":";
      $text = Str\trim($text);
      if (!self::hasMoreThanOneNonEmptyLine($text)) {
        $section_head .= ' ';
      } else {
        $section_head .= "\n";
      }
      $out .= $section_head."$text\n\n";
    }
    return Str\trim_right($out);
  }

  private static function hasMoreThanOneNonEmptyLine(string $str): bool {
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    $lines = \explode("\n", $str);
    $cn = 0;
    foreach ($lines as $line) {
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      if (!(\ctype_space($line) || Str\length($line) === 0)) {
        ++$cn;
      }
    }
    return $cn > 1;
  }
}
