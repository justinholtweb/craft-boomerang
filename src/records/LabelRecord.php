<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\records;

use craft\db\ActiveRecord;
use justinholtweb\boomerang\db\Table;

/**
 * @see Table::LABELS
 */
class LabelRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LABELS;
    }
}
