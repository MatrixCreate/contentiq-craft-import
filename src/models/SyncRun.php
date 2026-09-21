<?php

namespace matrixcreate\contentiqimporter\models;

use craft\helpers\Json;

/**
 * DTO for one contentiq_import_runs row.
 *
 * A thin, read-only wrapper — {@see \matrixcreate\contentiqimporter\services\SyncRunService}
 * does all the database work and hands back instances of this via
 * {@see self::fromRow()}. Options/state/globals arrive from the row as JSON
 * text and are decoded once here rather than by every caller.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class SyncRun
{
    // Constants
    // =========================================================================

    /** Staging: applying lock/consent, fetching + planning the export. */
    public const PHASE_STAGING = 'staging';

    /** Importing pages, one contentiq_sync_pages row at a time. */
    public const PHASE_IMPORTING = 'importing';

    /** Running the card-reference / link-sweep / redirect post-passes. */
    public const PHASE_POSTPASS = 'postpass';

    /** Ack, globals, auto-lock, report — see the STEP_* constants. */
    public const PHASE_FINALISING = 'finalising';

    /** Terminal — completed (with or without warnings). */
    public const PHASE_DONE = 'done';

    /** Terminal — a fatal error stopped the run. */
    public const PHASE_FAILED = 'failed';

    /** @var string[] Phases {@see self::isTerminal()} treats as finished. */
    private const TERMINAL_PHASES = [self::PHASE_DONE, self::PHASE_FAILED];

    /** finalising sub-step: acking genuinely-written pages back to ContentIQ. */
    public const STEP_ACK = 'ack';

    /** finalising sub-step: importing globals (drift check + consent gate). */
    public const STEP_GLOBALS = 'globals';

    /** finalising sub-step: the auto-lock upsert loop. */
    public const STEP_LOCK = 'lock';

    /** finalising sub-step: assembling the legacy `result` report JSON. */
    public const STEP_REPORT = 'report';

    // Public Properties
    // =========================================================================

    /**
     * @param int $id
     * @param string $source `api`|`upload`|`cli`|`widget`.
     * @param string $phase One of the PHASE_* constants.
     * @param string|null $step One of the STEP_* constants, set only while
     *   `phase === PHASE_FINALISING`.
     * @param string $status Legacy report status: `success`|`warnings`|`errors`|`pending`.
     * @param array $options Captured at request time (`unlockIds`,
     *   `unlockGlobals`, `newSelections`, `dryRun`), applied at job execution.
     * @param array $state Run-scoped facts that must survive job boundaries
     *   (`globalCtaClaimed`, `homepageSlug`, `structureId`, `sectionHandle`,
     *   `pulledAt`, counters — see CRAFT-IMPORT-QUEUE-SPEC.md §3.4).
     * @param array|null $globals The export's `globals` blob, staged once.
     * @param string|null $error Last fatal error message.
     * @param string|null $heartbeat Touched by every job execution/item — drives staleness.
     * @param int|null $queueJobId Craft queue row id of the currently queued job for this run.
     * @param int $pageCount
     * @param int $imageCount
     * @param string|null $dateCreated
     * @param string|null $dateFinished
     */
    public function __construct(
        public readonly int $id,
        public readonly string $source,
        public readonly string $phase,
        public readonly ?string $step,
        public readonly string $status,
        public readonly array $options,
        public readonly array $state,
        public readonly ?array $globals,
        public readonly ?string $error,
        public readonly ?string $heartbeat,
        public readonly ?int $queueJobId,
        public readonly int $pageCount,
        public readonly int $imageCount,
        public readonly ?string $dateCreated,
        public readonly ?string $dateFinished,
    ) {
    }

    // Public Methods
    // =========================================================================

    /**
     * Builds an instance from a raw contentiq_import_runs row (as returned
     * by a craft\db\Query — string keys, JSON columns still encoded).
     *
     * @param array $row
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            source: (string)($row['source'] ?? 'api'),
            phase: (string)($row['phase'] ?? self::PHASE_DONE),
            step: $row['step'] ?? null,
            status: (string)($row['status'] ?? 'success'),
            options: self::_decodeArray($row['options'] ?? null),
            state: self::_decodeArray($row['state'] ?? null),
            globals: self::_decodeNullableArray($row['globals'] ?? null),
            error: $row['error'] ?? null,
            heartbeat: $row['heartbeat'] ?? null,
            queueJobId: isset($row['queueJobId']) ? (int)$row['queueJobId'] : null,
            pageCount: (int)($row['pageCount'] ?? 0),
            imageCount: (int)($row['imageCount'] ?? 0),
            dateCreated: $row['dateCreated'] ?? null,
            dateFinished: $row['dateFinished'] ?? null,
        );
    }

    /**
     * Whether this run has reached a terminal phase (`done` or `failed`) —
     * the point past which no further job is ever pushed for it.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return in_array($this->phase, self::TERMINAL_PHASES, true);
    }

    // Private Methods
    // =========================================================================

    /**
     * Decodes a JSON-text column to an array, tolerating null/empty/already
     * non-JSON values by falling back to an empty array.
     *
     * @param mixed $value
     * @return array
     */
    private static function _decodeArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = Json::decodeIfJson($value);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Same as {@see self::_decodeArray()} but preserves "no value" as null
     * instead of collapsing it to an empty array — used for `globals`, where
     * null means "never staged" rather than "staged empty".
     *
     * @param mixed $value
     * @return array|null
     */
    private static function _decodeNullableArray(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = Json::decodeIfJson($value);

        return is_array($decoded) ? $decoded : null;
    }
}
