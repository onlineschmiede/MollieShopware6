<?php

namespace Kiener\MolliePayments\Subscriber;

use Kiener\MolliePayments\Components\Subscription\DAL\Subscription\Struct\IntervalType;
use Kiener\MolliePayments\Components\Subscription\Services\SubscriptionRenewing\OrderCloneService;
use Kiener\MolliePayments\Repository\Order\OrderRepositoryInterface;
use Kiener\MolliePayments\Service\OrderService;
use Kiener\MolliePayments\Service\SettingsService;
use Kiener\MolliePayments\Storefront\Struct\SubscriptionCartExtensionStruct;
use Kiener\MolliePayments\Storefront\Struct\SubscriptionDataExtensionStruct;
use Kiener\MolliePayments\Struct\LineItem\LineItemAttributes;
use Kiener\MolliePayments\Struct\Product\ProductAttributes;
use Shopware\Core\Checkout\Cart\Event\CartBeforeSerializationEvent;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
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

    /**
     * @var OrderService
     */
    private $orderService;

    private $orderCloneService;

    /**
     * @var NumberRangeValueGeneratorInterface
     */
    private $numberRanges;

    private SystemConfigService $systemConfigService;

    public function __construct(SettingsService $settingsService, TranslatorInterface $translator, OrderRepositoryInterface $repoOrders, SystemConfigService $systemConfigService, OrderService $orderService, OrderCloneService $orderCloneService, NumberRangeValueGeneratorInterface $numberRanges)
    {
        $this->settingsService = $settingsService;
        $this->translator = $translator;
        $this->repoOrders = $repoOrders;
        $this->systemConfigService = $systemConfigService;
        $this->orderService = $orderService;
        $this->orderCloneService = $orderCloneService;
        $this->numberRanges = $numberRanges;
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
            // ProductPageLoadedEvent::class => 'onProductPageLoaded',
            // OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
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

    // only for testing purposes
    /*
    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        // $product = $event->getPage()->getProduct();
        $product = $event
            ->getPage()
            ->getProduct()
        ;

        // Get a product ID
        $productId = $product->getId();
        if (empty($productId)) {
            return;
        }

        if ('0194893011ef7dd68ea81db2249091b8' == $productId) {
            $context = $event->getContext();
            $order = $this->orderService->getOrder('01948e536bab73bf9e5b9bd37177d0d9', $context);

            $newOrderNumber = $this->numberRanges->getValue('order', $context, $event->getSalesChannelContext()->getSalesChannel()->getId());
            $orderId = $this->orderCloneService->createNewOrder($order, $newOrderNumber, false, $context);
        }
    }
 */
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
