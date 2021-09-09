<?php
require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/admin/models/StatusAliasGateway.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Invoice_EventLog.php';
require_once 'modules/admin/models/Error_EventLog.php';
require_once 'modules/billing/models/BillingGateway.php';

class PluginPaypalCallback extends PluginCallback
{
    //NEW API CODE
    var $params;

    //NEW API CODE
    function setCallbackParams($params)
    {
        $this->params = $params;
    }

    function processCallback()
    {
        CE_Lib::log(4, 'Paypal callback invoked');

        if (!isset($GLOBALS['testing'])) {
            $testing = false;
        } else {
            $testing = $GLOBALS['testing'];
        }

        //NEW API CODE
        //Subscription
        if (isset($_REQUEST['newApi']) && $_REQUEST['newApi'] == 1) {
            
            //Execute an agreement
            //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#5-execute-an-agreement
            if (isset($_REQUEST['token'])) {
                $token = $_REQUEST['token'];

                $access_token = '';
                $agreement = array();

                //Get an access token
                //https://developer.paypal.com/docs/api/overview/#get-an-access-token
                $access_token = $this->getAnAccessToken();

                //Execute an agreement
                //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#5-execute-an-agreement
                $agreement = $this->executeAnAgreement($access_token, $token);

                $customValues = explode("_", $agreement['description']);
                $tInvoiceID         = $customValues[0];
                $tIsRecurring       = $customValues[1];
                $tGenerateInvoice   = $customValues[2];
                $tRecurringExclude  = '';
                if (isset($customValues[3])) {
                    $tRecurringExclude = $customValues[3];
                }

                if (!is_numeric($tInvoiceID)) {
                    throw new CE_Exception('Paypal event has failed. Invoice was not found');
                }

                // Create Plugin class object to interact with CE.
                $cPlugin = new Plugin($tInvoiceID, 'paypal', $this->user);

                //Lets mark the recurring fees as active subscription.
                $cPlugin->setSubscriptionId($agreement['id'], $tRecurringExclude);
                $transaction = "Started paypal subscription. Subscription ID: ".$agreement['id'];
                $cPlugin->logSubscriptionStarted($transaction, $agreement['id'].' '.$agreement['start_date']);

                $clientExecURL = CE_Lib::getSoftwareURL();
                $invoiceviewURLSuccess = $clientExecURL."/index.php?fuse=billing&paid=1&controller=invoice&view=invoice&id=".$tInvoiceID;
                $invoiceviewURLCancel = $clientExecURL."/index.php?fuse=billing&cancel=1&controller=invoice&view=invoice&id=".$tInvoiceID;

                //Need to check to see if user is coming from signup
                if (isset($_REQUEST['isSignup']) && $_REQUEST['isSignup'] == 1) {
                    if ($this->settings->get('Signup Completion URL') != '') {
                        $return_url = $this->settings->get('Signup Completion URL').'?success=1';
                        $cancel_url = $this->settings->get('Signup Completion URL');
                    } else {
                        $return_url = $clientExecURL."/order.php?step=complete&pass=1";
                        $cancel_url = $clientExecURL."/order.php?step=3";
                    }
                } else {
                    $return_url = $invoiceviewURLSuccess;
                    $cancel_url = $invoiceviewURLCancel;
                }

                header('Location: '.$return_url);
                exit;
            } else {
                $response = file_get_contents("php://input");
                $response = json_decode($response, true);
                CE_Lib::log(4, 'Paypal Response: ' . print_r($response, true));

                $logOK = $this->_logPaypalCallback(true, $response);

                if (!$logOK) {
                    return;
                }

                $subscriptionID = '';
                $tInvoiceID = '';

                switch (strtoupper($response['event_type'])) {
                    case 'BILLING.SUBSCRIPTION.CREATED':
                        if (!isset($response['resource']['id'])) {
                            throw new CE_Exception('Paypal BILLING.SUBSCRIPTION.CREATED event has failed. "resource" => "id" is not defined');
                        }

                        $subscriptionID = $response['resource']['id'];
                        break;
                    case 'PAYMENT.SALE.COMPLETED':
                    case 'PAYMENT.SALE.PENDING':
                    case 'PAYMENT.SALE.DENIED':
                        if (!isset($response['resource']['billing_agreement_id'])) {
                            if (!isset($response['resource']['id'])) {
                                throw new CE_Exception('Paypal PAYMENT.SALE event has failed. "resource" => "id" is not defined');
                            }

                            $cPlugin = new Plugin();
                            $newInvoice = $cPlugin->retrieveInvoiceForTransaction($response['resource']['id']);

                            if ($newInvoice) {
                                $tInvoiceID = $cPlugin->m_ID;
                            }
                        } else {
                            $subscriptionID = $response['resource']['billing_agreement_id'];
                        }

                        break;
                    case 'BILLING.SUBSCRIPTION.CANCELLED':
                        if (!isset($response['resource']['id'])) {
                            throw new CE_Exception('Paypal BILLING.SUBSCRIPTION.CANCELLED event has failed. "resource" => "id" is not defined');
                        }

                        $subscriptionID = $response['resource']['id'];
                        break;
                    case 'PAYMENT.SALE.REFUNDED':
                        $cPlugin = new Plugin();
                        $newInvoice = $cPlugin->retrieveInvoiceForTransaction($response['resource']['sale_id']);

                        if ($newInvoice) {
                            $tInvoiceID = $cPlugin->m_ID;
                        }
                        break;
                }

                if ($subscriptionID === '' && $tInvoiceID === '') {
                    if ($subscriptionID === '') {
                        throw new CE_Exception('Paypal event has failed. Subscription id was not defined');
                    }
                    if ($tInvoiceID === '') {
                        throw new CE_Exception('Paypal event has failed. Invoice was not found');
                    }
                }

                if ($subscriptionID !== '') {
                    $access_token = '';
                    $agreement = array();

                    //Get an access token
                    //https://developer.paypal.com/docs/api/overview/#get-an-access-token
                    $access_token = $this->getAnAccessToken();

                    //Show agreement details
                    //https://developer.paypal.com/docs/api/payments.billing-agreements/v1/#billing-agreements_get
                    $agreement = $this->showAgreementDetails($access_token, $subscriptionID);

                    $tIsRecurring       = 1;
                    $tGenerateInvoice   = 1;
                    $tRecurringExclude  = '';

                    if (isset($this->params) && isset($_REQUEST['redirected']) && $_REQUEST['redirected'] == 1) {
                        $ppTransID = $response['resource']['id'];
                        $cPlugin = new Plugin(0, 'paypal', $this->user);

                        if ($cPlugin->TransExists($ppTransID)) {
                            $newInvoice = $cPlugin->retrieveInvoiceForTransaction($ppTransID);

                            if ($newInvoice) {
                                $tInvoiceID = $cPlugin->m_Invoice->getId();
                            }
                        } else {
                            //Search for existing invoice, unpaid and with same subscription id
                            $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($agreement['id'], $ppTransID);

                            if ($newInvoice) {
                                $tInvoiceID = $cPlugin->m_Invoice->getId();
                            } else {
                                //get client id based on the subscription id of recurring fees
                                $customerid = $cPlugin->retrieveUserdIdForSubscription($agreement['id']);

                                $message = "There was a PayPal subscription payment for subscription ".$agreement['id'].".\n"
                                    ."However, the system could not find any pending invoice for this subscription. The PayPal transaction id for the payment is ".$ppTransID.".\n"
                                    ."Please take a look at the customer to confirm if this payment was required.\n"
                                    ."If the payment was not required, please make sure to login to your PayPal account and refund the payment. If the subscription should no longer apply, please cancel the subscription in your PayPal account.\n"
                                    ."\n"
                                    ."Thanks.";

                                if ($customerid !== false) {
                                    //try to generate the customer invoices and search again
                                    $billingGateway = new BillingGateway($this->user);
                                    $billingGateway->processCustomerBilling($customerid, $agreement['id']);

                                    //Search for existing invoice, unpaid and with same subscription id
                                    $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($agreement['id'], $ppTransID);

                                    if ($newInvoice) {
                                        $tInvoiceID = $cPlugin->m_Invoice->getId();
                                    } else {
                                        if (isset($customerid)) {
                                            // GENERATE TICKET
                                            $tUser = new User($customerid);
                                            $subject = 'Issue with paypal subscription payment';
                                            $cPlugin->createTicket($agreement['id'], $subject, $message, $tUser);
                                        } else {
                                            CE_Lib::log(1, $message);
                                        }

                                        $errorLog = Error_EventLog::newInstance(
                                            false,
                                            (isset($customerid))? $customerid : 0,
                                            $tInvoiceID,
                                            ERROR_EVENTLOG_PAYPAL_CALLBACK,
                                            NE_EVENTLOG_USER_SYSTEM,
                                            serialize($this->_utf8EncodePaypalCallback($response))
                                        );
                                        $errorLog->save();
                                        exit;
                                    }
                                } else {
                                    CE_Lib::log(1, $message);

                                    $errorLog = Error_EventLog::newInstance(
                                        false,
                                        0,
                                        $tInvoiceID,
                                        ERROR_EVENTLOG_PAYPAL_CALLBACK,
                                        NE_EVENTLOG_USER_SYSTEM,
                                        serialize($this->_utf8EncodePaypalCallback($response))
                                    );
                                    $errorLog->save();
                                    exit;
                                }
                            }
                        }
                    } else {
                        $customValues = explode("_", $agreement['description']);
                        $tInvoiceID         = $customValues[0];
                        $tIsRecurring       = $customValues[1];
                        $tGenerateInvoice   = $customValues[2];

                        if (isset($customValues[3])) {
                            $tRecurringExclude = $customValues[3];
                        }
                    }

                    if (!is_numeric($tInvoiceID)) {
                        throw new CE_Exception('Paypal event has failed. Invoice was not found');
                    }
                } else {
                    CE_Lib::log(4, 'Exiting Paypal callback invocation');
                    exit;
                }

                // Create Plugin class object to interact with CE.
                $cPlugin = new Plugin($tInvoiceID, 'paypal', $this->user);

                //Webhooks
                //https://developer.paypal.com/docs/api/webhooks/v1/
                //https://github.com/paypal/PayPal-PHP-SDK/blob/master/sample/notifications/CreateWebhook.php
                //https://developer.paypal.com/docs/integration/direct/webhooks/rest-webhooks/
                //https://developer.paypal.com/docs/integration/direct/webhooks/event-names/
                // - Login to https://developer.paypal.com
                // - Go to Dashboard -> Webhooks Simulator -> Enter the Webhooks URL, select the Event type and then click on 'Send test'.
                //This will give you a basic sample of the different events/notifications
                //subscr_signup  => BILLING.SUBSCRIPTION.CREATED
                //subscr_payment => PAYMENT.SALE.COMPLETED
                //subscr_eot     =>
                //subscr_cancel  => ? BILLING.SUBSCRIPTION.CANCELLED
                //subscr_failed  =>
                //refund         => ? PAYMENT.SALE.REFUNDED
                switch (strtoupper($response['event_type'])) {
                    case 'BILLING.SUBSCRIPTION.CREATED':
                        //Lets mark the recurring fees as active subscription.
                        $set = $cPlugin->setSubscriptionId($agreement['id'], $tRecurringExclude);

                        if ($set) {
                            $transaction = "Started paypal subscription. Subscription ID: ".$agreement['id'];
                            $cPlugin->logSubscriptionStarted($transaction, $agreement['id'].' '.$agreement['start_date']);
                        }

                        return;
                        break;
                    case 'PAYMENT.SALE.COMPLETED':
                    case 'PAYMENT.SALE.PENDING':
                    case 'PAYMENT.SALE.DENIED':
                        if (!isset($response['resource']['id'])) {
                            throw new CE_Exception('Paypal PAYMENT.SALE event has failed. "resource" => "id" is not defined');
                        }
                        if (!isset($response['resource']['state'])) {
                            throw new CE_Exception('Paypal PAYMENT.SALE event has failed. "resource" => "state" is not defined');
                        }
                        if (!isset($response['resource']['amount']['total'])) {
                            throw new CE_Exception('Paypal PAYMENT.SALE event has failed. "resource" => "amount" => "total" is not defined');
                        }

                        $ppTransID = $response['resource']['id'];
                        $ppPayStatus = strtolower($response['resource']['state']);
                        $ppPayAmount = $response['resource']['amount']['total'];

                        $newInvoice = false;

                        // Check to see if this Invoice is not unpaid
                        if ($cPlugin->IsUnpaid() == 0) {
                            $cPlugin->setSubscriptionId($agreement['id'], $tRecurringExclude);

                            CE_Lib::log(4, 'Previous subscription invoice is already paid');
                            // If it is, then check to see if the GenerateInvoice variable was passed to the script
                            if ($tGenerateInvoice) {
                                // If it was and is TRUE (1), set internal Plugin object variables to the existing invoice's information.
                                if ($cPlugin->TransExists($ppTransID)) {
                                    $newInvoice = $cPlugin->retrieveInvoiceForTransaction($ppTransID);

                                    if ($newInvoice && $cPlugin->m_Invoice->isPending()) {
                                        CE_Lib::log(4, 'Invoice already exists, and is marked as pending');
                                    }
                                } else {
                                    //Search for existing invoice, unpaid and with same subscription id
                                    $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($agreement['id'], $ppTransID);

                                    if ($newInvoice === false) {
                                        $customerid = $cPlugin->m_Invoice->getUserID();

                                        //try to generate the customer invoices and search again
                                        $billingGateway = new BillingGateway($this->user);
                                        $billingGateway->processCustomerBilling($customerid, $agreement['id']);

                                        //Search for existing invoice, unpaid and with same subscription id
                                        $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($agreement['id'], $ppTransID);

                                        if ($newInvoice === false) {
                                            $message = "There was a PayPal subscription payment for subscription ".$agreement['id'].".\n"
                                                      ."However, the system could not find any pending invoice for this subscription. The PayPal transaction id for the payment is ".$ppTransID.".\n"
                                                      ."Please take a look at the customer to confirm if this payment was required.\n"
                                                      ."If the payment was not required, please make sure to login to your PayPal account and refund the payment. If the subscription should no longer apply, please cancel the subscription in your PayPal account.\n"
                                                      ."\n"
                                                      ."Thanks.";
                                            if (isset($customerid)) {
                                                // GENERATE TICKET
                                                $tUser = new User($customerid);
                                                $subject = 'Issue with paypal subscription payment';
                                                $cPlugin->createTicket($agreement['id'], $subject, $message, $tUser);
                                            } else {
                                                CE_Lib::log(1, $message);
                                            }

                                            $errorLog = Error_EventLog::newInstance(
                                                false,
                                                (isset($customerid))? $customerid : 0,
                                                $tInvoiceID,
                                                ERROR_EVENTLOG_PAYPAL_CALLBACK,
                                                NE_EVENTLOG_USER_SYSTEM,
                                                serialize($this->_utf8EncodePaypalCallback($response))
                                            );
                                            $errorLog->save();
                                            exit;
                                        }
                                    }
                                }
                            } else {
                                CE_Lib::log(4, 'Exiting Paypal callback invocation');
                                exit;
                            }
                        }

                        //Add plugin details
                        $cPlugin->setAmount($ppPayAmount);
                        $cPlugin->m_TransactionID = $ppTransID;
                        $cPlugin->m_Action = "charge";
                        $cPlugin->m_Last4 = "NA";

                        if ($tIsRecurring) {
                            switch ($ppPayStatus) {
                                case "completed": // The payment has been completed, and the funds have been added successfully to your account balance.
                                    //The subscription will be updated when getting the payment callback, to avoid a conflcik with the subscr_signup callback
                                    $cPlugin->setSubscriptionId($agreement['id'], $tRecurringExclude);
                                    $transaction = "Paypal payment of $ppPayAmount was accepted. Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                                    $cPlugin->PaymentAccepted($ppPayAmount, $transaction, $ppTransID, $testing);
                                    break;
                                case "pending": // The payment is pending. See pending_reason for more information.
                                    //The subscription will be updated when getting the payment callback, to avoid a conflcik with the subscr_signup callback
                                    $cPlugin->setSubscriptionId($agreement['id'], $tRecurringExclude);
                                    $pendingReason = '';

                                    if (isset($response['resource']['pending_reason'])) {
                                        $pendingReason = '. Reason: '.$response['resource']['pending_reason'];
                                    }

                                    $transaction = "Paypal payment of $ppPayAmount was marked 'pending' by Paypal".$pendingReason.". Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                                    $cPlugin->PaymentPending($transaction, $ppTransID);
                                    break;
                                case "denied":  // The payment was denied.
                                    $transaction = "Paypal payment of $ppPayAmount was denied. Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                                    $cPlugin->PaymentRejected($transaction);
                                    break;
                            }
                        }

                        return;
                        break;
                    case 'BILLING.SUBSCRIPTION.CANCELLED':
                        if ($tIsRecurring) {
                            CE_Lib::log(4, "Subscription has been cancelled.");
                            $transaction = "Paypal subscription cancelled. Original Signup Invoice: $tInvoiceID";
                            $old_processorid = '';
                            $reset = $cPlugin->resetRecurring($transaction, $agreement['id'], $tRecurringExclude, $tInvoiceID, $old_processorid);

                            $tUser = new User($cPlugin->m_Invoice->m_UserID);

                            if (in_array($tUser->getStatus(), StatusAliasGateway::getInstance($this->user)->getUserStatusIdsFor(USER_STATUS_CANCELLED))) {
                                CE_Lib::log(4, 'User is already cancelled. Ignore callback.');
                            } elseif ($reset) {
                                $subject = 'Gateway recurring payment cancelled';
                                $message = "Recurring payment for invoice $tInvoiceID has been cancelled.";
                                // If createTicket returns false it's because this transaction has already been done
                                if (!$cPlugin->createTicket($agreement['id'], $subject, $message, $tUser)) {
                                    exit;
                                }
                            }
                        }

                        return;
                        break;
                    case 'PAYMENT.SALE.REFUNDED':
                        if (strtolower($response['resource']["state"]) == 'completed' && $response['resource']['sale_id'] == $transactionID) {
                            // Create Plugin class object to interact with CE.
                            $cPlugin = new Plugin($params['invoiceNumber'], 'paypal', $this->user);

                            //Add plugin details
                            $cPlugin->setAmount($params['invoiceTotal']);
                            $cPlugin->m_TransactionID = $response['resource']['id'];
                            $cPlugin->m_Action = "refund";
                            $cPlugin->m_Last4 = "NA";

                            if (isset($response['resource']['sale_id'])) {
                                $ppParentTransID = $response['resource']['sale_id'];

                                if ($cPlugin->TransExists($ppParentTransID)) {
                                    $newInvoice = $cPlugin->retrieveInvoiceForTransaction($ppParentTransID);

                                    if ($newInvoice && ($cPlugin->m_Invoice->isPaid() || $cPlugin->m_Invoice->isPartiallyPaid())) {
                                        $transaction = "Paypal payment of ".$params['invoiceTotal']." was refunded. Original Signup Invoice: ".$params['invoiceNumber']." (OrderID: ".$response['resource']['id'].")";
                                        $cPlugin->PaymentRefunded($params['invoiceTotal'], $transaction, $response['resource']['id']);
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
                            CE_Lib::log(4, 'Error with PayPal Refund: ' . print_r($response, true));
                            return 'Error with PayPal Refund';
                        }

                        return;
                        break;
                }
                exit;
            }
        }

        //Single Payment
        if (isset($this->params) && isset($this->params['newApi']) && $this->params['newApi'] == 1) {
            $params = $this->params;

            $pluginFolderName = basename(dirname(__FILE__));

            //Make sure to get and assign the invoice id from the parameters returned from the gateway.
            $invoiceId = $params['invoiceId'];
            $gatewayPlugin = new Plugin($invoiceId, $pluginFolderName, $this->user);

            //If the gateway API provides a way to verify the response, make sure to verify it before performing any actions over the invoice.
            $transactionVerified = true;
            if (!$transactionVerified) {
                $transaction = "Paypal transaction verification has failed.";
                $gatewayPlugin->PaymentRejected($transaction);
                exit;
            }

            //Make sure to get and assign the transaction id from the parameters returned from the gateway.
            $transactionId = $params['transactionId'];
            $gatewayPlugin->setTransactionID($transactionId);

            //Make sure to get and assign the transaction amount from the parameters returned from the gateway.
            $transactionAmount = $params['transactionAmount'];
            $gatewayPlugin->setAmount($transactionAmount);

            //Make sure to get and assign the credit card last four digits from the parameters returned from the gateway, or set it as 'NA' if not available.
            //Allowed values: 'NA', credit card last four digits
            $transactionLast4 = $params['transactionLast4'];
            $gatewayPlugin->setLast4($transactionLast4);

            //Make sure to get and assign the type of transaction from the parameters returned from the gateway.
            //Allowed values: charge, refund
            $transactionAction = $params['transactionAction'];
            switch ($transactionAction) {
                case 'charge':
                    $gatewayPlugin->setAction('charge');

                    //Make sure to get and assign the transaction status from the parameters returned from the gateway.
                    $transactionStatus = $params['transactionStatus'];

                    //Make sure to replace the case values in the following switch, to match the status values returned from the gateway.
                    switch ($transactionStatus) {
                        case 'completed':
                            $transaction = "Paypal payment of $transactionAmount was accepted. Original Signup Invoice: $invoiceId (OrderID: ".$transactionId.")";
                            $gatewayPlugin->PaymentAccepted($transactionAmount, $transaction, $transactionId);
                            break;
                        case 'pending':
                            $transaction = "Paypal payment of $transactionAmount was marked 'pending' by Paypal. Original Signup Invoice: $invoiceId (OrderID: ".$transactionId.")";
                            $gatewayPlugin->PaymentPending($transaction, $transactionId);
                            break;
                        case 'denied':
                            $transaction = "Paypal payment of $transactionAmount was rejected. Original Signup Invoice: $invoiceId (OrderID: ".$transactionId.")";
                            $gatewayPlugin->PaymentRejected($transaction);
                            break;
                    }
                    break;
                case 'refund':
//REFUND NOT HANDLED YET
                    break;
            }
            return;
        }
        //NEW API CODE

        $logOK = $this->_logPaypalCallback();

        if ($this->settings->get('plugin_paypal_Use PayPal Sandbox') == '0' && isset($_POST['test_ipn']) && $_POST['test_ipn'] == '1') {
            CE_Lib::log(4, "** Paypal sandbox callback but account is in production mode => callback discarded");
            return;
        }

        $ppTransType = '';
        if (!isset($_POST['txn_type']) && isset($_POST['payment_status']) && $_POST['payment_status'] == 'Refunded') {
            $ppTransType = 'refund';
        }

        if (!isset($_POST['txn_type']) && $ppTransType == '') {
            CE_Lib::log(4, 'Paypal callback ignored: txn_type is not defined');
            return;
        }

        // Assign IPN Variables
        // Different possible transaction types (txn_type) for subscriptions:
        // 1. 'subscr_signup': issued just after the client has paid the inicial amount. We ignore this.
        // 2. 'subscr_payment': issued just after the previous one and for every subsequent payment.
        if ($ppTransType == '') {
            $ppTransType = $_POST['txn_type'];
        }

        //Handling different names used for the same values in different transaction types
        //CUSTOM FIELD
        $custom = '';

        if (isset($_POST['custom'])) {
            $custom = $_POST['custom'];
        }

        if ($custom == '' && isset($_POST['product_name'])) {
            $custom = $_POST['product_name'];
        }

        if ($custom == '' && isset($_POST['transaction_subject'])) {
            $custom = $_POST['transaction_subject'];
        }

        $custom = str_replace('Invoice #', '', $custom);

        //SUNSCRIPTION ID
        $subscr_id = '';

        if (isset($_POST['subscr_id'])) {
            $subscr_id = $_POST['subscr_id'];
        }

        if ($subscr_id == '' && isset($_POST['recurring_payment_id'])) {
            $subscr_id = $_POST['recurring_payment_id'];
        }

        //SUBSCRIPTION DATE
        $subscr_date = '';

        if (isset($_POST['subscr_date'])) {
            $subscr_date = $_POST['subscr_date'];
        }

        if ($subscr_date == '' && isset($_POST['time_created'])) {
            $subscr_date = $_POST['time_created'];
        }
        //Handling different names used for the same values in different transaction types


        $ppTransID = @$_POST['txn_id']; // Transaction ID (Unique) (not defined for subscription cancellations)
        $ppPayStatus = @$_POST['payment_status']; // Payment Status (not defined for subscription cancellations)
        $ppPayAmount = @$_POST['mc_gross']; // Total paid for this transaction (not defined for subscription cancellations)

        $customValues = explode("_", $custom);
        $tInvoiceID = $customValues[0];

        $tIsRecurring = 0;
        if (isset($customValues[1])) {
            $tIsRecurring = $customValues[1];
        }

        $tGenerateInvoice = 1;
        if (isset($customValues[2])) {
            $tGenerateInvoice = $customValues[2];
        }

        $tRecurringExclude  = '';
        if (isset($customValues[3])) {
            $tRecurringExclude = $customValues[3];
        }

        CE_Lib::log(4, "\$ppTransType: $ppTransType; \$ppTransID: $ppTransID; \$ppPayStatus: $ppPayStatus;");
        CE_Lib::log(4, "\$ppPayAmount: $ppPayAmount; \$tInvoiceID: $tInvoiceID; \$tIsRecurring: $tIsRecurring; \$tGenerateInvoice: $tGenerateInvoice \$tRecurringExclude: $tRecurringExclude");

        if (!$logOK) {
            return;
        }

        // Create Plugin class object to interact with CE.
        $cPlugin = new Plugin($tInvoiceID, 'paypal', $this->user);

        // Comfirm the callback before assuming anything
        $exit = false;
        $createTicket = false;
        $maxRetries = 3;
        for ($retry = 1; $retry <= $maxRetries; $retry++) {
            $exit = false;
            $createTicket = false;
            CE_Lib::log(4, "Requesting callback confirmation (attempt $retry of $maxRetries); sending request: $paypal_url?$req");
            try {
                $res = $this->_requestConfirmation($testing);
            } catch (Exception $e) {
                $customerid = $cPlugin->m_Invoice->getUserID();
                $errorLog = Error_EventLog::newInstance(
                    false,
                    (isset($customerid))? $customerid : 0,
                    $tInvoiceID,
                    ERROR_EVENTLOG_PAYPAL_REQUEST_CONFIRMATION,
                    NE_EVENTLOG_USER_SYSTEM,
                    serialize($this->_utf8EncodePaypalCallback($_POST))
                );
                $errorLog->save();

                //exit;
                $exit = true;
                continue;
            }

            CE_Lib::log(4, "Request Confirmation Returned (attempt $retry of $maxRetries): ".$res);
            if (strpos($res, "VERIFIED") !== false) {
                CE_Lib::log(4, "Callback has been verified successfully");
                break;
            } elseif (strpos($res, "INVALID") !== false) {
                CE_Lib::log(4, "Callback verification returned 'INVALID'");
                $transaction = "Paypal IPN returned INVALID to Confirmation.";
                $cPlugin->PaymentRejected($transaction);

                //return;
                $exit = true;
                break;
            } else {
                CE_Lib::log(1, "Callback not returning verification code of 'VERIFIED' or 'INVALID' (attempt $retry of $maxRetries). Original Paypal callback details: ".print_r($_POST, true));

                //return;
                $createTicket = true;
                $exit = true;
            }
        }
        if ($exit) {
            if ($createTicket) {
                $customerid = $cPlugin->m_Invoice->getUserID();
                $message = "There was a PayPal Callback not returning verification code of 'VERIFIED' or 'INVALID'. Attempted verification $maxRetries time(s).\n"
                          ."\n"
                          ."Original Paypal callback details:\n"
                          .print_r($_POST, true)."\n"
                          ."\n"
                          ."When trying to verify, Paypal returned:\n"
                          .$res."\n"
                          ."\n"
                          ."Please make sure to login to your PayPal account and verify the transaction by yourself. Also, you probably will need to take some manual actions over an invoice.\n"
                          ."\n"
                          ."Thanks.";
                if (isset($customerid)) {
                    // GENERATE TICKET
                    $tUser = new User($customerid);
                    $subject = 'Issue when verifying paypal callback';
                    $cPlugin->createTicket(false, $subject, $message, $tUser);
                } else {
                    CE_Lib::log(1, $message);
                }
            }
            exit;
        }

        // Comfirm the callback before assuming anything
        if ($ppTransType == 'subscr_signup') {  // Subscription started
            // Here we should update a field in the first invoice with the subscription date:
            //     $subscr_date
            // Time/Date stamp generated by PayPal , in the following format: HH:MM:SS DD Mmm YY, YYYY PST
            //                                                                22:21:09 Oct 20, 2009 PDT
            // However, I am not believing in this param now, because seems that the documentacion says
            // one thing different than what I am really getting in this parameter.

            //Lets mark the recurring fees as active subscription.
            $cPlugin->setRecurring($tRecurringExclude);

            //Lets avoid updating invoice instance on this callback because there is another callback very close that is also updating the invoice, causing to lost some data.
            //The subscription will be instead updated when getting the payment callback.
            //$cPlugin->setSubscriptionId($subscr_id, $tRecurringExclude);

            $transaction = "Started paypal subscription. Subscription ID: ".$subscr_id;
            $cPlugin->logSubscriptionStarted($transaction, $subscr_id.' '.$subscr_date);

            CE_Lib::log(4, 'Paypal subscr_signup callback ignored');
            return;
        }

        $newInvoice = false;

        // Check to see if this Invoice is not unpaid
        if ($cPlugin->IsUnpaid() == 0 && in_array($ppTransType, array('subscr_payment', 'recurring_payment'))) {
            $cPlugin->setSubscriptionId($subscr_id, $tRecurringExclude);

            CE_Lib::log(4, 'Previous subscription invoice is already paid');
            // If it is, then check to see if the GenerateInvoice variable was passed to the script
            if ($tGenerateInvoice) {
                // If it was and is TRUE (1), set internal Plugin object variables to the existing invoice's information.
                if ($cPlugin->TransExists($ppTransID)) {
                    $newInvoice = $cPlugin->retrieveInvoiceForTransaction($ppTransID);

                    if ($newInvoice && $cPlugin->m_Invoice->isPending()) {
                        CE_Lib::log(4, 'Invoice already exists, and is marked as pending');
                    }
                } else {
                    //Search for existing invoice, unpaid and with same subscription id
                    $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($subscr_id, $ppTransID);

                    if ($newInvoice === false && isset($_POST['old_subscr_id'])) {
                        //Search for existing invoice, unpaid and with the old subscription id
                        $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($_POST['old_subscr_id'], $ppTransID);

                        if ($newInvoice) {
                            CE_Lib::log(4, 'The Subscription ID was changed from '.$_POST['old_subscr_id'].' to '.$subscr_id);

                            //Update the invoice with the new subscription id
                            $cPlugin->setSubscriptionId($subscr_id, $tRecurringExclude);
                        }
                    }

                    if ($newInvoice === false) {
                        $customerid = $cPlugin->m_Invoice->getUserID();

                        //try to generate the customer invoices and search again
                        $billingGateway = new BillingGateway($this->user);
                        $billingGateway->processCustomerBilling($customerid, $subscr_id);

                        //Search for existing invoice, unpaid and with same subscription id
                        $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($subscr_id, $ppTransID);

                        if ($newInvoice === false) {
                            $message = "There was a PayPal subscription payment for subscription ".$subscr_id.".\n"
                                      ."However, the system could not find any pending invoice for this subscription. The PayPal transaction id for the payment is ".$ppTransID.".\n"
                                      ."Please take a look at the customer to confirm if this payment was required.\n"
                                      ."If the payment was not required, please make sure to login to your PayPal account and refund the payment. If the subscription should no longer apply, please cancel the subscription in your PayPal account.\n"
                                      ."\n"
                                      ."Thanks.";
                            if (isset($customerid)) {
                                // GENERATE TICKET
                                $tUser = new User($customerid);
                                $subject = 'Issue with paypal subscription payment';
                                $cPlugin->createTicket($subscr_id, $subject, $message, $tUser);
                            } else {
                                CE_Lib::log(1, $message);
                            }

                            $errorLog = Error_EventLog::newInstance(
                                false,
                                (isset($customerid))? $customerid : 0,
                                $tInvoiceID,
                                ERROR_EVENTLOG_PAYPAL_CALLBACK,
                                NE_EVENTLOG_USER_SYSTEM,
                                serialize($this->_utf8EncodePaypalCallback($_POST))
                            );
                            $errorLog->save();
                            exit;
                        }
                    }
                }
            } else {
                CE_Lib::log(4, 'Exiting Paypal callback invocation');
                exit;
            }
        } elseif ($cPlugin->IsUnpaid() == 0 && $tIsRecurring && $ppTransType != 'refund' && $ppPayStatus != 'Refunded') {
            //LETS SEARCH THE LATEST SUBSCRIPTION INVOICE, NO MATTER THE STATUS
            $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($subscr_id, $ppTransID, false);

            if ($newInvoice === false && isset($_POST['old_subscr_id'])) {
                //LETS SEARCH THE LATEST SUBSCRIPTION INVOICE WITH THE OLD SUBSCRIPTION ID, NO MATTER THE STATUS
                $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($_POST['old_subscr_id'], $ppTransID, false);

                if ($newInvoice) {
                    CE_Lib::log(4, 'The Subscription ID was changed from '.$_POST['old_subscr_id'].' to '.$subscr_id);

                    //Update the invoice with the new subscription id
                    $cPlugin->setSubscriptionId($subscr_id, $tRecurringExclude);
                }
            }

            if ($newInvoice === false) {
                $errorLog = Error_EventLog::newInstance(
                    false,
                    (isset($customerid))? $customerid : 0,
                    $tInvoiceID,
                    ERROR_EVENTLOG_PAYPAL_CALLBACK,
                    NE_EVENTLOG_USER_SYSTEM,
                    serialize($this->_utf8EncodePaypalCallback($_POST))
                );
                $errorLog->save();
                exit;
            }
        }

        //Add plugin details
        $cPlugin->setAmount($ppPayAmount);
        $cPlugin->m_TransactionID = $ppTransID;
        $cPlugin->m_Action = "charge";
        $cPlugin->m_Last4 = "NA";

        // Manage the payment
        // TODO check that receiver_email is an email address in your PayPal account
        if ($tIsRecurring && $ppTransType != 'refund' && $ppPayStatus != 'Refunded') {
            // Uncomment to test payment failures through subscription cancellations
            // if ($ppTransType == 'subscr_cancel') $ppTransType = 'subscr_failed';
            switch ($ppTransType) {
                case 'subscr_payment':  // Subscription payment received
                case 'recurring_payment':  // Recurring payment received
                    switch ($ppPayStatus) {
                        case "Completed": // The payment has been completed, and the funds have been added successfully to your account balance.
                            //The subscription will be updated when getting the payment callback, to avoid a conflcik with the subscr_signup callback
                            $cPlugin->setSubscriptionId($subscr_id, $tRecurringExclude);
                            $transaction = "Paypal payment of $ppPayAmount was accepted. Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                            $cPlugin->PaymentAccepted($ppPayAmount, $transaction, $ppTransID, $testing);
                            break;
                        case "Pending": // The payment is pending. See pending_reason for more information.
                            //The subscription will be updated when getting the payment callback, to avoid a conflcik with the subscr_signup callback
                            $cPlugin->setSubscriptionId($subscr_id, $tRecurringExclude);
                            $transaction = "Paypal payment of $ppPayAmount was marked 'pending' by Paypal. Reason: ".$_POST['pending_reason'].". Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                            $cPlugin->PaymentPending($transaction, $ppTransID);
                            break;
                        case "Failed":  // The payment has failed. This happens only if the payment was made from your customer�s bank account.
                            $transaction = "Paypal payment of $ppPayAmount was rejected. Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                            $cPlugin->PaymentRejected($transaction);
                            break;
                    }
                    break;
                case 'subscr_eot':    // Subscription expired
                    CE_Lib::log(4, "Subscription has expired on Paypal's side.");
                    $tUser = new User($cPlugin->m_Invoice->m_UserID);

                    if (in_array($tUser->getStatus(), StatusAliasGateway::getInstance($this->user)->getUserStatusIdsFor(USER_STATUS_CANCELLED))) {
                        CE_Lib::log(4, 'User is already cancelled. Ignore callback.');
                    } else {
                        $subject = 'Gateway recurring payment expired';
                        $message = "Recurring payment for invoice $tInvoiceID, corresponding to package \"{$_POST['item_name']}\" has expired.";

                        // If createTicket returns false it's because this transaction has already been done
                        // Use subscr_id because txn_id is not sent on cancellation IPNs from Paypal
                        if (!$cPlugin->createTicket($subscr_id, $subject, $message, $tUser)) {
                            exit;
                        }
                    }

                    $transaction = "Paypal subscription has expired. Original Signup Invoice: $tInvoiceID. Subscription ID: ".$subscr_id;
                    $old_processorid = '';

                    if (isset($_POST['old_subscr_id'])) {
                        $old_processorid = $_POST['old_subscr_id'];
                    }

                    $cPlugin->resetRecurring($transaction, $subscr_id, $tRecurringExclude, $tInvoiceID, $old_processorid);
                    break;
                case 'subscr_cancel': // Subscription canceled
                    CE_Lib::log(4, "Subscription has been cancelled on Paypal's side.");
                    $tUser = new User($cPlugin->m_Invoice->m_UserID);

                    if (in_array($tUser->getStatus(), StatusAliasGateway::getInstance($this->user)->getUserStatusIdsFor(USER_STATUS_CANCELLED))) {
                        CE_Lib::log(4, 'User is already cancelled. Ignore callback.');
                    } else {
                        $subject = 'Gateway recurring payment cancelled';
                        $message = "Recurring payment for invoice $tInvoiceID, corresponding to package \"{$_POST['item_name']}\" has been cancelled by customer.";

                        // If createTicket returns false it's because this transaction has already been done
                        // Use subscr_id because txn_id is not sent on cancellation IPNs from Paypal
                        if (!$cPlugin->createTicket($subscr_id, $subject, $message, $tUser)) {
                            exit;
                        }
                    }

                    $transaction = "Paypal subscription cancelled. Original Signup Invoice: $tInvoiceID";
                    $old_processorid = '';

                    if (isset($_POST['old_subscr_id'])) {
                        $old_processorid = $_POST['old_subscr_id'];
                    }

                    $cPlugin->resetRecurring($transaction, $subscr_id, $tRecurringExclude, $tInvoiceID, $old_processorid);
                    break;
                case 'subscr_failed': // Subscription signup failed
                    // this is caused by lack of funds for example
                    $subject = 'Gateway recurring payment failed';
                    $reason = isset($_POST['pending_reason'])? $_POST['pending_reason'] : 'unknown';
                    $message = "Recurring payment for invoice $tInvoiceID, corresponding to package \"{$_POST['item_name']}\" has failed.\n";
                    $message .= "Reason: $reason.";
                    $tUser = new User($cPlugin->m_Invoice->m_UserID);
                    // if createTicket returns false it's because this transaction has already been done
                    if (!$cPlugin->createTicket($subscr_id, $subject, $message, $tUser)) {
                        exit;
                    }
                    // log failed transaction
                    $transaction = "Recurring subscription payment failed. Reason: $reason.";
                    $cPlugin->logFailedSubscriptionPayment($transaction, $subscr_id.' '.$subscr_date);
                    break;
            }
        } elseif ($ppTransType == 'refund' && $ppPayStatus == 'Refunded') {
            $cPlugin->m_Action = "refund";
            $ppPayAmount = str_replace("-", "", $ppPayAmount);

            if (isset($_POST['parent_txn_id'])) {
                $ppParentTransID = $_POST['parent_txn_id'];

                if ($cPlugin->TransExists($ppParentTransID)) {
                    $newInvoice = $cPlugin->retrieveInvoiceForTransaction($ppParentTransID);

                    if ($newInvoice && ($cPlugin->m_Invoice->isPaid() || $cPlugin->m_Invoice->isPartiallyPaid())) {
                        $transaction = "Paypal payment of $ppPayAmount was refunded. Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                        $cPlugin->PaymentRefunded($ppPayAmount, $transaction, $ppTransID);
                    } elseif (!$cPlugin->m_Invoice->isRefunded()) {
                        CE_Lib::log(1, 'Related invoice not found or not set as paid on the application, when doing the refund');
                    }
                } else {
                    CE_Lib::log(1, 'Parent transaction id not matching any existing invoice on the application, when doing the refund');
                }
            } else {
                CE_Lib::log(1, 'Callback not returning parent_txn_id when refunding');
            }
        } elseif (in_array($ppTransType, array('web_accept', 'express_checkout'))) {
            // Add Code for Normal Payment
            switch ($ppPayStatus) {
                case "Completed":
                    $transaction = "Paypal payment of $ppPayAmount was accepted. Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                    $cPlugin->PaymentAccepted($ppPayAmount, $transaction, $ppTransID, $testing);
                    break;
                case "Pending":
                    $transaction = "Paypal payment of $ppPayAmount was marked 'pending' by Paypal. Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                    $cPlugin->PaymentPending($transaction, $ppTransID);
                    break;
                case "Failed":
                    $transaction = "Paypal payment of $ppPayAmount was rejected. Original Signup Invoice: $tInvoiceID (OrderID: ".$ppTransID.")";
                    $cPlugin->PaymentRejected($transaction);
                    break;
            }
        }
    }

    //NEW API CODE
    //Get an access token
    //https://developer.paypal.com/docs/api/overview/#get-an-access-token
    private function getAnAccessToken()
    {
        $request = 'oauth2/token';

        //API Credentials
        $client_id = trim($this->settings->get('plugin_paypal_API Client ID'));
        $secret = trim($this->settings->get('plugin_paypal_API Secret'));

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
    //Execute an agreement
    //https://developer.paypal.com/docs/subscriptions/integrate/integrate-steps/#5-execute-an-agreement
    private function executeAnAgreement($access_token, $token)
    {
        $request = 'payments/billing-agreements/'.$token.'/agreement-execute';

        $header = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        $data = array(
            'grant_type' => 'client_credentials'
        );
        CE_Lib::log(4, 'Paypal Params executeAnAgreement: ' . print_r($data, true));
        $data = json_encode($data);
        $type = 'POST';
        $acceptHTTPcode = false;

        $return = $this->makeRequest($request, $header, $data, $type, $acceptHTTPcode);

        if (!isset($return['id'])) {
            throw new CE_Exception('Execute an agreement has failed. "id" is not defined');
        }
        if (!isset($return['description'])) {
            throw new CE_Exception('Execute an agreement has failed. "description" is not defined');
        }
        if (!isset($return['start_date'])) {
            throw new CE_Exception('Execute an agreement has failed. "start_date" is not defined');
        }

        return $return;
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

    function _requestConfirmation($testing)
    {
        if ($testing) {
            return 'VERIFIED';
        } else {
            $raw_post_data = file_get_contents('php://input');
            $raw_post_array = explode('&', $raw_post_data);
            $myPost = array();

            foreach ($raw_post_array as $keyval) {
                $keyval = explode('=', $keyval);
                if (count($keyval) == 2) {
                    $myPost[$keyval[0]] = urldecode($keyval[1]);
                }
            }

            if ($this->settings->get('plugin_paypal_Use PayPal Sandbox') == '1' && isset($myPost['test_ipn']) && $myPost['test_ipn']) {
                return 'VERIFIED';
            }

            // read the IPN message sent from PayPal and prepend 'cmd=_notify-validate'
            $req = 'cmd=_notify-validate';
            foreach ($myPost as $key => $value) {
                $value = urlencode($value);
                $req .= "&$key=$value";
            }

            // Step 2: POST IPN data back to PayPal to validate
            $ch = curl_init('https://ipnpb.paypal.com/cgi-bin/webscr');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
            curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));
            $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
            if (is_dir($caPathOrFile)) {
                curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
            } else {
                curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
            }
            if (!($res = curl_exec($ch))) {
                CE_Lib::log(1, "PayPal Callback Verification failed: " . curl_error($ch));
                curl_close($ch);
                exit;
            }
            curl_close($ch);
            CE_Lib::log(4, 'PayPal Callback Response: ' . $res);
            return $res;
        }
    }

    //return true if can add the event log
    //return false if can not add the event log
    function _logPaypalCallback($newAPI = false, $response = '')
    {
        //Handling different names used for the same values in different transaction types
        //CUSTOM FIELD
        $custom = '';

        if (isset($_POST['custom'])) {
            $custom = $_POST['custom'];
        }

        if ($custom == '' && isset($_POST['product_name'])) {
            $custom = $_POST['product_name'];
        }

        if ($custom == '' && isset($_POST['transaction_subject'])) {
            $custom = $_POST['transaction_subject'];
        }

        $custom = str_replace('Invoice #', '', $custom);

        //SUNSCRIPTION ID
        $subscr_id = '';

        if (isset($_POST['subscr_id'])) {
            $subscr_id = $_POST['subscr_id'];
        }

        if ($subscr_id == '' && isset($_POST['recurring_payment_id'])) {
            $subscr_id = $_POST['recurring_payment_id'];
        }

        //SUBSCRIPTION DATE
        $subscr_date = '';

        if (isset($_POST['subscr_date'])) {
            $subscr_date = $_POST['subscr_date'];
        }

        if ($subscr_date == '' && isset($_POST['time_created'])) {
            $subscr_date = $_POST['time_created'];
        }
        //Handling different names used for the same values in different transaction types

        if ($newAPI && $response !== '') {
            if (!isset($response['event_type'])) {
                $errorLog = Error_EventLog::newInstance(
                    false,
                    0,
                    0,
                    ERROR_EVENTLOG_PAYPAL_CALLBACK,
                    NE_EVENTLOG_USER_SYSTEM,
                    serialize($this->_utf8EncodePaypalCallback($response))
                );
                $errorLog->save();
                return false;
            }

            $allowedEventTypes = array(
                'BILLING.SUBSCRIPTION.CREATED',
                'PAYMENT.SALE.COMPLETED',
                'PAYMENT.SALE.PENDING',
                'PAYMENT.SALE.DENIED',
                'BILLING.SUBSCRIPTION.CANCELLED',
                'PAYMENT.SALE.REFUNDED'
            );

            if (!in_array($response['event_type'], $allowedEventTypes)) {
                return false;
            }

            $subscriptionID = '';
            $tInvoiceID = '';

            switch ($response['event_type']) {
                case 'BILLING.SUBSCRIPTION.CREATED':
                    if (!isset($response['resource']['id'])) {
                        $errorLog = Error_EventLog::newInstance(
                            false,
                            0,
                            0,
                            ERROR_EVENTLOG_PAYPAL_CALLBACK,
                            NE_EVENTLOG_USER_SYSTEM,
                            serialize($this->_utf8EncodePaypalCallback($response))
                        );
                        $errorLog->save();
                        return false;
                    }

                    $subscriptionID = $response['resource']['id'];
                    break;
                case 'PAYMENT.SALE.COMPLETED':
                case 'PAYMENT.SALE.PENDING':
                case 'PAYMENT.SALE.DENIED':
                    if (!isset($response['resource']['billing_agreement_id'])) {
                        if (!isset($response['resource']['id'])) {
                            $errorLog = Error_EventLog::newInstance(
                                false,
                                0,
                                0,
                                ERROR_EVENTLOG_PAYPAL_CALLBACK,
                                NE_EVENTLOG_USER_SYSTEM,
                                serialize($this->_utf8EncodePaypalCallback($response))
                            );
                            $errorLog->save();
                            return false;
                        }

                        $cPlugin = new Plugin();
                        $newInvoice = $cPlugin->retrieveInvoiceForTransaction($response['resource']['id']);

                        if ($newInvoice) {
                            $tInvoiceID = $cPlugin->m_ID;
                        } elseif (isset($this->params['invoiceId'])) {
                            $tInvoiceID = $this->params['invoiceId'];
                        }
                    } else {
                        $subscriptionID = $response['resource']['billing_agreement_id'];
                    }

                    break;
                case 'BILLING.SUBSCRIPTION.CANCELLED':
                    if (!isset($response['resource']['id'])) {
                        $errorLog = Error_EventLog::newInstance(
                            false,
                            0,
                            0,
                            ERROR_EVENTLOG_PAYPAL_CALLBACK,
                            NE_EVENTLOG_USER_SYSTEM,
                            serialize($this->_utf8EncodePaypalCallback($response))
                        );
                        $errorLog->save();
                        return false;
                    }

                    $subscriptionID = $response['resource']['id'];
                    break;
                case 'PAYMENT.SALE.REFUNDED':
                    if (!isset($response['resource']['sale_id'])) {
                        $errorLog = Error_EventLog::newInstance(
                            false,
                            0,
                            0,
                            ERROR_EVENTLOG_PAYPAL_CALLBACK,
                            NE_EVENTLOG_USER_SYSTEM,
                            serialize($this->_utf8EncodePaypalCallback($response))
                        );
                        $errorLog->save();
                        return false;
                    }

                    $cPlugin = new Plugin();
                    $newInvoice = $cPlugin->retrieveInvoiceForTransaction($response['resource']['sale_id']);

                    if ($newInvoice) {
                        $tInvoiceID = $cPlugin->m_ID;
                    } elseif (isset($this->params['invoiceId'])) {
                        $tInvoiceID = $this->params['invoiceId'];
                    }

                    break;
            }

            if ($subscriptionID === '' && $tInvoiceID === '') {
                $errorLog = Error_EventLog::newInstance(
                    false,
                    0,
                    0,
                    ERROR_EVENTLOG_PAYPAL_CALLBACK,
                    NE_EVENTLOG_USER_SYSTEM,
                    serialize($this->_utf8EncodePaypalCallback($response))
                );
                $errorLog->save();
                return false;
            }

            if ($subscriptionID !== '') {
                $access_token = '';
                $agreement = array();

                //Get an access token
                //https://developer.paypal.com/docs/api/overview/#get-an-access-token
                $access_token = $this->getAnAccessToken();

                //Show agreement details
                //https://developer.paypal.com/docs/api/payments.billing-agreements/v1/#billing-agreements_get
                $agreement = $this->showAgreementDetails($access_token, $subscriptionID);

                // search the customer id based on the invoice id
                if (isset($this->params) && isset($_REQUEST['redirected']) && $_REQUEST['redirected'] == 1) {
                    $ppTransID = $response['resource']['id'];
                    $cPlugin = new Plugin(0, 'paypal', $this->user);

                    if ($cPlugin->TransExists($ppTransID)) {
                        $newInvoice = $cPlugin->retrieveInvoiceForTransaction($ppTransID);

                        if ($newInvoice) {
                            $tInvoiceID = $cPlugin->m_Invoice->getId();
                        }
                    } else {
                        //Search for existing invoice, unpaid and with same subscription id
                        $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($agreement['id'], $ppTransID);

                        if ($newInvoice) {
                            $tInvoiceID = $cPlugin->m_Invoice->getId();
                        } else {
                            //get client id based on the subscription id of recurring fees
                            $customerid = $cPlugin->retrieveUserdIdForSubscription($agreement['id']);

                            if ($customerid !== false) {
                                //try to generate the customer invoices and search again
                                $billingGateway = new BillingGateway($this->user);
                                $billingGateway->processCustomerBilling($customerid, $agreement['id']);

                                //Search for existing invoice, unpaid and with same subscription id
                                $newInvoice = $cPlugin->retrieveLastInvoiceForSubscription($agreement['id'], $ppTransID);

                                if ($newInvoice) {
                                    $tInvoiceID = $cPlugin->m_Invoice->getId();
                                }
                            }
                        }
                    }
                } else {
                    $customValues = explode("_", $agreement['description']);
                    $tInvoiceID = $customValues[0];
                }

                if (!is_numeric($tInvoiceID)) {
                    $tInvoiceID = '';
                }
            }

            $query = "SELECT `customerid` "
                    ."FROM `invoice` "
                    ."WHERE `id` = ? ";
            $result = $this->db->query($query, $tInvoiceID);
            list($customerid) = $result->fetch();

            $invoiceNotFound = false;

            if (!isset($customerid)) {
                $invoiceNotFound = true;

                if ($subscriptionID !== '') {
                    // search the customer id based on the email address
                    $query = "SELECT `id` "
                            ."FROM `users` "
                            ."WHERE `email` = ? ";
                    $result = $this->db->query($query, $agreement['payer']['payer_info']['email']);
                    list($customerid) = $result->fetch();
                }
            }

            if (!isset($customerid) || $invoiceNotFound) {
                $errorLog = Error_EventLog::newInstance(
                    false,
                    (isset($customerid))? $customerid : 0,
                    $tInvoiceID,
                    ERROR_EVENTLOG_PAYPAL_CALLBACK,
                    NE_EVENTLOG_USER_SYSTEM,
                    serialize($this->_utf8EncodePaypalCallback($response))
                );
                $errorLog->save();
                return false;
            } else {
                $invoiceLog = Invoice_EventLog::newInstance(
                    false,
                    $customerid,
                    $tInvoiceID,
                    INVOICE_EVENTLOG_PAYPAL_CALLBACK,
                    NE_EVENTLOG_USER_SYSTEM,
                    serialize($this->_utf8EncodePaypalCallback($response))
                );
                $invoiceLog->save();
                return true;
            }
        }

        if ($custom == '') {
            if (!isset($_POST['txn_type']) || $_POST['txn_type'] != 'new_case') {
                $errorLog = Error_EventLog::newInstance(
                    false,
                    0,
                    0,
                    ERROR_EVENTLOG_PAYPAL_CALLBACK,
                    NE_EVENTLOG_USER_SYSTEM,
                    serialize($this->_utf8EncodePaypalCallback($_POST))
                );
                $errorLog->save();
            }

            return false;
        }

        // search the customer id based on the invoice id
        $customValues = explode("_", $custom);
        $tInvoiceID = $customValues[0];

        $query = "SELECT `customerid` "
                ."FROM `invoice` "
                ."WHERE `id` = ? ";
        $result = $this->db->query($query, $tInvoiceID);
        list($customerid) = $result->fetch();

        $invoiceNotFound = false;

        if (!isset($customerid)) {
            $invoiceNotFound = true;

            // search the customer id based on the email address
            $query = "SELECT `id` "
                    ."FROM `users` "
                    ."WHERE `email` = ? ";
            $result = $this->db->query($query, $_POST['payer_email']);
            list($customerid) = $result->fetch();
        }

        if (!isset($customerid) || $invoiceNotFound) {
            $errorLog = Error_EventLog::newInstance(
                false,
                (isset($customerid))? $customerid : 0,
                $tInvoiceID,
                ERROR_EVENTLOG_PAYPAL_CALLBACK,
                NE_EVENTLOG_USER_SYSTEM,
                serialize($this->_utf8EncodePaypalCallback($_POST))
            );
            $errorLog->save();
            return false;
        } else {
            $invoiceLog = Invoice_EventLog::newInstance(
                false,
                $customerid,
                $tInvoiceID,
                INVOICE_EVENTLOG_PAYPAL_CALLBACK,
                NE_EVENTLOG_USER_SYSTEM,
                serialize($this->_utf8EncodePaypalCallback($_POST))
            );
            $invoiceLog->save();
            return true;
        }
    }

    //return array with the utf8 encoded values of the original array
    function _utf8EncodePaypalCallback($callbackPost)
    {
        if (is_array($callbackPost)) {
            foreach ($callbackPost as $postKey => $postValue) {
                if (is_array($postValue)) {
                    $callbackPost[$postKey] = $this->_utf8EncodePaypalCallback($postValue);
                } else {
                    $callbackPost[$postKey] = utf8_encode($postValue);
                }
            }
        } else {
            $callbackPost = array();
        }

        return $callbackPost;
    }
}
