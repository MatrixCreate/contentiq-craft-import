<?php

namespace matrixcreate\contentiqimporter\helpers;

use Craft;

/**
 * Reads the batched sync pipeline's tunables from `config/contentiq.php`'s
 * `queue` key, falling back to inline defaults when the project hasn't set
 * one (or hasn't set the whole key). See CRAFT-IMPORT-QUEUE-SPEC.md §3.3.
 *
 * Deliberately NOT added to `src/config/defaults.php` — that file is the
 * ContentIQ block-mapping table (`content_types`, `blockOverrides`, …),
 * interpreted by MatrixBuilder; the `queue` key has nothing to do with block
 * mapping and would be a stray, unrelated entry there.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class SyncQueueConfig
{
    // Constants
    // =========================================================================

    /** @var int Default ImportPagesJob::$batchSize. */
    private const DEFAULT_PAGE_BATCH_SIZE = 20;

    /** @var int Default PostPassJob::$batchSize. */
    private const DEFAULT_POST_PASS_BATCH_SIZE = 50;

    /** @var int Default ttr for StageRunJob/ImportPagesJob/PostPassJob. */
    private const DEFAULT_TTR = 300;

    /** @var int Default ttr for FinaliseRunJob (ack + globals + auto-lock + report in one execution). */
    private const DEFAULT_FINALISE_TTR = 600;

    /** @var int Default staleness threshold (seconds) — see self::staleAfter(). */
    private const DEFAULT_STALE_AFTER = 600;

    // Public Methods
    // =========================================================================

    /**
     * @return int Pages per ImportPagesJob batch.
     */
    public static function pageBatchSize(): int
    {
        return (int)(self::_queueConfig()['pageBatchSize'] ?? self::DEFAULT_PAGE_BATCH_SIZE);
    }

    /**
     * @return int Rows per PostPassJob batch.
     */
    public static function postPassBatchSize(): int
    {
        return (int)(self::_queueConfig()['postPassBatchSize'] ?? self::DEFAULT_POST_PASS_BATCH_SIZE);
    }

    /**
     * @return int ttr (seconds) for StageRunJob/ImportPagesJob/PostPassJob.
     */
    public static function ttr(): int
    {
        return (int)(self::_queueConfig()['ttr'] ?? self::DEFAULT_TTR);
    }

    /**
     * @return int ttr (seconds) for FinaliseRunJob.
     */
    public static function finaliseTtr(): int
    {
        return (int)(self::_queueConfig()['finaliseTtr'] ?? self::DEFAULT_FINALISE_TTR);
    }

    /**
     * @return int Seconds a non-terminal run's `heartbeat` may go untouched
     *   before the status poller treats it as stale (§3.6).
     */
    public static function staleAfter(): int
    {
        return (int)(self::_queueConfig()['staleAfter'] ?? self::DEFAULT_STALE_AFTER);
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array The `queue` sub-array of `config/contentiq.php`, or `[]`
     *   when the project has no config file or no `queue` key in it.
     */
    private static function _queueConfig(): array
    {
        $config = Craft::$app->config->getConfigFromFile('contentiq');

        return is_array($config['queue'] ?? null) ? $config['queue'] : [];
    }
}
