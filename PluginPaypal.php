<?php
require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Currency.php';
require_once 'modules/billing/models/BillingCycle.php';

/**
* @package Plugins
*/
class PluginPaypal extends GatewayPlugin
{
    function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array(
                'type'        => 'hidden',
                'description' => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                'value'       => lang('Paypal')
            ),
            lang('User ID') => array(
                'type'        => 'text',
                'description' => lang('The email used to identify you to PayPal.<br>NOTE: The email is required if you have selected PayPal as a payment gateway for any of your clients.'),
                'value'       => ''
            ),
            lang('Signup Name') => array(
                'type'        => 'text',
                'description' => lang('Select the name to display in the signup process for this payment type. Example: eCheck or Credit Card.'),
                'value'       => 'Credit Card, eCheck, or Paypal'
            ),
            lang('Invoice After Signup') => array(
                'type'        => 'yesno',
                'description' => lang('Select YES if you want an invoice sent to the client after signup is complete.'),
                'value'       => '1'
            ),
            lang('Use PayPal Sandbox') => array(
                'type'        => 'yesno',
                'description' => lang('Select YES if you want to use Paypal\'s testing server, so no actual monetary transactions are made. You need to have a developer account with Paypal, and be logged-in in the developer panel in another browser window for the transaction to be successful.'),
                'value'       => '0'
            ),
            lang('Paypal Subscriptions Option')=> array(
                'type'        => 'options',
                'description' => lang('Determine if you are going to use subscriptions for recurring charges.<ul><li>Please avoid using <b>"Duration in months"</b> in your recurring fees if you are planning to use Paypal Subscriptions. The subscription will be unlimited until manually canceled either by you, your client or Paypal if lack of funds, etc.</li><li>Subscriptions will be created after a payment is completed by the client, as long as the invoice was paid before becoming overdue.</li><li>Subscriptions will not be created if the invoice has prorated items, or with billing cycles greater than 5 years (Old API), or with billing cycles greater than 1 year (New API).</li></ul>'),
                'options'     => array(
                    0 => lang('Use subscriptions'),
                    1 => lang('Do not use subscriptions')
                )
            ),
            lang('Page Style') => array(
                'type'        => 'text',
                'description' => lang('Please enter the name of the page style you would like displayed'),
                'value'       => ''
            ),
            lang('Separate Taxes') => array(
                'type'        => 'yesno',
                'description' => lang('Select YES if you want to pass amount and taxes separated to this payment processor.'),
                'value'       => '0'
            ),
            lang('Check CVV2') => array(
            'type'        => 'hidden',
            'description' => lang('Select YES if you want to accept CVV2 for this plugin.'),
            'value'       => '0'
            ),

            lang('API Username') => array(
                'type'        => 'text',
                'description' => lang('Please enter your API Username.<br><b>Required to do refunds or cancel subscriptions.</b>'),
                'value'       => ''
            ),
            lang('API Password') => array(
                'type'        => 'text',
                'description' => lang('Please enter your API Password.<br><b>Required to do refunds or cancel subscriptions.</b>'),
                'value'       => ''
            ),
            lang('API Signature') => array(
                'type'        => 'text',
                'description' => lang('Please enter your API Signature.<br><b>Required to do refunds or cancel subscriptions.</b>'),
                'value'       => ''
            ),

            lang('API Client ID') => array(
                'type'        => 'text',
                'description' => lang('Please enter your API Client ID.<br><b>Required to use the new API.</b><br>To get this value, you need to login to <a href="https://developer.paypal.com" target="_blank">https://developer.paypal.com</a> using your live PayPal account credentials and then follow the instructions below:<ul><li>Go to Dashboard > My Apps & Credentials</li><li>Go to REST API Apps > Create App</li><li>Enter a name for the app and generate an app</li><li>Once the App is generated, click on the App > Select "Live" mode and then you will see the live "Client ID" and "Secret"</li></ul><b>NOTE:</b> Make sure to create one app per each Clientexec installation you use, or it could lead to issues.'),
                'value'       => ''
            ),
            lang('Form') => array(
                'type'        => 'hidden',
                'description' => lang('Has a form to be loaded?  1 = YES, 0 = NO<br><b>Required to use the new API.</b>'),
                'value'       => '1'
            ),

            lang('API Secret') => array(
                'type'        => 'text',
                'description' => lang('Please enter your API Secret.<br><b>Required to do subscriptions using the new API.</b><br>To get this value, you need to login to <a href="https://developer.paypal.com" target="_blank">https://developer.paypal.com</a> using your live PayPal account credentials and then follow the instructions below:<ul><li>Go to Dashboard > My Apps & Credentials</li><li>Go to REST API Apps > Create App</li><li>Enter a name for the app and generate an app</li><li>Once the App is generated, click on the App > Select "Live" mode and then you will see the live "Client ID" and "Secret"</li></ul><b>NOTE:</b> Make sure to create one app per each Clientexec installation you use, or it could lead to issues.'),
                'value'       => ''
            ),
        );

        return $variables;
    }

    function cancelSubscription($params)
    {
        //NEW API CODE
        if ($params['plugin_paypal_API Client ID'] != '') {
            return $this->newAPIcancelSubscription($params);
        }
        //NEW API CODE

        return $this->oldAPIcancelSubscription($params);
    }

    function oldAPIcancelSubscription($params)
    {
        if ($params['plugin_paypal_API Username'] == '' || $params['plugin_paypal_API Password'] == '' || $params['plugin_paypal_API Signature'] == '') {
            throw new CE_Exception('You must fill out the API Section of the PayPal configuration to Cancel Paypal Subscriptions.');
        }

        $subscriptionId = urlencode($params['subscriptionId']);
        $memo = urlencode('Cancelled due to client requesting cancellation of package');
        $requestString = "&PROFILEID={$subscriptionId}&ACTION=Cancel&NOTE={$memo}";
        $response = $this->sendRequest('ManageRecurringPaymentsProfileStatus ', $requestString, $params);

        //If it has an invalid status, assume it was already canceled
        //https://developer.paypal.com/docs/nvp-soap-api/errors/
        if (isset($response['L_ERRORCODE0']) && in_array($response['L_ERRORCODE0'], array(11556))) {
            $this->removeSubscriptionReference($subscriptionId);
            return;
        }

        //If "The profile ID is invalid" or "Merchant account is denied" and not using the new API, ask to configure it
        //https://developer.paypal.com/docs/nvp-soap-api/errors/
        if (isset($response['L_ERRORCODE0']) && in_array($response['L_ERRORCODE0'], array(11552, 11546)) && $params['plugin_paypal_API Client ID'] == '') {
            $errorMessage = 'Error with PayPal Cancel Subscription. Please take a look at your Paypal plugin configuration and set valid values for "API Client ID" and "API Secret", then try canceling again.';
            CE_Lib::log(4, $errorMessage);
            return $errorMessage;
        }

        if (!in_array(strtoupper($response["ACK"]), array('SUCCESS', 'SUCCESSWITHWARNING'))) {
            $errorMessage = urldecode($response['L_LONGMESSAGE0']);
            CE_Lib::log(4, 'Error with PayPal Cancel Subscription: ' . print_r($response, true));
            return $errorMessage;
        }
    }

    function removeSubscriptionReference($subscription_id)
    {
        $response = "%Started paypal subscription. Subscription ID: " . $subscription_id . "%";
        $query = "SELECT `invoiceid` FROM `invoicetransaction` WHERE `response` LIKE ? ";
        $result = $this->db->query($query, $response);
        $row = $result->fetch();

        if ($row) {
            $tInvoiceID = $row['invoiceid'];
            $tRecurringExclude = '';

            // Create Plugin class object to interact with CE.
            $cPlugin = new Plugin($tInvoiceID, 'paypal', $this->user);

            CE_Lib::log(4, "Subscription has been cancelled.");
            $tUser = new User($cPlugin->m_Invoice->m_UserID);

            if (in_array($tUser->getStatus(), StatusAliasGateway::getInstance($this->user)->getUserStatusIdsFor(USER_STATUS_CANCELLED))) {
                CE_Lib::log(4, 'User is already cancelled. Ignore callback.');
            } else {
                $subject = 'Gateway recurring payment cancelled';
                $message = "Recurring payment for invoice $tInvoiceID has been cancelled.";
                // If createTicket returns false it's because this transaction has already been done
                if (!$cPlugin->createTicket($subscription_id, $subject, $message, $tUser)) {
                    exit;
                }
            }

            $transaction = "Paypal subscription cancelled. Original Signup Invoice: $tInvoiceID";
            $old_processorid = '';
            $cPlugin->resetRecurring($transaction, $subscription_id, $tRecurringExclude, $tInvoiceID, $old_processorid);
        } else {
            $query2 = "UPDATE recurringfee SET subscription_id = '', disablegenerate = 0 WHERE subscription_id = ? ";
            $this->db->query($query2, $subscription_id);

            $query3 = "UPDATE invoice SET subscription_id = '' WHERE status IN (0, 4) AND subscription_id = ? ";
            $this->db->query($query3, $subscription_id);
        }
    }

    function credit($params)
    {
        //NEW API CODE
        if ($params['plugin_paypal_API Client ID'] != '') {
            $this->newAPIcredit($params);
            return;
        }
        //NEW API CODE

        if ($params['plugin_paypal_API Username'] == '' || $params['plugin_paypal_API Password'] == '' || $params['plugin_paypal_API Signature'] == '') {
            throw new CE_Exception('You must fill out the API Section of the PayPal configuration to do PayPal refunds.');
        }

        $transactionID = $params['invoiceRefundTransactionId'];
        $currency = urlencode($params['userCurrency']);
        $refundType = urlencode('Full');
        $memo = urlencode('Refund of Invoice #' . $params['invoiceNumber']);

        $requestString = "&TRANSACTIONID={$transactionID}&REFUNDTYPE={$refundType}&CURRENCYCODE={$currency}&NOTE={$memo}";

        $response = $this->sendRequest('RefundTransaction', $requestString, $params);
        if ("SUCCESS" == strtoupper($response["ACK"]) || "SUCCESSWITHWARNING" == strtoupper($response["ACK"])) {
            return array('AMOUNT' => $params['invoiceTotal']);
        } else {
            $errorMessage = urldecode($response['L_LONGMESSAGE0']);
            CE_Lib::log(4, 'Error with PayPal Refund: ' . print_r($response, true));
            return 'Error with PayPal Refund: ' . $errorMessage;
        }
    }

    private function sendRequest($methodName, $requestString, $params)
    {
        // Set up your API credentials, PayPal end point, and API version.
        $API_UserName = urlencode($params['plugin_paypal_API Username']);
        $API_Password = urlencode($params['plugin_paypal_API Password']);
        $API_Signature = urlencode($params['plugin_paypal_API Signature']);
        $API_Endpoint = "https://api-3t.paypal.com/nvp";
        if ($params['plugin_paypal_Use PayPal Sandbox'] == '1') {
            $API_Endpoint = "https://api-3t.sandbox.paypal.com/nvp";
        }
        $version = urlencode('51.0');

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $API_Endpoint);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        // Set the API operation, version, and API signature in the request.
        $nvpreq = "METHOD={$methodName}&VERSION={$version}&PWD={$API_Password}&USER={$API_UserName}&SIGNATURE={$API_Signature}{$requestString}";

        // Set the request as a POST FIELD for curl.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

        // Get response from the server.
        $httpResponse = curl_exec($ch);

        if (!$httpResponse) {
            throw new CE_Exception("PayPal $methodName failed: ".curl_error($ch).'('.curl_errno($ch).')');
        }

        // Extract the response details.
        $httpResponseAr = explode("&", $httpResponse);

        $httpParsedResponseAr = array();
        foreach ($httpResponseAr as $i => $value) {
            $tmpAr = explode("=", $value);
            if (count($tmpAr) > 1) {
                $httpParsedResponseAr[$tmpAr[0]] = $tmpAr[1];
            }
        }

        if ((0 == count($httpParsedResponseAr)) || !array_key_exists('ACK', $httpParsedResponseAr)) {
            throw new CE_Exception("Invalid HTTP Response for POST request($nvpreq) to $API_Endpoint.");
        }

        return $httpParsedResponseAr;
    }

    function singlepayment($params, $test = false)
    {
        $billingCycleObject = new BillingCycle($params['billingCycle']);

        //NEW API CODE
        if ($params['plugin_paypal_API Client ID'] != '') {
            $subscriptionsUsed = false;

            // use subscriptions only if has package fee
            if ($params['usingRecurringPlugin'] == '1' && isset($params['invoicePackageUnproratedFee'])) {
                // If prorating, avoid creating a subscription
                // If period is greather than 5 years (60 months), avoid creating a subscription as Paypal does not support it
                //     Paypal currently only support subscriptions up to 5 Years
                //         https://developer.paypal.com/docs/classic/paypal-payments-standard/integration-guide/Appx_websitestandard_htmlvariables/
                //             Allowable values for t1 and t3 are:
                //                 D – for days;   allowable range for p1 and p3 is 1 to 90
                //                 W - for weeks;  allowable range for p1 and p3 is 1 to 52
                //                 M – for months; allowable range for p1 and p3 is 1 to 24
                //                 Y – for years;  allowable range for p1 and p3 is 1 to 5
                // However, in the new API, for frequency_interval, value cannot be greater than 12 months.
                if ($params['plugin_paypal_API Secret'] != '' && !$params['invoiceProratingDays'] && $billingCycleObject->amount_of_units > 0
                  && (($billingCycleObject->time_unit == 'd' && $billingCycleObject->amount_of_units <= 90)
                   || ($billingCycleObject->time_unit == 'w' && $billingCycleObject->amount_of_units <= 52)
                   || ($billingCycleObject->time_unit == 'm' && $billingCycleObject->amount_of_units <= 12)
                   || ($billingCycleObject->time_unit == 'y' && $billingCycleObject->amount_of_units <= 1))) {
                    $subscriptionsUsed = true;
                }

                if ($subscriptionsUsed) {
                    CE_Lib::log(4, 'Paypal will try to create a new subscription using the new API');
                    if ($test) {
                        return $this->newAPIcreateSubscription($params);
                    } else {
                        echo $this->newAPIcreateSubscription($params);
                        exit;
                    }
                }
            }

            if (!$subscriptionsUsed) {
                CE_Lib::log(4, 'Paypal will try to charge a new payment using the new API');
                $this->newAPIsinglePayment($params);
                exit;
            }
        }
        //NEW API CODE

        $currency = new Currency($this->user);

        //Function needs to build the url to the payment processor, then redirect
        $stat_url = mb_substr($params['clientExecURL'], -1, 1) == "//" ? $params['clientExecURL']."plugins/gateways/paypal/callback.php" : $params['clientExecURL']."/plugins/gateways/paypal/callback.php";
        if ($params['plugin_paypal_Use PayPal Sandbox'] == '1') {
            $paypal_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
        } else {
            $paypal_url = 'https://www.paypal.com/cgi-bin/webscr';
        }

        $strRet = "<html>\n";
        $strRet .= "<head></head>\n";
        $strRet .= "<body>\n";
        $strRet .= "<form name=frmPayPal action=\"$paypal_url\" method=\"post\">\n";
        $strRet .= "<input type=hidden name=\"business\" value=\"".trim($params["plugin_paypal_User ID"])."\">\n";

        //determine if this is a single payment
        // We will ignore the domain subscription. Billing has become complicated.
        // Good part is, the subcription will be created in the next payment, and using the renewal value
        if ($billingCycleObject->amount_of_units == 0) {
            $params['usingRecurringPlugin'] = 0;
        }
        //echo "<pre>";print_r($params);echo "</pre>\n";

        $subscriptionsUsed = false;

        // This var will be used to pass the excluded recurring fees ids for the subscription if any
        $tRecurringExclude = '';

        // use subscriptions only if has package fee
        if ($params['usingRecurringPlugin'] == '1' && isset($params['invoicePackageUnproratedFee'])) {
            // paypal only accepts two decimals
            $initialAmount = sprintf("%01.2f", round($params['invoiceTotal'], 2));

            // if invoicePackageUnproratedFee is 0, it means this is not a package invoice, so the invoiceTotal will be the same charge always
            $tRecurringTotal = 0;

            if (!$params['invoicePackageUnproratedFee']) {
                $tRecurringTotal = $params['invoiceTotal'];
            } else {
                $tRecurringTotal = $params['invoicePackageUnproratedFee'];

                if (count($params['invoiceExcludedRecurrings']) > 0) {
                    $tRecurringExclude = '_'.implode(',', $params['invoiceExcludedRecurrings']);
                }
            }

            $tRecurringTotal = sprintf("%01.2f", round($tRecurringTotal, 2));

            // If prorating, avoid creating a subscription
            // If period is greather than 5 years (60 months), avoid creating a subscription as Paypal does not support it
            //     Paypal currently only support subscriptions up to 5 Years
            //         https://developer.paypal.com/docs/classic/paypal-payments-standard/integration-guide/Appx_websitestandard_htmlvariables/
            //             Allowable values for t1 and t3 are:
            //                 D – for days;   allowable range for p1 and p3 is 1 to 90
            //                 W - for weeks;  allowable range for p1 and p3 is 1 to 52
            //                 M – for months; allowable range for p1 and p3 is 1 to 24
            //                 Y – for years;  allowable range for p1 and p3 is 1 to 5

            if (!$params['invoiceProratingDays'] && $billingCycleObject->amount_of_units > 0
              && (($billingCycleObject->time_unit == 'd' && $billingCycleObject->amount_of_units <= 90)
               || ($billingCycleObject->time_unit == 'w' && $billingCycleObject->amount_of_units <= 52)
               || ($billingCycleObject->time_unit == 'm' && $billingCycleObject->amount_of_units <= 24)
               || ($billingCycleObject->time_unit == 'y' && $billingCycleObject->amount_of_units <= 5))) {
                // First we normalize the timestamps to midnight
                $dueDate = mktime(0, 0, 0, date('m', $params['invoiceDueDate']), date('d', $params['invoiceDueDate']), date('Y', $params['invoiceDueDate']));

                switch ($billingCycleObject->time_unit) {
                    case 'd':
                        //D – for days; allowable range for p1 and p3 is 1 to 90
                        $initialPeriodLength = $billingCycleObject->amount_of_units;
                        $initialPeriodUnits = 'D';
                        $subscriptionsUsed = true;
                        break;
                    case 'w':
                        //W - for weeks; allowable range for p1 and p3 is 1 to 52
                        $initialPeriodLength = $billingCycleObject->amount_of_units;
                        $initialPeriodUnits = 'W';
                        $subscriptionsUsed = true;
                        break;
                    case 'm':
                        //M – for months; allowable range for p1 and p3 is 1 to 24
                        $initialPeriodLength = $billingCycleObject->amount_of_units;
                        $initialPeriodUnits = 'M';
                        $subscriptionsUsed = true;
                        break;
                    case 'y':
                        //Y – for years; allowable range for p1 and p3 is 1 to 5
                        $initialPeriodLength = $billingCycleObject->amount_of_units;
                        $initialPeriodUnits = 'Y';
                        $subscriptionsUsed = true;
                        break;
                }
            }

            if ($subscriptionsUsed) {
                $strRet .= "<input type=hidden name=\"cmd\" value=\"_xclick-subscriptions\">\n";

                // Trial Period 1 used for initial signup payment.
                // So we can include the total cost of Domain + Hosting + Setup.
                $strRet .= "<input type=hidden name=\"a1\" value=\"$initialAmount\">\n";
                $strRet .= "<input type=hidden name=\"p1\" value=\"".$initialPeriodLength."\">\n";
                $strRet .= "<input type=hidden name=\"t1\" value=\"$initialPeriodUnits\">\n";

                // Normal Billing cycle information including Recurring Payment (only the cost of service).
                $strRet .= "<input type=hidden name=\"a3\" value=\"".$tRecurringTotal."\">\n";
                $strRet .= "<input type=hidden name=\"p3\" value=\"$initialPeriodLength\">\n";
                $strRet .= "<input type=hidden name=\"t3\" value=\"$initialPeriodUnits\">\n";

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
                $amount = sprintf("%01.2f", round($params['invoiceRawAmount'], 2));
                $tax = sprintf("%01.2f", round($params['invoiceTaxes'], 2));
                $strRet .= "<input type=hidden name=\"tax\" value=\"$tax\">\n";
            } else {
                $amount = sprintf("%01.2f", round($params['invoiceTotal'], 2));
            }
            $strRet .= "<input type=hidden name=\"amount\" value=\"$amount\">\n";
        }

        //Need to check to see if user is coming from signup
        if ($params['isSignup']==1) {
            // Actually handle the signup URL setting
            if ($this->settings->get('Signup Completion URL') != '') {
                $returnURL=$this->settings->get('Signup Completion URL'). '?success=1';
                $returnURL_Cancel=$this->settings->get('Signup Completion URL');
            } else {
                $returnURL=$params["clientExecURL"]."/order.php?step=complete&pass=1";
                $returnURL_Cancel=$params["clientExecURL"]."/order.php?step=3";
            }
        } else {
            $returnURL=$params["invoiceviewURLSuccess"];
            $returnURL_Cancel=$params["invoiceviewURLCancel"];
        }

        $strRet .= "<input type=hidden name=\"custom\" value=\"".$params['invoiceNumber']."_".$params['usingRecurringPlugin']."_1".$tRecurringExclude."\">\n";
        $strRet .= "<input type=hidden name=\"return\" value=\"".$returnURL."\">\n";
        $strRet .= "<input type=hidden name=\"rm\" value=\"2\">\n";
        $strRet .= "<input type=hidden name=\"cancel_return\" value=\"".$returnURL_Cancel."\">\n";
        $strRet .= "<input type=hidden name=\"notify_url\" value=\"".$stat_url."\">\n";
        $strRet .= "<input type=hidden name=\"first_name\" value=\"".$params["userFirstName"]."\">\n";
        $strRet .= "<input type=hidden name=\"last_name\" value=\"".$params["userLastName"]."\">\n";
        $strRet .= "<input type=hidden name=\"address1\" value=\"".$params["userAddress"]."\">\n";
        $strRet .= "<input type=hidden name=\"city\" value=\"".$params["userCity"]."\">\n";
        $strRet .= "<input type=hidden name=\"state\" value=\"".$params["userState"]."\">\n";
        $strRet .= "<input type=hidden name=\"zip\" value=\"".$params["userZipcode"]."\">\n";
        $strRet .= "<input type=hidden name=\"no_shipping\" value=\"1\">\n";
        $strRet .= "<input type=hidden name=\"no_note\" value=\"1\">\n";
        $strRet .= "<input type=hidden name=\"bn\" value=\"Clientexec_SP\">\n";
        $strRet .= "<input type=hidden name=\"currency_code\" value=\"".$params["currencytype"]."\">\n";
        $strRet .= "<input type=hidden name=\"page_style\" value=\"".$params["plugin_paypal_Page Style"]."\">\n";
        $strRet .= "<input type=hidden name=\"email\" value=\"".$params["userEmail"]."\">\n";

        //The next 2 fields are to get the phone number on the form for new customers. It was not working with just one of them
        $strRet .= "<input type=hidden name=\"night_phone_a\" value=\""."\">\n";
        $strRet .= "<input type=hidden name=\"night_phone_b\" value=\"".$params["userPhone"]."\">\n";
        //The previous 2 fields were to get the phone number on the form for new customers. It was not working with just one of them

        $strRet .= "<input type=hidden name=\"country\" value=\"".$params["userCountry"]."\">\n";
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

    //NEW API CODE
    function newAPIsinglePayment($params)
    {
        //Get the values of the custom fields defined in the top of the file form.phtml
        $pluginFolderName = basename(dirname(__FILE__));
        $Transaction_ID = $params['plugincustomfields'][$pluginFolderName.'_Transaction_ID'];
        $Transaction_State = strtolower($params['plugincustomfields'][$pluginFolderName.'_Transaction_State']);
        $Transaction_Amount = $params['plugincustomfields'][$pluginFolderName.'_Transaction_Amount'];
        $Transaction_Currency = $params['plugincustomfields'][$pluginFolderName.'_Transaction_Currency'];

        //Set this variable later to false if something fails.
        $success = true;

        //Set in this variable the values you will use in the callback.
        $newParams = array();
        $newParams['newApi'] = 1;
        $newParams['invoiceId'] = $params['invoiceNumber'];

        //Make sure to get and assign the transaction amount from the parameters returned from the gateway.
        //Please take in count $params['invoiceTotal'] has the amount of the transaction that will be refunded, while it has the balance due of the invoice when doing a payment
        $newParams['transactionAmount'] = $Transaction_Amount;

        //Make sure to get and assign the credit card last four digits from the parameters returned from the gateway, or set it as 'NA' if not available.
        //Allowed values: 'NA', credit card last four digits.
        $newParams['transactionLast4'] = 'NA';

        //If something failed, set this variable to false.
        if ($Transaction_State == 'denied') {
            $success = false;
        }

        //Make sure to get and assign the type of transaction from the parameters returned from the gateway.
        //Allowed values: charge, refund
        $newParams['transactionAction'] = 'charge';

        //Make sure to get and assign the transaction id from the parameters returned from the gateway.
        $newParams['transactionId'] = $Transaction_ID;

        //Make sure to get and assign the transaction status from the parameters returned from the gateway.
        //Possible values: completed, partially_refunded, pending, refunded, denied.
        $newParams['transactionStatus'] = $Transaction_State;

        //This code will be calling the callback file directly
        $pluginFolderName = basename(dirname(__FILE__));
        include 'plugins/gateways/'.$pluginFolderName.'/Plugin'.ucfirst($pluginFolderName).'Callback.php';
        $callbackClassName = 'Plugin'.ucfirst($pluginFolderName).'Callback';
        $callback = new $callbackClassName;
        $callback->setCallbackParams($newParams);
        $callback->processCallback();

        //Need to check to see if user is coming from signup
        if ($params['fromDirectLink']) {
            if ($success === true) {
                $strMsg = $this->user->lang("Invoice(s) were processed successfully");
            } else {
                $strMsg = $this->user->lang("There was an error processing this invoice.");
            }

            $url = 'index.php';

            CE_Lib::redirectPage($url, $strMsg);
            exit;
        } elseif ($params['isSignup'] == 1) {
            // Actually handle the signup URL setting
            if ($this->settings->get('Signup Completion URL') != '') {
                if ($success === true) {
                    $returnUrl = $this->settings->get('Signup Completion URL').'?success=1';
                } else {
                    $returnUrl = $this->settings->get('Signup Completion URL');
                }
            } else {
                if ($success === true) {
                    $returnUrl = $params["clientExecURL"]."/order.php?step=complete&pass=1";
                } else {
                    $returnUrl = $params["clientExecURL"]."/order.php?step=3";
                }
            }
        } else {
            if ($success === true) {
                $returnUrl = $params["invoiceviewURLSuccess"];
            } else {
                $returnUrl = $params["invoiceviewURLCancel"];
            }
        }

        header("Location: " . $returnUrl);
    }

    //NEW API CODE
    public function getForm($params)
    {
        if ($params['from'] == 'paymentmethod') {
            return '';
        }

        if ($params['from'] == 'signup') {
            $fakeForm = '<a style="margin-left:0px;cursor:pointer;" class="app-btns primary customButton" onclick="cart.submit_form('.$params['loggedIn'].');"  id="submitButton"></a>';
        } else {
            $fakeForm = '<button class="app-btns primary" id="submitButton">'.$this->user->lang('Pay Invoice').'</button>';
        }

        //NEW API CODE
        $params['plugin_paypal_API Client ID'] = trim($this->settings->get('plugin_paypal_API Client ID'));

        if ($params['plugin_paypal_API Client ID'] != '') {
            $subscriptionsUsed = false;

            if ($params['from'] == 'signup') {
                $useRecurringPlugin = 0;

                if ($this->user->getID() != 0) {
                    $useRecurringPlugin = $this->user->getUsePaypalSubscriptions();
                } elseif (!is_null($this->settings->get('plugin_paypal_Paypal Subscriptions Option')) && $this->settings->get('plugin_paypal_Paypal Subscriptions Option') == 0) {
                    $useRecurringPlugin = 1;
                }

                $invoiceBillingCycleInDays = 0;
                $invoiceBillingCycle = 0;
                $billingCycleInDays = 0;
                $billingCycle = 0;

                foreach ($params['cartsummary']['cartItems'] as $cartItem) {
                    $billingCycleObject = new BillingCycle($cartItem['trueTerm']);
                    $periodLengthInDays = 0;

                    switch ($billingCycleObject->time_unit) {
                        case 'd':
                            $periodLengthInDays = 1;
                            break;
                        case 'w':
                            $periodLengthInDays = 7;
                            break;
                        case 'm':
                            $periodLengthInDays = 30;
                            break;
                        case 'y':
                            $periodLengthInDays = 365;
                            break;
                    }

                    if ($invoiceBillingCycleInDays < ($billingCycleObject->amount_of_units * $periodLengthInDays)) {
                        $invoiceBillingCycleInDays = $billingCycleObject->amount_of_units * $periodLengthInDays;
                        $invoiceBillingCycle = $cartItem['trueTerm'];
                    }

                    if ($billingCycleInDays < ($billingCycleObject->amount_of_units * $periodLengthInDays)) {
                        $billingCycleInDays = $billingCycleObject->amount_of_units * $periodLengthInDays;
                        $billingCycle = $cartItem['trueTerm'];
                    }

                    if (isset($cartItem['hasAddons']) && $cartItem['hasAddons']) {
                        foreach ($cartItem['addons'] as $cartAddon) {
                            $addonBillingCycleObject = new BillingCycle($cartAddon['recurringprice_cyle']);
                            $addonPeriodLengthInDays = 0;

                            switch ($addonBillingCycleObject->time_unit) {
                                case 'd':
                                    $addonPeriodLengthInDays = 1;
                                    break;
                                case 'w':
                                    $addonPeriodLengthInDays = 7;
                                    break;
                                case 'm':
                                    $addonPeriodLengthInDays = 30;
                                    break;
                                case 'y':
                                    $addonPeriodLengthInDays = 365;
                                    break;
                            }

                            if ($invoiceBillingCycleInDays < ($addonBillingCycleObject->amount_of_units * $addonPeriodLengthInDays)) {
                                $invoiceBillingCycleInDays = $addonBillingCycleObject->amount_of_units * $addonPeriodLengthInDays;
                                $invoiceBillingCycle = $cartAddon['recurringprice_cyle'];
                            }
                        }
                    }
                }

                if (!$billingCycleInDays) {
                    $billingCycle = $invoiceBillingCycle;
                }

                $params['billingCycle'] = $billingCycle;
                $newBillingCycleObject = new BillingCycle($params['billingCycle']);
                $params['usingRecurringPlugin'] = $useRecurringPlugin;
                $params['plugin_paypal_API Secret'] = trim($this->settings->get('plugin_paypal_API Secret'));
                $params['invoiceProratingDays'] = (isset($params['cartsummary']['isProRating']))? $params['cartsummary']['isProRating'] : 0;

                // use subscriptions only if has package fee
                if ($params['usingRecurringPlugin'] == '1') {
                    // If prorating, avoid creating a subscription
                    // If period is greather than 5 years (60 months), avoid creating a subscription as Paypal does not support it
                    //     Paypal currently only support subscriptions up to 5 Years
                    //         https://developer.paypal.com/docs/classic/paypal-payments-standard/integration-guide/Appx_websitestandard_htmlvariables/
                    //             Allowable values for t1 and t3 are:
                    //                 D – for days;   allowable range for p1 and p3 is 1 to 90
                    //                 W - for weeks;  allowable range for p1 and p3 is 1 to 52
                    //                 M – for months; allowable range for p1 and p3 is 1 to 24
                    //                 Y – for years;  allowable range for p1 and p3 is 1 to 5
                    // However, in the new API, for frequency_interval, value cannot be greater than 12 months.
                    if ($params['plugin_paypal_API Secret'] != '' && !$params['invoiceProratingDays'] && $newBillingCycleObject->amount_of_units > 0
                      && (($newBillingCycleObject->time_unit == 'd' && $newBillingCycleObject->amount_of_units <= 90)
                       || ($newBillingCycleObject->time_unit == 'w' && $newBillingCycleObject->amount_of_units <= 52)
                       || ($newBillingCycleObject->time_unit == 'm' && $newBillingCycleObject->amount_of_units <= 12)
                       || ($newBillingCycleObject->time_unit == 'y' && $newBillingCycleObject->amount_of_units <= 1))) {
                        $subscriptionsUsed = true;
                    }
                }
            } else {
                $tempInvoice = new Invoice($params['invoiceId']);
                $tempUser = new User($tempInvoice->getUserID());

                // if corresponding recurringfee entry has been set to disablegenereate=0 (after a subscription cancellation),
                // or if it has a monthly usage
                // then don't use paypal subscriptions even if the user settings say so
                $useRecurringPlugin = $tempUser->getUsePaypalSubscriptions();

                if ($useRecurringPlugin) {
                    $invoiceEntries = $tempInvoice->getInvoiceEntries();

                    foreach ($invoiceEntries as $invoiceEntry) {
                        if ($invoiceEntry->getRecurringAppliesTo() != 0) {
                            //** Removed old status field from recurringfee .. We need to check if
                            //maybe we need to add to sql to check for package status of 1 as a where clause
                            $query = "SELECT disablegenerate, monthlyusage FROM recurringfee WHERE id = ? ";
                            $result = $this->db->query($query, $invoiceEntry->getRecurringAppliesTo());
                            $row = $result->fetch();

                            if ($row) {
                                //recurringfee
                                if ($row['disablegenerate'] == '0') {
                                    $useRecurringPlugin = 0;
                                    break;
                                }

                                //monthly usage
                                $monthlyusage = explode("|", $row['monthlyusage']);

                                if (isset($monthlyusage[1]) && is_numeric($monthlyusage[1]) && $monthlyusage[1] > 0) {
                                    $useRecurringPlugin = 0;
                                    break;
                                }
                            }
                        }
                    }
                }

                // Do not start subscription if:
                // - paying after due date
                // - the invoice is partialy paid
                // - there are multiple invoice entries for the same item
                // - the invoice entries have different period start
                if ($useRecurringPlugin && ($tempInvoice->isPartiallyPaid() || $tempInvoice->isOverdue() || $tempInvoice->hasMultipleEntriesForSameItem() || !$tempInvoice->samePeriodStartAmongEntries())) {
                    $useRecurringPlugin = 0;
                }

                $invoiceBillingCycle = $tempInvoice->GetBillingCycle();
                $invoiceEntriesBillingTypes = $tempInvoice->getInvoiceEntriesBillingTypes();
                $invoiceEntriesBillingCycles = $tempInvoice->getBillingCycles();
                $nonRecurringInvoices = true;
                $manuallyEnteredInvoice = false;

                for ($i = 0; $i < count($invoiceEntriesBillingTypes); $i++) {
                    $invoiceEntryBillingCycleObject = new BillingCycle($invoiceEntriesBillingCycles[$i]);

                    if ($invoiceEntriesBillingTypes[$i] < 0 || $invoiceEntryBillingCycleObject->amount_of_units > 0) {
                        $nonRecurringInvoices = false;
                    }

                    if ($invoiceEntriesBillingTypes[$i] > 0) {
                        $manuallyEnteredInvoice = true;
                    }
                }

                if ((!$billingCycle = $tempInvoice->getRelatedPackageBillingCycle()) || $nonRecurringInvoices || $manuallyEnteredInvoice) {
                    $billingCycle = $invoiceBillingCycle;
                }

                $params['billingCycle'] = $billingCycle;
                $anotherBillingCycleObject = new BillingCycle($params['billingCycle']);
                $params['usingRecurringPlugin'] = $useRecurringPlugin;
                $params['invoicePackageUnproratedFee'] = $tempInvoice->getPackageUnproratedRecurringFee($billingCycle);
                $params['plugin_paypal_API Secret'] = trim($this->settings->get('plugin_paypal_API Secret'));
                $params['invoiceProratingDays'] = $tempInvoice->hasProratedCharges();

                // use subscriptions only if has package fee
                if ($params['usingRecurringPlugin'] == '1' && isset($params['invoicePackageUnproratedFee'])) {
                    // If prorating, avoid creating a subscription
                    // If period is greather than 5 years (60 months), avoid creating a subscription as Paypal does not support it
                    //     Paypal currently only support subscriptions up to 5 Years
                    //         https://developer.paypal.com/docs/classic/paypal-payments-standard/integration-guide/Appx_websitestandard_htmlvariables/
                    //             Allowable values for t1 and t3 are:
                    //                 D – for days;   allowable range for p1 and p3 is 1 to 90
                    //                 W - for weeks;  allowable range for p1 and p3 is 1 to 52
                    //                 M – for months; allowable range for p1 and p3 is 1 to 24
                    //                 Y – for years;  allowable range for p1 and p3 is 1 to 5
                    // However, in the new API, for frequency_interval, value cannot be greater than 12 months.
                    if ($params['plugin_paypal_API Secret'] != '' && !$params['invoiceProratingDays'] && $anotherBillingCycleObject->amount_of_units > 0
                      && (($anotherBillingCycleObject->time_unit == 'd' && $anotherBillingCycleObject->amount_of_units <= 90)
                       || ($anotherBillingCycleObject->time_unit == 'w' && $anotherBillingCycleObject->amount_of_units <= 52)
                       || ($anotherBillingCycleObject->time_unit == 'm' && $anotherBillingCycleObject->amount_of_units <= 12)
                       || ($anotherBillingCycleObject->time_unit == 'y' && $anotherBillingCycleObject->amount_of_units <= 1))) {
                        $subscriptionsUsed = true;
                    }
                }
            }

            if ($subscriptionsUsed) {
                CE_Lib::log(4, 'Paypal getForm subscription new API');
                return $fakeForm;
            } else {
                $this->view->sandboxClientID = $this->getVariable('API Client ID');
                $this->view->productionClientID = $this->getVariable('API Client ID');

                if ($this->getVariable('Use PayPal Sandbox')) {
                    $this->view->environment = 'sandbox';
                } else {
                    $this->view->environment = 'production';
                }

                $this->view->amount = $params['invoiceBalanceDue'];
                $this->view->invoiceId = $params['invoiceId'];
                $this->view->currency = $params['currency'];

                $this->view->from = $params['from'];
                $this->view->termsConditions = $params['termsConditions'];
                $this->view->fromDirectLink = (isset($params['fromDirectLink']))? $params['fromDirectLink']: false;

                CE_Lib::log(4, 'Paypal getForm singlepayment new API');
                return $this->view->render('form.phtml');
            }
        }
        //NEW API CODE

        CE_Lib::log(4, 'Paypal getForm old API');
        return $fakeForm;
    }


    //NEW API CODE
    function newAPIcreateSubscription($params)
    {

        // This var will be used to pass the excluded recurring fees ids for the subscription if any
        $tRecurringExclude = '';

        if ($params['invoicePackageUnproratedFee'] && count($params['invoiceExcludedRecurrings']) > 0) {
            $tRecurringExclude = '_'.implode(',', $params['invoiceExcludedRecurrings']);
        }

        $params['new_api_custom'] = $params['invoiceNumber']."_".$params['usingRecurringPlugin']."_1".$tRecurringExclude;

        $access_token = '';
        $webhooks = array();
        $webhookfound = false;
        $plan_id = '';
        $links = array();
        $approval_url = '';
        $execute = '';
        $token = '';
        $agreement_id = '';

        //Get an access token
        //https://developer.paypal.com/docs/api/overview/#get-an-access-token
        $access_token = $this->getAnAccessToken($params);

        //Pass this variable to your gateway to let it know where to send a callback.
        $urlFix = mb_substr($params['clientExecURL'], -1, 1) == "//" ? '' : '/';
        $callbackUrl = $params['clientExecURL'].$urlFix.'plugins/gateways/paypal/callback.php?newApi=1';

        //List all webhooks
        //https://developer.paypal.com/docs/api/webhooks/v1/#webhooks_get-all
        $webhooks = $this->listAllWebhooks($params, $access_token);
        foreach ($webhooks as $webhook) {
            if ($webhook['url'] == $callbackUrl) {
                $webhookfound = true;
                break;
            }
        }

        if (!$webhookfound) {
            //Create webhook
            //https://developer.paypal.com/docs/api/webhooks/v1/#webhooks_create
            $this->createWebhook($params, $access_token, $callbackUrl);
        }

        //Create a plan
        //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#1-create-a-plan
        $plan_id = $this->createAPlan($params, $access_token, $callbackUrl);

        //Activate a plan
        //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#2-activate-a-plan
        $this->activateAPlan($params, $access_token, $plan_id);

        //Create an agreement
        //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#3-create-an-agreement
        $links = $this->createAnAgreement($params, $access_token, $plan_id);
        foreach ($links as $link) {
            switch ($link['rel']) {
                case 'approval_url':
                    $approval_url = $link['href'];
                    break;
                case 'execute':
                    $execute = $link['href'];
                    break;
            }
        }

        //Get customer approval
        //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#4-get-customer-approval
        $this->getCustomerApproval($params, $approval_url);
    }

    //NEW API CODE
    function newAPIcancelSubscription($params)
    {
        $access_token = '';
        $agreement_id = $params['subscriptionId'];

        //Get an access token
        //https://developer.paypal.com/docs/api/overview/#get-an-access-token
        $access_token = $this->getAnAccessToken($params);

        //Pass this variable to your gateway to let it know where to send a callback.
        $params['clientExecURL'] = CE_Lib::getSoftwareURL();
        $urlFix = mb_substr($params['clientExecURL'], -1, 1) == "//" ? '' : '/';
        $callbackUrl = $params['clientExecURL'].$urlFix.'plugins/gateways/paypal/callback.php?newApi=1';

        //List all webhooks
        //https://developer.paypal.com/docs/api/webhooks/v1/#webhooks_get-all
        $webhooks = $this->listAllWebhooks($params, $access_token);
        foreach ($webhooks as $webhook) {
            if ($webhook['url'] == $callbackUrl) {
                $webhookfound = true;
                break;
            }
        }

        if (!$webhookfound) {
            //Create webhook
            //https://developer.paypal.com/docs/api/webhooks/v1/#webhooks_create
            $this->createWebhook($params, $access_token, $callbackUrl);
        }

        //Cancel agreement
        //https://developer.paypal.com/docs/api/payments.billing-agreements/v1/#billing-agreements_cancel
        $response = $this->cancelAgreement($params, $access_token, $agreement_id);

        //If it has an invalid status, assume it was already canceled
        if (isset($response['name']) && in_array($response['name'], array('STATUS_INVALID'))) {
            $this->removeSubscriptionReference($agreement_id);
            return;
        }

        //If "The profile ID is invalid" or "Merchant account is denied", try to cancel using the old API
        if (isset($response['name']) && in_array($response['name'], array('INVALID_PROFILE_ID', 'MERCHANT_ACCOUNT_DENIED'))) {
            return $this->oldAPIcancelSubscription($params);
        }

        $agreement = array();

        //Show agreement details
        //https://developer.paypal.com/docs/api/payments.billing-agreements/v1/#billing-agreements_get
        $agreement = $this->showAgreementDetails($access_token, $agreement_id);

        $customValues = explode("_", $agreement['description']);
        $tInvoiceID         = $customValues[0];
        $tIsRecurring       = $customValues[1];
        $tGenerateInvoice   = $customValues[2];
        $tRecurringExclude  = '';

        if (isset($customValues[3])) {
            $tRecurringExclude = $customValues[3];
        }

        // Create Plugin class object to interact with CE.
        $cPlugin = new Plugin($tInvoiceID, 'paypal', $this->user);

        CE_Lib::log(4, "Subscription has been cancelled.");
        $tUser = new User($cPlugin->m_Invoice->m_UserID);

        if (in_array($tUser->getStatus(), StatusAliasGateway::getInstance($this->user)->getUserStatusIdsFor(USER_STATUS_CANCELLED))) {
            CE_Lib::log(4, 'User is already cancelled. Ignore callback.');
        } else {
            $subject = 'Gateway recurring payment cancelled';
            $message = "Recurring payment for invoice $tInvoiceID has been cancelled.";
            // If createTicket returns false it's because this transaction has already been done
            if (!$cPlugin->createTicket($agreement['id'], $subject, $message, $tUser)) {
                exit;
            }
        }

        $transaction = "Paypal subscription cancelled. Original Signup Invoice: $tInvoiceID";
        $old_processorid = '';
        $cPlugin->resetRecurring($transaction, $agreement['id'], $tRecurringExclude, $tInvoiceID, $old_processorid);
    }

    //NEW API CODE
    function newAPIcredit($params)
    {
        $access_token = '';
        $transactionID = $params['invoiceRefundTransactionId'];

        //Get an access token
        //https://developer.paypal.com/docs/api/overview/#get-an-access-token
        $access_token = $this->getAnAccessToken($params);

        //Pass this variable to your gateway to let it know where to send a callback.
        $urlFix = mb_substr($params['clientExecURL'], -1, 1) == "//" ? '' : '/';
        $callbackUrl = $params['clientExecURL'].$urlFix.'plugins/gateways/paypal/callback.php?newApi=1';

        //List all webhooks
        //https://developer.paypal.com/docs/api/webhooks/v1/#webhooks_get-all
        $webhooks = $this->listAllWebhooks($params, $access_token);
        foreach ($webhooks as $webhook) {
            if ($webhook['url'] == $callbackUrl) {
                $webhookfound = true;
                break;
            }
        }

        if (!$webhookfound) {
            //Create webhook
            //https://developer.paypal.com/docs/api/webhooks/v1/#webhooks_create
            $this->createWebhook($params, $access_token, $callbackUrl);
        }

        //Refund sale
        //https://developer.paypal.com/docs/api/payments/v1/#sale_refund
        $refund = $this->refundSale($params, $access_token, $transactionID);

        if (strtolower($refund["state"]) == 'completed' && $refund['sale_id'] == $transactionID) {
            // Create Plugin class object to interact with CE.
            $cPlugin = new Plugin($params['invoiceNumber'], 'paypal', $this->user);

            //Add plugin details
            $cPlugin->setAmount($params['invoiceTotal']);
            $cPlugin->m_TransactionID = $refund['id'];
            $cPlugin->m_Action = "refund";
            $cPlugin->m_Last4 = "NA";

            if (isset($refund['sale_id'])) {
                $ppParentTransID = $refund['sale_id'];

                if ($cPlugin->TransExists($ppParentTransID)) {
                    $newInvoice = $cPlugin->retrieveInvoiceForTransaction($ppParentTransID);

                    if ($newInvoice && ($cPlugin->m_Invoice->isPaid() || $cPlugin->m_Invoice->isPartiallyPaid())) {
                        $transaction = "Paypal payment of ".$params['invoiceTotal']." was refunded. Original Signup Invoice: ".$params['invoiceNumber']." (OrderID: ".$refund['id'].")";
                        $cPlugin->PaymentRefunded($params['invoiceTotal'], $transaction, $refund['id']);
                    } elseif (!$cPlugin->m_Invoice->isRefunded()) {
                        CE_Lib::log(1, 'Related invoice not found or not set as paid on the application, when doing the refund');
                    }
                } else {
                    CE_Lib::log(1, 'Parent transaction id not matching any existing invoice on the application, when doing the refund');
                }
            } else {
                CE_Lib::log(1, 'Callback not returning parent_txn_id when refunding');
            }

            return array('AMOUNT' => $params['invoiceTotal']);
        } else {
            CE_Lib::log(4, 'Error with PayPal Refund: ' . print_r($refund, true));
            return 'Error with PayPal Refund';
        }
    }

    //NEW API CODE
    //Get an access token
    //https://developer.paypal.com/docs/api/overview/#get-an-access-token
    private function getAnAccessToken($params)
    {
        $request = 'oauth2/token';

        //API Credentials
        if ($params['plugin_paypal_API Client ID'] == '' || $params['plugin_paypal_API Secret'] == '') {
            throw new CE_Exception('You must fill out the Required to use the new API and Required to do subscriptions using the new API Sections of the PayPal configuration to create and cancel Paypal Subscriptions using the new API.');
        }

        $client_id = trim($params['plugin_paypal_API Client ID']);
        $secret = trim($params['plugin_paypal_API Secret']);

        $header = array(
            'Accept: application/json',
            'Accept-Language: en_US',
            'Authorization: Basic '.base64_encode($client_id.':'.$secret)
        );

        $data = 'grant_type=client_credentials';
        CE_Lib::log(4, 'Paypal Params getAnAccessToken: ' . print_r($data, true));
        $type = 'POST';
        $acceptHTTPcode = false;

        $return = $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);

        if (!isset($return['access_token'])) {
            throw new CE_Exception('Get an access token has failed');
        }

        return $return['access_token'];
    }

    //NEW API CODE
    //List all webhooks
    //https://developer.paypal.com/docs/api/webhooks/v1/#webhooks_get-all
    private function listAllWebhooks($params, $access_token)
    {
        $request = 'notifications/webhooks';

        $header = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        $data = false;
        CE_Lib::log(4, 'Paypal Params listAllWebhooks: ' . print_r($data, true));
        $type = 'GET';
        $acceptHTTPcode = false;

        $return = $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);

        if (!isset($return['webhooks'])) {
            throw new CE_Exception('List all webhooks has failed');
        }

        return $return['webhooks'];
    }

    //NEW API CODE
    //Create webhook
    //https://developer.paypal.com/docs/api/webhooks/v1/#webhooks_create
    private function createWebhook($params, $access_token, $callbackUrl)
    {
        $request = 'notifications/webhooks';

        $header = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        //As there is no 'custom' field, we will use the value as the name and description of the plan. Not sure if we will get it back that way.
        $data = array(
            'url'         => $callbackUrl,
            'event_types' => array(
                array(
                    "name" => "*"
                )
            )
        );
        CE_Lib::log(4, 'Paypal Params createWebhook: ' . print_r($data, true));
        $data = json_encode($data);
        $type = 'POST';
        $acceptHTTPcode = false;

        $return = $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);

        if (!isset($return['url']) || $return['url'] !== $callbackUrl) {
            throw new CE_Exception('Create webhook has failed');
        }

        return $return['url'];
    }


    //NEW API CODE
    //Create a plan
    //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#1-create-a-plan
    //https://developer.paypal.com/docs/api/payments.billing-plans/v1/
    //https://developer.paypal.com/docs/api/payments.billing-plans/v1/#definition-merchant_preferences
    private function createAPlan($params, $access_token, $callbackUrl)
    {
        $request = 'payments/billing-plans/';

        $header = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        //Need to check to see if user is coming from signup
        if ($params['isSignup'] == 1) {
            $return_url = $callbackUrl.'&isSignup=1';

            if ($this->settings->get('Signup Completion URL') != '') {
                $cancel_url = $this->settings->get('Signup Completion URL');
            } else {
                $cancel_url = $params["clientExecURL"].'/order.php?step=3';
            }
        } else {
            $return_url = $callbackUrl.'&isSignup=0';
            $cancel_url = $params['invoiceviewURLCancel'];
        }

        if (!$params['invoicePackageUnproratedFee']) {
            // if invoicePackageUnproratedFee is 0, it means this is not a package invoice, so the invoiceTotal will be the same charge always
            $tRecurringTotal = $params['invoiceTotal'];
        } else {
            $tRecurringTotal = $params['invoicePackageUnproratedFee'];
        }

        $billingCycleObject = new BillingCycle($params['billingCycle']);

        switch ($billingCycleObject->time_unit) {
            case 'd':
                //DAY; allowable range is 1 to 90
                $initialPeriodLength = $billingCycleObject->amount_of_units;
                $initialPeriodUnits = 'DAY';
                break;
            case 'w':
                //WEEK; allowable range is 1 to 52
                $initialPeriodLength = $billingCycleObject->amount_of_units;
                $initialPeriodUnits = 'WEEK';
                break;
            case 'm':
                //MONTH; allowable range is 1 to 24
                // However, in the new API, for frequency_interval, value cannot be greater than 12 months.
                $initialPeriodLength = $billingCycleObject->amount_of_units;
                $initialPeriodUnits = 'MONTH';
                break;
            case 'y':
                //YEAR; allowable range is 1 to 5
                // However, in the new API, for frequency_interval, value cannot be greater than 12 months.
                $initialPeriodLength = $billingCycleObject->amount_of_units;
                $initialPeriodUnits = 'YEAR';
                break;
        }

        //As there is no 'custom' field, we will use the value as the name and description of the plan. Not sure if we will get it back that way.
        $data = array(
            'name'                 => $params['new_api_custom'],
            'description'          => $params['new_api_custom'],
            'type'                 => 'INFINITE',
            'payment_definitions'  => array(
                array(
                    'name'               => 'Regular payment definition',
                    'type'               => 'REGULAR',
                    'frequency'          => $initialPeriodUnits,
                    'frequency_interval' => $initialPeriodLength,
                    'cycles'             => '0',
                    'amount'             => array(
                        'value'    => sprintf("%01.2f", round($tRecurringTotal, 2)),
                        'currency' => $params["currencytype"]
                    )
                )
            ),
            'merchant_preferences' => array(
                'setup_fee' => array(
                    'value'    => sprintf("%01.2f", round($params['invoiceTotal'], 2)),
                    'currency' => $params["currencytype"]
                ),
                'return_url' => $return_url,
                'cancel_url' => $cancel_url
            )
        );
        CE_Lib::log(4, 'Paypal Params createAPlan: ' . print_r($data, true));
        $data = json_encode($data);
        $type = 'POST';
        $acceptHTTPcode = false;

        $return = $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);

        if (!isset($return['id'])) {
            throw new CE_Exception('Create a plan has failed');
        }

        return $return['id'];
    }

    //NEW API CODE
    //Activate a plan
    //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#2-activate-a-plan
    private function activateAPlan($params, $access_token, $plan_id)
    {
        $request = 'payments/billing-plans/'.$plan_id.'/';

        $header = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        $data = array(
            array(
                'op'    => 'replace',
                'path'  => '/',
                'value' => array(
                    'state' => 'ACTIVE'
                )
            )
        );
        CE_Lib::log(4, 'Paypal Params activateAPlan: ' . print_r($data, true));
        $data = json_encode($data);
        $type = 'PATCH';
        $acceptHTTPcode = array(200);

        $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);
    }

    //NEW API CODE
    //Create an agreement
    //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#3-create-an-agreement
    //https://developer.paypal.com/docs/api/payments.billing-agreements/v1/#billing-agreements_create
    private function createAnAgreement($params, $access_token, $plan_id)
    {
        $request = 'payments/billing-agreements/';

        $header = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        //The start date must be no less than 24 hours after the current date as the agreement can take up to 24 hours to activate.
        //We are charging the first invoice amount as the setup_fee for the Plan, and the recurring charges will start on the next billing cycle, so start_date will be set to the next due date
        $billingCycleObject = new BillingCycle($params['billingCycle']);

        switch ($billingCycleObject->time_unit) {
            case 'd':
                $initialPeriodLength = $billingCycleObject->amount_of_units;
                $initialPeriodUnits = 'days';
                break;
            case 'w':
                $initialPeriodLength = $billingCycleObject->amount_of_units * 7;
                $initialPeriodUnits = 'days';
                break;
            case 'm':
                $initialPeriodLength = $billingCycleObject->amount_of_units;
                $initialPeriodUnits = 'months';
                break;
            case 'y':
                $initialPeriodLength = $billingCycleObject->amount_of_units;
                $initialPeriodUnits = 'years';
                break;
        }

        $start_date_iso_string = date('c', strtotime('+'.$initialPeriodLength.' '.$initialPeriodUnits));

        //As there is no 'custom' field, we will use the value as the name and description of the agreement. Not sure if we will get it back that way.
        $data = array(
            'name'             => $params['new_api_custom'],
            'description'      => $params['new_api_custom'],
            'start_date'       => $start_date_iso_string,
            'plan'             => array(
                'id' => $plan_id
            ),
            'payer'            => array(
                'payment_method' => 'paypal'
            )
        );
        CE_Lib::log(4, 'Paypal Params createAnAgreement: ' . print_r($data, true));
        $data = json_encode($data);
        $type = 'POST';
        $acceptHTTPcode = false;

        $return = $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);

        if (!isset($return['links'])) {
            throw new CE_Exception('Create an agreement has failed');
        }

        return $return['links'];
    }

    //NEW API CODE
    //Get customer approval
    //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#4-get-customer-approval
    private function getCustomerApproval($params, $approval_url)
    {
        header('Location: '.$approval_url);
        exit;
    }

    //NEW API CODE
    //Cancel agreement
    //https://developer.paypal.com/docs/api/payments.billing-agreements/v1/#billing-agreements_cancel
    private function cancelAgreement($params, $access_token, $agreement_id)
    {
        $request = 'payments/billing-agreements/'.$agreement_id.'/cancel';

        $header = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        //As there is no 'custom' field, we will use the value in the note of the agreement cancellation. Not sure if it can help as a reference or not.
        $data = array(
            'note' => 'Canceling '.$params['new_api_custom']    //Maximum length: 128
        );
        CE_Lib::log(4, 'Paypal Params cancelAgreement: ' . print_r($data, true));
        $data = json_encode($data);
        $type = 'POST';
        $acceptHTTPcode = array(204);

        return $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);
    }

    //NEW API CODE
    //Show agreement details
    //https://developer.paypal.com/docs/api/payments.billing-agreements/v1/#billing-agreements_get
    private function showAgreementDetails($access_token, $subscriptionID)
    {
        $request = 'payments/billing-agreements/'.$subscriptionID;

        $header = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        $data = false;
        CE_Lib::log(4, 'Paypal Params showAgreementDetails: ' . print_r($data, true));
        $type = 'GET';
        $acceptHTTPcode = false;

        $return = $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);

        if (!isset($return['id'])) {
            throw new CE_Exception('Show agreement details has failed. "id" is not defined');
        }
        if (!isset($return['description'])) {
            throw new CE_Exception('Show agreement details has failed. "description" is not defined');
        }
        if (!isset($return['start_date'])) {
            throw new CE_Exception('Show agreement details has failed. "start_date" is not defined');
        }
        if (!isset($return['payer']['payer_info']['email'])) {
            throw new CE_Exception('Show agreement details has failed. "payer" => "payer_info" => "email" is not defined');
        }

        return $return;
    }

    //NEW API CODE
    //Refund sale
    //https://developer.paypal.com/docs/api/payments/v1/#sale_refund
    private function refundSale($params, $access_token, $transactionID)
    {
        $request = 'payments/sale/'.$transactionID.'/refund';

        $header = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        //As there is no 'custom' field, we will use the value in the note of the agreement cancellation. Not sure if it can help as a reference or not.
        $data = array(
            'description' => 'Refund of Invoice #'.$params['invoiceNumber']    //The refund description. Value is a string of single-byte alphanumeric characters. Maximum length: 255
        );
        CE_Lib::log(4, 'Paypal Params refundSale: ' . print_r($data, true));
        $data = json_encode($data);
        $type = 'POST';
        $acceptHTTPcode = false;

        $return = $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);

        if (!isset($return['id'])) {
            throw new CE_Exception('Refund sale has failed. "id" is not defined');
        }
        if (!isset($return['sale_id'])) {
            throw new CE_Exception('Refund sale has failed. "sale_id" is not defined');
        }
        if (!isset($return['state'])) {
            throw new CE_Exception('Refund sale has failed. "state" is not defined');
        }

        return $return;
    }

    //NEW API CODE
    private function makeRequest($request, $header, $data = false, $type = 'POST', $acceptHTTPcode = false)
    {
        $sandbox = '';
        if ($this->settings->get('plugin_paypal_Use PayPal Sandbox') == '1') {
            $sandbox = 'sandbox.';
        }
        $url = 'https://api.'.$sandbox.'paypal.com/v1/'.$request;

        CE_Lib::log(4, 'Making request to: ' . $url);
        $ch = curl_init($url);

        switch ($type) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, 1);
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                break;
            case 'GET':
                break;
        }

        if ($data !== false) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);

        if (!$response) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            CE_Lib::log(4, 'Paypal Response HTTP Code: ' . print_r($httpCode, true));

            if (!$acceptHTTPcode || !in_array($httpCode, $acceptHTTPcode)) {
                throw new CE_Exception('cURL Paypal Error: '.curl_error($ch).' ('.curl_errno($ch).')');
            }
        } else {
            $response = json_decode($response, true);
            CE_Lib::log(4, 'Paypal Response: ' . print_r($response, true));
        }

        curl_close($ch);

        return $response;
    }
}
