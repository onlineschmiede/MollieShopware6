<?php

namespace Kiener\MolliePayments\Subscriber;

use Kiener\MolliePayments\Components\Subscription\DAL\Subscription\Struct\IntervalType;
use Kiener\MolliePayments\Repository\Order\OrderRepositoryInterface;
use Kiener\MolliePayments\Service\SettingsService;
use Kiener\MolliePayments\Storefront\Struct\SubscriptionCartExtensionStruct;
use Kiener\MolliePayments\Storefront\Struct\SubscriptionDataExtensionStruct;
use Kiener\MolliePayments\Struct\LineItem\LineItemAttributes;
use Kiener\MolliePayments\Struct\Product\ProductAttributes;
use Shopware\Core\Checkout\Cart\Event\CartBeforeSerializationEvent;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SubscriptionSubscriber implements EventSubscriberInterface
{
    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    private $repoOrders;

    private SystemConfigService $systemConfigService;

    public function __construct(SettingsService $settingsService, TranslatorInterface $translator, OrderRepositoryInterface $repoOrders, SystemConfigService $systemConfigService)
    {
        $this->settingsService = $settingsService;
        $this->translator = $translator;
        $this->repoOrders = $repoOrders;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents()
    {
        return [
            CartBeforeSerializationEvent::class => 'onBeforeSerializeCart',
            // ------------------------------------------------------------------------
            StorefrontRenderEvent::class => 'onStorefrontRender',
            ProductPageLoadedEvent::class => 'addSubscriptionData',
            CheckoutConfirmPageLoadedEvent::class => 'addSubscriptionData',
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderPlaced',
        ];
    }

    /**
     * this is required to allow our custom fields
     * if we don't add them in here, then they will be removed for cart lineItems
     * https://github.com/shopware/platform/blob/trunk/UPGRADE-6.5.md.
     */
    public function onBeforeSerializeCart(CartBeforeSerializationEvent $event): void
    {
        $allowed = $event->getCustomFieldAllowList();

        foreach (LineItemAttributes::getKeyList() as $key) {
            $allowed[] = $key;
        }

        $event->setCustomFieldAllowList($allowed);
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $settings = $this->settingsService->getSettings($event->getSalesChannelContext()->getSalesChannel()->getId());

        $event->setParameter('mollie_subscriptions_enabled', $settings->isSubscriptionsEnabled());
    }

    public function addSubscriptionData(PageLoadedEvent $event): void
    {
        $settings = $this->settingsService->getSettings($event->getSalesChannelContext()->getSalesChannel()->getId());

        if (!$settings->isSubscriptionsEnabled()) {
            $struct = new SubscriptionDataExtensionStruct(
                false,
                '',
                false
            );

            $event->getPage()->addExtension('mollieSubscription', $struct);

            return;
        }

        $page = $event->getPage();

        if ($page instanceof ProductPage) {
            $product = $page->getProduct();
            $productAttributes = new ProductAttributes($product);

            $isSubscription = $productAttributes->isSubscriptionProduct();

            // only load our data if we really
            // have a subscription product
            if ($isSubscription) {
                $interval = (int) $productAttributes->getSubscriptionInterval();
                $unit = (string) $productAttributes->getSubscriptionIntervalUnit();
                $repetition = (int) $productAttributes->getSubscriptionRepetitionCount();
                $translatedInterval = $this->getTranslatedInterval($interval, $unit, $repetition);
                $showIndicator = $settings->isSubscriptionsShowIndicator();
            } else {
                $translatedInterval = '';
                $showIndicator = false;
            }

            $struct = new SubscriptionDataExtensionStruct(
                $isSubscription,
                $translatedInterval,
                $showIndicator
            );

            $event->getPage()->addExtension('mollieSubscription', $struct);

            return;
        }

        if ($page instanceof CheckoutConfirmPage) {
            $subscriptionFound = false;

            foreach ($page->getCart()->getLineItems()->getFlat() as $lineItem) {
                $lineItemAttributes = new LineItemAttributes($lineItem);

                $isSubscription = $lineItemAttributes->isSubscriptionProduct();

                if ($isSubscription) {
                    $subscriptionFound = true;

                    $interval = (int) $lineItemAttributes->getSubscriptionInterval();
                    $unit = (string) $lineItemAttributes->getSubscriptionIntervalUnit();
                    $repetition = (int) $lineItemAttributes->getSubscriptionRepetition();

                    $translatedInterval = $this->getTranslatedInterval($interval, $unit, $repetition);

                    $struct = new SubscriptionDataExtensionStruct(
                        $isSubscription,
                        $translatedInterval,
                        false
                    );

                    $lineItem->addExtension('mollieSubscription', $struct);
                }
            }

            // we need this for some checks on the cart
            $cartStruct = new SubscriptionCartExtensionStruct($subscriptionFound);
            $event->getPage()->addExtension('mollieSubscriptionCart', $cartStruct);
        }
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        // Here you can handle the event
        $order = $event->getOrder();
        if (null === $order) {
            return;
        }
        $orderId = $order->getId();
        $orderLineItems = $order->getLineItems();
        $customFields = $order->getCustomFields();

        if (isset($customFields['MolliePayments']['swSubscriptionId'])) {
            $mollieSubscriptionId = $customFields['MolliePayments']['swSubscriptionId'];

            // do something with the subscription ID
            if (null !== $mollieSubscriptionId) {
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $mollieSubscriptionId));

                $context = $event->getContext();

                $ordersWithSameMollieId = $this->repoOrders->search($criteria, $context)->getEntities();

                // count all orders with the same mollie id
                $subscriptionOrderCount = count($ordersWithSameMollieId);

                $subscriptionDiscountPercentage = $this->getSubscriptionDiscountPercentage($subscriptionOrderCount, $event->getSalesChannelId());
            }
        }

        foreach ($orderLineItems as $item) {
            $item->setCustomFieldsValue('mollie_payments.mollie_payments_subscription_discount', $subscriptionDiscountPercentage);
        }
    }

    public function getSubscriptionDiscountPercentage(int $subscriptionOrderCount, $salesChannelId): float
    {
        $discount = 0.0;

        $numberOfDiscountsToApplyField = $this->systemConfigService->get('MolliePayments.config.numberOfDiscounts', $salesChannelId);

        if ($subscriptionOrderCount > $numberOfDiscountsToApplyField) {
            $discountTakes = (int) $numberOfDiscountsToApplyField;
        } else {
            $discountTakes = $subscriptionOrderCount;
        }

        switch ($discountTakes) {
            case 1:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterFirstPaymentRate', $salesChannelId);

                break;

            case 2:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterSecondPaymentRate', $salesChannelId);

                break;

            case 3:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterThirdPaymentRate', $salesChannelId);

                break;

            case 4:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterFourthPaymentRate', $salesChannelId);

                break;

            case 5:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterFifthPaymentRate', $salesChannelId);

                break;

            case 6:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterSixthPaymentRate', $salesChannelId);

                break;

            case 7:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterSeventhPaymentRate', $salesChannelId);

                break;

            case 8:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterEighthPaymentRate', $salesChannelId);

                break;

            case 9:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterNinthPaymentRate', $salesChannelId);

                break;

            case 10:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterTenthPaymentRate', $salesChannelId);

                break;

            case 11:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterEleventhPaymentRate', $salesChannelId);

                break;

            case 12:
                $discount = (float) $this->systemConfigService->get('MolliePayments.config.afterTwelfthPaymentRate', $salesChannelId);

                break;

            default:
                $discount = 0;

                break;
        }

        return $discount;
    }

    private function getTranslatedInterval(int $interval, string $unit, int $repetition): string
    {
        $snippetKey = '';

        switch ($unit) {
            case IntervalType::DAYS:
                if (1 === $interval) {
                    $snippetKey = 'molliePayments.subscriptions.options.everyDay';
                } else {
                    $snippetKey = 'molliePayments.subscriptions.options.everyDays';
                }

                break;

            case IntervalType::WEEKS:
                if (1 === $interval) {
                    $snippetKey = 'molliePayments.subscriptions.options.everyWeek';
                } else {
                    $snippetKey = 'molliePayments.subscriptions.options.everyWeeks';
                }

                break;

            case IntervalType::MONTHS:
                if (1 === $interval) {
                    $snippetKey = 'molliePayments.subscriptions.options.everyMonth';
                } else {
                    $snippetKey = 'molliePayments.subscriptions.options.everyMonths';
                }

                break;
        }

        $mainText = $this->translator->trans($snippetKey, ['%value%' => $interval]);

        if ($repetition >= 1) {
            $mainText .= ', '.$this->translator->trans('molliePayments.subscriptions.options.repetitionCount', ['%value%' => $repetition]);
        }

        return $mainText;
    }
}
