<?php
$nzshpcrt_gateways[$num]['name'] = __( 'Paybox', 'wpsc' );
$nzshpcrt_gateways[$num]['internalname'] = 'paybox';
$nzshpcrt_gateways[$num]['function'] = 'gateway_paybox';
$nzshpcrt_gateways[$num]['form'] = "form_paybox";
$nzshpcrt_gateways[$num]['submit_function'] = "submit_paybox";
$nzshpcrt_gateways[$num]['display_name'] = __( 'PayBox', 'wpsc' );
$nzshpcrt_gateways[$num]['image'] = WPSC_URL . '/images/paybox.png';

require_once 'paybox/PG_Signature.php';

// создание транзакции
function gateway_paybox($separator, $sessionid)
{
	global $wpdb;
	$purchase_log_sql = $wpdb->prepare( "SELECT * FROM `".WPSC_TABLE_PURCHASE_LOGS."` WHERE `sessionid`= %s LIMIT 1", $sessionid );
	$purchase_log = $wpdb->get_results($purchase_log_sql,ARRAY_A) ;

	$strCartSql = "SELECT * FROM `".WPSC_TABLE_CART_CONTENTS."` WHERE `purchaseid`='".$purchase_log[0]['id']."'";
	$arrCartItems = $wpdb->get_results($strCartSql,ARRAY_A) ;

	$strCurrency = WPSC_Countries::get_currency_code( get_option( 'currency_type' ) );
	if($strCurrency == 'RUR')
		$strCurrency = 'RUB';

	$arrEmailData = $wpdb->get_results("SELECT `id`,`type` FROM `".WPSC_TABLE_CHECKOUT_FORMS."` WHERE `name` IN ('email','Email') AND `active` = '1'",ARRAY_A);
	foreach((array)$arrEmailData as $email)
    	$strEmail = $_POST['collected_data'][$email['id']];

  	if(($_POST['collected_data'][get_option('email_form_field')] != null) && ($strEmail == null))
    	$strEmail = $_POST['collected_data'][get_option('email_form_field')];

	$arrPhoneData = $wpdb->get_results("SELECT `id`,`type` FROM `".WPSC_TABLE_CHECKOUT_FORMS."` WHERE `name` IN ('phone','Phone') AND `active` = '1'",ARRAY_A);
	foreach((array)$arrPhoneData as $phone)
    	$strPhone = $_POST['collected_data'][$phone['id']];

  	if(($_POST['collected_data'][get_option('phone_form_field')] != null) && ($strPhone == null))
    	$strPhone = $_POST['collected_data'][get_option('phone_form_field')];

	$strDescription = 'Продукт(ы): ';
	foreach($arrCartItems as $arrItem){
		$strDescription .= $arrItem['name'];
		if($arrItem['quantity'] > 1)
			$strDescription .= '*'.$arrItem['quantity']."; ";
		else
			$strDescription .= "; ";
	}

	$strUrlToCallBack = add_query_arg( 'paybox_callback', 'true', home_url( '/index.php' ) ) . "&session_id=" . $sessionid;

	$arrFields = array(
		'pg_merchant_id'		=> get_option( 'merchant_id' ),
		'pg_order_id'			=> $purchase_log[0]['id'],
		'pg_currency'			=> $strCurrency,
		'pg_amount'				=> number_format($purchase_log[0]['totalprice'], 2, '.', ''),
		'pg_user_phone'			=> $strPhone,
		'pg_user_email'			=> $strEmail,
		'pg_user_contact_email'	=> $strEmail,
		'pg_lifetime'			=> (get_option( 'lifetime' ))?get_option('lifetime')*60:0,
		'pg_testing_mode'		=> get_option( 'testmode' ),
		'pg_description'		=> $strDescription,
		'pg_user_ip'			=> $_SERVER['REMOTE_ADDR'],
		'pg_language'			=> (WPLANG == 'ru_RU')?'ru':'en',
		'pg_check_url'			=> $strUrlToCallBack . '&type=check',
		'pg_result_url'			=> $strUrlToCallBack . '&type=result',
		'pg_request_method'		=> 'GET',
		'pg_salt'				=> rand(21,43433), // Параметры безопасности сообщения. Необходима генерация pg_salt и подписи сообщения.
	);

	$strSuccessUrl = get_option( 'success_url' );
	if(isset($strSuccessUrl))
		$arrFields['pg_success_url'] = $strSuccessUrl;

	$strFailureUrl = get_option( 'failure_url' );
	if(isset($strFailureUrl))
		$arrFields['pg_failure_url'] = $strFailureUrl;

	$strPaymentSystemName = get_option( 'payment_system_name' );
	if(!empty($strPaymentSystemName))
		$arrFields['pg_payment_system'] = $strPaymentSystemName;

	$arrFields['pg_sig'] = PG_Signature::make('payment.php', $arrFields, get_option( 'secret_key' ));

	if(WPSC_GATEWAY_DEBUG == true ) {
		exit("<pre>".print_r($arrFields,true)."</pre>");
	}


	// Create Form to post to Paybox
	$output = "
		<form id=\"paybox_form\" name=\"paybox_form\" method=\"post\" action=\"https://api.paybox.money/payment.php\">\n";

	foreach($arrFields as $strName=>$strValue) {
			$output .= "			<input type=\"hidden\" name=\"$strName\" value=\"$strValue\" />\n";
	}

	$output .= "			<input type=\"submit\" value=\"Pay\" />
		</form>
		<script language=\"javascript\" type=\"text/javascript\">document.getElementById('paybox_form').submit();</script>
	";

	echo $output;
  	exit();
}

function nzshpcrt_paybox_callback()
{

	if(isset($_GET['paybox_callback']))
	{

		global $wpdb;
		// needs to execute on page start
		// look at page 36

		if(!empty($_POST))
			$arrRequest = $_POST;
		else
			$arrRequest = $_GET;

		$thisScriptName = PG_Signature::getOurScriptName();
		if (empty($arrRequest['pg_sig']) || !PG_Signature::check($arrRequest['pg_sig'], $thisScriptName, $arrRequest, get_option( 'secret_key' )))
			die("Wrong signature");


		$arrAllStatuses = array(
			'1' => 'pending',
			'2'	=> 'completed',
			'3' => 'ok',
			'4' => 'processed',
			'5'	=> 'closed',
			'6' => 'rejected',
		);
		$aGoodCheckStatuses = array(
			'1' => 'pending',
			'4' => 'processed',
		);

		$aGoodResultStatuses = array(
			'1' => 'pending',
			'2'	=> 'completed',
			'3' => 'ok',
			'4' => 'processed',
		);

		$purchase_log_sql = $wpdb->prepare( "SELECT * FROM `".WPSC_TABLE_PURCHASE_LOGS."` WHERE `id`= %s LIMIT 1", $arrRequest['pg_order_id'] );
		$purchase_log = $wpdb->get_results($purchase_log_sql,ARRAY_A);
		$nRealOrderStatus = $purchase_log[0]['processed'];
		$nTotalPrice = $purchase_log[0]['totalprice'];

		switch($arrRequest['type']){
			case 'check':
				$bCheckResult = 1;
				if(empty($purchase_log) || !array_key_exists($nRealOrderStatus, $aGoodCheckStatuses)){
					$bCheckResult = 0;
					$error_desc = 'Order status '.$arrAllStatuses[$nRealOrderStatus].' or deleted order';
				}
				if(intval($nTotalPrice) != intval($arrRequest['pg_amount'])){
					$bCheckResult = 0;
					$error_desc = 'Wrong amount';
				}

				$arrResponse['pg_salt']              = $arrRequest['pg_salt'];
				$arrResponse['pg_status']            = $bCheckResult ? 'ok' : 'error';
				$arrResponse['pg_error_description'] = $bCheckResult ?  ""  : $error_desc;
				$arrResponse['pg_sig']				 = PG_Signature::make($thisScriptName, $arrResponse, get_option( 'secret_key' ));

				$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
				$objResponse->addChild('pg_salt', $arrResponse['pg_salt']);
				$objResponse->addChild('pg_status', $arrResponse['pg_status']);
				$objResponse->addChild('pg_error_description', $arrResponse['pg_error_description']);
				$objResponse->addChild('pg_sig', $arrResponse['pg_sig']);
				break;
			case 'result':
				if(intval($nTotalPrice) != intval($arrRequest['pg_amount'])){
					$strResponseDescription = 'Wrong amount';
					if($arrRequest['pg_can_reject'] == 1)
						$strResponseStatus = 'rejected';
					else
						$strResponseStatus = 'error';
				}
				elseif((empty($purchase_log) || !array_key_exists($nRealOrderStatus, $aGoodResultStatuses)) &&
						!($arrRequest['pg_result'] == 0 && $nRealOrderStatus == array_search('failed', $arrAllStatuses))){
					$strResponseDescription = 'Order status '.$arrAllStatuses[$nRealOrderStatus].' or deleted order';
					if($arrRequest['pg_can_reject'] == 1)
						$strResponseStatus = 'rejected';
					else
						$strResponseStatus = 'error';
				} else {
					$strResponseStatus = 'ok';
					$strResponseDescription = "Request cleared";
					if ($arrRequest['pg_result'] == 1){
						$data = array(
							'processed'  => array_search('ok', $arrAllStatuses),
							'transactid' => $arrRequest['pg_transaction_id'],
							'date'       => time(),
						);
						wpsc_update_purchase_log_details( $arrRequest['session_id'], $data, 'sessionid' );
					}
					else{
						$data = array(
							'processed'  => array_search('rejected', $arrAllStatuses),
							'transactid' => $arrRequest['pg_transaction_id'],
							'date'       => time(),
						);
						wpsc_update_purchase_log_details( $arrRequest['session_id'], $data, 'sessionid' );
					}
				}
				transaction_results($arrRequest['session_id'], false, $arrRequest['pg_transaction_id']);

				$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
				$objResponse->addChild('pg_salt', $arrRequest['pg_salt']);
				$objResponse->addChild('pg_status', $strResponseStatus);
				$objResponse->addChild('pg_description', $strResponseDescription);
				$objResponse->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $objResponse, get_option( 'secret_key' )));
				break;
			default:
				die('Wrong type request');
				break;
		}

		header("Content-type: text/xml");
		echo $objResponse->asXML();
		die();
	}
}

function nzshpcrt_paybox_results()
{
	// Function used to translate the ChronoPay returned cs1=sessionid POST variable into the recognised GET variable for the transaction results page.
	if(isset($_POST['cs1']) && ($_POST['cs1'] !='') && ($_GET['sessionid'] == ''))
	{
		$_GET['sessionid'] = $_POST['cs1'];
	}
}


// сохранение формы из админки
function submit_paybox()
{
	if(isset($_POST['merchant_id']))
    {
    	update_option('merchant_id', $_POST['merchant_id']);
    }

	if(isset($_POST['secret_key']))
    {
    	update_option('secret_key', $_POST['secret_key']);
    }

  	if(isset($_POST['testmode']))
    {
    	update_option('testmode', $_POST['testmode']);
    }

  	if(isset($_POST['lifetime']))
    {
    	update_option('lifetime', $_POST['lifetime']);
    }

	 if(isset($_POST['success_url']))
    {
    	update_option('success_url', $_POST['success_url']);
    }

	 if(isset($_POST['failure_url']))
    {
    	update_option('failure_url', $_POST['failure_url']);
    }

 	if(isset($_POST['payment_system_name']))
    {
    	update_option('payment_system_name', $_POST['payment_system_name']);
    }

	return true;
}

// вывод полей в админке
function form_paybox()
{
	$paybox_testmode = get_option('testmode');
	$testmode_yes = "";
	$testmode_no = "";
	switch($paybox_testmode)
	{
		case 0:
			$testmode_yes = "checked ='checked'";
			break;
		case 1:
			$testmode_no = "checked ='checked'";
			break;
	}

	$output = "
		<tr>
			<td>" . __( 'Merchant ID', 'wpsc' ) . "</td>
			<td>
				<input type='text' size='40' value='" . get_option( 'merchant_id' ) . "' name='merchant_id' />
				<p class='description'>
					" . __( 'Your merchant number. You can find it in the PayBox <a href="https://my.paybox.money">admin</a>.', 'wpsc' ) . "
				</p>
			</td>
		</tr>
		<tr>
			<td>" . __( 'Secret key', 'wpsc' ) . "</td>
			<td>
				<input type='text' size='40' value='" . get_option( 'secret_key' ) . "' name='secret_key' />
				<p class='description'>
					" . __( 'This key will be used to make signature.', 'wpsc' ) . "
				</p>
		</tr>
		<tr>
			<td>" . __( 'Testing mode', 'wpsc' ) . "</td>
			<td>
				<input type='radio' value='1' name='testmode' id='testmode_yes' " . $testmode_yes . " /> <label for='testmode_yes'>".__('Yes', 'wpsc')."</label> &nbsp;
				<input type='radio' value='0' name='testmode' id='testmode_no' " . $testmode_no . " /> <label for='testmode_no'>".__('No', 'wpsc')."</label>
				<p class='description'>
					" . __( 'Debug mode is used to write HTTP communications between the ChronoPay server and your host to a log file.  This should only be activated for testing!', 'wpsc' ) . "
				</p>
		</tr>
		<tr>
			<td>" . __( 'Transaction life time', 'wpsc' ) . "</td>
			<td>
				<input type='text' size='40' value='" . get_option( 'lifetime' ) . "' name='lifetime' />
				<p class='description'>
					" . __( 'If payment system dont support check or reject you need to set payment lifetime. Min 5 minute max 10800 (7 days).', 'wpsc' ) . "
				</p>
		</tr>
		<tr>
			<td>" . __( 'Success url', 'wpsc' ) . "</td>
			<td>
				<input type='text' size='80' value='" . get_option( 'success_url' ) . "' name='success_url' />
				<p class='description'>
					" . __( 'Url, where customer returned to see success transaction result. You can set it as example: www.your.domain/?page_id=7 (page customer account to see purchase result) or other page', 'wpsc' ) . "
				</p>
		</tr>
		<tr>
			<td>" . __( 'Failure url', 'wpsc' ) . "</td>
			<td>
				<input type='text' size='80' value='" . get_option( 'failure_url' ) . "' name='failure_url' />
				<p class='description'>
					" . __( 'Url, where customer returned to see failed transaction result. You can set it as example: www.your.domain/?page_id=7 (page customer account to see purchase result) or other page', 'wpsc' ) . "
				</p>
		</tr>
		<tr>
			<td>" . __( 'Payment system name', 'wpsc' ) . "</td>
			<td>
				<input type='text' size='40' value='" . get_option( 'payment_system_name' ) . "' name='payment_system_name' />
				<p class='description'>
					" . __( 'If you want customer to choose payment system on merchant side - set in paramenter. And copy plugin with rename "PayBox" so many times so many payment systems you have.', 'wpsc' ) . "
				</p>
		</tr>";

	return $output;
}


add_action('init', 'nzshpcrt_paybox_callback');
add_action('init', 'nzshpcrt_paybox_results');

?>
