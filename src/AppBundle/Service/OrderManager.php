<?php

namespace AppBundle\Service;

use AppBundle\Entity\Delivery;
use AppBundle\Entity\StripePayment;
use AppBundle\Entity\Task;
use AppBundle\Event\OrderCancelEvent;
use AppBundle\Event\OrderCreateEvent;
use AppBundle\Event\OrderAcceptEvent;
use AppBundle\Event\OrderFullfillEvent;
use AppBundle\Event\OrderReadyEvent;
use AppBundle\Event\OrderRefuseEvent;
use AppBundle\Event\PaymentAuthorizeEvent;
use AppBundle\Sylius\Order\OrderTransitions;
use AppBundle\Sylius\Order\OrderInterface;
use Doctrine\Common\Persistence\ManagerRegistry;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use Stripe;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class OrderManager
{
    private $doctrine;
    private $routing;
    private $stateMachineFactory;
    private $settingsManager;
    private $currencyContext;
    private $eventDispatcher;

    public function __construct(
        ManagerRegistry $doctrine,
        RoutingInterface $routing,
        StateMachineFactoryInterface $stateMachineFactory,
        SettingsManager $settingsManager,
        CurrencyContextInterface $currencyContext,
        EventDispatcherInterface $eventDispatcher)
    {
        $this->doctrine = $doctrine;
        $this->routing = $routing;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->settingsManager = $settingsManager;
        $this->currencyContext = $currencyContext;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function create(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_CREATE);
    }

    public function accept(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_ACCEPT);
    }

    public function refuse(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_REFUSE);
    }

    public function ready(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_READY);
    }

    public function fulfill(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_FULFILL);
    }

    public function cancel(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_CANCEL);
    }

    public function createDelivery(OrderInterface $order)
    {
        if (null !== $order->getDelivery()) {
            return;
        }

        $pickupAddress = $order->getRestaurant()->getAddress();
        $dropoffAddress = $order->getShippingAddress();

        $duration = $this->routing->getDuration(
            $pickupAddress->getGeo(),
            $dropoffAddress->getGeo()
        );

        $dropoffDoneBefore = $order->getShippedAt();

        $pickupDoneBefore = clone $dropoffDoneBefore;
        $pickupDoneBefore->modify(sprintf('-%d seconds', $duration));

        $delivery = new Delivery();

        $pickup = $delivery->getPickup();
        $pickup->setAddress($pickupAddress);
        $pickup->setDoneBefore($pickupDoneBefore);

        $dropoff = $delivery->getDropoff();
        $dropoff->setAddress($dropoffAddress);
        $dropoff->setDoneBefore($dropoffDoneBefore);

        $order->setDelivery($delivery);
    }

    public function authorizePayment(OrderInterface $order)
    {
        $stripePayment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        $stripeToken = $stripePayment->getStripeToken();


        if (null === $stripeToken) {
            return;
        }

        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));
        $stateMachine = $this->stateMachineFactory->get($stripePayment, PaymentTransitions::GRAPH);
        $stripeAccount = $order->getRestaurant()->getStripeAccount();
        $restaurantPaysStripeFee = $order->getRestaurant()->getContract()->isRestaurantPaysStripeFee();

        $applicationFee = $order->getFeeTotal();

        $stripeParams = array(
            'amount' => $order->getTotal(),
            'currency' => strtolower($stripePayment->getCurrencyCode()),
            'source' => $stripeToken,
            'description' => sprintf('Order %s', $order->getNumber()),
            // To authorize a payment without capturing it,
            // make a charge request that also includes the capture parameter with a value of false.
            // This instructs Stripe to only authorize the amount on the customer’s card.
            'capture' => false
        );

        $stripeOptions = array();

        if (!is_null($stripeAccount)) {

            if($restaurantPaysStripeFee) {
                // needed only when using direct charges (the charge is linked to the restaurant's Stripe account)
                $stripePayment->setStripeUserId($stripeAccount->getStripeUserId());
                $stripeOptions['stripe_account'] = $stripeAccount->getStripeUserId();
                $stripeParams['application_fee'] = $applicationFee;
            } else {
                $stripeParams['destination'] = array(
                    'account' => $stripeAccount->getStripeUserId(),
                    'amount' => $order->getTotal() - $applicationFee
                );
            }
        }

        try {

            $charge = Stripe\Charge::create(
                $stripeParams,
                $stripeOptions
            );

            $stripePayment->setCharge($charge->id);

            $stateMachine->apply('authorize');

        } catch (\Exception $e) {
            $stripePayment->setLastError($e->getMessage());
            $stateMachine->apply(PaymentTransitions::TRANSITION_FAIL);
        }
    }

    public function capturePayment(OrderInterface $order)
    {
        $stripePayment = $order->getLastPayment(PaymentInterface::STATE_AUTHORIZED);

        if (null === $stripePayment) {
            return;
        }

        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));

        $stateMachine = $this->stateMachineFactory->get($stripePayment, PaymentTransitions::GRAPH);

        $stripeAccount = $stripePayment->getStripeUserId();
        $stripeOptions = array();

        // stripe account & needed is set if and only the Stripe charge is a direct charge (restaurant pays stripe fee)
        if (!is_null($stripeAccount)) {
            $stripeOptions['stripe_account'] = $stripeAccount;
        }

        try {

            $charge = Stripe\Charge::retrieve(
                $stripePayment->getCharge(),
                $stripeOptions
            );

            if ($charge->captured) {
                throw new \Exception('Charge already captured');
            }

            $charge->capture();

            $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);

        } catch (\Exception $e) {
            $stripePayment->setLastError($e->getMessage());
            $stateMachine->apply(PaymentTransitions::TRANSITION_FAIL);
        }
     }

    /**
     * Create a fresh payment after payment failure
     *
     * @param OrderInterface $order
     */
    public function afterPaymentFailed(OrderInterface $order) {

        if ($order->getTotal() === 0) {
            return;
        }

        $payment = new StripePayment();
        $payment->setOrder($order);
        $payment->setAmount($order->getTotal());
        $payment->setCurrencyCode($this->currencyContext->getCurrencyCode());

        $order->addPayment($payment);
    }

    public function completePayment(PaymentInterface $payment)
    {
        $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
        $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);
    }

    public function dispatchOrderEvent(OrderInterface $order, $eventName)
    {
        switch ($eventName) {
            case OrderCancelEvent::NAME:
                $this->eventDispatcher->dispatch(OrderCancelEvent::NAME, new OrderCancelEvent($order));
                break;
            case OrderCreateEvent::NAME:
                $this->eventDispatcher->dispatch(OrderCreateEvent::NAME, new OrderCreateEvent($order));
                break;
            case OrderRefuseEvent::NAME:
                $this->eventDispatcher->dispatch(OrderRefuseEvent::NAME, new OrderRefuseEvent($order));
                break;
            case OrderAcceptEvent::NAME:
                $this->eventDispatcher->dispatch(OrderAcceptEvent::NAME, new OrderAcceptEvent($order));
                break;
            case OrderReadyEvent::NAME:
                $this->eventDispatcher->dispatch(OrderReadyEvent::NAME, new OrderReadyEvent($order));
                break;
            case OrderFullfillEvent::NAME:
                $this->eventDispatcher->dispatch(OrderFullfillEvent::NAME, new OrderFullfillEvent($order));
                break;
        }
    }

    public function dispatchPaymentEvent(PaymentInterface $payment, $eventName)
    {
        switch ($eventName) {
            case PaymentAuthorizeEvent::NAME:
                $this->eventDispatcher->dispatch(PaymentAuthorizeEvent::NAME, new PaymentAuthorizeEvent($payment));
                break;
        }
    }
}
