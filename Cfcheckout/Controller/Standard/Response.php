<?php

namespace Cashfree\Cfcheckout\Controller\Standard;

use Cashfree\Cfcheckout\Model\Cfcheckout;
use Magento\Framework\Controller\ResultFactory;

class Response extends \Cashfree\Cfcheckout\Controller\CfAbstract {

    protected $quote;

    protected $checkoutSession;

    protected $customerSession;

    protected $cache;

    protected $orderRepository;

    protected $invoiceService;
    
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Cashfree\Cfcheckout\Model\Cfcheckout $paymentMethod,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Cashfree\Cfcheckout\Helper\Cfcheckout $checkoutHelper,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManagement,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepo,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
    ) {
        $this->logger           = $logger;
        $this->_customerSession = $customerSession;
        $this->quoteRepository  = $quoteRepository;
        $this->_paymentMethod   = $paymentMethod;
        $this->quoteManagement  = $quoteManagement;
        $this->storeManagement  = $storeManagement;
        $this->customerRepo     = $customerRepo;
        $this->customerFactory  = $customerFactory;
        $this->orderRepository = $orderRepository;
        $this->objectManagement = \Magento\Framework\App\ObjectManager::getInstance();
        
        parent::__construct(
            $cache,
            $order,
            $context,
            $orderFactory,
            $customerSession,
            $checkoutSession,
            $paymentMethod,
            $quoteManagement,
            $checkoutHelper,
            $storeManagement,
            $resultJsonFactory
        );
    }

    /**
     * Get related order by quote
     */
    protected function getCurrentOrder($quote)
    {
        $quoteId = $quote->getId();
        # fetch the related sales order
        # To avoid duplicate order entry for same quote
        $collection = $this->objectManagement->get('Magento\Sales\Model\Order')
                                            ->getCollection()
                                            ->addFieldToSelect('entity_id')
                                            ->addFilter('quote_id', $quoteId)
                                            ->getFirstItem();
        $salesOrder = $collection->getData();
        
        if (empty($salesOrder['entity_id']) === true) {
            $order = $this->quoteManagement->submit($quote);
        } else {
            $order = $this->orderRepository->get($salesOrder['entity_id']);
        }
        return $order;
    }
    
    /**
     * execute
     *
     * @return void
     */
    public function execute() {
        $returnUrl = $this->getCheckoutHelper()->getUrl('checkout');
        $params = $this->getRequest()->getParams();
        $quoteId = strip_tags($params["orderId"]);
        list($quoteId) = explode('_', $quoteId);
        $quote = $this->getQuoteObject($params, $quoteId);
        if (!$this->getCustomerSession()->isLoggedIn()) {
            $customerId = $quote->getCustomer()->getId();
            if(!empty($customerId)) {
                $customer = $this->customerFactory->create()->load($customerId);
                $this->_customerSession->setCustomerAsLoggedIn($customer);
            }
        }
        try {
            $paymentMethod = $this->getPaymentMethod();
            $status = $paymentMethod->validateResponse($params);
            $debugLog = "";
            if ($status == "SUCCESS") {
                # fetch the related sales order
                # To avoid duplicate order entry for same quote
                $collection = $this->objectManagement->get('Magento\Sales\Model\Order')
                                                   ->getCollection()
                                                   ->addFieldToSelect('entity_id')
                                                   ->addFilter('quote_id', $quoteId)
                                                   ->getFirstItem();
                $salesOrder = $collection->getData();
               
                if (empty($salesOrder['entity_id']) === true) {
                    $order = $this->quoteManagement->submit($quote);
                    $payment = $order->getPayment();        
                
                    $paymentMethod->postProcessing($order, $payment, $params);
                } else {
                    $order = $this->orderRepository->get($salesOrder['entity_id']);
                }
                $this->_checkoutSession
                            ->setLastQuoteId($quote->getId())
                            ->setLastSuccessQuoteId($quote->getId())
                            ->clearHelperData();

                if ($order) {
                    $this->_checkoutSession->setLastOrderId($order->getId())
                                        ->setLastRealOrderId($order->getIncrementId())
                                        ->setLastOrderStatus($order->getStatus());


                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_response_success',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }
              
                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/onepage/success');
                $this->messageManager->addSuccess(__('Your payment was successful'));
                $debugLog = "Order status changes to processing for quote id: ".$quoteId;

            } else if ($status == "CANCELLED") {
                $order = $this->getCurrentOrder($quote);
                $quote->setIsActive(1)->setReservedOrderId(null)->save();
                $this->_checkoutSession->replaceQuote($quote);
                $this->messageManager->addError($params['txMsg']);
                $debugLog = "Order status changes to cancelled for quote id: ".$quoteId;
                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/cart');
                if ($order) {
                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_cancelled',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'quote' => $quote,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }
            } else if ($status == "FAILED") {
                $order = $this->getCurrentOrder($quote);
                $quote->setIsActive(1)->setReservedOrderId(null)->save();
                $this->_checkoutSession->replaceQuote($quote);
                $this->messageManager->addError($params['txMsg']);
                $debugLog = "Order status changes to falied for quote id: ".$quoteId;
                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/cart');
                if ($order) {
                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_failed',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'quote' => $quote,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }
            } else if($status == "PENDING"){
                $order = $this->getCurrentOrder($quote);
                $debugLog = "Order status changes to pending for quote id: ".$quoteId;
                $this->messageManager->addWarning(__('Your payment is pending'));
                if ($order) {
                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_pending',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'quote' => $quote,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }
            } else{
                $order = $this->getCurrentOrder($quote);
                $debugLog = "Order status changes to pending for quote id: ".$quoteId;
                $this->messageManager->addErrorMessage(__('There is an error.Payment status is pending'));
                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/onepage/failure');
                if ($order) {
                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_response',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'quote' => $quote,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }
            }

            $enabledDebug = $paymentMethod->enabledDebugLog();
            if($enabledDebug === "1"){
                $this->logger->info($debugLog);
            }
              
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t place the order.'));
        }

        $this->getResponse()->setRedirect($returnUrl);
    }
    
    /**
     * Get quote object
     *
     * @param  mixed $params
     * @param  mixed $quoteId
     * @return void
     */
    protected function getQuoteObject($params, $quoteId)
    {
        $quote = $this->quoteRepository->get($quoteId);

        $firstName = $quote->getBillingAddress()->getFirstname() ?? 'null';
        $lastName  = $quote->getBillingAddress()->getLastname() ?? 'null';
        $email     = $quote->getBillingAddress()->getEmail() ?? 'null';

        $quote->getPayment()->setMethod(Cfcheckout::PAYMENT_CFCHECKOUT_CODE);

        $store = $quote->getStore();

        if(empty($store) === true)
        {
            $store = $this->storeManagement->getStore();
        }

        $websiteId = $store->getWebsiteId();

        $customer = $this->objectManagement->create('Magento\Customer\Model\Customer');
        
        $customer->setWebsiteId($websiteId);

        //get customer from quote , otherwise from payment email
        $customer = $customer->loadByEmail($email);
        
        //if quote billing address doesn't contains address, set it as customer default billing address
        if ((empty($quote->getBillingAddress()->getFirstname()) === true) and
            (empty($customer->getEntityId()) === false))
        {   
            $quote->getBillingAddress()->setCustomerAddressId($customer->getDefaultBillingAddress()['id']);
        }

        //If need to insert new customer as guest
        if ((empty($customer->getEntityId()) === true) or
            (empty($quote->getBillingAddress()->getCustomerId()) === true))
        {
            $quote->setCustomerFirstname($firstName);
            $quote->setCustomerLastname($lastName);
            $quote->setCustomerEmail($email);
            $quote->setCustomerIsGuest(true);
        }

        $quote->setStore($store);

        $quote->collectTotals();

        $quote->save();

        return $quote;
    }

}