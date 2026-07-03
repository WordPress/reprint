# Push Failure Modes

Every way a push can go wrong, layer by layer, with the test that pins each
mode. The suites live in `tests/` (PHPUnit) and `tests/e2e/` (Docker); test
names are exact so `phpunit --filter <name>` finds them.

The push pipeline: `PushPlanBuilder` (plan) → `StagedPushRunner` (orchestration
+ done cache) → `StagedUploadClient` (per-artifact/batch HTTP) →
`Site_Export_Staged_Endpoints` (auth + routing) → `Site_Export_Staged_Artifacts`
(store) → `Site_Export_Staged_Apply` (atomic window).

## Store (`StagedArtifactsTest`)

| Failure mode | Test |
| --- | --- |
| Kill between append and cursor write | `testKilledCursorWriteLeavesRecoverableState` |
| Kill mid-append (uncommitted tail beyond cursor) | `testStoppingInsideAStepDiscardsTheUncommittedTail` |
| Torn/corrupt cursor record | `testCorruptCommitRecordRestartsTheArtifact` |
| Torn verified marker | `testTornVerifiedRecordIsIgnoredAndRefinalizeRecovers` |
| Kill between finalize and cursor clear | `testFinalizeKilledBeforeClearingCursorStaysConsistent` |
| Staging file shrunk outside the store | `testShrunkenStagingFileIsZeroFilledBackToTheCursor` |
| Marker without artifact bytes | `testVerifiedRecordWithoutTheArtifactFileReportsMissing` |
| Duplicate / straddling / gapped offsets | `testResendingCommittedBytesIsADuplicateNoOp`, `testOffsetGapIsRejectedWithCommittedBytesForResync` |
| Concurrent writers | `testConcurrentWriterGetsBusy` |
| Discard killed midway / discard vs lock | `testDiscardKilledMidwayIsRetriable`, `testDiscardOfAnUntracedArtifactRespectsTheWriterLock` |
| Discard I/O failures (file, cursor, marker) | `testDiscardReportsFailureWhen*` (three) |
| Traversal ids, ids that mirror store internals | `testIdsOutsideTheStagingDirAreRejected`, `testSiteFilenamesThatLookLikeStagingRecordsStageVerbatim` (incl. `lock`, `files`) |
| Deep nesting | `testDeeplyNestedIdsStageAndDiscardCleanly` |
| Unwritable staging dir | `testUnwritableStagingDirectoryIsATypedError` |
| Write to a verified artifact | `testWritingToAVerifiedArtifactIsRejected` |

## Endpoints (`StagedEndpointsTest`)

| Failure mode | Test |
| --- | --- |
| Missing/forged/expired auth headers | `testUploadWithoutAuthHeadersIsRejectedBeforeTheStore`, `testUploadWithWrongSignatureIsRejected`, `testUploadWithExpiredTimestampIsRejected` |
| Body swapped under a valid signature | `testBodyNotMatchingTheSignedHashNeverReachesTheStore`, `testBatchBodyMismatchNeverReachesTheStore` |
| Captured request replayed (no nonce cache by design) | `testReplayedRequestIsAbsorbedAsADuplicate` |
| Malformed nonce | `testShortNonceIsRejected` |
| Request over the size cap | 413 + `max_request_bytes` (`UploadChunkSizer` feedback tests) |
| Torn batch frame header | `testBatchStopsAtTheFirstBadFrameAndReportsProgress` |
| Frame length overruns the body (truncated request) | same test, `truncated_batch` |
| Same file twice in one batch | `testDuplicateIdWithinOneBatchIsIdempotent` |
| Negative lengths, traversal ids in frames | `testHostileFrameFieldsAreTypedRejections` |
| Store busy during any route | `testUploadWhileTheStoreIsHeldReportsBusy` (+ discard/apply busy tests) |
| Wrong method / missing params / unconfigured | `testMutatingRoutesRequirePost`, `testApplyRouteValidatesConfigurationAndMethod` |

Replay posture: HMAC has a timestamp window but no nonce cache
(trunk-inherited). The protocol is replay-*tolerant*: every mutating verb is
idempotent and appends are absorbed by the committed frontier.

## Upload client (`StagedUploadClientTest`)

| Failure mode | Test |
| --- | --- |
| Transport dies mid-artifact | `testTransportHardFailureStopsBounded` |
| Response lost after the server committed | `testLostResponseRetryLandsAsDuplicateAndContinues` |
| Server-side discard mid-transfer | `testServerSideDiscardMidTransferResyncsFromZero` |
| Host cap smaller than the chunk | `test413ShrinksToTheReportedCapAndSucceeds`, `testGivesUpWhenTheFloorIsStillTooLarge` |
| Busy store | `testBusyStoreRetriesThenSucceeds` |
| Bad credentials (fail fast, never retried) | `testWrongSecretFailsFastWithoutRetries`, `testControlPlaneAuthEnvelopeSurfacesAsAuthFailed` |
| HTML error page instead of JSON (WAF, mod_security) | `testHtmlErrorPageInsteadOfJsonFailsTypedWithoutARetryStorm` |
| Server disk full / io_error | `testServerIoErrorSurfacesTyped` |
| Source rewritten before/during upload | `testSourceRewrittenMidUploadFailsTyped`, `testStalePlanMtimeFailsBeforeAnyUpload`, `testUploadBatchExcludesAVolatileFileLocally` |
| Source shorter than declared / missing | `testSourceShorterThanDeclaredTotalFails`, `testMissingSourceFileFails` |
| Batch too large for the learned budget | `testUploadBatchRepartitionsWhenTooLarge` |

## Runner (`StagedPushRunnerTest`)

| Failure mode | Test |
| --- | --- |
| Rerun after interruption (cache + committed offsets) | `testResumeSkipsCachedArtifactsWithoutRequests`, `testMidArtifactResumeUploadsOnlyTheTail` |
| Torn done-cache line | `testTornCacheLineFallsBackToTheServerShortCircuit` |
| Per-file vs transfer-wide failures | `testArtifactScopedFailureContinuesAndRerunRetriesIt`, `testTransferScopedFailureAborts` |
| Plan/cache size disagreement | `testPlanSizeDisagreeingWithTheCache*` |
| Same-size edit (different second) | `testSameSizeEditReuploadsInsteadOfCacheSkipping` |
| Same-size edit in the same second — the documented boundary | `testSameSecondSameSizeEditIsTheDocumentedCacheBoundary` |
| Kill between forget-tmp write and rename | `testForgetCachedToleratesAStaleTmpOrphan` |
| Unwritable state dir | `testUnwritableStateDirAbortsTypedBeforeAnyRequest` |
| Learned limits survive runs | `testLearnedChunkLimitsSurviveRuns` |
| Deletion bookkeeping | `testCacheRemembersDeletionsUntilForgotten` |

Known bound: deletions derive from the done cache, whose loss contract is
"a lost record costs a re-upload". A wiped state dir re-uploads correctly but
derives no deletions for files removed in the interim — the same contract as
two pulls sharing an fs-root. Deliberate; revisit only with a server-side
ship journal.

## Apply window (`StagedApplyTest`)

| Failure mode | Test |
| --- | --- |
| Kill mid-window, rerun finishes | `testRerunAfterAKillMidApplyFinishesTheWindow` |
| Incomplete/unverified transfer rejects whole | `testUnverifiedArtifactRejectsTheWholeTransfer`, `testMissingArtifactRejectsTheWholeTransfer` |
| Cross-device staging (rename impossible) | `testCrossDeviceStagingIsRejectedBeforeAnythingMoves` |
| Manifest forgery (traversal, self-listing, duplicates, torn) | `testManifestProblemsAreTypedRejections`, `testMalformedDeleteEntriesRejectTheManifest` |
| Type swaps (dir↔file↔symlink) between pushes | `testReplacesADirectoryWithAFile`, `testReplacesASymlinkWithoutFollowingIt`, `testClearsAFileBlockingTheParentChain`, `testDeletionsRunBeforeMovesRegardlessOfManifestOrder` |
| Deletion kill windows / staged garbage | `testDeletionsAreIdempotentAcrossReruns`, staged-consumption asserts in `testDeleteEntriesRemoveFilesDirectoriesAndLinks` |
| Policy protection (ownership vs occupancy) | `testSkipPolicyDoesNotProtectOwnedEntries`, `testSkipPolicyDoesNotProtectOwnedDeletions`, `testSymlinkedParentGuardProtectsDeletions` |
| Protected-set reporting past the response cap | `testOnProtectedReportsEveryEntryBeyondTheResponseCap` |
| Upload racing the window | `testBusyWhileAWriterHoldsTheStore` |

Trust note: `owned`/delete exemptions are sender-attested manifest data. Safe
because the push endpoint pins `on_existing=replace`; if a skip policy is ever
wired into the endpoint, strip sender exemptions there first.

## CLI (`PushFilesCliTest`) and e2e (`import-52`)

| Failure mode | Test |
| --- | --- |
| Dead target (retryable, exit 2) | `testUnreachableTargetAbortsWithTheRetryableExitCode` |
| Bad credentials (fatal, exit 1) | `testWrongSecretAbortsFatalNotRetryable` |
| Interrupted push then push | `testInterruptedPushResumesFromTheCommittedOffset` |
| Staging too small for the plan | `testInsufficientStagingSpaceRefusesBeforeUploading` |
| Local deletions propagate; `--only` scoping | `testLocalDeletionPropagatesOnTheNextPushApply`, `testOnlyScopedPushDerivesDeletionsOnlyInsideItsPrefixes` |
| Adversarial names end to end | `testAdversarialFilenamesSurviveTheWire` |
| Hostile `--only` | `testHostileOnlyPrefixIsRejectedBeforeAnyUpload` |
| Break-the-remote-then-fix-it | mtime re-push + type-swap engine tests + `import-52` scenarios |

Not portably testable (documented, not covered): mid-operation `chmod`
failures (CI containers run as root), true disk-full on append (needs a
quota'd filesystem), and kernel-level rename atomicity (assumed per POSIX).
