<?php
class PMTECH_Splitorder_Model_Checkout_Type_Onepage extends Mage_Checkout_Model_Type_Onepage
{
	/**
     * Create order based on checkout type. Create customer if necessary.
     *
     * @return Mage_Checkout_Model_Type_Onepage
     */
    public function saveOrder()
    {
        $this->validate();
        $isNewCustomer = false;
        switch ($this->getCheckoutMethod()) {
            case self::METHOD_GUEST:
                $this->_prepareGuestQuote();
                break;
            case self::METHOD_REGISTER:
                $this->_prepareNewCustomerQuote();
                $isNewCustomer = true;
                break;
            default:
                $this->_prepareCustomerQuote();
                break;
        }
         



        $cart = $this->getQuote();
		$key=0;
      foreach ($cart->getAllItems() as $item) 
      {
        $key= $key+1;
        $temparray[$key]['product_id']=  $item->getProduct()->getId();
        $temparray[$key]['qty']= $item->getQty();
        $cart->removeItem($item->getId());
        $cart->setSubtotal(0);
        $cart->setBaseSubtotal(0);

        $cart->setSubtotalWithDiscount(0);
        $cart->setBaseSubtotalWithDiscount(0);

        $cart->setGrandTotal(0);
        $cart->setBaseGrandTotal(0);
        
        $cart->setTotalsCollectedFlag(false);
        $cart->collectTotals();
        }
         $cart->save();
       
        foreach ($temparray as $key => $item) 
        {
        $customer_id = Mage::getSingleton('customer/session')->getId();

        $store_id = Mage::app()->getStore()->getId();
        $customerObj = Mage::getModel('customer/customer')->load($customer_id);
        $quoteObj = $cart;
        $storeObj = $quoteObj->getStore()->load($store_id);
        $quoteObj->setStore($storeObj);
        $productModel = Mage::getModel('catalog/product');
        $productObj = $productModel->load($item['product_id']);
        $quoteItem = Mage::getModel('sales/quote_item')->setProduct($productObj);
        $quoteItem->setBasePrice($productObj->getFinalPrice());
        $quoteItem->setPriceInclTax($productObj->getFinalPrice());
        $quoteItem->setData('original_price', $productObj->getPrice());
        $quoteItem->setData('price', $productObj->getPrice());
        $quoteItem->setRowTotal($productObj->getFinalPrice());
        $quoteItem->setQuote($quoteObj);
        $quoteItem->setQty($item['qty']);
        $quoteItem->setStoreId($store_id);
        $quoteObj->addItem($quoteItem);

        $quoteObj->setBaseSubtotal($productObj->getFinalPrice());
        $quoteObj->setSubtotal($productObj->getFinalPrice());
        $quoteObj->setBaseGrandTotal($productObj->getFinalPrice());
        $quoteObj->setGrandTotal($productObj->getFinalPrice());
        
        $quoteObj->setStoreId($store_id);
        $quoteObj->collectTotals();
        $quoteObj->save();
        $this->_quote=$quoteObj;
         
        $service = Mage::getModel('sales/service_quote', $quoteObj);
        $service->submitAll();

        if ($isNewCustomer) {
            try {
                $this->_involveNewCustomer();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        $this->_checkoutSession->setLastQuoteId($quoteObj->getId())
            ->setLastSuccessQuoteId($quoteObj->getId())
            ->clearHelperData();

        $order = $service->getOrder();
        if ($order) {
            Mage::dispatchEvent('checkout_type_onepage_save_order_after',
                array('order'=>$order, 'quote'=>$quoteObj));
            $quoteObj->removeAllItems();
            $quoteObj->setTotalsCollectedFlag(false);
            $quoteObj->collectTotals();


}






            /**
             * a flag to set that there will be redirect to third party after confirmation
             * eg: paypal standard ipn
             */
            $redirectUrl = $this->getQuote()->getPayment()->getOrderPlaceRedirectUrl();
            /**
             * we only want to send to customer about new order when there is no redirect to third party
             */
            if (!$redirectUrl && $order->getCanSendNewEmailFlag()) {
                try {
                    $order->sendNewOrderEmail();
                } catch (Exception $e) {
                    Mage::logException($e);
                }
            }

            // add order information to the session
            $this->_checkoutSession->setLastOrderId($order->getId())
                ->setRedirectUrl($redirectUrl)
                ->setLastRealOrderId($order->getIncrementId());

            // as well a billing agreement can be created
            $agreement = $order->getPayment()->getBillingAgreement();
            if ($agreement) {
                $this->_checkoutSession->setLastBillingAgreementId($agreement->getId());
            }
        }

        // add recurring profiles information to the session
        $profiles = $service->getRecurringPaymentProfiles();
        if ($profiles) {
            $ids = array();
            foreach ($profiles as $profile) {
                $ids[] = $profile->getId();
            }
            $this->_checkoutSession->setLastRecurringProfileIds($ids);
            // TODO: send recurring profile emails
        }

        Mage::dispatchEvent(
            'checkout_submit_all_after',
            array('order' => $order, 'quote' => $this->getQuote(), 'recurring_profiles' => $profiles)
        );

        return $this;
    }

}
		