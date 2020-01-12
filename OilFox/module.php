<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/helpers/autoload.php');

/**
 * Class Oilfox
 * Driver to OilFox API (inofficial)
 *
 * @version     1.2
 * @category    Symcon
 * @package     de.codeking.symcon.oilfox
 * @author      Frank Herrmann <frank@herrmann.to>
 * @link        https://herrmann.to
 * @link        https://github.com/CodeKing/de.codeking.symcon.oilfox
 *
 */
class Oilfox extends Module
{
    private $email;
    private $password;
    private $token;

    public $tanks = [];

    protected $archive_mappings = [ // archive: 0 = default, 1 = counter
        'Current Level (L)' => 0,
        'Current Level (%)' => 0,
        'Current Price' => 0
    ];

    protected $profile_mappings = [
        'Current Level (L)' => 'Liter',
        'Current Level (%)' => '~Intensity.100',
        'Level next month (L)' => 'Liter',
        'Level next month (%)' => '~Intensity.100',
        'Current Price' => 'Price',
        'Battery' => '~Battery.100',
        'Volume' => 'Liter',
        'Tank Height' => 'Distance',
        'Filling Height' => 'Distance',
        'Empty Height' => 'Distance'
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('email', 'user@email.com');
        $this->RegisterPropertyString('password', '');
        $this->RegisterPropertyInteger('interval', 60); // in minutes

        // register timer
        $this->RegisterTimer('UpdateData', 60 * 60 * 1000, $this->_getPrefix() . '_Update($_IPS[\'TARGET\']);');
    }

    /**
     * execute, when kernel is ready
     */
    protected function onKernelReady()
    {
        // update timer
        $this->SetTimerInterval('UpdateData', $this->ReadPropertyInteger('interval') * 60 * 1000);

        // Update data
        $this->Update();
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        $this->email = $this->ReadPropertyString('email');
        $this->password = $this->ReadPropertyString('password');

        $this->token = $this->GetBuffer('token');
    }

    /**
     * read & update tank data
     */
    public function Update()
    {
        // return if service or internet connection is not available
        if (!Sys_Ping('oilfox.io', 1000)) {
            $this->_log('OilFox', 'Error: Oilfox api or internet connection not available!');
            exit(-1);
        }

        // read config
        $this->ReadConfig();

        // check if email and password are provided
        if (!$this->email || !$this->password) {
            return false;
        }

        // read access token
        if (!$this->token) {
            $this->token = $this->GetBuffer('token');
        }

        // force login every request
        $this->Login();

        // simple error handling
        if (!$this->token) {
            $this->SetStatus(201);
            $this->_log('OilFox', 'Error: The email address or password of your oilfox account is invalid!');
            exit(-1);
        }

        // everything looks ok, start
        $this->SetStatus(102);

        // get tank data
        $tanks = $this->Api('user/summary');

        // loop each tank
        foreach ($tanks['devices'] AS $tank) {
            // extract values
            $product = isset($tank['partner']['primaryProducts'][0]) ? $tank['partner']['primaryProducts'][0] : null;
            $current_price = $tank['chartData']['priceData'] ? end($tank['chartData']['priceData']) : null;
            $forecast = $tank['chartData']['forecastData'] ? reset($tank['chartData']['forecastData']) : null;
            $metering = $tank['metering'];

            // map data
            $this->tanks[$tank['id']] = [
                'Name' => $tank['name'] ? $tank['name'] : $tank['hwid'],
                'Oil Type' => $product['name'],
                'Volume' => (float)$tank['tankVolume'],
                'Tank Height' => $tank['tankHeight'],
                'Empty Height' => $metering['value'],
                'Filling Height' => $metering['currentOilHeight'],
                'Current Level (L)' => (float)$metering['liters'],
                'Current Level (%)' => (int)$metering['fillingPercentage'],
                'Level next month (L)' => (float)$forecast['liters'],
                'Level next month (%)' => (int)$forecast['fillingPercentage'],
                'Battery' => (int)$tank['metering']['battery'],
                'Current Price' => (float)$current_price['price']
            ];
        }

        // log data
        $this->_log('OilFox Data', json_encode($this->tanks));

        // save data
        $this->SaveData();
    }

    /**
     * save tank data to variables
     */
    private function SaveData()
    {
        // loop tanks and save data
        foreach ($this->tanks AS $tank_id => $data) {
            // get category id from tank id
            $category_id_tank = $this->CreateCategoryByIdentifier($this->InstanceID, $tank_id, $data['Name']);

            // loop tank data and add variables to tank category
            $position = 0;
            foreach ($data AS $key => $value) {
                $this->CreateVariableByIdentifier([
                    'parent_id' => $category_id_tank,
                    'name' => $key,
                    'value' => $value,
                    'position' => $position
                ]);
                $position++;
            }
        }
    }

    /**
     * basic api to oilfox (inofficial)
     * @param string $request
     * @return mixed
     */
    public function Api(string $request)
    {
        // build url
        $url = 'https://api.oilfox.io/v3/' . $request;

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Connection: Keep-Alive',
                'User-Agent: okhttp/3.2.0'
            ]
        ];

        // call api
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);
        $result = curl_exec($ch);
        curl_close($ch);

        // return result
        return json_decode($result, true);
    }

    /**
     * Login to oilfox
     */
    public function Login()
    {
        $this->_log('OilFox', sprintf('Logging in to oilfox account of %s...', $this->email));

        // login url
        $url = 'https://api.oilfox.io/v3/login';

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'email' => $this->email,
                'password' => $this->password
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Connection: Keep-Alive',
                'User-Agent: okhttp/3.2.0'
            ]
        ];

        // login
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);
        $result = curl_exec($ch);
        curl_close($ch);

        // extract token
        $json = json_decode($result, true);
        $this->token = isset($json['access_token']) ? $json['access_token'] : false;

        // save valid token
        if ($this->token) {
            $this->SetStatus(102);
            $this->SetBuffer('token', $this->token);
        } // simple error handling
        else {
            $this->SetStatus(201);
            $this->_log('OilFox', 'Error: The email address or password of your oilfox account is invalid!');
            exit(-1);
        }
    }

    /**
     * create custom variable profile
     * @param string $profile_id
     * @param string $name
     */
    protected function CreateCustomVariableProfile(string $profile_id, string $name)
    {
        switch ($name):
            case 'Price':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 2); // 2 decimals
                IPS_SetVariableProfileText($profile_id, '', ' €'); // currency symbol
                IPS_SetVariableProfileIcon($profile_id, 'Euro');
                break;
            case 'Liter':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 0); // 0 decimals
                IPS_SetVariableProfileText($profile_id, '', ' Liter');
                IPS_SetVariableProfileIcon($profile_id, 'Drops');
                break;
            case 'Distance':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', ' cm');
                IPS_SetVariableProfileIcon($profile_id, 'Gauge');
                break;
        endswitch;
    }
}