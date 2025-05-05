<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
date_default_timezone_set('Europe/Warsaw');

define("DEFINE_MY_ACCESS", true);
define("DEFINE_DHRU_FILE", true);
define("ROOTDIR", __DIR__);

include ROOTDIR . "/comm.php";
require ROOTDIR . "/includes/fun.inc.php";
include ROOTDIR . "/includes/gateway.fun.php";
include ROOTDIR . "/includes/invoice.fun.php";

$version = 1.1;
$GATEWAY = loadGatewayModule('usdtportal');

if ($GATEWAY["active"] != 1) {
    exit("Module Not Activated");
}

$rawBody = trim(file_get_contents("php://input"));
if (!$rawBody) {
    exit("OK!");
}

if (isset($_POST['test_callback'], $_POST['email'], $_POST['callback_url_password'])) {
    if ($GATEWAY['callback_url_password'] != $_POST['callback_url_password'] || $GATEWAY['email'] != $_POST['email']) {
        $data = [
            'is_success' => false,
            'code' => 403,
            'message' => "Credentials no match. Make sure you setup Email, Api Key and Secret Callback Password in your DHRU website from Settings>Payment Gateways>USDT Portal - Auto Crypto Payments"
        ];
        exit(json_encode($data));
    } else {
        $ip = getServerIP();
        $ipv6 = getServerIPv6();
        $data = [
            'is_success' => true,
            'code' => 200,
            'message' => "Credentials match. Callback is correctly set.<br>IPv4: $ip<br>IPv6: $ipv6",
            'ip' => $ip,
            'ipv6' => $ipv6,
            'version' => $version
        ];
        exit(json_encode($data));
    }
}

if (!isset($_POST['order_id'], $_POST['transaction_id'], $_POST['amount_with_commission'], $_POST['fee'], $_POST['user_email'], $_POST['txn_hash'], $_POST['received_timestamp'], $_POST['email'], $_POST['callback_url_password'])) {
    exit("What are you doing here?");
}

if ($GATEWAY['callback_url_password'] != $_POST['callback_url_password'] || $GATEWAY['email'] != $_POST['email']) {
    $data = [
        'is_success' => false,
        'code' => 403,
        'message' => "Credentials no match. Make sure you setup Email, Api Key and Secret Callback Password in your DHRU website from Settings>Payment Gateways>USDT Portal - Auto Crypto Payments"
    ];
    exit(json_encode($data));
}

// Incoming data you can use for your records
$order_id = $_POST['order_id'];
$transaction_id = $_POST['transaction_id'];
$user_email = $_POST['user_email'];
$amount = $_POST['amount'];
$amount_with_commission = $_POST['amount_with_commission'];
$fee = $_POST['fee'];
$currency = $_POST['currency'];
$network = $_POST['network'];
$target_wallet = $_POST['target_wallet'];
$txn_hash = $_POST['txn_hash'];
$initiated_timestamp = $_POST['initiated_timestamp'];
$received_timestamp = $_POST['received_timestamp'];
$status = $_POST['status'];
$creation_ip = $_POST['creation_ip'];
$edit_amount = $_POST['edit_amount'];


$orderDetails = getInvoiceDetails($order_id, $user_email);

if (!$orderDetails) {
    $data = [
        'is_success' => false,
        'code' => '404',
        'message' => "Order not found"
    ];
    exit(json_encode($data));
}

if ($orderDetails['status'] != "Unpaid") {
    $data = [
        'is_success' => false,
        'code' => '405',
        'message' => "Order found but status is " . $orderDetails['status']
    ];
    exit(json_encode($data));
}

if (searchTxid($txn_hash)) {
    $data = [
        'is_success' => false,
        'code' => '406',
        'message' => "Txid already located in database"
    ];
    exit(json_encode($data));
}

$isAddFundsOrder = ($orderDetails['pos'] == 0 && $orderDetails['warehouse'] == 0 && $orderDetails['retail'] == 0 && $orderDetails['item_types'] == 'AddFunds') ? true:false;

if ($edit_amount && $isAddFundsOrder && $orderDetails['subtotal'] != $amount) {
    if (!updateInvoice($order_id, $amount)) {
        $data = [
            'is_success' => false,
            'code' => '500',
            'message' => "Invoice update error"
        ];
        exit(json_encode($data));
    }
}

if (explode('_',$transaction_id)[0] != $order_id || !checkUSDTPortal($transaction_id, $amount, $GATEWAY)) {
    $data = [
        'is_success' => false,
        'code' => '406',
        'message' => "USDT Portal status claims transaction is unpaid or amount not same",
    ];
    exit(json_encode($data));
}

addPayment($order_id, $txn_hash, $amount, $fee, $GATEWAY['paymentmethod']);

$data = [
    'is_success' => true,
    'code' => '200',
    'message' => "Credits Added - $amount"
];
exit(json_encode($data));



function getInvoiceDetails($order_id, $user_email) {
    global $config;

    $order_id_safe = intval($order_id);
    $user_email_safe = addslashes($user_email);

    $query = "
        SELECT 
            i.*, 
            GROUP_CONCAT(ii.`type` SEPARATOR ',') AS item_types
        FROM `tbl_invoices` AS i
        INNER JOIN `tblUsers` AS u 
            ON i.`userid` = u.`id`
        LEFT JOIN `tbl_invoiceitems` AS ii 
            ON ii.`invoiceid` = i.`id`
        WHERE 
            i.`id`    = '$order_id_safe'
        AND u.`email` = '$user_email_safe'
        GROUP BY i.`id`
        LIMIT 1
    ";

    $result = dquery($query);
    if (!$result) {
        return false;
    }


    return mysqli_fetch_assoc($result);
}

function updateInvoice($order_id, $amount) {
    global $config;
    
    $order_id_safe = intval($order_id);
    $amount_safe = floatval($amount);
    
    $query = "UPDATE tbl_invoices 
              SET subtotal = '$amount_safe', tax = '0', tax2 = '0', shippingtax = '0', taxrate = '0', taxrate2 = '0', fixedcharge = '0', total = '$amount_safe', receivedamount = '0', paymentmethod = 'usdtportal'
              WHERE id = '$order_id_safe';";
    
    $result = dquery($query);
    if (!$result) {
        return false;
    }
    
    return updateInvoice2($order_id_safe, $amount_safe);
}


function updateInvoice2($order_id_safe, $amount_safe) {
    global $config;
    
    $query = "UPDATE tbl_invoiceitems 
        SET amount = '$amount_safe', paymentmethod = 'usdtportal'
        WHERE invoiceid = '$order_id_safe'";
    
    $result = dquery($query);
    if (!$result) {
        return false;
    }
    
    return true;
}

function searchTxid($transid)
{
    $result = select_query("tbl_transaction", "id", ["transid" => $transid]);
    $num_rows = mysqli_num_rows($result);
    if($num_rows) {
        return true;
    }
    return false;
}


function getServerIP() {
    $ch = curl_init('https://api.ipify.org?format=json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($response);
    return isset($json->ip) ? $json->ip : '0';
}

function getServerIPv6() {
    $ch = curl_init('https://api64.ipify.org?format=json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($response);
    return isset($json->ip) ? $json->ip : '0';
}

function checkUSDTPortal($transaction_id, $amount, $GATEWAY) {
    $args = [
        "action" => "status",
        "merchant" => [
            "email" => $GATEWAY['email'],
            "api_key" => $GATEWAY['api_key'],
        ],
        "transaction_id" => $transaction_id,
    ];

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, "https://usdtportal.com/api/");
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($args));
    curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded;charset=UTF-8"]);
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_code == 200) {
        $data = json_decode($response, true);
        if (isset($data['transaction_status'], $data['amount']) && $data['transaction_status'] === 'paid' && $data['amount'] == $amount) {
            return true;
        }
    }
    return false;
}
?>
