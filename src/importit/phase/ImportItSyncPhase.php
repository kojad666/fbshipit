<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/tvejazta
 */
namespace Facebook\ImportIt;

use namespace HH\Lib\Str;
use type Facebook\ShipIt\{
  ShipItManifest,
  ShipItChangeset,
  ShipItDestinationRepo,
  ShipItLogger,
};

final class ImportItSyncPhase extends \Facebook\ShipIt\ShipItPhase {

  private ?string $expectedHeadRev;
  private ?string $patchesDirectory;
  private ?string $pullRequestNumber;
  private bool $skipPullRequest = false;
  private bool $applyToLatest = false;
  private bool $shouldDoSubmodules = true;

  public function __construct(
    private (function(ShipItChangeset): ShipItChangeset) $filter,
  ) {
  }

  <<__Override>>
  final public function getReadableName(): string {
    return 'Import Commits';
  }

  <<__Override>>
  final public function getCLIArguments(
  ): vec<\Facebook\ShipIt\ShipItCLIArgument> {
    return vec[
      shape(
        'long_name' => 'expected-head-revision::',
        'description' => 'The expected revision at the HEAD of the PR',
        'write' => $x ==> {
          $this->expectedHeadRev = $x;
          return $this->expectedHeadRev;
        },
      ),
      shape(
        'long_name' => 'pull-request-number::',
        'description' => 'The number of the Pull Request to import',
        'write' => $x ==> {
          $this->pullRequestNumber = $x;
          return $this->pullRequestNumber;
        },
      ),
      shape(
        'long_name' => 'save-patches-to::',
        'description' =>
          'Directory to copy created patches to. Useful for '.'debugging',
        'write' => $x ==> {
          $this->patchesDirectory = $x;
          return $this->patchesDirectory;
        },
      ),
      shape(
        'long_name' => 'skip-pull-request',
        'description' => 'Dont fetch a PR, instead just use the local '.
          'expected-head-revision',
        'write' => $_ ==> {
          $this->skipPullRequest = true;
          return $this->skipPullRequest;
        },
      ),
      shape(
        'long_name' => 'apply-to-latest',
        'description' => 'Apply the PR patch to the latest internal revision, '.
          'instead of on the internal commit that matches the '.
          'PR base.',
        'write' => $_ ==> {
          $this->applyToLatest = true;
          return $this->applyToLatest;
        },
      ),
      shape(
        'long_name' => 'skip-submodules',
        'description' => 'Don\'t sync submodules',
        'write' => $_ ==> {
          $this->shouldDoSubmodules = false;
          return $this->shouldDoSubmodules;
        },
      ),
    ];
  }

  <<__Override>>
  final protected function runImpl(ShipItManifest $manifest): void {
    list($changeset, $destination_base_rev) =
      $this->getSourceChangsetAndDestinationBaseRevision($manifest);
    $this->applyPatchToDestination(
      $manifest,
      $changeset,
      $destination_base_rev,
    );
  }

  private function getSourceChangsetAndDestinationBaseRevision(
    ShipItManifest $manifest,
  ): (ShipItChangeset, ?string) {
    $pr_number = null;
    $expected_head_rev = $this->expectedHeadRev;
    if ($this->skipPullRequest) {
      invariant(
        $expected_head_rev !== null,
        '--expected-head-revision must be set!',
      );
    } else {
      $pr_number = $this->pullRequestNumber;
      invariant(
        $pr_number !== null && $expected_head_rev !== null,
        '--expected-head-revision must be set! '.
        'And either --pull-request-number or --skip-pull-request must be set',
      );
    }
    $source_repo = new ImportItRepoGIT(
      $manifest->getSourceSharedLock(),
      $manifest->getSourcePath(),
      $manifest->getSourceBranch(),
    );
    return $source_repo->getChangesetAndBaseRevisionForPullRequest(
      $pr_number,
      $expected_head_rev,
      $manifest->getSourceBranch(),
      $this->applyToLatest,
    );
  }

  private function applyPatchToDestination(
    ShipItManifest $manifest,
    ShipItChangeset $changeset,
    ?string $base_rev,
  ): void {
    $destination_repo = ImportItRepo::open(
      $manifest->getDestinationSharedLock(),
      $manifest->getDestinationPath(),
      $manifest->getDestinationBranch(),
    );
    if ($base_rev !== null) {
      ShipItLogger::out(
        "  Updating destination branch to new base revision...\n",
      );
      $destination_repo->updateBranchTo($base_rev);
    }
    invariant(
      $destination_repo is ShipItDestinationRepo,
      'The destination repository must implement ShipItDestinationRepo!',
    );
    ShipItLogger::out("  Filtering...\n");
    $filter_fn = $this->filter;
    $changeset = $filter_fn($changeset);
    if ($manifest->isVerboseEnabled()) {
      $changeset->dumpDebugMessages();
    }
    ShipItLogger::out("  Exporting...\n");
    $this->maybeSavePatch($destination_repo, $changeset);
    try {
      $rev = $destination_repo->commitPatch(
        $changeset,
        $this->shouldDoSubmodules,
      );
      ShipItLogger::out(
        "  Done.  %s committed in %s\n",
        $rev,
        $destination_repo->getPath(),
      );
    } catch (\Exception $e) {
      if ($this->patchesDirectory !== null) {
        ShipItLogger::out(
          "  Failure to apply patch at %s\n",
          $this->getPatchLocationForChangeset($changeset),
        );
      } else {
        ShipItLogger::out(
          "  Failure to apply patch:\n%s\n",
          $destination_repo::renderPatch($changeset),
        );
      }
      throw $e;
    }
  }

  private function maybeSavePatch(
    ShipItDestinationRepo $destination_repo,
    ShipItChangeset $changeset,
  ): void {
    $patchesDirectory = $this->patchesDirectory;
    if ($patchesDirectory === null) {
      return;
    }
    /* HH_FIXME[2049] __PHPStdLib */
    /* HH_FIXME[4107] __PHPStdLib */
    if (!\file_exists($patchesDirectory)) {
      /* HH_FIXME[2049] __PHPStdLib */
      /* HH_FIXME[4107] __PHPStdLib */
      \mkdir($patchesDirectory, 0755, /* recursive = */ true);
      /* HH_FIXME[2049] __PHPStdLib */
      /* HH_FIXME[4107] __PHPStdLib */
    } else if (!\is_dir($patchesDirectory)) {
      /* HH_FIXME[2049] __PHPStdLib */
      ShipItLogger::err(
        "Cannot log to %s: the path exists and is not a directory.\n",
        $patchesDirectory,
      );
      return;
    }
    $file = $this->getPatchLocationForChangeset($changeset);
    /* HH_FIXME[2049] __PHPStdLib */
    /* HH_FIXME[4107] __PHPStdLib */
    \file_put_contents($file, $destination_repo::renderPatch($changeset));
    $changeset->withDebugMessage('Saved patch file: %s', $file);
  }

  private function getPatchLocationForChangeset(
    ShipItChangeset $changeset,
  ): string {
    return $this->patchesDirectory.'/'.$changeset->getID().'.patch';
  }
}
