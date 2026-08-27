<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\Plugin;

/**
 * Move a batch of returns to a state.
 *
 * Every one of them goes through {@see \justinholtweb\boomerang\services\Returns::transition()} —
 * the bulk action is a loop, not a shortcut — so each gets its guard, its history entry, its
 * restock and its email. A bulk approval that silently skipped the approval emails would be worse
 * than no bulk action at all.
 *
 * A batch that hits a refused transition or a declined refund says how many did not move. "12
 * returns updated" when three of them quietly did not is the kind of report that gets found out a
 * week later by a customer.
 */
class SetState extends ElementAction
{
    public ?int $stateId = null;

    public function getTriggerLabel(): string
    {
        return Craft::t('boomerang', 'Set state');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['stateId'], 'required'];
        $rules[] = [['stateId'], 'integer'];

        return $rules;
    }

    public function getTriggerHtml(): ?string
    {
        $view = Craft::$app->getView();

        $view->registerJsWithVars(fn($type) => <<<JS
(() => {
    new Craft.ElementActionTrigger({
        type: $type,
        validateSelection: (selectedItems) => selectedItems.length > 0,
    });
})();
JS, [static::class]);

        return $view->renderTemplate('boomerang/_actions/set-state', [
            'states' => Plugin::getInstance()->states->getAllStates(),
        ]);
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $plugin = Plugin::getInstance();
        $state = $this->stateId !== null ? $plugin->states->getStateById($this->stateId) : null;

        if ($state === null) {
            $this->setMessage(Craft::t('boomerang', 'That state no longer exists.'));

            return false;
        }

        $moved = 0;
        $refused = 0;

        // `Db::each()` rather than `all()`: a returns desk clearing a backlog can select several
        // hundred, and hydrating them all to change one column each is how a bulk action becomes a
        // memory limit.
        foreach (Db::each($query) as $return) {
            if (!$return instanceof ReturnRequest) {
                continue;
            }

            if ($return->stateId === $state->id) {
                continue;
            }

            if ($plugin->returns->transition($return, $state)) {
                $moved++;
            } else {
                $refused++;
            }
        }

        if ($refused > 0) {
            $this->setMessage(Craft::t('boomerang', '{moved} moved to {state}; {refused} could not be — open those returns individually to see why.', [
                'moved' => $moved,
                'state' => $state->name,
                'refused' => $refused,
            ]));

            return $moved > 0;
        }

        $this->setMessage(Craft::t('boomerang', '{count, plural, =1{1 return} other{# returns}} moved to {state}.', [
            'count' => $moved,
            'state' => $state->name,
        ]));

        return true;
    }
}
