<?php

/**
 * Minimal stand-ins for the Craft/Yii classes MatrixBuilder and NodesRenderer
 * reference. This repo has no vendor/ for the zero-dependency test runner (no
 * Craft install), so these are just enough surface for the real service
 * classes to run standalone against a fixture — no behaviour beyond what's
 * needed to not fatal.
 *
 * All three namespace blocks below must use the braced form (PHP forbids
 * mixing bracketed and unbracketed namespace declarations in one file), since
 * one of them (Craft) has to land in the global namespace.
 */

namespace yii\base {
    /**
     * Stand-in for yii\base\Component — MatrixBuilder and NodesRenderer both
     * extend this and use none of its behaviour beyond being a valid parent.
     */
    class Component
    {
    }
}

namespace matrixcreate\contentiqimporter {
    /**
     * Stand-in for the plugin singleton. MatrixBuilder reaches services via
     * ContentIQImporter::$plugin->{nodes,images,imports} — the test wires a
     * fake object with just those three properties before calling build().
     */
    class ContentIQImporter
    {
        /**
         * @var object|null
         */
        public static $plugin;
    }
}

namespace craft\fields {
    /**
     * Stand-in for craft\fields\ContentBlock. ImportService::_detectHeroShape()
     * (§7.5 hero shape probe) only needs this to be a distinguishable class for
     * an `instanceof` check — no behaviour required.
     */
    class ContentBlock
    {
    }
}

namespace craft\models {
    /**
     * Stand-in for craft\models\FieldLayout. ImportService's hero shape probe
     * (_detectHeroShape()) and the §7.6.1 guard-2 "was this previously
     * non-empty" checks only ever call getFieldByHandle() — this stub is
     * built directly from a handle => field-object map so tests can construct
     * exactly the layout shape they want to probe.
     */
    class FieldLayout
    {
        /**
         * @param array<string, object> $fieldsByHandle
         */
        public function __construct(private array $fieldsByHandle = [])
        {
        }

        public function getFieldByHandle(string $handle): ?object
        {
            return $this->fieldsByHandle[$handle] ?? null;
        }
    }
}

namespace {
    /**
     * Stand-in for Craft::warning()/Craft::error() — MatrixBuilder logs
     * through these for unknown block types and image failures. No-ops here;
     * the fixture below never exercises those paths.
     */
    class Craft
    {
        public static function warning($message, $category = null): void
        {
        }

        public static function error($message, $category = null): void
        {
        }
    }
}
