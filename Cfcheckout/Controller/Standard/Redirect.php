<?php

namespace Cashfree\Cfcheckout\Controller\Standard;

class Redirect extends \Cashfree\Cfcheckout\Controller\CfAbstract {
    
    /**
     * update quote to redirect payment page
     *
     * @return void
     */
    public function execute() {
        if (!$this->getRequest()->isAjax()) {
            $this->_cancelPayment();
            $order = $this->_checkoutSession->getLastRealOrder();
            if ($order && $order->getId()) {
                $this->_eventManager->dispatch(
                    'cfcheckout_controller_standard_redirect',
                    [
                        'order' => $order,
                        'request' => $this
                    ]
                );
            }

            $this->_checkoutSession->restoreQuote();
            $this->getResponse()->setRedirect(
                    $this->getCheckoutHelper()->getUrl('checkout')
            );
        }

        $quote = $this->getQuote();
        $email = $this->getRequest()->getParam('email');
        if ($this->getCustomerSession()->isLoggedIn()) {
            $this->getCheckoutSession()->loadCustomerQuote();
            $quote->updateCustomerData($this->getQuote()->getCustomer());
        } else {
            $quote->setCustomerEmail($email);
        }

        if ($this->getCustomerSession()->isLoggedIn()) {
            $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER);
        } else {
            $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST);
        }

        $quote->setCustomerEmail($email);
        $quote->save();

      
        $params = [];
        $params["fields"] = $this->getPaymentMethod()->buildCheckoutRequest();
        $params["url"] = $this->getPaymentMethod()->getCgiUrl();

        return $this->resultJsonFactory->create()->setData($params);
    }

}
