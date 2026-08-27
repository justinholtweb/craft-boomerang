<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\records;

use craft\db\ActiveRecord;
use justinholtweb\boomerang\db\Table;

/**
 * @see Table::STATES
 */
class StateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::STATES;
    }
}
