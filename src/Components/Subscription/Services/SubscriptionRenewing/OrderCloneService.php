<?php

namespace Kiener\MolliePayments\Components\Subscription\Services\SubscriptionRenewing;

use Kiener\MolliePayments\Repository\Order\OrderRepositoryInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Order\OrderConversionContext;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\PercentagePriceDefinition;
use Shopware\Core\Checkout\Cart\Processor;
use Shopware\Core\Checkout\Cart\Rule\LineItemRule;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class OrderCloneService
{
    /**
     * @var OrderRepositoryInterface
     */
    private $repoOrders;

    /**
     * @var OrderConverter
     */
    private $orderConverter;

    /**
     * @var Processor
     */
    private $processor;

    private SystemConfigService $systemConfigService;

    private PercentagePriceCalculator $percentage_calculator;

    public function __construct(OrderRepositoryInterface $repoOrders, OrderConverter $orderConverter, Processor $processor, SystemConfigService $systemConfigService, PercentagePriceCalculator $percentage_calculator)
    {
        $this->repoOrders = $repoOrders;
        $this->orderConverter = $orderConverter;
        $this->processor = $processor;
        $this->systemConfigService = $systemConfigService;
        $this->percentage_calculator = $percentage_calculator;
    }

    /**
     * @throws \Exception
     */
    public function createNewOrder(OrderEntity $existingOrder, string $newOrderNumber, bool $needsSeparateShippingAddress, Context $context): string
    {
        if (!$existingOrder->getAddresses() instanceof OrderAddressCollection) {
            throw new \Exception('Order does not have an address collection');
        }

        if (!$existingOrder->getOrderCustomer() instanceof OrderCustomerEntity) {
            throw new \Exception('Order does not have an order customer assigned');
        }

        $newOrderId = Uuid::randomHex();

        $salesChannelContext = $this->orderConverter->assembleSalesChannelContext($existingOrder, $context);

        // we start by converting our existing order
        // into a cart. this one will be adjusted and later on converted into a new order
        $cart = $this->orderConverter->convertToCart($existingOrder, $context);

        // CUSTOM ADDITION FOR APPLYING SUBSCRIPTION DISCOUNT
        $mollieSubscriptionId = $existingOrder->getCustomFields()['mollie_payments']['swSubscriptionId'] ?? null;

        if (null !== $mollieSubscriptionId) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $mollieSubscriptionId));

            $ordersWithSameMollieId = $this->repoOrders->search($criteria, $context)->getEntities();

            // count all orders with the same mollie id
            $subscriptionOrderCount = count($ordersWithSameMollieId);

            // only for testing
            // $subscriptionOrderCount = 4;

            // get all subscription products
            $subscriptionProducts = $this->findSubscriptionProducts($cart);

            if ($subscriptionProducts->count() > 0) {
                // get the subscription discount percentage
                $subscriptionDiscount = $this->getSubscriptionDiscountPercentage($subscriptionOrderCount, $salesChannelContext->getSalesChannelId());

                // create a new line item for our discount
                $discountLineItem = $this->createDiscountLineItem($subscriptionDiscount);

                // declare price definition to define how this price is calculated
                $definition = new PercentagePriceDefinition(
                    $subscriptionDiscount['value'],
                    new LineItemRule(LineItemRule::OPERATOR_EQ, $subscriptionProducts->getKeys())
                );

                $discountLineItem->setPriceDefinition($definition);

                // calculate price
                $discountLineItem->setPrice(
                    $this->percentage_calculator->calculate($definition->getPercentage(), $subscriptionProducts->getPrices(), $salesChannelContext)
                );

                // add discount to new cart
                $cart->getLineItems()->add($discountLineItem);
            }
        }

        $behavior = new CartBehavior($salesChannelContext->getPermissions());

        // process our cart with the new discount
        $cart = $this->processor->process($cart, $salesChannelContext, $behavior);

        $conversionContext = new OrderConversionContext();
        $conversionContext->setIncludeCustomer(true);
        $conversionContext->setIncludeBillingAddress(true);
        $conversionContext->setIncludeDeliveries(true);
        $conversionContext->setIncludeTransactions(true);
        $conversionContext->setIncludeOrderDate(false);

        $orderData = $this->orderConverter->convertToOrder($cart, $salesChannelContext, $conversionContext);

        // if we only have 1 billing address, but we need a separate
        // shipping address, then we need to duplicate the existing one to
        // have a separate shipping address
        $duplicateShippingAddress = (count($existingOrder->getAddresses()) <= 1) && $needsSeparateShippingAddress;

        // -----------------------------------------------------------------
        // adjust the data so that it has new IDs and will be inserted again

        $orderData['id'] = $newOrderId;
        $orderData['orderNumber'] = $newOrderNumber;
        $orderData['orderDateTime'] = new \DateTime();

        $orderData['orderCustomer'] = $this->getOrderCustomer($existingOrder->getOrderCustomer());
        $orderData['addresses'] = $this->getOrderAddresses($existingOrder->getAddresses(), $duplicateShippingAddress);

        // only set our first transaction.
        // we don't need the history, but without this, we don't have any transaction at all
        $orderData['transactions'] = [
            $orderData['transactions'][0],
        ];

        // we need a lookup and mapping of old address IDs and new ones
        // our new order has new IDs. the order structure has some
        // references to address which already exist in the exiting order.
        // we need to create those same references with our new IDs.
        // so we just store the [oldID] = $newID
        $mappingsAddressIDs = [];

        foreach ($orderData['addresses'] as $index => $address) {
            $oldAddressId = $orderData['addresses'][$index]['id'];
            $newAddressId = Uuid::randomHex();

            // add our mapping for this address
            $mappingsAddressIDs[$oldAddressId] = $newAddressId;

            $orderData['addresses'][$index]['id'] = $newAddressId;
        }

        // reference our new billing address id
        // for our new order
        $oldBillingAddressID = $existingOrder->getBillingAddressId();
        $orderData['billingAddressId'] = $mappingsAddressIDs[$oldBillingAddressID];

        foreach ($orderData['lineItems'] as $index => $lineItem) {
            $orderData['lineItems'][$index]['id'] = Uuid::randomHex();
        }

        foreach ($orderData['deliveries'] as $index => $delivery) {
            $oldDeliveryId = $orderData['deliveries'][$index]['id'];
            $newDeliveryId = Uuid::randomHex();

            $orderData['deliveries'][$index]['id'] = $newDeliveryId;

            if ($existingOrder->getDeliveries() instanceof OrderDeliveryCollection) {
                /** @var OrderDeliveryEntity $orderDelivery */
                $orderDelivery = $existingOrder->getDeliveries()->get($oldDeliveryId);

                $orderData['deliveries'][$index]['shippingOrderAddressId'] = $mappingsAddressIDs[$orderDelivery->getShippingOrderAddressId()];

                // if we have duplicated our billing address as shipping address
                // then we use the second ID as shipping in our duplicated address
                if ($duplicateShippingAddress) {
                    $orderData['deliveries'][$index]['shippingOrderAddressId'] = $orderData['addresses'][1]['id'];
                }
            }
        }

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($orderData): void {
            $this->repoOrders->create([$orderData], $context);
        });

        return $newOrderId;
    }

    private function findSubscriptionProducts(Cart $cart): LineItemCollection
    {
        return $cart->getLineItems()->filter(function (LineItem $item) {
            // Only consider products, not custom line items or promotional line items
            if (LineItem::PRODUCT_LINE_ITEM_TYPE !== $item->getType()) {
                return false;
            }

            // $exampleInLabel = false !== stripos($item->getLabel(), 'example');
            if (null === $item->getPayloadValue('customFields')) {
                return false;
            }

            if (!array_key_exists('mollie_payments_product_subscription_enabled', $item->getPayloadValue('customFields'))) {
                return false;
            }

            $isMollieSubscriptionProduct = $item->getPayloadValue('customFields')['mollie_payments_product_subscription_enabled'];

            if (!$isMollieSubscriptionProduct) {
                return false;
            }

            return $item;
        });
    }

    private function getSubscriptionDiscountPercentage(int $subscriptionOrderCount, $salesChannelId): array
    {
        $discount = [
            'value' => 0.0,
            'label' => 'Abo Rabatt',
            'type' => 'promotion',
        ];

        $numberOfDiscountsToApplyField = $this->systemConfigService->get('MolliePayments.config.numberOfDiscounts', $salesChannelId);

        if ($subscriptionOrderCount > $numberOfDiscountsToApplyField) {
            $discountTakes = (int) $numberOfDiscountsToApplyField;
        } else {
            $discountTakes = $subscriptionOrderCount;
        }

        switch ($discountTakes) {
            case 1:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterFirstPaymentRate', $salesChannelId) * -1;

                break;

            case 2:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterSecondPaymentRate', $salesChannelId) * -1;

                break;

            case 3:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterThirdPaymentRate', $salesChannelId) * -1;

                break;

            case 4:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterFourthPaymentRate', $salesChannelId) * -1;

                break;

            case 5:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterFifthPaymentRate', $salesChannelId) * -1;

                break;

            case 6:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterSixthPaymentRate', $salesChannelId) * -1;

                break;

            case 7:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterSeventhPaymentRate', $salesChannelId) * -1;

                break;

            case 8:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterEighthPaymentRate', $salesChannelId) * -1;

                break;

            case 9:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterNinthPaymentRate', $salesChannelId) * -1;

                break;

            case 10:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterTenthPaymentRate', $salesChannelId) * -1;

                break;

            case 11:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterEleventhPaymentRate', $salesChannelId) * -1;

                break;

            case 12:
                $discount['value'] = (float) $this->systemConfigService->get('MolliePayments.config.afterTwelfthPaymentRate', $salesChannelId) * -1;

                break;

            default:
                $discount['value'] = (float) 0.0 * -1;

                break;
        }

        return $discount;
    }

    private function createDiscountLineItem(array $discount): LineItem
    {
        $discountLineItem = new LineItem($discount['type'], $discount['type'], null, 1);

        $discountLineItem->setLabel($discount['label']);
        $discountLineItem->setGood(false);
        $discountLineItem->setStackable(false);
        $discountLineItem->setRemovable(false);

        return $discountLineItem;
    }

    /**
     * @return array<mixed>
     */
    private function getOrderCustomer(OrderCustomerEntity $orderCustomer): array
    {
        return [
            'customerId' => $orderCustomer->getCustomerId(),
            'email' => $orderCustomer->getEmail(),
            'salutationId' => $orderCustomer->getSalutationId(),
            'firstName' => $orderCustomer->getFirstName(),
            'lastName' => $orderCustomer->getLastName(),
        ];
    }

    /**
     * @return array<mixed>
     */
    private function getOrderAddresses(OrderAddressCollection $addresses, bool $duplicateAddress): array
    {
        $addressData = [];

        foreach ($addresses as $address) {
            $data = [
                'id' => $address->getId(),
                'salutationId' => $address->getSalutationId(),
                'firstName' => $address->getFirstName(),
                'lastName' => $address->getLastName(),
                'street' => $address->getStreet(),
                'zipcode' => $address->getZipcode(),
                'city' => $address->getCity(),
                'company' => $address->getCompany(),
                'department' => $address->getDepartment(),
                'title' => $address->getTitle(),
                'vatId' => $address->getVatId(),
                'phoneNumber' => $address->getPhoneNumber(),
                'additionalAddressLine1' => $address->getAdditionalAddressLine1(),
                'additionalAddressLine2' => $address->getAdditionalAddressLine2(),
                'countryId' => $address->getCountryId(),
                'countryStateId' => $address->getCountryStateId(),
            ];

            $addressData[] = $data;

            if ($duplicateAddress) {
                $data['id'] = Uuid::randomHex();
                $addressData[] = $data;
            }
        }

        return $addressData;
    }
}
