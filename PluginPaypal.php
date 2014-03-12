<?php
require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/Currency.php';

/**
* @package Plugins
*/
class PluginPaypal extends GatewayPlugin
{
    function getVariables()
    {
        /* Specification
            itemkey     - used to identify variable in your other functions
            type          - text,textarea,yesno,password
            description - description of the variable, displayed in ClientExec
        */

        $variables = array (
            /*T*/"Plugin Name"/*/T*/ => array (
                                "type"          =>"hidden",
                                "description"   =>/*T*/"How CE sees this plugin (not to be confused with the Signup Name)"/*/T*/,
                                "value"         =>/*T*/"Paypal"/*/T*/
                                ),
            /*T*/"User ID"/*/T*/ => array (
                                 "type"          =>"text",
                                 "description"   =>/*T*/"The email used to identify you to PayPal.<br>NOTE: The email is required if you have selected PayPal as a payment gateway for any of your clients."/*/T*/,
                                 "value"         =>""
                                 ),
             /*T*/"Signup Name"/*/T*/ => array (
                                 "type"          =>"text",
                                 "description"   =>/*T*/"Select the name to display in the signup process for this payment type. Example: eCheck or Credit Card."/*/T*/,
                                 "value"         =>"Credit Card, eCheck, or Paypal"
                                 ),
            /*T*/"Generate Invoices After Callback Notification"/*/T*/ => array (
                                "type"          =>"hidden",
                                "description"   =>/*T*/"Select YES if you prefer CE to only generate invoices upon notification of payment via the callback supported by this processor.  Setting to NO will generate invoices normally but require you to manually mark them paid as you receive notification from processor."/*/T*/,
                                "value"         =>"1"
                                ),
            /*T*/"Invoice After Signup"/*/T*/ => array (
                                "type"          =>"yesno",
                                "description"   =>/*T*/"Select YES if you want an invoice sent to the customer after signup is complete."/*/T*/,
                                "value"         =>"1"
                                ),
            /*T*/"Use PayPal Sandbox"/*/T*/ => array(
                                "type"          =>"yesno",
                                "description"   =>/*T*/"Select YES if you want to use Paypal's testing server, so no actual monetary transactions are made. You need to have a developer account with Paypal, and be logged-in in the developer panel in another browser window for the transaction to be successful."/*/T*/,
                                "value"         =>"0"
            ),
            /*T*/'Paypal Subscriptions Option'/*/T*/=> array(
                                'type'          => 'options',
                                'description'   => /*T*/'Determine if you are going to use subscriptions for recurring charges.  Subscriptions are started after the initial payment is completed by customer.'/*/T*/,
                                'options'       => array(0 => /*T*/ 'Use subscriptions' /*/T*/,
                                                         1 => /*T*/'Do not use subscriptions'/*/T*/),
            ),
            /*T*/"Separate Taxes"/*/T*/ => array (
                                "type"          =>"yesno",
                                "description"   =>/*T*/"Select YES if you want to pass amount and taxes separated to this payment processor."/*/T*/,
                                "value"         =>"0"
            ),
           /*T*/"Check CVV2"/*/T*/ => array (
                                "type"          =>"hidden",
                                "description"   =>/*T*/"Select YES if you want to accept CVV2 for this plugin."/*/T*/,
                                "value"         =>"0"
            ),
            /*T*/"API Username"/*/T*/ => array (
                                 "type"          =>"text",
                                 "description"   =>/*T*/"Please enter your API Username"/*/T*/,
                                 "value"         =>""
             ),
            /*T*/"API Password"/*/T*/ => array (
                                 "type"          =>"text",
                                 "description"   =>/*T*/"Please enter your API Password"/*/T*/,
                                 "value"         =>""
             ),
            /*T*/"API Signature"/*/T*/ => array (
                                 "type"          =>"text",
                                 "description"   =>/*T*/"Please enter your API Signature"/*/T*/,
                                 "value"         =>""
             ),
        );
        return $variables;
    }

    function credit($params)
    {
        if ( $params['plugin_paypal_API Username'] == '' || $params['plugin_paypal_API Password'] == '' || $params['plugin_paypal_API Signature'] == '' ) {
            throw new CE_Exception('You must fill out the API Section of the PayPal configuration to do PayPal refunds.');
        }


        $transactionID = $params['invoiceRefundTransactionId'];
        $currency = urlencode($params['userCurrency']);
        $refundType = urlencode('Full');
        $memo = urlencode('Refund of Invoice #' . $params['invoiceNumber']);

        $requestString = "&TRANSACTIONID={$transactionID}&REFUNDTYPE={$refundType}&CURRENCYCODE={$currency}&NOTE={$memo}";

        $response = $this->sendRequest('RefundTransaction', $requestString, $params);
        if("SUCCESS" == strtoupper($response["ACK"]) || "SUCCESSWITHWARNING" == strtoupper($response["ACK"])) {
            return array('AMOUNT' => $params['invoiceTotal']);
        } else {
            CE_Lib::log(4, 'Error with PayPal Refund: ' . print_r($response, true));
            return 'Error with PayPal Refund.';
        }
    }

    private function sendRequest($methodName, $requestString, $params)
    {
        // Set up your API credentials, PayPal end point, and API version.
        $API_UserName = urlencode($params['plugin_paypal_API Username']);
        $API_Password = urlencode($params['plugin_paypal_API Password']);
        $API_Signature = urlencode($params['plugin_paypal_API Signature']);
        $API_Endpoint = "https://api-3t.paypal.com/nvp";
        if ( $params['plugin_paypal_Use PayPal Sandbox'] == '1' ) {
            $API_Endpoint = "https://api-3t.sandbox.paypal.com/nvp";
        }
        $version = urlencode('51.0');

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $API_Endpoint);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        // Set the API operation, version, and API signature in the request.
        $nvpreq = "METHOD={$methodName}&VERSION={$version}&PWD={$API_Password}&USER={$API_UserName}&SIGNATURE={$API_Signature}{$requestString}";

        // Set the request as a POST FIELD for curl.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

        // Get response from the server.
        $httpResponse = curl_exec($ch);

        if(!$httpResponse) {
            throw new CE_Exception("PayPal $methodName failed: ".curl_error($ch).'('.curl_errno($ch).')');
        }

        // Extract the response details.
        $httpResponseAr = explode("&", $httpResponse);

        $httpParsedResponseAr = array();
        foreach ($httpResponseAr as $i => $value) {
            $tmpAr = explode("=", $value);
            if(sizeof($tmpAr) > 1) {
                $httpParsedResponseAr[$tmpAr[0]] = $tmpAr[1];
            }
        }

        if((0 == sizeof($httpParsedResponseAr)) || !array_key_exists('ACK', $httpParsedResponseAr)) {
            throw new CE_Exception("Invalid HTTP Response for POST request($nvpreq) to $API_Endpoint.");
        }

        return $httpParsedResponseAr;
    }

    function singlepayment($params, $test = false)
    {
        $currency = new Currency($this->user);

        //Function needs to build the url to the payment processor, then redirect
        $stat_url = mb_substr($params['clientExecURL'],-1,1) == "//" ? $params['clientExecURL']."plugins/gateways/paypal/callback.php" : $params['clientExecURL']."/plugins/gateways/paypal/callback.php";
        if ($params['plugin_paypal_Use PayPal Sandbox'] == '1') {
            $paypal_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
        } else {
            $paypal_url = 'https://www.paypal.com/cgi-bin/webscr';
        }

        $strRet = "<html>\n";
        $strRet .= "<head></head>\n";
        $strRet .= "<body>\n";
        $strRet .= "<form name=frmPayPal action=\"$paypal_url\" method=\"post\">\n";
        $strRet .= "<input type=hidden name=\"business\" value=\"".$params["plugin_paypal_User ID"]."\">\n";

        //determine if this is a single payment
        // We will ignore the domain subscription. Billing has become complicated.
        // Good part is, the subcription will be created in the next payment, and using the renewal value
        if (!$params['billingCycle']){
            $params['usingRecurringPlugin']=0;
        }
        //echo "<pre>";print_r($params);echo "</pre>\n";

        $subscriptionsUsed = false;
        $initialTrial = true;

        // This var will be used to pass the excluded recurring fees ids for the subscription if any
        $tRecurringExclude = '';

        // don't use subscriptions if it's a package with a setup fee and no package fee
        if($params['usingRecurringPlugin'] == '1' && !($params['invoiceSetup'] && !$params['invoicePackageUnproratedFee']))
        {
            $billingCycle = $params['billingCycle'];

            // paypal only accepts two decimals
            $initialAmount = $currency->format($params['currencytype'], $params['invoiceTotal'], false);

            // if invoicePackageUnproratedFee is 0, it means this is not a package invoice, so the invoiceTotal will be the same charge always
            $tRecurringTotal = 0;

            if (!$params['invoicePackageUnproratedFee']) {
            //if (!$params['invoicePackageUnproratedFee'] || (!$params['isSignup'] && $params['sameCycleAmongEntries'])) {
                $tRecurringTotal = $currency->format($params['currencytype'], $params['invoiceTotal'], false);
            } else {
                $tRecurringTotal += $params['invoicePackageUnproratedFee'];
                $tRecurringTotal = $currency->format($params['currencytype'], $tRecurringTotal, false);

                if(count($params['invoiceExcludedRecurrings']) > 0){
                    $tRecurringExclude = '_'.implode(',', $params['invoiceExcludedRecurrings']);
                }
            }

            $todayDate = mktime(0, 0, 0);
            if ($params['invoiceProratingDays']) {
                // If prorating, next payment will be on the prorate day at least
                $today = date('d');
                $month = date('m');
                if ($today < $this->settings->get('Prorate To Day')) {
                   $tmpNextBill = mktime(0,0,0, $month, $this->settings->get('Prorate To Day'));
                } else {
                   $tmpNextBill = mktime(0,0,0,$month + 1, $this->settings->get('Prorate To Day'));
                }

                $includeFollowingPayment = $this->settings->get('Include Following Payment');
                // If including following payment is set as well, then move the next payment one billing cycle later
                if (    ($includeFollowingPayment == 0 && $params['invoiceProratingDays'] <= 10)
                        || ($includeFollowingPayment == 2 && $params['billingCycle'] != 1)
                        || ($includeFollowingPayment == 3 && $params['billingCycle'] == 1)
                        || ($includeFollowingPayment == 1))
                {

                    // use strtotime to take care of 30/31 days in a month and leap years
                    if ($params['billingCycle'] == 12) { // 12 months == 1 year
                       $tmpNextBill = strtotime('+1 year', $tmpNextBill);
                    } else if ($params['billingCycle'] == 24) { // 24 months == 2 years
                       $tmpNextBill = strtotime('+2 year', $tmpNextBill);
                    } else { // X month
                       $tmpNextBill = strtotime('+'.$params['billingCycle'].' month', $tmpNextBill);
                    }
                }

                // 86400 is the number of secs in a day
                $initialPeriodLength = floor(($tmpNextBill- $todayDate) / 86400);
                $initialPeriodUnits = 'D';
            } else {
                // special case: about to pay an invoice with a future due date
                // First we normalize the timestamps to midnight
                $dueDate = mktime(0, 0, 0, date('m', $params['invoiceDueDate']), date('d', $params['invoiceDueDate']), date('Y', $params['invoiceDueDate']));
                if ($todayDate < $dueDate) {
                    if ($billingCycle >= 12) { // 12 months == 1 year, 24 == 2 years
                        // In this case we can't create an initial trial period so that following payments are made on the due date,
                        // because that would imply a initial trial period longer than 90 days which is not allowed by Paypal.
                        // For example if client pays 5 days in advance we would have an initial trial period of 365+5 = 370
                        $initialTrial = false;
                        $initialPeriodLength = floor(($dueDate - $todayDate) / 86400);
                        $initialPeriodUnits = 'D';
                    } else { // X month
                       $dueDate = strtotime("+$billingCycle month", $dueDate);
                        $initialPeriodLength = floor(($dueDate - $todayDate) / 86400);
                        $initialPeriodUnits = 'D';
                    }
                } else {
                    $initialPeriodLength = $billingCycle;
                    $initialPeriodUnits = 'M';
                }
            }

            // Special case: using prorating and including following payment with billing cycles greater than monthly
            // implies a trial period of more than 90 days, which is not supported by paypal.
            // In this case we must fallback to not use subscriptions :(
            if ($initialPeriodLength <= 90) {
                $subscriptionsUsed = true;

                $strRet .= "<INPUT type=hidden name=\"cmd\" value=\"_xclick-subscriptions\">\n";

                // Trial Period 1 used for initial signup payment.
                // So we can include the total cost of Domain + Hosting + Setup.
                if ($initialTrial) {
                    $strRet .= "<input type=hidden name=\"a1\" value=\"$initialAmount\">\n";
                    $strRet .= "<input type=hidden name=\"p1\" value=\"".$initialPeriodLength."\">\n";
                    $strRet .= "<input type=hidden name=\"t1\" value=\"$initialPeriodUnits\">\n";
                }

                // Normal Billing cycle information including Recurring Payment (only the cost of service).
                $strRet .= "<input type=hidden name=\"a3\" value=\"".$tRecurringTotal."\">\n";
                $strRet .= "<input type=hidden name=\"p3\" value=\"$billingCycle\">\n";
                $strRet .= "<input type=hidden name=\"t3\" value=\"M\">\n";

                // Recurring and retry options. Set retry until Paypal's system gives up. And set recurring indefinately.)
                $strRet .= "<input type=hidden name=\"src\" value=\"1\">\n";
                $strRet .= "<input type=hidden name=\"sra\" value=\"1\">\n";

                $strRet .= "<input type=hidden name=\"item_name\" value=\"".$params["companyName"]." - Subscription\">\n";
            }

        }
        if (!$subscriptionsUsed) {
            $params['usingRecurringPlugin'] = 0;
            $strRet .= "<input type=hidden name=\"cmd\" value=\"_ext-enter\">\n";
            $strRet .= "<input type=hidden name=\"redirect_cmd\" value=\"_xclick\">\n";
            $strRet .= "<input type=hidden name=\"item_name\" value=\"".$params["companyName"]." Invoice\">\n";
            $strRet .= "<input type=hidden name=\"item_number\" value=\"".$params['invoiceNumber']."\">\n";

            if ($params['plugin_paypal_Separate Taxes'] == '1') {
                $amount = $currency->format($params['currencytype'], $params['invoiceRawAmount'] , false);
                $tax = $currency->format($params['currencytype'], $params['invoiceTaxes'] , false);
                $strRet .= "<INPUT type=hidden name=\"tax\" value=\"$tax\">\n";
            }else{
                $amount = $currency->format($params['currencytype'], $params['invoiceTotal'] , false);
            }
            $strRet .= "<INPUT type=hidden name=\"amount\" value=\"$amount\">\n";
        }

        //Need to check to see if user is coming from signup
        if ($params['isSignup']==1) {
            // Actually handle the signup URL setting
            if($this->settings->get('Signup Completion URL') != '') {

                $returnURL=$this->settings->get('Signup Completion URL'). '?success=1';
                $returnURL_Cancel=$this->settings->get('Signup Completion URL');
            }else{
                $returnURL=$params["clientExecURL"]."/order.php?step=complete&pass=1";
                $returnURL_Cancel=$params["clientExecURL"]."/order.php?step=3";
            }
        }else {
            $returnURL=$params["invoiceviewURLSuccess"];
            $returnURL_Cancel=$params["invoiceviewURLCancel"];
        }

        $strRet .= "<input type=hidden name=\"custom\" value=\"".$params['invoiceNumber']."_".$params['usingRecurringPlugin']."_".$params["plugin_paypal_Generate Invoices After Callback Notification"].$tRecurringExclude."\">\n";
        $strRet .= "<INPUT type=hidden name=\"return\" value=\"".$returnURL."\">\n";
		$strRet .= "<INPUT type=hidden name=\"rm\" value=\"2\">\n";
        $strRet .= "<input type=hidden name=\"cancel_return\" value=\"".$returnURL_Cancel."\">\n";
        $strRet .= "<input type=hidden name=\"notify_url\" value=\"".$stat_url."\">\n";
        $strRet .= "<INPUT type=hidden name=\"first_name\" value=\"".$params["userFirstName"]."\">\n";
        $strRet .= "<INPUT type=hidden name=\"last_name\" value=\"".$params["userLastName"]."\">\n";
        $strRet .= "<INPUT type=hidden name=\"address1\" value=\"".$params["userAddress"]."\">\n";
        $strRet .= "<INPUT type=hidden name=\"city\" value=\"".$params["userCity"]."\">\n";
        $strRet .= "<INPUT type=hidden name=\"state\" value=\"".$params["userState"]."\">\n";
        $strRet .= "<INPUT type=hidden name=\"zip\" value=\"".$params["userZipcode"]."\">\n";
        $strRet .= "<INPUT type=hidden name=\"no_shipping\" value=\"1\">\n";
        $strRet .= "<INPUT type=hidden name=\"no_note\" value=\"1\">\n";
        $strRet .= "<INPUT type=hidden name=\"currency_code\" value=\"".$params["currencytype"]."\">\n";
        //die($strRet);
        $strRet .= "<script language=\"JavaScript\">\n";
        $strRet .= "document.forms['frmPayPal'].submit();\n";
        $strRet .= "</script>\n";
        $strRet .= "</form>\n";
        $strRet .= "</body>\n</html>\n";

        if ($test) {
            return $strRet;
        } else {
            echo $strRet;
            exit;
        }
    }
}
?>
