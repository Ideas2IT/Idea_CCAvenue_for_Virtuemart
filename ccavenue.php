<?php
defined('_JEXEC') or die();

/**
 * CCAvenue.ch - Payment-Plugin for VirtueMart 2
 *
 * @author Lokesh 
 * @version 1.0
 * @package VirtueMart
 * @subpackage payment
 *
 */

if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentCCAvenue extends vmPSPlugin {

	// instance of class
	public static $_this = false;
	protected $version	= '1.0';

	function __construct(& $subject, $config) {
		parent::__construct($subject, $config);
		
		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id'; 
		$this->_tableId = 'id'; 
		
		//Basic CCAvenue Account Settings
		$varsToPush = array(
			'ccavenue_merchant_id' => array('', 'int'),
			'ccavenue_testmode' => array('', 'int'),
			'ccavenue_security_level' => array(0, 'int'),
			'ccavenue_security_token' => array('', 'char'),
			'payment_logos' => array('', 'char'),
			'payment_currency' => array('', 'int'),
			'debug' => array(0, 'int'),
			'status_pending' => array('', 'char'),
			'status_success' => array('', 'char'),
			'status_canceled' => array('', 'char'),
			'countries' => array('', 'char'),
			'min_amount' => array('', 'int'),
			'max_amount' => array('', 'int'),
			'no_shipping' => array('', 'int'),
			'cost_per_transaction' => array('', 'int'),
			'cost_percent_total' => array('', 'int'),
			'tax_id' => array(0, 'int')
		);

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}
	
	//Create Database tabe for CCAvenue to capture result from payment gateway
	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment CCAvenue Table');
	}
	
	//Get the CCAvenue Payment table columns
	function getTableSQLFields() {
		$SQLfields = array(
			'id' => ' INT(11) unsigned NOT NULL AUTO_INCREMENT ',
			'virtuemart_order_id' => ' int(1) UNSIGNED DEFAULT NULL',
			'order_number' => ' char(32) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => ' mediumint(1) UNSIGNED DEFAULT NULL',
			'payment_name' => 'varchar(5000)',
			'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
			'payment_currency' => 'char(3) ',
			'ccavenue_response_Merchant_Id' => 'int ',
			'ccavenue_custom' => ' varchar(255)  ',
			'ccavenue_response_Merchant_Param' => ' varchar(255)  ',
			'ccavenue_response_CheckSum' => ' char(50) DEFAULT NULL',
			'ccavenue_response_AuthDecs' => 'char(50) ',
			'ccavenue_response_nb_bid' => 'char(50) ',
			'ccavenue_response_nb_order_no' => 'char(50) ',
			'ccavenue_response_card_category' => 'char(50) ',
			'ccavenue_response_bank_name' => 'char(50) ',
			'ccavenue_response_bankRespCode' => 'char(50) ',
			'ccavenue_response_bankRespMsg' => 'char(50) ',
		);
		return $SQLfields;
	}

	//This function makes required information which is used to connect with
	//CCAvenue payment gateway
	//$cart - This contains the list of items in the cart to purchase
	//$order - This contains the order information required for payment gateway.
	function plgVmConfirmedOrder($cart, $order) {
	
		//The below lines can be uncomment for logging purpose.
		//$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!'); //Its create log file which tell whether this function has been called for execution or not.
		//$this->dump($cart, 'cart'); //Logs the cart details
		//$this->dump($order, 'order'); //Logs the order details
		//$this->dumpMessage("Payment Started");
		
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		//Get the current session
		$session = JFactory::getSession();
		$return_context = $session->getId();
		$this->_debug = $method->debug;
		//$this->dumpMessage('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message'); //Log order Number for transaction

		if (!class_exists('VirtueMartModelOrders'))
		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		if (!class_exists('VirtueMartModelCurrency'))
		require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');

		$new_status = '';
		$usrBT = $order['details']['BT'];
		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']); //User Address - if shipping address is there for user then shipping address is selected else billing address is selected.

		if (!class_exists('TableVendors'))
		require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		$vendorModel = VmModel::getModel('Vendor');
		$vendorModel->setId(1);
		$vendor = $vendorModel->getVendor();
		$vendorModel->addImages($vendor, 1);
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
		$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
		
		// prepare the post var values:
		$order_number		= $order['details']['BT']->order_number;
		$total_sum_to_pay 	= $order['details']['BT']->order_total;
		$shop_uri 			= JROUTE::_(JURI::root() . 'index.php');
		$url 				= "https://www.ccavenue.com/shopzone/cc_details.jsp";
		$Redirect_Url       = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
		$WorkingKey 		= $method->ccavenue_security_token;
		$Merchant_Id 		= $method->ccavenue_merchant_id;
		
		//Log for order number and total amount
		$this->dumpMessage('Order No: '.$order_number.' - Total Amount: '.$total_sum_to_pay);
		
		//create checksum for payment gateway
		$Checksum 			= $this->getCheckSum($Merchant_Id,round($total_sum_to_pay,2),$order_number,$Redirect_Url,$WorkingKey); 
		$firstname 			= $address->first_name;
		$lastname 			= $address->last_name;
		$name				= $firstname." ".$lastname;
		$address1 			= $address->address_1;
		$address2 			= $address->address_2;
		$address 			= $address1." ".$address2;
		$q = 'SELECT `country_3_code` FROM `#__virtuemart_countries` WHERE `virtuemart_country_id`="'.$order['details']['BT']->virtuemart_country_id.'"';
		$db->setQuery($q);
		$country = $db->loadResult();
		$q = 'SELECT `state_3_code` FROM `#__virtuemart_states` WHERE `virtuemart_state_id`="'.$order['details']['BT']->virtuemart_state_id.'"';
		$db->setQuery($q);
		$state = $db->loadResult();
		$city 				= $order['details']['BT']->city;
		$zip  				= $order['details']['BT']->zip;
		$telephone  		= $order['details']['BT']->phone_1;
		$email 				= $order['details']['BT']->email;
		$notes 				= "";
		$mparam				= "";

		$post_variables = Array(
		"Merchant_Id" 			=> $Merchant_Id,
		"Amount" 				=> round( $total_sum_to_pay, 2),    
		"Order_Id" 				=> $order_number,
		"Redirect_Url" 			=> $Redirect_Url,
		"Checksum" 				=> $Checksum,
		"billing_cust_name"		=> $name,
		"billing_cust_address"  => $address,
		"billing_cust_country"  => $country,
		"billing_cust_state"    => $state,
		"billing_cust_city"		=> $city,
		"billing_zip"			=> $zip,
		"billing_cust_tel"		=> $telephone,
		"billing_cust_email"	=> $email,
		"delivery_cust_name"	=> $name,
		"delivery_cust_address"	=> $address,
		"delivery_cust_country"	=> $country,
		"delivery_cust_state"	=> $state,
		"delivery_cust_tel"		=> $telephone,
		"delivery_cust_notes"	=> $notes,
		"Merchant_Param"		=> $mparam,
		"billing_zip_code"		=> $zip,
		"delivery_cust_city"	=> $city,
		"delivery_zip_code"		=> $zip
		);
		
		// Prepare data that should be stored in the database
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['ccavenue_custom'] = $return_context;
		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_currency'] = $method->payment_currency;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency;
		$dbValues['tax_id'] = $method->tax_id;
		
		//Store basic internal data to CCAvenue table like Orer number, Pyment Name, Payment Id(Virtuemart), etc.,
		$this->storePSPluginInternalData($dbValues);
		
		// Lokesh - Custom html display for redirection
		$html = '<html><head><meta http-equiv="content-type" content="text/html; charset=utf-8" /><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
		$html .= '<p>Please wait while redirecting to CCAVENUE.</p>';
		$html .= '<p>If you are not redirected under 10 seconds, please click the button below.</p>';
		$html .= '<form action="' . $url . '" method="post" name="vm_ccavenue_form" id="vm_ccavenue_form">';
		$html .= '<input type="submit"  value="' . JText::_('VMPAYMENT_CCAVENUE_REDIRECT_MESSAGE') . '" />';
		foreach ($post_variables as $name => $value) {
			$html.= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '" />';
		}
		$html .= '</form></div>';
		$html .= ' <script type="text/javascript">';
		$html .= ' setTimeout(document.vm_ccavenue_form.submit(),500);';
		$html .= ' </script></body></html>';*/
		
		$cart->_confirmDone = FALSE;
		$cart->_dataValidated = FALSE;
		$cart->setCartIntoSession ();
		JRequest::setVar ('html', $html); // Output the html for redirection
	}

	//Get payment currency
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
	}
	
	//Process Payment Gateway response
	function plgVmOnPaymentResponseReceived(&$html) {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$order_number = JRequest::getVar('on', 0);
		$vendorId = 0;
		$app = JFactory::getApplication();

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		if (!class_exists('VirtueMartCart'))
		require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		if (!class_exists('shopFunctionsF'))
		require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		if (!class_exists('VirtueMartModelOrders'))
		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );

		$ccavenue_data = JRequest::get('post');
		//$this->dump($ccavenue_data, 'Data received');
		$payment_name = $this->renderPluginName($method);
		
		//Log response from payment gateway
		$this->dump($ccavenue_data);
		$WorkingKey = $method->ccavenue_security_token;
    	$billing_cust_name = $ccavenue_data['billing_cust_name'];
    	$Merchant_Id = $ccavenue_data['Merchant_Id'];
    	$Amount = $ccavenue_data['Amount'];
		$Order_Id = $ccavenue_data['Order_Id'];
		$Merchant_Param = $ccavenue_data['Merchant_Param'];
		$Checksum = $ccavenue_data['Checksum'];
		$AuthDesc = $ccavenue_data['AuthDesc'];
		$emailid = $ccavenue_data['billing_cust_email'];
        
        //Verify Checksum
        $Checksum = $this->verifychecksum($Merchant_Id, $Order_Id , $Amount,$AuthDesc,$Checksum,$WorkingKey);
   		$this->dumpMessage('Checksum : '. $Checksum);
   		$order_number = $Order_Id;
   		
   		if (!class_exists('VirtueMartModelOrders'))
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		
    	if(($Checksum=="true" && $AuthDesc=="Y")||($Checksum=="true" && $AuthDesc=="B")){
    		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
			$payment_name = $this->renderPluginName($method);		
			if ($virtuemart_order_id) {
				$order['customer_notified']=1;
				$order['order_status'] = $this->_getPaymentStatus($method, $ccavenue_data['AuthDesc']);
				$order['comments'] = JText::sprintf('VMPAYMENT_CCAVENUE_PAYMENT_STATUS_CONFIRMED', $order_number);
				// send the email ONLY if payment has been accepted
				$modelOrder = VmModel::getModel('orders');
				$orderitems = $modelOrder->getOrder($virtuemart_order_id);
				$nb_history = count($orderitems['history']);
				if ($orderitems['history'][$nb_history - 1]->order_status_code != $order['order_status']) {
					$this ->_storeCCAvenueInternalData($method, $ccavenue_data, $virtuemart_order_id);
					$this->logInfo('plgVmOnPaymentResponseReceived, sentOrderConfirmedEmail ' . $order_number, 'message');
					$order['virtuemart_order_id'] = $virtuemart_order_id;
					$order['comments'] = JText::sprintf('VMPAYMENT_CCAVENUE_EMAIL_SENT');
					$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
				}else{
					$this ->_storeCCAvenueInternalData($method, $ccavenue_data, $virtuemart_order_id);
					$this->logInfo('plgVmOnPaymentResponseReceived, sentOrderConfirmedEmail ' . $order_number, 'message');
					$order['virtuemart_order_id'] = $virtuemart_order_id;
					$order['comments'] = JText::sprintf('VMPAYMENT_CCAVENUE_SUCCESS_CCAVENUE_RESPONSE');
					$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
				}
			}else{
					vmError('CCAvenue data received, but no order number');
					return;
			}		    
		}else if($Checksum=="true" && $AuthDesc=="N"){
			$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
			$payment_name = $this->renderPluginName($method);
			$order['customer_notified']=1;
			$order['virtuemart_order_id'] = $virtuemart_order_id;
			$order['order_status'] = $this->_getPaymentStatus($method, $ccavenue_data['AuthDesc']);
			$order['comments'] = JText::sprintf('VMPAYMENT_CCAVENUE_ERROR_CCAVENUE_RESPONSE');
			$this ->_storeCCAvenueInternalData($method, $ccavenue_data, $virtuemart_order_id);
			$modelOrder = VmModel::getModel('orders');
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
			$msg = JText::_('VMPAYMENT_CCAVENUE_ERROR_CCAVENUE_RESPONSE');
			$app->enqueueMessage($msg, 'error');
			return;
		}
		$this->dumpMessage("Payment Completed");
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		if (!($paymentTable = $this->_getCCAvenueInternalData($virtuemart_order_id, $order_number) )) {
			return '';
		}
		$html = $this->_getPaymentResponseHtml($paymentTable, $payment_name);
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		return true;
	}

	//On User Payment Cancel Process - Lokesh **Have to check Not using**
	function plgVmOnUserPaymentCancel() {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		if (!class_exists('VirtueMartModelOrders'))
		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		$order_number = JRequest::getVar('on');
		if (!$order_number)
		return false;
		$db = JFactory::getDBO();
		$query = 'SELECT ' . $this->_tablename . '.`virtuemart_order_id` FROM ' . $this->_tablename . " WHERE  `order_number`= '" . $order_number . "'";

		$db->setQuery($query);
		$virtuemart_order_id = $db->loadResult();

		if (!$virtuemart_order_id) {
			return null;
		}
		$this->handlePaymentUserCancel($virtuemart_order_id);
		return true;
	}

	//Payment Notification Process
	function plgVmOnPaymentNotification() {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		if (!class_exists('VirtueMartModelOrders'))
		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		$ccavenue_data = JRequest::get('post');
		if (!isset($ccavenue_data['Order_Id'])) {
			return;
		}
		$order_number = $ccavenue_data['Order_Id'];
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		if (!$virtuemart_order_id) {
			return;
		}
		$vendorId = 0;
		$payment = $this->getDataByOrderId($virtuemart_order_id);
		$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->_debug = $method->debug;
		if (!$payment) {
			$this->logInfo('getDataByOrderId payment not found: exit ', 'ERROR');
			return null;
		}
		$this->logInfo('ccavenue_data ' . implode('   ', $ccavenue_data), 'message');
		$this->_storeCCAvenueInternalData($method, $ccavenue_data, $virtuemart_order_id);
		$new_status = $this->_getPaymentStatus($method, $ccavenue_data['AuthDesc']);
		$this->dumpMessage("Payment Notification Completed");
		$modelOrder = VmModel::getModel('orders');
		$order = array();
		$order['order_status'] = $new_status;
		$order['customer_notified'] =1;
		$order['comments'] = JText::sprintf('VMPAYMENT_CCAVENUE_PAYMENT_STATUS_CONFIRMED', $order_number);
		$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
		$this->logInfo('Notification, sentOrderConfirmedEmail ' . $order_number . ' ' . $new_status, 'message');
		$this->emptyCart($return_context);
	}

	//Store Payment table internal data
	function _storeCCAvenueInternalData($method, $ccavenue_data, $virtuemart_order_id) {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		// get all know columns of the table
		$db = JFactory::getDBO();
		$query = 'SHOW COLUMNS FROM `' . $this->_tablename . '` ';
		$db->setQuery($query);
		$columns = $db->loadResultArray(0);
		$post_msg = '';
		foreach ($ccavenue_data as $key => $value) {
			$post_msg .= $key . "=" . $value . "<br />";
			$table_key = 'ccavenue_response_' . $key;
			if (in_array($table_key, $columns)) {
				$response_fields[$table_key] = $value;
			}
		}

		$response_fields[$this->_tablepkey] = $this->_getTablepkeyValue($virtuemart_order_id);
		$response_fields['payment_name'] = $this->renderPluginName($method);
		$response_fields['ccavenueresponse_raw'] = $post_msg;
		$return_context = $ccavenue_data['Notes'];
		$response_fields['order_number'] = $ccavenue_data['Order_Id'];
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
		$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);
	}

	function _getTablepkeyValue($virtuemart_order_id) {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		$db = JFactory::getDBO();
		$q = 'SELECT ' . $this->_tablepkey . ' FROM `' . $this->_tablename . '` '
		. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);

		if (!($pkey = $db->loadResult())) {
			JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		return $pkey;
	}

	function _getPaymentStatus($method, $status) {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		$new_status = '';
		if (strcmp($status, 'Y') == 0) {
			$new_status = $method->status_success;
		} elseif (strcmp($status, 'B') == 0) {
			$new_status = $method->status_pending;
		} else {
			$new_status = $method->status_canceled;
		}
		$this->dumpMessage($status);
		$this->dumpMessage($new_status);
		return $new_status;
	}

	/**
	 * Display stored payment data for an order
	 * Lokesh - Template after getting response to show customer
	 * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
	 */
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		if (!$this->selectedThisByMethodId($payment_method_id)) {
			return null; // Another method was selected, do nothing
		}

		if (!($paymentTable = $this->_getCCAvenueInternalData($virtuemart_order_id) )) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}

		$this->getPaymentCurrency($paymentTable);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $paymentTable->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('CCAVENUE_PAYMENT_NAME', $paymentTable->payment_name);
		//$html .= $this->getHtmlRowBE('CCAVENUE_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total.' '.$currency_code_3);
		$code = "ccavenue_response_";
		foreach ($paymentTable as $key => $value) {
			if (substr($key, 0, strlen($code)) == $code) {
				$html .= $this->getHtmlRowBE($key, $value);
			}
		}
		$html .= '</table>' . "\n";
		return $html;
	}

	function _getCCAvenueInternalData($virtuemart_order_id, $order_number='') {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($order_number) {
			$q .= " `order_number` = '" . $order_number . "'";
		} else {
			$q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}

		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		return $paymentTable;
	}


	function _getPaymentResponseHtml($ccavenueTable, $payment_name) {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('CCAVENUE_PAYMENT_NAME', $payment_name);
		if (!empty($ccavenueTable)) {
			$html .= $this->getHtmlRow('CCAVENUE_ORDER_NUMBER', $ccavenueTable->order_number);
			$html .= $this->getHtmlRow('CCAVENUE_AMOUNT', $ccavenueTable->payment_order_total. " " . $ccavenueTable->payment_currency);
		}
		$html .= '</table>' . "\n";

		return $html;
	}

	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		$this->dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		if (preg_match('/%$/', $method->cost_percent_total)) {
			$cost_percent_total = substr($method->cost_percent_total, 0, -1);
		} else {
			$cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
	}

	/**
	 * Check if the payment conditions are fulfilled for this payment method
	 * @author: Valerie Isaksen
	 *
	 * @param $cart_prices: cart prices
	 * @param $payment
	 * @return true: if the conditions are fulfilled, false otherwise
	 *
	 */
	protected function checkConditions($cart, $method, $cart_prices) {
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
		OR
		($method->min_amount <= $amount AND ($method->max_amount == 0) ));
		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array($method->countries)) {
				$countries[0] = $method->countries;
			} else {

				$countries = $method->countries;
			}
		}
		// probably did not gave his BT:ST address
		if (!is_array($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id']))
			$address['virtuemart_country_id'] = 0;
		if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
			if ($amount_cond) {
				return true;
			}
		}
		return false;
	}
	
	/**
	 * Lokesh - Not in Use
	 */
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	/**
	 * Lokesh - Not in Use
	 */
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		return $this->OnSelectCheck($cart);
	}

	/**
	 * Lokesh - Not in Use
	 */
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	/**
	 * Lokesh - Not in Use
	 */
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * Lokesh - Not in Use
	 */
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
	}
	
	/**
	 * Lokesh - Not in Use
	 */
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	/**
	 * Lokesh - Not in Use
	 */
	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
	}
	
	/**
	 * Lokesh - Not in Use
	 */
	function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		return $this->declarePluginParams('payment', $name, $id, $data);
	}
	
	/**
	 * Lokesh - Want to check
	 */
	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
	}
	
	/**
	 * Lokesh - Not in Use
	 */
	protected function getLanguageTag(){
		// available CCAvenue GUI-Languages
		$langArray = array('de', 'en', 'fr','it', 'es', 'el', 'no', 'da');
		//$language = JLanguageHelper::detectLanguage();
		$language =& JFactory::getLanguage();
		$tag = strtolower(substr($language->get('tag'), 0,2));
		if (in_array($tag, $langArray))
		{
			return $tag;
		}
		return 'en';
	}
	
    function hexstr($hex){
       // translate byte array to hex string
       $string="";
       for ($i=0;$i<strlen($hex)-1;$i+=2)
           $string.=chr(hexdec($hex[$i].$hex[$i+1]));
       return $string;
    }

    function hmac ($key, $data){
       // RFC 2104 HMAC implementation for php.
       // Creates an md5 HMAC.
       // Eliminates the need to install mhash to compute a HMAC

       $b = 64; // byte length for md5
       if (strlen($key) > $b) {
           $key = pack("H*",md5($key));
       }
       $key  = str_pad($key, $b, chr(0x00));
       $ipad = str_pad('', $b, chr(0x36));
       $opad = str_pad('', $b, chr(0x5c));
       $k_ipad = $key ^ $ipad ;
       $k_opad = $key ^ $opad;
       return md5($k_opad  . pack("H*",md5($k_ipad . $data)));
    }

    function sign($key, $merchId, $amount, $ccy, $idno){
	   	// emulates the hash_hmac (PHP 5 >= 5.1.2, PECL hash >= 1.1 requiered)
    	$str=$merchId.$amount.$ccy.$idno;
    	$key2=$this->hexstr($key);
    	return $this->hmac($key2, $str);
    }
    
    //Log function
    function dumpMessage($text, $type="message"){
		$date = JFactory::getDate ();
		$file = JPATH_ROOT . "/logs/Payment_log/CCAvenue" . $date->toFormat ('%Y-%m-%d') . ".log";
		$fp = fopen ($file, 'a');
		fwrite ($fp, "\n" . $date->toFormat ('%Y-%m-%d %H:%M:%S'));
		fwrite ($fp, "\t\t" . $type . ': ' . $text);
		fclose ($fp);
    }
    
    //Log function
    function dump($object, $text){
    	$date = JFactory::getDate ();
		$file = JPATH_ROOT . "/logs/Payment_log/CCAvenue" . $date->toFormat ('%Y-%m-%d') . ".log";
		$fp = fopen ($file, 'a');
		fwrite ($fp, "\n\n" . $date->toFormat ('%Y-%m-%d %H:%M:%S'));
		fwrite ($fp, "\n" . print_r($object) . ': ' . $text);
		fclose ($fp);
    }
    
    function getCheckSum($MerchantId,$Amount,$OrderId ,$URL,$WorkingKey){
		$str = "$MerchantId|$OrderId|$Amount|$URL|$WorkingKey";
		$adler = 1;
		$adler = $this->adler32($adler,$str);
		return $adler;
	}

	function verifychecksum($MerchantId,$OrderId,$Amount,$AuthDesc,$CheckSum,$WorkingKey){
		$str = "$MerchantId|$OrderId|$Amount|$AuthDesc|$WorkingKey";
		$adler = 1;
		$adler = $this->adler32($adler,$str);
		if($adler == $CheckSum)
			return "true" ;
		else
			return "false" ;
	}
	
	function adler32($adler , $str){
		$BASE = 65521;
		$s1 = $adler & 0xffff ;
		$s2 = ($adler >> 16) & 0xffff;
		for($i = 0 ; $i < strlen($str) ; $i++){
			$s1 = ($s1 + Ord($str[$i])) % $BASE ;
			$s2 = ($s2 + $s1) % $BASE ;
		}
		return $this->leftshift($s2 , 16) + $s1;
	}

	function leftshift($str , $num){
		$str = DecBin($str);
		for( $i = 0 ; $i < (64 - strlen($str)) ; $i++)
			$str = "0".$str ;
		for($i = 0 ; $i < $num ; $i++){
			$str = $str."0";
			$str = substr($str , 1 ) ;
		}
		return $this->cdec($str) ;
	}

	function cdec($num){
		for ($n = 0 ; $n < strlen($num) ; $n++){
			$temp = $num[$n] ;
			$dec = $dec + $temp*pow(2 , strlen($num) - $n - 1);
		}
		return $dec;
	}
}

// No closing tag
	
