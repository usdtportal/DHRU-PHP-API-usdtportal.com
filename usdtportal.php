<?php

class USDT_Portal_Service
{
    private $gateway_url = "https://usdtportal.com/api/";
    private $params = [];
    private $args = [];
    public $http_code = 0;

    public function __construct($params) {
        $this->params = $params;

        $this->args = [
            'action' => 'new',
            "merchant" => [
                'email' => $this->params['email'],
                'api_key' => $this->params['api_key'],
            ],
            "customer" => [
                'user_email' => $this->params["clientdetails"]["email"],
                'amount' => ($this->params["amount"] - $this->params["invtax"]), //itemamt
                'currency' => strtoupper($params['currency'])
            ],
            'order_id' => $this->params["invoiceid"],
            'redirect_paid' => $this->params['systemurl'] . 'settings/statement',
            'redirect_canceled' => $this->params['systemurl'] . 'main',
        ];
    }
   
    public function generate_link() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->gateway_url);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($this->args));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type:application/x-www-form-urlencoded;charset=UTF-8',
        ]);
        $response = curl_exec($ch);
        $this->http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return json_decode($response);
    }
}
function usdtportal_config()
{
    $configarray = array(
        'name' => array(
            'Type' => 'System',
            'Value' => 'USDT Portal - Auto Crypto Payments www.usdtportal.com'
        ),
        'email' => array(
            'Name' => 'USDT Portal Account Email',
            'Type' => 'text',
            'Size' => '50',
            'Description' => '<a href="https://usdtportal.com/register.php" target="_blank" style="color:blue;">Register</a>'
        ),
        'api_key' => array(
            'Name' => 'API Key',
            'Type' => 'text',
            'Value' => '',
            'Size' => '32',
            'Description' => '<a href="https://usdtportal.com/panel" target="_blank" style="color:blue;">Get Your Api Key</a>'
        ),
        'callback_url_password' => array(
            'Name' => 'Secret Callback Password',
            'Type' => 'text',
            'Value' => '',
            'Size' => '32'
        ),
    );
    
    return $configarray;
}
function usdtportal_link($params)
{   
    global $lng_languag;
    $client = new USDT_Portal_Service($params);
    $server_response = $client->generate_link();

    if($client->http_code === 403) {
        return '<p style="color:red;">Please whitelist your Server IP from <a style="color:#FF5555;" href="https://usdtportal.com/panel" target="_blank" style="color:red;">Merchant Panel</a></p>';
    }

    if($client->http_code !== 200) {
        return '<p style="color:red;">USDT Portal Server Offline</p>';
    }

    if (!$server_response->auth || $server_response->error) {
        return '<p style="color:red;">'.$server_response->message.'</p>';
    }

    if (isset($server_response->message) AND strlen($server_response->message) > 1) {
        return '<a class="btn btn-success pt-3 pb-3" style="width: 100%; background-color: green!important;" href="'.$server_response->url.'">'.$lng_languag.'<br><span>'.$server_response->message.'</span>';
    } else {
        return '<a class="btn btn-success pt-3 pb-3" style="width: 100%; background-color: green!important;" href="'.$server_response->url.'">'.$lng_languag["invoicespaynow"].'</a>';
    }
}

?>
