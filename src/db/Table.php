<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\db;

/**
 * Boomerang's table names, in one place.
 *
 * A query written against `boomerang_returns` and the migration that created it cannot drift apart
 * in a typo that only shows up on the one database driver nobody tested.
 */
abstract class Table
{
    public const RETURNS = '{{%boomerang_returns}}';
    public const RETURNITEMS = '{{%boomerang_returnitems}}';
    public const STATES = '{{%boomerang_states}}';
    public const REASONS = '{{%boomerang_reasons}}';
    public const EVENTS = '{{%boomerang_events}}';
    public const LABELS = '{{%boomerang_labels}}';
    public const CREDITLOTS = '{{%boomerang_creditlots}}';
    public const CREDITENTRIES = '{{%boomerang_creditentries}}';
}
