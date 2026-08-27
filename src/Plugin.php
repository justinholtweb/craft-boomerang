<?php

declare(strict_types=1);

namespace justinholtweb\boomerang;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\services\Gateways;
use craft\commerce\services\OrderAdjustments;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\boomerang\adjusters\ExchangeCredit;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\gateways\StoreCredit;
use justinholtweb\boomerang\models\Settings;
use justinholtweb\boomerang\services\Analytics;
use justinholtweb\boomerang\services\Eligibility;
use justinholtweb\boomerang\services\Exchanges;
use justinholtweb\boomerang\services\Labels;
use justinholtweb\boomerang\services\Notifications;
use justinholtweb\boomerang\services\Reasons;
use justinholtweb\boomerang\services\Refunds;
use justinholtweb\boomerang\services\Restock;
use justinholtweb\boomerang\services\Returns;
use justinholtweb\boomerang\services\States;
use justinholtweb\boomerang\services\Wallet;
use justinholtweb\boomerang\twig\BoomerangVariable;
use yii\base\Event;

/**
 * Boomerang — returns, RMAs and a store-credit wallet for Craft Commerce.
 *
 * @property-read States $states
 * @property-read Reasons $reasons
 * @property-read Returns $returns
 * @property-read Eligibility $eligibility
 * @property-read Refunds $refunds
 * @property-read Wallet $wallet
 * @property-read Restock $restock
 * @property-read Exchanges $exchanges
 * @property-read Labels $labels
 * @property-read Notifications $notifications
 * @property-read Analytics $analytics
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'boomerang';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'boomerang-viewReturns';
    public const PERMISSION_MANAGE = 'boomerang-manageReturns';
    public const PERMISSION_RESOLVE = 'boomerang-resolveReturns';
    public const PERMISSION_CREDIT = 'boomerang-manageCredit';
    public const PERMISSION_ANALYTICS = 'boomerang-viewAnalytics';
    public const PERMISSION_CONFIGURE = 'boomerang-configure';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'states' => ['class' => States::class],
                'reasons' => ['class' => Reasons::class],
                'returns' => ['class' => Returns::class],
                'eligibility' => ['class' => Eligibility::class],
                'refunds' => ['class' => Refunds::class],
                'wallet' => ['class' => Wallet::class],
                'restock' => ['class' => Restock::class],
                'exchanges' => ['class' => Exchanges::class],
                'labels' => ['class' => Labels::class],
                'notifications' => ['class' => Notifications::class],
                'analytics' => ['class' => Analytics::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerTemplateRoots();
        $this->registerTwigVariable();
        $this->registerPermissions();
        $this->registerElementTypes();
        $this->registerCpRoutes();
        $this->registerSiteRoutes();

        // The plugin can be installed while Commerce is disabled or mid-upgrade, and everything
        // below touches an order, a gateway or an adjuster.
        if (!self::commerceIsReady()) {
            return;
        }

        // Registered on every edition, deliberately. Unregistering the gateway type on Lite would
        // turn a merchant's configured gateway into Commerce's MissingGateway the moment a licence
        // lapsed, breaking the orders that were paid with it; the gateway refuses to be *used*
        // instead. Same reasoning for the adjuster, which is what keeps an existing exchange
        // order's total correct.
        $this->registerGateways();
        $this->registerAdjusters();
        $this->registerOrderPanel();
        $this->registerUserPanel();
        $this->registerOrderCompleteHandler();
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getStates(): States
    {
        return $this->get('states');
    }

    public function getReasons(): Reasons
    {
        return $this->get('reasons');
    }

    public function getReturns(): Returns
    {
        return $this->get('returns');
    }

    public function getEligibility(): Eligibility
    {
        return $this->get('eligibility');
    }

    public function getRefunds(): Refunds
    {
        return $this->get('refunds');
    }

    public function getWallet(): Wallet
    {
        return $this->get('wallet');
    }

    public function getRestock(): Restock
    {
        return $this->get('restock');
    }

    public function getExchanges(): Exchanges
    {
        return $this->get('exchanges');
    }

    public function getLabels(): Labels
    {
        return $this->get('labels');
    }

    public function getNotifications(): Notifications
    {
        return $this->get('notifications');
    }

    public function getAnalytics(): Analytics
    {
        return $this->get('analytics');
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('boomerang/settings/_general', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('boomerang', 'Returns');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission(self::PERMISSION_VIEW)) {
            $subNav['returns'] = [
                'label' => Craft::t('boomerang', 'Returns'),
                'url' => 'boomerang/returns',
            ];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_CREDIT)) {
            $subNav['credit'] = [
                'label' => Craft::t('boomerang', 'Store credit'),
                'url' => 'boomerang/credit',
            ];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_ANALYTICS)) {
            $subNav['analytics'] = [
                'label' => Craft::t('boomerang', 'Analytics'),
                'url' => 'boomerang/analytics',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_CONFIGURE)) {
            $subNav['states'] = [
                'label' => Craft::t('boomerang', 'States'),
                'url' => 'boomerang/states',
            ];
            $subNav['reasons'] = [
                'label' => Craft::t('boomerang', 'Reasons'),
                'url' => 'boomerang/reasons',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('boomerang', 'Settings'),
                'url' => 'settings/plugins/boomerang',
            ];
        }

        if ($subNav === []) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    // Registration
    // -------------------------------------------------------------------------

    /**
     * Make the plugin's templates reachable from the front end, overridably.
     *
     * Craft looks in the site's own templates folder *before* any registered root, so a merchant
     * who creates `templates/boomerang/portal/index.twig` replaces the shipped one and keeps the
     * URLs — which is the whole override story for the portal and the emails, and it needs no
     * configuration.
     */
    private function registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots[self::HANDLE] = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates';
            },
        );
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('boomerang', BoomerangVariable::class);
            },
        );
    }

    private function registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = ReturnRequest::class;
            },
        );
    }

    private function registerGateways(): void
    {
        Event::on(
            Gateways::class,
            Gateways::EVENT_REGISTER_GATEWAY_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = StoreCredit::class;
            },
        );
    }

    private function registerAdjusters(): void
    {
        Event::on(
            OrderAdjustments::class,
            OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS,
            static function(RegisterComponentTypesEvent $event) {
                // Appended, so it runs after shipping, discounts and tax and applies to the grand
                // total — which is what a customer means by exchanging one thing for another.
                $event->types[] = ExchangeCredit::class;
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('boomerang', 'Boomerang'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('boomerang', 'View returns'),
                            'nested' => [
                                self::PERMISSION_MANAGE => [
                                    'label' => Craft::t('boomerang', 'Manage returns'),
                                    'nested' => [
                                        // Separate from managing, because moving an RMA along and
                                        // paying a customer are different amounts of trust.
                                        self::PERMISSION_RESOLVE => [
                                            'label' => Craft::t('boomerang', 'Issue refunds, credit and exchanges'),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        self::PERMISSION_CREDIT => [
                            'label' => Craft::t('boomerang', 'Manage store credit'),
                        ],
                        self::PERMISSION_ANALYTICS => [
                            'label' => Craft::t('boomerang', 'View return analytics'),
                        ],
                        self::PERMISSION_CONFIGURE => [
                            'label' => Craft::t('boomerang', 'Configure states and reasons'),
                        ],
                    ],
                ];
            },
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['boomerang'] = 'boomerang/returns/index';
                $event->rules['boomerang/returns'] = 'boomerang/returns/index';
                $event->rules['boomerang/returns/<returnId:\d+>'] = 'boomerang/returns/edit';

                $event->rules['boomerang/credit'] = 'boomerang/credit/index';
                $event->rules['boomerang/credit/<customerId:\d+>'] = 'boomerang/credit/detail';

                $event->rules['boomerang/analytics'] = 'boomerang/analytics/index';

                $event->rules['boomerang/states'] = 'boomerang/states/index';
                $event->rules['boomerang/states/new'] = 'boomerang/states/edit';
                $event->rules['boomerang/states/<stateId:\d+>'] = 'boomerang/states/edit';

                $event->rules['boomerang/reasons'] = 'boomerang/reasons/index';
                $event->rules['boomerang/reasons/new'] = 'boomerang/reasons/edit';
                $event->rules['boomerang/reasons/<reasonId:\d+>'] = 'boomerang/reasons/edit';
            },
        );
    }

    /**
     * The customer-facing portal, mounted where the merchant asked for it.
     *
     * Routes rather than templates, so a merchant who wants their own markup overrides the
     * templates and keeps the URLs, and a merchant who wants their own URLs sets the prefix to
     * nothing and posts to the action endpoints from wherever they like.
     */
    private function registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $settings = $this->getSettings();
                $prefix = trim($settings->portalUriPrefix, '/');

                if (!$settings->portalEnabled || $prefix === '') {
                    return;
                }

                $event->rules[$prefix] = 'boomerang/portal/index';
                $event->rules[$prefix . '/lookup'] = 'boomerang/portal/lookup';
                $event->rules[$prefix . '/start'] = 'boomerang/portal/start';
                $event->rules[$prefix . '/status'] = 'boomerang/portal/status';
            },
        );
    }

    /**
     * Returns for an order, on Commerce's own order screen — which is where somebody looking at a
     * return actually is.
     */
    private function registerOrderPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || $order->id === null) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('boomerang/_panels/order', [
                'order' => $order,
                'returns' => $this->getReturns()->getReturnsForOrder((int)$order->id),
                'eligibility' => $this->getEligibility()->evaluate($order),
                'canManage' => Craft::$app->getUser()->checkPermission(self::PERMISSION_MANAGE),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * A customer's wallet, on their user screen.
     */
    private function registerUserPanel(): void
    {
        Craft::$app->getView()->hook('cp.users.edit.details', function(array &$context) {
            $user = $context['user'] ?? null;

            if ($user === null || $user->id === null || !$this->isPro()) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_CREDIT)) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('boomerang/_panels/user', [
                'user' => $user,
                'balance' => $this->getWallet()->getBalance((int)$user->id),
                'lots' => $this->getWallet()->getLots((int)$user->id),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * Settle the leftover credit on an exchange order once its basket is final.
     *
     * At creation the basket is not final, and credit issued against a guess has to be clawed
     * back — which is a worse conversation than issuing it a moment later.
     */
    private function registerOrderCompleteHandler(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;

                if (!$this->isPro()) {
                    return;
                }

                try {
                    $this->getExchanges()->settleRemainder($order);
                } catch (\Throwable $e) {
                    // Never fatal: an order completing is the customer's payment landing, and
                    // nothing in Boomerang may stand in the way of that.
                    Craft::error('Boomerang could not settle an exchange remainder: ' . $e->getMessage(), __METHOD__);
                }
            },
        );
    }
}
