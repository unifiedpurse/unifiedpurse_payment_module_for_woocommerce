<?php
/**
 * Plugin Name: Unifiedpurse WooCommerce Payment Gateway
 * Plugin URI: https://unifiedpurse.com
 * Description: Through Unifiedpurse, you can accept payments via Bitcoin and over 80 alternatives.
 * Version: 1.0.0
 * Author: Tormuto Information Technologies
 * Author URI: http://tormuto.com/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * WC tested up to: 3.9.3
*/
	
if ( ! defined( 'ABSPATH' ) ) {exit; }
add_action('plugins_loaded', 'wc_unifiedpurse_pay_gateway', 99);

function wc_unifiedpurse_pay_gateway(){
    if (!class_exists( 'WC_Payment_Gateway')){ return; }

	class WC_Gateway_UnifiedpursePayment extends WC_Payment_Gateway{

		public function __construct(){
			$this->id			= 'unifiedpurse';
			$this->method_title = __('UnifiedPurse - Bitcoin or 80+ alternative payment methods', 'woothemes');
            $this->assets_base  =   WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/assets/';
	        $this->icon_alt 		= $this->assets_base.'images/logo.png';
            $this->icon ="";
            $this->supported_currencies=array(); //'USD','EUR'
            $this->db_tablename = 'wc_woo_unifiedpurse';

	        $this->has_fields 	= false;
			// Load the form fields
			$this->init_form_fields();			
			// Load the settings.
			$this->init_settings();
		
			// Define user set variables
			$this->enabled = $this->settings['enabled'];
			$this->title = $this->settings['title'];
			$this->unifiedpurse_merchant_id = $this->settings['unifiedpurse_merchant_id'];
			$this->subjecttext = $this->settings['subjecttext'];
			$this->description = $this->settings['description'];

			// Actions
			//add_action( 'init', array(& $this, 'check_callback') );
			//add_action( 'valid_unifiedpurse_callback', array(& $this, 'successful_request'));
			add_action( 'woocommerce_api_' . strtolower( get_class($this) ), array( $this, 'check_callback' ) );
			add_action(	'woocommerce_update_options_payment_gateways', array(& $this, 'process_admin_options', ));
			add_action(	'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action(	'woocommerce_receipt_unifiedpurse', array( $this, 'receipt'));
			//add_action(	'woocommerce_thankyou_unifiedpurse', array( $this, 'thankyou'));

			if (!$this->is_valid_for_use()) $this->enabled = false;			
		}

		/**
		* Check if this gateway is enabled and available in the users country
		*/		
		function is_valid_for_use(){
			if(!empty($this->supported_currencies)&&!in_array(get_option('woocommerce_currency'),$this->supported_currencies)) return false;
			return true;
		}
		
		public function admin_options(){
	    	?>
			<h3><?php _e('UnifiedPurse Payment', 'woothemes'); ?></h3>
			<p><?php _e('With Unifiedpurse you can accept payments through, Bitcoin, Litecoin, Ethereum and over 80 alternatives.  If you need help with the payment module, <a href="https://unifiedpurse.com/docs" target="_blank">click here for a Payment module description</a>', 'woothemes'); ?></p>
	    	<table class="form-table">
	    	<?php
	    		if ( $this->is_valid_for_use() ) :	    	
	    			// Generate the HTML For the settings form.
	    			$this->generate_settings_html();	    		
	    		else :	    		
	    			?>
	            		<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woothemes' ); ?></strong>: <?php _e( 'Unifiedpurse Direct does not support your store currency.', 'woothemes' ); ?></p></div>
	        		<?php	        		
	    		endif;
	    	?>
			</table><!--/.form-table-->
	    	<p class="auto-style1">&nbsp;</p>

	    	<?php
	    }		

			
		function init_form_fields(){
			global $woocommerce;
			
			$this->form_fields = array(
				'enabled' => array(
				'title' => __( 'Enable/Disable', 'woothemes' ),
				'type' => 'checkbox',
				'label' => __( 'Enable Unifiedpurse Payment', 'woothemes' ),
				'default' => 'yes'
				),
				'title' => array(
				'title' => __( 'Title', 'woothemes' ),
				'type' => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ),
				'default' => __( 'UnifiedPurse - Bitcoin and 80+ alternative payment methods', 'woothemes' )
				),
				'description' => array(
				'title' => __( 'Description', 'woothemes' ),
				'type' => 'text',
				'description' => __( 'Unifiedpurse Payment description', 'woothemes' ),
				'default' => __( 'Universal payment methods via UnifiedPurse', 'woothemes' )
				),
				'unifiedpurse_merchant_id' => array(
				'title' => __( 'Unifiedpurse Username or Email', 'woothemes' ),
				'type' => 'text',
				'description' => __( 'Your username on Unifiedpurse (<a href="https://unifiedpurse.com/profile" target="_blank">https://unifiedpurse.com/profile</a>)', 'woothemes' ),
				'default' => __( 'XXXXXXX', 'woothemes' )
				),
				'subjecttext' => array(
				'title' => __( 'Payment Subject', 'woothemes' ),
				'type' => 'text',
				'description' => __( 'What will be shown in the Subject field on the Netpayment before the order id.', 'woothemes' ),
				'default' => __( 'Order:', 'woothemes' )
				),
			);
		}

		function payment_fields()	
		{
			if ($this->description) echo wpautop(wptexturize($this->description));
		}

		/**
		 * Process the payment and return the result
		 **/			
		
		function generate_unifiedpurse_form($order_id){
			global $woocommerce;
			global $wpdb;


			$sql="CREATE TABLE IF NOT EXISTS {$this->db_tablename}(
					id INT NOT NULL AUTO_INCREMENT,PRIMARY KEY(id),
					order_id INT NOT NULL DEFAULT 0,unique(order_id),
					time INT NOT NULL DEFAULT 0,
					transaction_reference VARCHAR(68) NOT NULL DEFAULT '',INDEX(transaction_reference),
					approved_amount DOUBLE NOT NULL DEFAULT 0,
					customer_email VARCHAR(160) NOT NULL DEFAULT '',
					response_description VARCHAR(225) NOT NULL DEFAULT '',
					response_code VARCHAR(5) NOT NULL DEFAULT '',
					external_ref VARCHAR(225) NOT NULL DEFAULT '',INDEX(external_ref),
					transaction_amount DOUBLE NOT NULL DEFAULT 0,
					customer_firstname VARCHAR(60) NOT NULL DEFAULT '',
					customer_lastname VARCHAR(60) NOT NULL DEFAULT '',
					customer_phone VARCHAR(32) NOT NULL DEFAULT '',
					customer_id VARCHAR(160) NOT NULL DEFAULT '',
					currency_code VARCHAR(3) NOT NULL DEFAULT '',
					status TINYINT(1) NOT NULL DEFAULT '0',
                    json_details TEXT
					)";
			$wpdb->query($sql);
			
			$order = new WC_order( $order_id );		
			/* Amount Conversion */
			//$amountTotal = $order->order_total*100; 
			$amountTotal = $order->order_total; 			
			/* Subject */
			$unifiedpurseSubject = $this->subjecttext.''.$order->id;
			/* Currency */
			$unifiedpurseCurrency = $woocommerce_currency = get_woocommerce_currency();
			/* URLs */
			$time=$transaction_reference=time();			
			$callbackURL=WC()->api_request_url( get_class($this) );
			if(!strstr($callbackURL,'?'))$callbackURL.='?';
			$callbackURL.="&ref=$transaction_reference";
			
			//$acceptURL = $this->get_return_url( $order );
			//$callbackAcceptURL = add_query_arg ('wooorderid', $order_id, add_query_arg ('wc-api', get_class($this), $this->get_return_url( $order )));

			$notify_url=$callbackURL;
			$customer_email=$order->billing_email;
			$customer_firstname=$order->billing_first_name;
			$customer_lastname=$order->billing_last_name;
            $customer_phone=$order->get_billing_phone();
			
			//"description" => $unifiedpurseSubject,
			
			$customer_fullname="$customer_firstname $customer_lastname";
			$res=$wpdb->get_results("SELECT * FROM {$this->db_tablename} WHERE order_id='$order_id' AND status=0 LIMIT 1");
			if(!empty($res)){
					$transaction=(array)current($res);	
					$sql="UPDATE {$this->db_tablename} SET
						transaction_reference='$transaction_reference',time='$time',
						transaction_amount='$amountTotal',
						currency_code='$unifiedpurseCurrency',
                        customer_email='$customer_email',
                        customer_phone='$customer_phone',
						customer_firstname='$customer_firstname',customer_lastname='$customer_lastname',customer_id='$customer_email'
						WHERE id={$transaction['id']} LIMIT 1";					
			}
			else { 

				$sql="INSERT INTO {$this->db_tablename}
				(order_id,transaction_reference,time,transaction_amount,currency_code,customer_email,customer_phone,customer_firstname,customer_lastname,customer_id) 
				VALUES('$order_id','$transaction_reference','$time','$amountTotal','$unifiedpurseCurrency','$customer_email','$customer_phone','$customer_firstname','$customer_lastname','$customer_email')";
			}
			$wpdb->query($sql);
		
			$unifiedpurse_merchant_id=$this->unifiedpurse_merchant_id;
			$form_action='https://unifiedpurse.com/sci';
			$item_id=$order->id;

			$input_fields="
	<input type='hidden' name='amount' value='$amountTotal' />
	<input type='hidden' name='receiver' value='$unifiedpurse_merchant_id' />
	<input type='hidden' name='ref' value='$transaction_reference' />
	<input type='hidden' name='email' value='$customer_email' />
	<input type='hidden' name='currency' value='$unifiedpurseCurrency' />
	<input type='hidden' name='memo' value=\"$unifiedpurseSubject\" />
	<input type='hidden' name='notification_url' value='$notify_url' />
	<input type='hidden' name='success_url' value='$notify_url' />
	<input type='hidden' name='cancel_url' value='$notify_url' />";
			
			$order->update_status('Processing', __('Pending payment from Unifiedpurse', 'woothemes'));
			/*
			jQuery("body").block({ 
						message: "<img src=\"'.esc_url( $woocommerce->plugin_url() ).'/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" style=\"float:left; margin-right: 10px;\" />'.__('Thank you for your order. We are now redirecting you to Unifiedpurse to make the payment.', 'woothemes').'", 
						overlayCSS: 
						{ 
							background: "#fff", 
							opacity: 0.6 
						},
						css: { 
							padding:        20, 
							textAlign:      "center", 
							color:          "#555", 
							border:         "3px solid #aaa", 
							backgroundColor:"#fff", 
							cursor:         "wait",
							lineHeight:		"32px"
						} 
					});
			*/
			$js_script='<script type="text/javascript">
				jQuery("#unifiedpurse_payment_form").submit();
			</script>';
			
			$mail_headers = "MIME-Version: 1.0"."\r\n";
			//$mail_headers .= "Content-Type: text/html; charset=\"iso-8859-1\""."\r\n";
			//$mail_message=str_replace(array("\r\n", "\n\r", "\r", "\n",'\r\n', '\n\r', '\r', '\n',),"<br/>",$mail_message);
			$mail_headers .= "X-Priority: 1 (Highest)"."\r\n";
			$mail_headers .= "X-MSMail-Priority: High"."\r\n";
			$mail_headers .= "Importance: High"."\r\n";
		
			$domain=$_SERVER['HTTP_HOST'];
			if(substr($domain,0,4)=='www.')$domain=substr($domain,4);
            $site_name=get_option('blogname');
			$mail_from="$site_name<no-reply@$domain>";
			$mail_headers.="From: $mail_from" . "\r\n";
			$mail_headers.= 'X-Mailer: PHP/' . phpversion();
			
			$history_params=array('wc-api'=>get_class($this),'load_ippay_as'=>'1','wooorderid'=>$order_id,'ref'=>$transaction_reference);
			$checkout_url = $woocommerce->cart->get_checkout_url();
			$history_url = add_query_arg ($history_params, $checkout_url);
			
			
			$transaction_date=date('d-m-Y g:i a');
			$mail_message="Here are the details of your transaction:\r\n\r\nTRANSACTION REFERENCE: $transaction_reference\r\nNAME:$customer_fullname\r\nTOTAL AMOUNT: $amountTotal $unifiedpurseCurrency \r\nDATE: $transaction_date\r\n\r\nYou can always confirm your transaction/payment status at $history_url\r\n\r\nRegards.";
			@mail ($customer_email,"Transaction Information",$mail_message,$mail_headers);

			return '<form action="'.$form_action.'" method="post" id="unifiedpurse_payment_form">'.$input_fields.'
						<input type="submit" class="button-alt" id="submit_unifiedpurse_payment_form" value="'.__('Pay via Unifiedpurse', 'woothemes').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'woothemes').'</a>
						</form>'.$js_script;
		}



		/**
		 * Process the payment and return the result
		 **/
		function process_payment($order_id){
			global $woocommerce;
			$order = new WC_Order( $order_id );
			
		 	// Return payment page
			return array(
			'result'    => 'success',
			'redirect'	=> $order->get_checkout_payment_url( true )
			);
		}
		
		
		/**
		 * Successful Payment!
		 **/
		function check_callback( $posted ){
			global $wpdb;
			global $woocommerce;
			
			$posted = stripslashes_deep($_GET);
			
			$home_url=home_url();
			$checkout_url = $woocommerce->cart->get_checkout_url();
			$toecho="";
			$transaction_reference=empty($_POST['ref'])?$posted['ref']:@$_POST['ref'];
			
			if(isset($_REQUEST['load_unifiedpurse_as']))$_SESSION['LOAD_AS_ADMIN']=($_REQUEST['load_unifiedpurse_as']=='ADMIN');
			elseif(empty($_SESSION['LOAD_AS_ADMIN']))$_SESSION['LOAD_AS_ADMIN']=false;	
			$LOAD_AS_ADMIN=$_SESSION['LOAD_AS_ADMIN'];

			if(!empty($transaction_reference)){
				$transaction_reference=floatval($transaction_reference);
				$order_id=0;
				
				$res=$wpdb->get_results("SELECT * FROM {$this->db_tablename} WHERE transaction_reference=$transaction_reference LIMIT 1");
				if(empty($res))$Error=$response_description="No unifiedpurse transaction record found with the supplied reference";
				else {
					$transaction=(array)current($res);
					$order_id=$transaction['order_id']; 
					$unifiedpurse_merchant_id=$this->unifiedpurse_merchant_id;
					
					$order = new WC_Order($order_id);
					$order_amount=$order->order_total;
					$currency_code = $woocommerce_currency = get_woocommerce_currency();
					$new_status=$transaction['status'];
                    
					$approved_amount=0;
					$response_description="";
					$response_code="";
					
					$url="https://unifiedpurse.com/api_v1?action=get_transaction&receiver=$unifiedpurse_merchant_id&ref=$transaction_reference&amount=$order_amount&currency=$currency_code";
					
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);			
					curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
					curl_setopt($ch, CURLOPT_HEADER, 0);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
					curl_setopt($ch, CURLOPT_URL, $url);
					
					$response = @curl_exec($ch);
					$returnCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					if($returnCode != 200)$response=curl_error($ch);
					curl_close($ch);	
					$json=null;

					if($returnCode == 200)$json=@json_decode($response,true);
					else $response="HTTP Error $returnCode: $response. ";					
					
					if(!empty($json['error'])){
						$response_description=$json['error'];
						$response_code=$returnCode;						
					}
					elseif(!empty($json)){
						$response_description=$json['info'];
						$response_code=$new_status=$json['status'];
						$approved_amount=$json['amount'];
                        $success=($new_status==1);
					}
					else {
						$response_description=$response;
						$response_code=$returnCode;
					}
				
					if($order->status !== 'completed'){
						if($new_status==1){			
							$order->add_order_note(__("Unifiedpurse payment was successful. Ref: $transaction_reference", 'woothemes'));
                            $order->update_status('completed');
							$order->payment_complete($transaction_reference);
                            WC()->cart->empty_cart();
						}
						elseif($new_status==-1){ 
							$order->cancel_order(__('Unifiedpurse payment verification failed', 'woothemes'));
							error_log("$response_description Check to see if data is corrupted for OrderID:$order_id, Transaction Reference: $transaction_reference ");
						}
						//update_post_meta($order_id,'UnifiedpursetransactionId', $_GET['transactionId']);
						$response_descriptions=addslashes($response_description);
						$sql="UPDATE {$this->db_tablename} SET response_code='$response_code',response_description='$response_descriptions',status='$new_status' WHERE transaction_reference='$transaction_reference'";
						$wpdb->query($sql);
					}
					elseif($new_status==-1)error_log("$response_description Unifiedpurse info received for already completed payment OrderID:$order_id, Transaction Reference: $transaction_reference ");
					
					$f_email=$wpdb->get_var("SELECT customer_email FROM {$this->db_tablename} WHERE transaction_reference='$transaction_reference' LIMIT 1");
									
					
					$history_params=array('wc-api'=>get_class($this),'f_email'=>$f_email,'p'=>@$posted['p']);
					$history_url = add_query_arg ($history_params, $checkout_url);
                    $success=($new_status==1);
				}
				
				if($success){
                    $receipt_url=$order->get_checkout_order_received_url();
					$toecho="<div class='successMsg'>
                                $response_description<br/>
                                Your order has been successfully Processed <br/>
                                ORDER ID: $order_id<br/>
                                TRANSACTION REFERRENCE: $transaction_reference<br/>
                                <div style='text-align:center;'>
                                    <a href='$history_url' class='btn btn-default btn-sm'><i class='fa fa-list'></i> TRANSACTIONS</a>
                                    <a href='$receipt_url' class='btn btn-default btn-sm><i class='fa fa-home'></i> VIEW RECEIPT</a>
                                </div>
							</div>";
				}
				else {
					$toecho="<div class='errorMessage'>
							Your transaction was not successful<br/>
							REASON: $response_description<br/>
							ORDER ID: $order_id<br/>
							TRANSACTION REFERRENCE: $transaction_reference<br/>
							<a href='$history_url' class='btn btn-default btn-sm'><i class='fa fa-list'></i> TRANSACTION HISTORY</a>  
							<a href='$home_url' class='btn btn-default btn-sm'><i class='fa fa-home'></i> HOME</a>
							</div>";
				}
				
			}
			else
			{
				$f_email=addslashes(trim($posted['f_email']));
				
				$dwhere=($f_email=='admin')?"":"  WHERE customer_email='$f_email' ";				
				$num=$wpdb->get_var("SELECT COUNT(*) FROM {$this->db_tablename} $dwhere ");
				
				$history_url = add_query_arg ('wc-api',get_class($this), $checkout_url);

				$toecho.="<h3><i class='fa fa-credit-card'></i>
					Unifiedpurse Transactions 
					<a href='$home_url' class='btn btn-sm btn-link pull-right'><i class='fa fa-home'></i> HOME</a>
				</h3>
				<div class='text-right'>
					<form method='get' action='$history_url' class='form-inline'>
						<div class='form-group'>
							<label for='f_email'>Email</label>
							<input type='text' class='form-control input-sm' name='f_email' value='$f_email' />
						</div>
						<button class='btn btn-sm btn-info'>Fetch</button>
					</form>
				</div>
				<hr/>";

				if($num==0)$toecho.="<strong>No record found for transactions made through GTPay</strong>";
				else
				{
					$perpage=10;
					$totalpages=ceil($num/$perpage);
					$p=empty($posted['p'])?1:$posted['p'];
					if($p>$totalpages)$p=$totalpages;
					if($p<1)$p=1;
					$offset=($p-1) *$perpage;
					$sql="SELECT * FROM {$this->db_tablename} $dwhere ORDER BY id DESC LIMIT $offset,$perpage ";
					$query=$wpdb->get_results($sql);                    
                    $status_vals=array(
                        -1=>"<span style='color:#ff0000;'>FAILED</span>",
                        0=>"<span style='color:#ff9900;'>PENDING</span>",
                        1=>"<span style='color:#009900;font-weight:bold;'>SUCCESSFUL</span>",
                        );
                        
					$toecho.="
							<table style='width:100%;' class='table table-striped table-condensed' >
								<tr style='width:100%;text-align:center;'>
                                    <th>S/N</th>
									<th>Email</th>
									<th>Reference</th>
									<th>Order#</th>
									<th>Status</th>
									<th>Date</th>
									<th>Amount</th>
									$code_td
									<th style='width:20%;'>Transaction Response</th>
									<th>Action</th>
								</tr>";
					$sn=$offset;
                    $admin_edit_url=get_admin_url(null,'post.php?action=edit');
                    
					foreach($query  as $row){
						$row=(array)$row;
						$sn++;
						
						if($row['status']==0){
							$history_params=array('wc-api'=>get_class($this),'wooorderid'=>$row['order_id'],'ref'=>$row['transaction_reference'],'p'=>$p);
							$history_url = add_query_arg ($history_params, $checkout_url);
							$trans_action="<a href='$history_url' class='btn btn-xs btn-primary' >REQUERY</a>";
						}
						else {
							$trans_action='NONE';						
						}
						$date_time=date('d-m-Y g:i a',$row['time']);
						$transaction_response=$row['response_description'];
						
						if(empty($transaction_response))$transaction_response='(pending)';
						if(empty($row['approved_amount']))$row['approved_amount']='0.00';
                        $transaction_amount=number_format($row['transaction_amount'],2);
						
						$toecho.="<tr align='center'>
									<td>$sn</td>
									<td>
										{$row['customer_email']} <br/>
										(<i>{$row['customer_firstname']} {$row['customer_lastname']}</i>)
									</td>
									<td>{$row['transaction_reference']}</td>
                                    <td><a href='{$admin_edit_url}&post={$row['order_id']}' target='_blank'>#{$row['order_id']}</a></td>
									<td>{$status_vals[$row['status']]}</td>
									<td>$date_time
									</td>
									<td>{$transaction_amount} {$row['currency_code']}</td>
									<td style='font-size:small;word-break:break-word;'>$transaction_response</td>
									<td>$trans_action</td>								
								 </tr>";
					}
					$toecho.="</table>";
					
					$pagination="";
					$prev=$p-1;
					$next=$p+1;
					
					$history_params=array('wc-api'=>get_class($this),'f_email'=>$f_email);
				
					if($totalpages>2&&$prev>1){
						$history_params['p']=1;
						$history_url = add_query_arg ($history_params, $checkout_url);
						$pagination.=" <li><a href='$history_url'>&lt;&lt;</a></li> ";	
					}
					if($prev>=1){
						$history_params['p']=$prev;
						$history_url = add_query_arg ($history_params, $checkout_url);
						$pagination.=" <li><a href='$history_url' >&lt;</a></li>  ";
					}
					if($next<=$totalpages){
						$history_params['p']=$next;
						$history_url = add_query_arg ($history_params, $checkout_url);
						$pagination.=" <li><a href='$history_url'> > </a></li> ";
					}
					if($next<=$totalpages){
						$history_params['p']=$totalpages;
						$history_url = add_query_arg ($history_params, $checkout_url);
						$pagination.=" <li><a href='$history_url'> >> </a></li> ";
					}

					$pagination="<ul class='pagination pagination-sm' style='margin:0px;'><span class='btn btn-default btn-sm disabled'>PAGE: $p of $totalpages</span> $pagination</ul>";
					
					$toecho.="<div class='text-right' >$pagination</div>";
				}
			}
            /*
            
			<link href='//maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap-theme.min.css' rel='stylesheet'>
			
			<script src='//ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js'></script>
			<script src='//maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js'></script>
            */
			
            $inside_head="<link href='{$this->assets_base}/css/bootstrap.min.css' rel='stylesheet'>
            <link href='//maxcdn.bootstrapcdn.com/font-awesome/4.2.0/css/font-awesome.min.css' rel='stylesheet'>
			<style type='text/css'>
				.errorMessage,.successMsg
				{
					color:#ffffff;
					font-size:18px;
					font-family:helvetica;
					border-radius:9px
					display:inline-block;
					max-width:50%;
					border-radius: 8px;
					padding: 4px;
					margin:auto;
				}
				
				.errorMessage{background-color:#ff5500;}
				
				.successMsg{background-color:#00aa99;}
                a.btn.btn-sm:visited,a.btn.btn-xs:visited{color:#ffffff;}
                a.btn.btn-default:visited{color:#333333;}
				table td, table th{color:#333;background-color:#ffffff;}
				body,html{min-width:100%;}
			</style>";
			/*
			$str= "<!DOCTYPE html>
		<html lang='en'>
		<head>
			<meta charset='utf-8'>
			<meta http-equiv='X-UA-Compatible' content='IE=edge'>
			<meta name='viewport' content='width=device-width, initial-scale=1'>
			<title>Unifiedpurse WooCommerce  Payments</title>
			
		</head>
		<body>
			<div class='container' style='min-height: 500px;padding-top:15px;padding-bottom:15px;'>
				$toecho
			</div>
		</body>
		</html>";
        */
            
			if(empty($LOAD_AS_ADMIN))get_header();
			echo "<div class='container' style='padding-top:15px;padding-bottom:15px;'>$toecho</div>";
            echo $inside_head;
            if(empty($LOAD_AS_ADMIN))get_footer();
			exit;
		}
	
		/**
		 * receipt_page
		**/
		function receipt( $order ) {
			echo '<p>'.__('You are being redirected to the Unifiedpurse Payment Window.', 'woothemes').'</p>';
			echo $this->generate_unifiedpurse_form( $order );
		}
	
		/**
		 * thankyou_page
		 **/
		function thankyou($order) {
			echo '<p>'.__('Thank you for your order.', 'woothemes').'</p>';
		}
	}

	
    add_filter( 'woocommerce_payment_gateways', 'wc_unifiedpurse_gateway' );
}


function wc_unifiedpurse_gateway( $methods ) {
	$methods[] = 'WC_Gateway_UnifiedpursePayment';
	return $methods;
}

/**
* Add Settings link to the plugin entry in the plugins menu
**/
function wc_unifiedpurse_gateway_action_links($links){
    $links[]='<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=unifiedpurse' ) . '" title="View UnifiedPurse WooCommerce Settings">Settings</a>';
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_unifiedpurse_gateway_action_links' ); //todo:: woo_unifiedpurse

add_action('admin_menu', 'unifiedpurse_transactions_menu');
function unifiedpurse_transactions_menu() {
	function load_admin_unifiedpurse_transactions(){
		$url=home_url().'?wc-api=WC_Gateway_UnifiedpursePayment&load_unifiedpurse_as=ADMIN';
		echo "<iframe  src='$url' style='width:100%;min-height:650px;border:1px solid #bbb;border-radius:3px;'></iframe>";
	}	
	add_submenu_page('woocommerce', 'UnifiedPurse Payment Transactions', 'UnifiedPurse Transaction History','manage_options','unifiedpurse-transactions','load_admin_unifiedpurse_transactions');
}